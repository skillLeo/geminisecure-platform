<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\CheckpointScan;
use App\Models\PatrolCheckpoint;
use App\Models\Shift;
use App\Services\Dispatch\ClockInFence;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A site's patrol checkpoints, and scanning them (13 D2). Board guard-app-02 screen 7.
 *
 * THE SCAN PROVES PRESENCE ONLY IF IT CARRIES THE CODE. A checkpoint's `code` is
 * what its QR tag or NFC tag encodes; the handset sends what it read, and a scan
 * whose code does not match the checkpoint is refused — otherwise a guard could
 * "scan" a checkpoint from the gatehouse by tapping its row.
 */
class PatrolController extends Controller
{
    public function checkpoints(string $site, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        if ($site !== $context->tenantId) {
            throw ApiError::forbidden('wrong_site', 'A handset reads its own site\'s checkpoints.');
        }

        // A tour is "today's" on the SITE'S clock (M4 §3.1) — a night shift's scans do not reset at 7 PM Kingston.
        $today = SiteTime::today($context->tenantId);

        // When each checkpoint was REACHED — a scan taken with no signal at the moment it was taken, not when it synced.
        $lastScans = DB::connection('mysql')->table('checkpoint_scans')
            ->where('guard_id', $guard->id)
            ->whereRaw(CheckpointScan::REACHED_AT_SQL.' >= ?', [$today])
            ->selectRaw('patrol_checkpoint_id, MAX('.CheckpointScan::REACHED_AT_SQL.') AS last_scanned')
            ->groupBy('patrol_checkpoint_id')
            ->pluck('last_scanned', 'patrol_checkpoint_id');

        // The guard's own route when their post has one, else the site's (PatrolCheckpoint::tourFor).
        $tour = PatrolCheckpoint::tourFor($guard->id, $site);

        $items = DB::connection('mysql')->table('patrol_checkpoints')
            ->whereIn('id', $tour)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $c): array => [
                'id' => (int) $c->id,
                'label' => (string) $c->label,
                'sequence' => (int) $c->sequence,
                'post_id' => $c->post_id === null ? null : (int) $c->post_id,
                // Where it stands, when it has been placed (Operations → Sites & geofences, M11 §6); null otherwise.
                'latitude' => $c->latitude === null ? null : (float) $c->latitude,
                'longitude' => $c->longitude === null ? null : (float) $c->longitude,
                'last_scanned_at' => isset($lastScans[$c->id]) ? Carbon::parse((string) $lastScans[$c->id])->toIso8601String() : null,
            ])->all();

        /*
         * THE SITE'S POSTS, for the checkpoint map (M11 §6): each placed post and the geofence a guard clocks in
         * within. The surveyed positions of the estate's own gates — already what the clock-in compares against —
         * and nothing about any guard.
         */
        $posts = DB::connection('mysql')->table('posts')
            ->where('tenant_id', $site)->where('is_active', true)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderBy('name')
            ->get(['id', 'name', 'latitude', 'longitude', 'geofence_radius_m'])
            ->map(static fn (object $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'latitude' => (float) $p->latitude,
                'longitude' => (float) $p->longitude,
                'radius_m' => (int) ($p->geofence_radius_m ?? 5),
            ])->all();

        return response()->json([
            'site_id' => $site,
            'items' => $items,
            'posts' => $posts,
            'tour' => [
                'total' => count($items),
                'scanned_today' => count(array_filter($items, static fn (array $i): bool => $i['last_scanned_at'] !== null)),
            ],
        ]);
    }

    /**
     * WHAT A SCAN MUST BE, AND WHAT IT SAYS WHEN IT IS NOT (M21 §1.1). Each refusal is one sentence the guard reads
     * at arm's length, and nothing is recorded for it:
     *
     *   the wrong tag             code_mismatch — the code read is not this checkpoint's
     *   off shift                 not_on_shift — a patrol scan belongs to a shift, so the guard clocks in first
     *   outside the checkpoint    outside_checkpoint — the phone was further from the placed checkpoint than its
     *                             radius plus the margin of the phone's own reading (ClockInFence's rule), so a tag
     *                             photographed and scanned from the gatehouse does not count
     *
     * And what is recorded, and said, without refusing: a checkpoint nobody has placed yet, and a phone that sent
     * no position or only a poor one, are recorded with that fact beside them — the tag itself is the proof of
     * presence, and a guard is never stopped from proving it. A checkpoint scanned again within the tour cadence is
     * recorded as EARLY and the answer says when the next round there is due; it does not advance the tour.
     *
     * The moment judged is when the tag was REACHED — for a scan queued with no signal, its device time (D-192) —
     * so an offline scan is placed on the shift it was made on, not the one it synced on.
     */
    public function scan(Request $request, int $checkpoint, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'captured_offline' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $row = PatrolCheckpoint::query()->whereKey($checkpoint)->where('tenant_id', $context->tenantId)->first();

        if (! $row instanceof PatrolCheckpoint) {
            throw ApiError::notFound('not_found', 'No such checkpoint at your site.');
        }

        if (! hash_equals((string) $row->code, (string) $data['code'])) {
            throw ApiError::unprocessable('code_mismatch', 'The tag read does not belong to '.$row->label.'. Scan the tag at the checkpoint.');
        }

        // Taken off the route in Sites & geofences (M22): its tag may still be on the wall, and a scan of it is no round.
        if (! $row->is_active) {
            throw ApiError::unprocessable('checkpoint_retired', $row->label.' is no longer a checkpoint on your route, so this scan is not counted. Tell your supervisor the tag is still up.');
        }

        $offline = (bool) ($data['captured_offline'] ?? false);
        // In UTC, as the rows are: a Carbon binding is written in its own zone, and a device time carries the phone's.
        $reachedAt = ($offline && ! $context->clockSkewed() && $context->deviceTime !== null ? Carbon::instance($context->deviceTime) : Carbon::instance($context->serverTime))->utc();

        // Two minutes' grace before the clock-in: the phone's clock and the server's are two clocks (B066).
        $shift = Shift::query()
            ->where('guard_id', $guard->id)
            ->whereNotNull('actual_start')
            ->where('actual_start', '<=', $reachedAt->copy()->addMinutes(2))
            ->where(static fn ($q) => $q->whereNull('actual_end')->orWhere('actual_end', '>=', $reachedAt))
            ->orderByDesc('actual_start')
            ->first();

        if (! $shift instanceof Shift) {
            throw ApiError::unprocessable('not_on_shift', 'Clock in first. A patrol scan belongs to a shift, and you had none running when this tag was read.');
        }

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $accuracy = isset($data['accuracy_m']) ? (int) $data['accuracy_m'] : null;

        $fence = $row->latitude === null || $row->longitude === null ? null : [
            'source' => ClockInFence::SOURCE_POST, 'name' => (string) $row->label,
            'latitude' => (float) $row->latitude, 'longitude' => (float) $row->longitude, 'radius_m' => $row->radius(),
        ];
        $verdict = $fence === null ? null : ClockInFence::judge($fence, $lat, $lng, $accuracy);
        $position = $fence === null ? 'unplaced' : (string) $verdict['outcome'];

        if ($position === ClockInFence::OUTSIDE) {
            throw ApiError::unprocessable('outside_checkpoint', sprintf(
                'You are %dm from %s — %dm outside the %dm around its tag. %s is %s of you. Scan the tag where it hangs.',
                $verdict['distance_m'], $row->label, $verdict['beyond_m'], $row->radius(), $row->label, $verdict['direction'],
            ));
        }

        // The same checkpoint, by the same guard, inside the cadence: recorded, and said to be early.
        $previous = DB::connection('mysql')->table('checkpoint_scans')
            ->where('guard_id', $guard->id)->where('patrol_checkpoint_id', $row->id)
            ->whereRaw(CheckpointScan::REACHED_AT_SQL.' <= ?', [$reachedAt])
            ->selectRaw('MAX('.CheckpointScan::REACHED_AT_SQL.') AS at')
            ->value('at');
        $previousAt = $previous === null ? null : Carbon::parse((string) $previous);
        // The estate's own patrol interval (M27 §4.4): a vehicle patrol's is the Director's to set.
        $cadence = PatrolCheckpoint::cadenceFor($context->tenantId);
        $early = $previousAt !== null && $previousAt->diffInMinutes($reachedAt) < $cadence;

        $id = DB::connection('mysql')->table('checkpoint_scans')->insertGetId([
            'patrol_checkpoint_id' => $row->id,
            'guard_id' => $guard->id,
            'shift_id' => $shift->id,
            'latitude' => $lat === null ? null : round($lat, 7),
            'longitude' => $lng === null ? null : round($lng, 7),
            'accuracy_m' => $accuracy,
            'distance_m' => $verdict['distance_m'] ?? null,
            'early' => $early,
            'device_time' => $context->deviceTime,
            'server_time' => $context->serverTime,
            'clock_skewed' => $context->clockSkewed(),
            'captured_offline' => $offline,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'is_simulated' => $request->boolean('simulated'),
        ]);

        // The tour this scan counts toward: the guard's own route when it has checkpoints, else the site's.
        $onTour = PatrolCheckpoint::tourFor($guard->id, $context->tenantId);
        $total = count($onTour);
        $scanned = DB::connection('mysql')->table('checkpoint_scans')->where('guard_id', $guard->id)->whereIn('patrol_checkpoint_id', $onTour)->whereRaw(CheckpointScan::REACHED_AT_SQL.' >= ?', [SiteTime::today($context->tenantId)])->distinct()->count('patrol_checkpoint_id');
        $nextDue = ($early ? $previousAt : $reachedAt)->copy()->addMinutes($cadence);

        return response()->json([
            'scan_id' => $id,
            'checkpoint_id' => (int) $row->id,
            'label' => (string) $row->label,
            'shift_id' => (int) $shift->id,
            'position' => $position,
            'distance_m' => $verdict['distance_m'] ?? null,
            'early' => $early,
            'next_due_at' => $nextDue->toIso8601String(),
            'note' => self::note($position, $early, $row->label, $previousAt, $reachedAt, $nextDue, $context->tenantId),
            'tour' => ['total' => $total, 'scanned_today' => $scanned],
        ], 201);
    }

    /** The one sentence a recorded scan says beyond "confirmed", or null when there is nothing more to say. */
    private static function note(string $position, bool $early, string $label, ?Carbon $previousAt, Carbon $reachedAt, Carbon $nextDue, string $site): ?string
    {
        $parts = [];

        if ($early && $previousAt !== null) {
            $parts[] = sprintf('Recorded as early: you scanned %s %d min ago. The next round here is due at %s.', $label, max(0, (int) $previousAt->diffInMinutes($reachedAt)), SiteTime::stampIn($nextDue, SiteTime::zone($site), 'g:i A'));
        }

        $parts[] = match ($position) {
            'unplaced' => 'This checkpoint is not on the map yet, so where you were could not be checked — the office places it.',
            ClockInFence::NO_POSITION => 'No position came from this phone, so where you were could not be checked. Keep location on.',
            ClockInFence::POOR_FIX => 'Your phone could not say where it was closely enough to check against the checkpoint.',
            default => null,
        };

        $parts = array_values(array_filter($parts));

        return $parts === [] ? null : implode(' ', $parts);
    }
}
