<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Live\Events\GuardSignalLive;
use App\Live\LiveBus;
use App\Models\Guard;
use App\Models\Shift;
use App\Models\Tenant;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "NO SIGNAL FROM <NAME> AT <SITE>, LAST SEEN HH:MM, BATTERY 14%" (Hassam, 12 Oct 2026; GUARD_TRACKING.md 5.6; the
 * battery D-242). A guard whose phone has gone quiet at 2 a.m. is an operational problem, not a map detail, so it is an
 * alert in the dispatcher's queue.
 *
 * RAISED by `tracking:signal`, every minute: a guard clocked in (and not out, on a shift begun within the last day),
 * who agreed to share their position, from whose phone nothing has ARRIVED for longer than their estate's tolerance
 * (GuardFreshness::tolerances, five minutes unless the estate set another), counted from the newest arrival,
 * or from the clock-in when nothing has come at all. One alert per stretch of silence.
 *
 * CLEARED, once, by setting `cleared_at`, the moment nothing is missing any more (`cleared_reason`):
 *   position_arrived  a position arrived (`arrived()`, called by the one writer both of a phone's addresses use)
 *   shift_ended       the shift ended (`ended()`, called by `ShiftClock::clockOut`, the one place a shift ends, so it
 *                     goes with the clock-out and not at the next minute; the watch clears any it finds, a shift gone
 *                     included)
 *   not_shared        the guard no longer shares: they refused the wording, or it changed and they have not answered
 *   tracking_off      location sharing was switched off for the service
 * A guard who refused the wording, or whose tracking is off, is never alerted on: nothing is expected from their phone,
 * and the map names them "Position not shared". An alert raised while they shared clears when that stops (Payroll,
 * 12 Oct 2026, extending it): it stood until the shift ended. Each raise and each clear is written with its live event
 * in one transaction (DESIGN.md §1.3); the watch wrote them outside one, which production reports as an error.
 */
final class GuardSignal
{
    /**
     * Raise what has gone quiet and clear what has ended. Returns how many were raised and cleared.
     *
     * @return array{raised: int, cleared: int}
     */
    public function watch(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $cleared = 0;
        $raised = 0;

        // Shifts that have ended take their alert with them.
        $open = DB::connection('mysql')->table('guard_signal_alerts')->whereNull('cleared_at')->get(['id', 'shift_id', 'guard_id', 'tenant_id', 'raised_at']);
        $endedOrGone = $open->isEmpty() ? collect() : collect($open->pluck('shift_id')->all())
            ->diff(Shift::query()->whereIn('id', $open->pluck('shift_id')->all())->whereNotNull('actual_start')->whereNull('actual_end')->pluck('id')->all());

        foreach ($open->whereIn('shift_id', $endedOrGone->all()) as $alert) {
            $cleared += $this->clear((int) $alert->shift_id, (int) $alert->guard_id, (string) $alert->tenant_id, 'shift_ended', $now);
        }

        if (! GuardTracking::enabled()) {
            // Sharing is switched off for the service: no phone is asked for a position, so no silence is missing one.
            foreach ($open->whereNotIn('shift_id', $endedOrGone->all()) as $alert) {
                $cleared += $this->clear((int) $alert->shift_id, (int) $alert->guard_id, (string) $alert->tenant_id, 'tracking_off', $now);
            }

            return ['raised' => 0, 'cleared' => $cleared];
        }

        $shifts = Shift::query()->with('officer')
            ->whereNotNull('actual_start')->whereNull('actual_end')
            ->where('actual_start', '<=', $now)
            ->where('actual_start', '>=', $now->copy()->subHours(24))
            ->get();

        if ($shifts->isEmpty()) {
            return ['raised' => 0, 'cleared' => $cleared];
        }

        $consents = app(GuardTracking::class)->consentsOf($shifts->pluck('guard_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all());
        $readings = GuardTracking::newestReadings($shifts->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $tolerances = GuardFreshness::tolerances($shifts->pluck('tenant_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all());
        $stillOpen = DB::connection('mysql')->table('guard_signal_alerts')->whereNull('cleared_at')->pluck('raised_at', 'shift_id');

        foreach ($shifts as $shift) {
            if (! $shift->officer instanceof Guard || ($consents[(int) $shift->guard_id] ?? null) !== GuardTracking::GRANTED) {
                // Not sharing: nothing is expected from the phone, so an alert raised while they shared has nothing to wait for.
                if ($stillOpen->has($shift->id)) {
                    $cleared += $this->clear((int) $shift->id, (int) $shift->guard_id, (string) $shift->tenant_id, 'not_shared', $now);
                }

                continue;
            }

            $reading = $readings[(int) $shift->id] ?? null;
            $arrived = $reading === null ? null : Carbon::parse((string) $reading->arrived_at, 'UTC');
            $quietSince = $arrived ?? Carbon::instance($shift->actual_start);
            $tolerance = $tolerances[(string) $shift->tenant_id] ?? GuardFreshness::DEFAULT_TOLERANCE_SECONDS;

            if ($stillOpen->has($shift->id)) {
                // Something arrived after it was raised and nothing cleared it (a write that failed): clear it now.
                if ($arrived !== null && $arrived->greaterThan(Carbon::parse((string) $stillOpen->get($shift->id), 'UTC'))) {
                    $cleared += $this->clear((int) $shift->id, (int) $shift->guard_id, (string) $shift->tenant_id, 'position_arrived', $now);
                }

                continue;
            }

            if ($quietSince->diffInSeconds($now, false) <= $tolerance) {
                continue;
            }

            $lastSeen = $reading === null ? null : GuardFreshness::readingAt(Carbon::parse((string) $reading->captured_at, 'UTC'), Carbon::parse((string) $reading->received_at, 'UTC'));

            $raised += DB::connection('mysql')->transaction(static function () use ($shift, $lastSeen, $now): int {
                $inserted = DB::connection('mysql')->table('guard_signal_alerts')->insertOrIgnore([
                    'tenant_id' => (string) $shift->tenant_id,
                    'shift_id' => (int) $shift->id,
                    'guard_id' => (int) $shift->guard_id,
                    'last_seen_at' => $lastSeen,
                    'raised_at' => $now,
                ]);

                if ($inserted > 0) {
                    LiveBus::record(GuardSignalLive::changed((int) $shift->guard_id, (string) $shift->tenant_id));
                }

                return $inserted;
            });
        }

        return ['raised' => $raised, 'cleared' => $cleared];
    }

    /** A position arrived on this shift: its open No signal alert, if any, clears itself. */
    public function arrived(int $shiftId, ?Carbon $now = null): void
    {
        $this->clearShift($shiftId, 'position_arrived', $now ?? Carbon::now());
    }

    /**
     * The shift ended (`ShiftClock::clockOut`): its open No signal alert, if any, goes with it, so the queue never says a
     * guard who has gone home is silent.
     */
    public function ended(int $shiftId, ?Carbon $now = null): void
    {
        $this->clearShift($shiftId, 'shift_ended', $now ?? Carbon::now());
    }

    private function clearShift(int $shiftId, string $reason, Carbon $now): void
    {
        $open = DB::connection('mysql')->table('guard_signal_alerts')->where('shift_id', $shiftId)->whereNull('cleared_at')->first(['guard_id', 'tenant_id']);

        if ($open !== null) {
            $this->clear($shiftId, (int) $open->guard_id, (string) $open->tenant_id, $reason, $now);
        }
    }

    /** Clear the shift's open alert and say so on the dispatch feed, in one transaction (DESIGN.md §1.3). */
    private function clear(int $shiftId, int $guardId, string $tenantId, string $reason, Carbon $now): int
    {
        return DB::connection('mysql')->transaction(static function () use ($shiftId, $guardId, $tenantId, $reason, $now): int {
            $done = DB::connection('mysql')->table('guard_signal_alerts')->where('shift_id', $shiftId)->whereNull('cleared_at')
                ->update(['cleared_at' => $now, 'cleared_reason' => $reason]);

            if ($done > 0) {
                LiveBus::record(GuardSignalLive::changed($guardId, $tenantId));
            }

            return $done;
        });
    }

    /**
     * The open No signal alerts for the dispatcher's queue, the longest silence first, each with its sentence: "No signal
     * from Marlon Grant at Phoenix Park Village 1, last seen 2:08 AM JA, battery 14%" (D-242). The battery is the
     * employer's, for the dispatcher deciding between calling the guard and sending someone: an estate's screen never
     * shows a guard's battery (Hassam's ruling on consent), so nothing built for an estate reads this.
     *
     * @param  list<string>|null  $tenantIds  null: every estate
     * @return list<array{id: int, shift_id: int, guard_id: int, tenant_id: string, sentence: string, raised_at: string|null, last_seen_at: string|null, href: string}>
     */
    public function open(?array $tenantIds = null): array
    {
        $rows = DB::connection('mysql')->table('guard_signal_alerts as a')
            ->join('guards as g', 'g.id', '=', 'a.guard_id')
            ->join('shifts as s', 's.id', '=', 'a.shift_id')
            ->whereNull('a.cleared_at')
            ->when($tenantIds !== null, static fn ($q) => $q->whereIn('a.tenant_id', $tenantIds))
            ->orderBy('a.raised_at')
            ->get(['a.id', 'a.shift_id', 'a.guard_id', 'a.tenant_id', 'a.last_seen_at', 'a.raised_at', 'g.full_name', 's.actual_start']);

        if ($rows->isEmpty()) {
            return [];
        }

        $names = [];

        foreach (Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->unique()->all())->get() as $estate) {
            if ($estate instanceof Tenant) {
                $names[(string) $estate->getTenantKey()] = (string) $estate->name;
            }
        }

        $readings = GuardTracking::newestReadings($rows->pluck('shift_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all());

        return $rows->map(static function (object $row) use ($names, $readings): array {
            $tenant = (string) $row->tenant_id;
            $site = $names[$tenant] ?? $tenant;
            $lastSeen = $row->last_seen_at === null ? null : Carbon::parse((string) $row->last_seen_at, 'UTC');
            $since = $lastSeen === null
                ? 'nothing since clock-in at '.SiteTime::tagged(Carbon::parse((string) $row->actual_start, 'UTC'), $tenant)
                : 'last seen '.SiteTime::tagged($lastSeen, $tenant);
            // The battery at the reading "last seen" names; nothing when the phone sent none or nothing has arrived yet.
            $battery = $lastSeen === null ? null : ($readings[(int) $row->shift_id]->battery_pct ?? null);

            return [
                'id' => (int) $row->id,
                'shift_id' => (int) $row->shift_id,
                'guard_id' => (int) $row->guard_id,
                'tenant_id' => $tenant,
                'sentence' => 'No signal from '.$row->full_name.' at '.$site.', '.$since.($battery === null ? '' : ', battery '.(int) $battery.'%'),
                'raised_at' => SiteTime::iso(Carbon::parse((string) $row->raised_at, 'UTC')),
                'last_seen_at' => SiteTime::iso($lastSeen),
                'href' => '/guards/'.$row->guard_id,
            ];
        })->values()->all();
    }
}
