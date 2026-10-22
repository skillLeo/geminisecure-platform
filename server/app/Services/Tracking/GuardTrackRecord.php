<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Models\Guard;
use App\Models\Shift;
use App\Models\Tenant;
use App\Support\SiteTime;
use App\Support\TrackingPolicyWords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHAT IS HELD ABOUT ONE OFFICER'S WHEREABOUTS, AND WHAT THEY AGREED TO (Hassam, 12 October 2026: "nobody is tracked
 * without being able to see what is held about them").
 *
 * Read only, for the Gemini console's two screens from a guard's page:
 *   consentRecord()  every answer the officer gave the location policy, newest first: the words they answered, the
 *                    button they pressed, and when; and which answer stands for the policy in force now.
 *   shiftsHeld(), shiftRecord()  the shifts on which positions are kept for them, and one shift's readings oldest first,
 *                    in the fields the officer's own record has on the Guard App (GUARD_TRACKING.md §5.2, Security's
 *                    `GuardTracking::ownRecord()`: lat, lng, accuracy_metres, source, captured_at), for any shift still
 *                    within the retention, where the app shows today's.
 *
 * It writes nothing: `GuardTracking::record()` is the one writer of positions, `decide()` of answers.
 */
final class GuardTrackRecord
{
    /** Why each reading was taken, in the words the console shows (the Guard App's `source`). */
    public const SOURCE_WORDS = [
        'interval' => 'Regular reading',
        'movement' => 'Moved',
        'clock_in' => 'Clock-in',
        'checkpoint' => 'Checkpoint scan',
        'gate' => 'Gate scan',
        'duress' => 'Duress alert',
        'clock_out' => 'Clock-out',
    ];

    /** A reading that arrived later than this after it was taken says so: it waited on the phone, out of signal. */
    private const ARRIVED_LATE_SECONDS = 120;

    /**
     * @return array{enabled: bool, current_version: string, current_label: string, retention_days: int, standing: array{state: string, line: string}, answers: list<array<string, mixed>>, words: array<string, array<string, mixed>>}
     */
    public function consentRecord(Guard $guard): array
    {
        $tenant = (string) $guard->tenant_id;
        $current = GuardTracking::policyVersion();
        $retention = (int) config('tracking.retention_days', 90);

        $rows = DB::connection('mysql')->table('guard_tracking_consents')
            ->where('guard_id', $guard->id)
            ->orderByDesc('id')
            ->get(['id', 'policy_version', 'decision', 'decided_at', 'device_time']);

        // The answer that stands is the newest to the words in force now, as `GuardTracking::consentOf()` reads it.
        $standing = $rows->first(static fn (object $row): bool => (string) $row->policy_version === $current);

        $answers = [];
        $later = [];

        foreach ($rows as $row) {
            $version = (string) $row->policy_version;
            $agreed = (string) $row->decision === GuardTracking::GRANTED;
            $words = TrackingPolicyWords::VERSIONS[$version] ?? null;

            $answers[] = [
                'id' => (int) $row->id,
                'when' => $this->moment((string) $row->decided_at, $tenant),
                'phone_time' => $row->device_time === null ? null : $this->moment((string) $row->device_time, $tenant),
                'version' => $version,
                'version_label' => self::versionLabel($version),
                'answer' => $agreed ? 'Agreed' : 'Refused',
                'agreed' => $agreed,
                // The button they pressed, in that version's own words; null where the server never held them.
                'pressed' => $words === null ? null : ($agreed ? $words['agree'] : $words['refuse']),
                'stands' => $standing !== null && (int) $row->id === (int) $standing->id,
                'note' => match (true) {
                    $standing !== null && (int) $row->id === (int) $standing->id => 'The answer in force now.',
                    $version !== $current => 'Asked again when the words changed.',
                    isset($later[$version]) => 'Changed by the later answer above.',
                    default => null,
                },
            ];

            $later[$version] = true;
        }

        // The words of every version answered, and of the one in force, as the officer read them.
        $versions = array_values(array_unique([$current, ...array_map(static fn (array $a): string => $a['version'], $answers)]));
        $words = [];
        foreach ($versions as $version) {
            $held = TrackingPolicyWords::of($version, $retention);
            if ($held !== null) {
                $words[$version] = ['label' => self::versionLabel($version), ...$held];
            }
        }

        return [
            'enabled' => GuardTracking::enabled(),
            'current_version' => $current,
            'current_label' => self::versionLabel($current),
            'retention_days' => $retention,
            'standing' => $this->standing($guard, $standing, $answers, $current),
            'answers' => $answers,
            'words' => $words,
        ];
    }

