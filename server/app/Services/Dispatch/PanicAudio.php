<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Api\ApiError;
use App\Live\Events\AlertLive;
use App\Live\LiveBus;
use App\Models\DuressAlert;
use App\Models\DuressAlertAudio;
use App\Models\ResidentAccount;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "SHARE LIVE AUDIO" — WHAT THE RESIDENT'S PHONE HEARS, TO THE CONTROL ROOM, WHILE A PANIC IS OPEN (board
 * resident-app-06; M50 §4.2 #1).
 *
 *   the phone        records a few seconds at a time while the switch is on — in the background too — and sends each
 *                    piece as it ends (`duress.audio.store`), through its queue: with no signal the pieces wait on the
 *                    phone and go in order once there is one
 *   the console      the alert's page says whether audio is coming in; "Listen" plays the pieces in order as they
 *                    arrive (DispatchController::audioList / audioPiece), from the private `panic_audio` disk only
 *
 * LIVE MEANS A PIECE ARRIVED A MOMENT AGO, never "the switch was on": the console says "Live" while the last piece is
 * no older than `LIVE_SECONDS`, and otherwise when the last one came. A piece for a closed alert is refused, and the
 * phone stops recording.
 */
class PanicAudio
{
    /** A piece longer than this was not made by the app (it records five seconds at a time). */
    public const MAX_KB = 2048;

    /**
     * The central disk the pieces live on (config/filesystems.php): never `local`, which tenancy re-roots per estate —
     * the phone writes from inside its estate's request and the console reads from outside any.
     */
    public const DISK = 'panic_audio';

    /** The console says "Live" while the newest piece is no older than this. */
    public const LIVE_SECONDS = 15;

    public const MIMES = ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac', 'audio/mpeg', 'video/mp4', 'audio/3gpp', 'video/3gpp'];

    private const CLOSED = ['resolved', 'false_alarm'];

    public const CLOSED_FOR_RESIDENT = 'This alert is closed, so its live audio has stopped. If you still need help, press the panic button again or call 119.';

    public function __construct(private readonly AuditLogger $audit) {}

    /** One piece from the resident's phone. Idempotent on `seq`. Refused on a closed alert (409 `alert_closed`). */
    public function store(DuressAlert $alert, ResidentAccount $account, UploadedFile $file, int $seq, ?int $durationMs): DuressAlertAudio
    {
        if (($already = $this->piece($alert, $seq)) !== null) {
            return $already;
        }

        if (in_array($alert->status, self::CLOSED, true)) {
            throw ApiError::conflict('alert_closed', self::CLOSED_FOR_RESIDENT);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'm4a');
        $path = $file->storeAs(sprintf('%s/%d', $alert->tenant_id, $alert->id), sprintf('%06d.%s', $seq, preg_match('/^[a-z0-9]{2,4}$/', $extension) === 1 ? $extension : 'm4a'), self::DISK);

        if (! is_string($path) || $path === '') {
            throw ApiError::conflict('audio_not_kept', 'That piece of audio could not be kept on the server. It is sent again by itself.');
        }

        try {
            $piece = DuressAlertAudio::query()->create([
                'duress_alert_id' => $alert->id,
                'tenant_id' => (string) $alert->tenant_id,
                'seq' => $seq,
                'path' => (string) $path,
                'mime' => mb_substr((string) ($file->getMimeType() ?: 'audio/mp4'), 0, 40),
                'bytes' => (int) $file->getSize(),
                'duration_ms' => $durationMs,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->piece($alert, $seq) ?? throw ApiError::conflict('audio_conflict', 'That piece of audio is already being sent.');
        }

        // The first piece is news on every screen showing the alert: "Live audio" appears on its page without a touch.
        if ($seq === 0 || DuressAlertAudio::query()->where('duress_alert_id', $alert->id)->count() === 1) {
            rescue(static fn () => DB::connection($alert->getConnectionName())->transaction(static fn () => LiveBus::recordAll(AlertLive::for($alert, 'audio'))), report: true);

            rescue(fn () => $this->audit->record(
                action: 'panic.live_audio_started',
                entityType: 'DuressAlert',
                entityId: (string) $alert->id,
                after: ['account_id' => (int) $account->id],
                tenantId: (string) $alert->tenant_id,
            ), report: false);
        }

        return $piece;
    }

    /**
     * What the alert's page says about its audio.
     *
     * @return array{pieces: int, seconds: int, last_at: string|null, live: bool}|null
     */
    public function summary(DuressAlert $alert): ?array
    {
        if ($alert->raised_by_account_id === null) {
            return null;
        }

        $rows = DuressAlertAudio::query()->where('duress_alert_id', $alert->id);
        $last = (clone $rows)->max('created_at');
        $lastAt = $last === null ? null : Carbon::parse((string) $last);

        return [
            'pieces' => (clone $rows)->count(),
            'seconds' => (int) round(((int) (clone $rows)->sum('duration_ms')) / 1000),
            'last_at' => $lastAt?->toIso8601String(),
            'live' => $lastAt !== null && $lastAt->greaterThan(Carbon::now()->subSeconds(self::LIVE_SECONDS)) && ! in_array($alert->status, self::CLOSED, true),
        ];
    }

    /**
     * The pieces after `$after`, oldest first, for the console's player.
     *
     * @return list<array{seq: int, url: string, duration_ms: int|null, at: string}>
     */
    public function after(DuressAlert $alert, int $after): array
    {
        return DuressAlertAudio::query()->where('duress_alert_id', $alert->id)->where('seq', '>', $after)->orderBy('seq')->limit(60)->get()
            ->map(static fn (DuressAlertAudio $piece): array => [
                'seq' => (int) $piece->seq,
                'url' => '/dispatch/alerts/'.$alert->id.'/audio/'.$piece->seq,
                'duration_ms' => $piece->duration_ms,
                'at' => $piece->created_at->toIso8601String(),
            ])->values()->all();
    }

    /** One piece's bytes, from the private disk — the only way it is ever served. */
    public function stream(DuressAlert $alert, int $seq): ?StreamedResponse
    {
        $piece = $this->piece($alert, $seq);

        if ($piece === null || ! Storage::disk(self::DISK)->exists($piece->path)) {
            return null;
        }

        return Storage::disk(self::DISK)->response($piece->path, null, ['Content-Type' => $piece->mime, 'Cache-Control' => 'private, no-store']);
    }

    private function piece(DuressAlert $alert, int $seq): ?DuressAlertAudio
    {
        return DuressAlertAudio::query()->where('duress_alert_id', $alert->id)->where('seq', $seq)->first();
    }
}
