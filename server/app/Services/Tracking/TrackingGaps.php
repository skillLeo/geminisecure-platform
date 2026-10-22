<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Models\Shift;
use App\Services\Push\PushMessage;
use App\Services\Push\PushNotifier;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHEN AN OFFICER'S POSITION WAS NOT RECORDED (M22 §1.1).
 *
 * Found on the emulator: Android started the Guard App in the background, the on-duty task stopped receiving its
 * positions for the rest of that run, and the notification went on saying "location shared". The phone could not
 * know; nothing else did either. So the record itself says it: on a shift whose officer agreed to share, a stretch
 * longer than `tracking.gap_after_seconds` with no position TAKEN — between two fixes, from the clock-in to the first,
 * or from the last to the clock-out (or to now) — is a gap. The shift's record lists them, the dispatcher's alertness
 * board shows one still open, and `tracking:watch` tells the officer's phone once.
 *
 * CAPTURE TIME, NOT ARRIVAL. A phone out of signal keeps sampling and sends its queue later, each fix with the time it
 * was taken: those positions were recorded, and once they arrive there is no gap. What is left is a period when the
 * phone took nothing — switched off, killed, signed out, or the fault above.
 *
 * NOT A GAP: an officer who has not agreed to share (the record says "not shared", which is an HR matter, not a
 * fault), and the feature switched off.
 */
final class TrackingGaps
{
    public static function threshold(): int
    {
        return max(60, (int) config('tracking.gap_after_seconds', 600));
    }

    /**
     * Every gap on one shift, earliest first. `to` is null for the one still open on a shift under way.
     *
     * @return array{shared: bool, count: int, last_at: Carbon|null, gaps: list<array{from: Carbon, to: Carbon|null, seconds: int}>}
     */
    public function onShift(Shift $shift, ?Carbon $now = null): array
    {
        return $this->onShifts([$shift], $now)[(int) $shift->id];
    }