    /**
     * The shifts on which positions are held for this officer, newest first: only those at an estate `$mayReach` lets
     * the viewer see, so a site-scoped role learns nothing of another estate's shifts.
     *
     * @param  callable(string): bool  $mayReach
     * @return list<array{id: int, day: string, window: string, post: string, estate: string, count: int}>
     */
    public function shiftsHeld(Guard $guard, callable $mayReach): array
    {
        $counts = DB::connection('mysql')->table('guard_positions')
            ->where('guard_id', $guard->id)
            ->groupBy('shift_id')
            ->selectRaw('shift_id, COUNT(*) as n')
            ->pluck('n', 'shift_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $shifts = Shift::query()->with('post')->whereIn('id', $counts->keys()->all())
            ->orderByDesc('actual_start')->orderByDesc('id')->get()
            ->filter(static fn (Shift $shift): bool => $mayReach((string) $shift->tenant_id))
            ->values();

        $estates = Tenant::query()->whereIn('id', $shifts->pluck('tenant_id')->unique()->all())->pluck('name', 'id');

        return $shifts->map(static fn (Shift $shift): array => [
            'id' => (int) $shift->id,
            'day' => self::day($shift),
            'window' => self::window($shift),
            'post' => $shift->post?->label() ?? 'Post',
            'estate' => (string) ($estates[$shift->tenant_id] ?? 'Estate'),
            'count' => (int) $counts[$shift->id],
        ])->all();
    }

    /**
     * One shift's record as the officer's own (GUARD_TRACKING.md §5.2): when it began and ended, how long positions are
     * kept, and every reading held for it, oldest first, in §5.2's fields; and the words the console shows beside them.
     *
     * @return array{shift: array{id: int, day: string, window: string, post: string, clocked_in_at: string|null, clocked_out_at: string|null}, retention_days: int, positions: list<array<string, mixed>>}
     */
    public function shiftRecord(Shift $shift): array
    {
        $tenant = (string) $shift->tenant_id;

        $rows = DB::connection('mysql')->table('guard_positions')
            ->where('shift_id', $shift->id)
            ->where('guard_id', $shift->guard_id)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get(['id', 'latitude', 'longitude', 'accuracy_m', 'source', 'battery_pct', 'captured_at', 'received_at', 'is_simulated']);

        $positions = [];
        foreach ($rows as $row) {
            $taken = Carbon::parse((string) $row->captured_at, 'UTC');
            $arrived = Carbon::parse((string) $row->received_at, 'UTC');
            $late = (int) $taken->diffInSeconds($arrived, false);
            $source = (string) $row->source;

            $positions[] = [
                'id' => (int) $row->id,
                'captured_at' => SiteTime::iso($taken),
                'taken' => SiteTime::stamp($taken, $tenant, 'g:i:s A'),
                'lat' => (float) $row->latitude,
                'lng' => (float) $row->longitude,
                'accuracy_metres' => $row->accuracy_m === null ? null : (int) $row->accuracy_m,
                'source' => $source,
                'why' => self::SOURCE_WORDS[$source] ?? 'Reading',
                'battery_percent' => $row->battery_pct === null ? null : (int) $row->battery_pct,
                // Taken out of signal and sent when it came back: the reading was recorded on time, and arrived late.
                'arrived' => $late > self::ARRIVED_LATE_SECONDS ? 'Arrived '.self::minutes($late).' later' : null,
                'simulated' => (bool) $row->is_simulated,
            ];
        }

        return [
            'shift' => [
                'id' => (int) $shift->id,
                'day' => self::day($shift),
                'window' => self::window($shift),
                'post' => $shift->post?->label() ?? 'Post',
                'clocked_in_at' => SiteTime::iso($shift->actual_start),
                'clocked_out_at' => SiteTime::iso($shift->actual_end),
            ],
            'retention_days' => (int) config('tracking.retention_days', 90),
            'positions' => $positions,
        ];
    }

    /**
     * Where the officer stands with the words in force, in one line.
     *
     * @param  list<array<string, mixed>>  $answers
     * @return array{state: string, line: string}
     */
    private function standing(Guard $guard, ?object $standing, array $answers, string $current): array
    {
        $name = (string) $guard->full_name;
        $label = self::versionLabel($current);

        if (! GuardTracking::enabled()) {
            return ['state' => 'off', 'line' => 'Location sharing is switched off for the platform, so nothing is recorded for anybody.'];
        }

        if ($standing === null) {
            return ['state' => 'unanswered', 'line' => $answers === []
                ? $name.' has not answered the location policy yet. Nothing is recorded until they do.'
                : $name.' has not answered the words of '.$label.'. Nothing is recorded until they do.'];
        }

        $at = $this->moment((string) $standing->decided_at, (string) $guard->tenant_id);

        return (string) $standing->decision === GuardTracking::GRANTED
            ? ['state' => 'agreed', 'line' => $name.' agreed to the words of '.$label.' on '.$at.'. Positions are recorded from clock-in to clock-out.']
            : ['state' => 'refused', 'line' => $name.' refused the words of '.$label.' on '.$at.'. Nothing is recorded, and the control room sees "position not shared".'];
    }

    /** A stored UTC instant on the estate's clock, in Law 5's form (DR-039): "12 Oct 2026, 2:04 AM". */
    private function moment(string $stored, string $tenant): string
    {
        return SiteTime::stamp(Carbon::parse($stored, 'UTC'), $tenant === '' ? null : $tenant, 'j M Y, g:i A');
    }

    /** "2026-10-10" read as the day the words date from: "10 Oct 2026". */
    private static function versionLabel(string $version): string
    {
        try {
            return Carbon::parse($version)->format('j M Y');
        } catch (\Throwable) {
            return $version;
        }
    }

    private static function day(Shift $shift): string
    {
        return ($shift->actual_start ?? $shift->rostered_start)->format('D j M Y');
    }

    /** "8:00 AM – 8:00 PM", on the shift's own clock, or "on duty now" for one still open. */
    private static function window(Shift $shift): string
    {
        $start = $shift->actual_start ?? $shift->rostered_start;
        $until = $shift->actual_end === null ? 'on duty now' : $shift->actual_end->format('g:i A').SiteTime::label($shift->actual_end);

        return $start->format('g:i A').' – '.$until;
    }

    private static function minutes(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h '.($minutes % 60).' min';
    }
}