    /**
     * `onShift` for many shifts in three reads, whatever their number — the consents, each shift's count and first and
     * last capture, and the database pairing each fix with the one before it on its own shift.
     *
     * @param  iterable<Shift>  $shifts
     * @return array<int, array{shared: bool, count: int, last_at: Carbon|null, gaps: list<array{from: Carbon, to: Carbon|null, seconds: int}>}>
     */
    public function onShifts(iterable $shifts, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy()->utc();
        $shifts = collect($shifts)->values();
        $limit = self::threshold();

        $consents = GuardTracking::enabled()
            ? app(GuardTracking::class)->consentsOf($shifts->pluck('guard_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all())
            : [];
        $started = $shifts->filter(static fn (Shift $s): bool => $s->actual_start !== null && ($consents[(int) $s->guard_id] ?? null) === GuardTracking::GRANTED);
        $ids = $started->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

        $stats = $ids === [] ? collect() : DB::connection('mysql')->table('guard_positions')->whereIn('shift_id', $ids)
            ->groupBy('shift_id')->selectRaw('shift_id, COUNT(*) as n, MIN(captured_at) as first_at, MAX(captured_at) as last_at')->get()->keyBy('shift_id');

        $between = $ids === [] ? [] : DB::connection('mysql')->select(
            'SELECT shift_id, prev_at, captured_at FROM (
                SELECT shift_id, captured_at, LAG(captured_at) OVER (PARTITION BY shift_id ORDER BY captured_at, id) AS prev_at
                FROM guard_positions WHERE shift_id IN ('.implode(',', array_fill(0, count($ids), '?')).')
            ) paired WHERE prev_at IS NOT NULL AND TIMESTAMPDIFF(SECOND, prev_at, captured_at) > ?
            ORDER BY shift_id, captured_at',
            [...$ids, $limit],
        );
        $pairs = collect($between)->groupBy('shift_id');

        $out = [];

        foreach ($shifts as $shift) {
            $shared = GuardTracking::enabled() && ($consents[(int) $shift->guard_id] ?? null) === GuardTracking::GRANTED;

            if (! $shared || $shift->actual_start === null) {
                $out[(int) $shift->id] = ['shared' => $shared, 'count' => 0, 'last_at' => null, 'gaps' => []];

                continue;
            }

            $start = $shift->actual_start->copy()->utc();
            $end = $shift->actual_end?->copy()->utc() ?? $now;
            $open = $shift->actual_end === null;
            $stat = $stats->get($shift->id);
            $count = (int) ($stat->n ?? 0);

            $gaps = [];
            $add = static function (Carbon $from, Carbon $to, bool $isOpen) use (&$gaps, $limit): void {
                $seconds = (int) $from->diffInSeconds($to, false);
                if ($seconds > $limit) {
                    $gaps[] = ['from' => $from, 'to' => $isOpen ? null : $to, 'seconds' => $seconds];
                }
            };

            if ($count === 0) {
                $add($start, $end, $open);
                $out[(int) $shift->id] = ['shared' => true, 'count' => 0, 'last_at' => null, 'gaps' => $gaps];

                continue;
            }

            $first = Carbon::parse((string) $stat->first_at, 'UTC');
            $last = Carbon::parse((string) $stat->last_at, 'UTC');

            $add($start, $first, false);
            foreach ($pairs->get($shift->id, collect()) as $row) {
                $add(Carbon::parse((string) $row->prev_at, 'UTC'), Carbon::parse((string) $row->captured_at, 'UTC'), false);
            }
            $add($last, $end, $open);

            $out[(int) $shift->id] = ['shared' => true, 'count' => $count, 'last_at' => $last, 'gaps' => $gaps];
        }

        return $out;
    }

    /**
     * THE WATCH (`tracking:watch`, every minute). Every officer on duty who agreed to share and whose phone has taken
     * no position for `tracking.gap_after_seconds` is told on that phone, ONCE per gap — the row in
     * `tracking_gap_notices` is taken before the push, so two runs at once tell nobody twice. The dispatcher's alertness
     * board shows the same gap from the same rule; the shift's record keeps it.
     *
     * @return array{watched: int, open: int, told: int}
     */
    public function watch(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy()->utc();

        if (! GuardTracking::enabled()) {
            return ['watched' => 0, 'open' => 0, 'told' => 0];
        }

        $shifts = Shift::query()->whereNotNull('actual_start')->whereNull('actual_end')->where('actual_start', '<=', $now)
            ->get(['id', 'tenant_id', 'guard_id', 'actual_start', 'actual_end']);
        $tracking = app(GuardTracking::class);
        $consents = $tracking->consentsOf($shifts->pluck('guard_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all());
        $shifts = $shifts->filter(static fn (Shift $s): bool => ($consents[(int) $s->guard_id] ?? null) === GuardTracking::GRANTED)->values();
        $newest = $tracking->newestTakenOn($shifts->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        $open = 0;
        $told = 0;

        foreach ($shifts as $shift) {
            $since = self::openSince($shift, $newest[(int) $shift->id] ?? null, $now);

            if ($since === null) {
                continue;
            }

            $open++;
            $taken = DB::connection('mysql')->table('tracking_gap_notices')->insertOrIgnore([
                'shift_id' => $shift->id, 'guard_id' => $shift->guard_id, 'tenant_id' => (string) $shift->tenant_id,
                'gap_since' => $since, 'noticed_at' => $now,
            ]);

            if ($taken === 0) {
                continue;
            }

            $at = SiteTime::fmt(SiteTime::local($since, (string) $shift->tenant_id), 'g:i A');
            $handsets = app(PushNotifier::class)->toGuard((string) $shift->tenant_id, (int) $shift->guard_id, new PushMessage(
                'tracking_gap',
                'Your position is not reaching the control room',
                'Nothing has been recorded since '.$at.'. Open GeminiSecure Guard — sign in if it asks, and check location is on.',
                (int) $shift->id,
            ));

            DB::connection('mysql')->table('tracking_gap_notices')->where('shift_id', $shift->id)->where('gap_since', $since)
                ->update(['handsets' => $handsets]);
            $told++;
        }

        return ['watched' => $shifts->count(), 'open' => $open, 'told' => $told];
    }

    /**
     * The gap still open on a shift under way, from what the live map already reads (its newest fix), or null. One
     * comparison, no read of its own: the alertness board asks for every officer on duty every few seconds.
     */
    public static function openSince(Shift $shift, ?string $newestCapturedAt, ?Carbon $now = null): ?Carbon
    {
        if ($shift->actual_start === null || $shift->actual_end !== null) {
            return null;
        }

        $now = ($now ?? Carbon::now())->copy()->utc();
        $since = $newestCapturedAt === null ? $shift->actual_start->copy()->utc() : Carbon::parse($newestCapturedAt, 'UTC');

        return $since->diffInSeconds($now, false) > self::threshold() ? $since : null;
    }

    /**
     * "No position recorded 3:14 PM – 4:25 PM JA (1 h 11 min)", or, still open, "No position received since 3:14 PM JA
     * (71 min)" — on the estate's clock, named.
     *
     * @param  array{from: Carbon, to: Carbon|null, seconds: int}  $gap
     */
    public static function line(array $gap, string $tenantId): string
    {
        $from = SiteTime::local($gap['from'], $tenantId);

        if ($gap['to'] === null) {
            return sprintf('No position received since %s (%s)', SiteTime::fmt($from, 'g:i A'), self::length($gap['seconds']));
        }

        $to = SiteTime::local($gap['to'], $tenantId);
        $sameDay = $from->isSameDay($to);

        return sprintf(
            'No position recorded %s – %s (%s)',
            $from->format($sameDay ? 'g:i A' : 'D j M, g:i A'),
            SiteTime::fmt($to, $sameDay ? 'g:i A' : 'D j M, g:i A'),
            self::length($gap['seconds']),
        );
    }

    /** "71 min" · "1 h 11 min" · "12 h". */
    public static function length(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);

        if ($minutes < 90) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours.' h' : $hours.' h '.$rest.' min';
    }
}
