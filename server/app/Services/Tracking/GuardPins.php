<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Models\DuressAlert;
use App\Models\Shift;
use App\Models\Tenant;
use App\Support\Geo;
use App\Support\SiteTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE OFFICERS ON DUTY AS PINS ON A MAP (M11 §3.4; D-174), for the Gemini dispatch map and, only where the estate may
 * see guard positions (TRACK-01), for that estate's own Security map. The estate never receives this array as it is:
 * `EstateSecurityMap` copies the fields an estate may see, one by one.
 *
 * ONE ENTRY PER OFFICER ON A SHIFT UNDER WAY (`officers`), whether or not they have a position, each with:
 *   who and where they stand   name, post, estate, when they clocked in
 *   what they are doing        `state`: responding to an alert (an open one they were sent to or took), on break (a
 *                              break not yet ended), on patrol (a patrol shift), else at post; `off_post` from their
 *                              position against the post's fence, null when either is unknown
 *   the newest reading         where, how accurate, battery, moving or not, where it came from (`source`), any clock flag
 *   how fresh it is            `GuardFreshness`, one rule for both consoles: reading_at, arrived_at, the estate's
 *                              tolerance, and the state as of this read; the pages keep it current on their own clock
 * `guards` (the placed ones) and `unshared` (the rest) are the same entries, kept for the dispatch map as it reads them.
 *
 * Four reads for any number of officers: the consents, the newest reading per shift, the open breaks and the alerts
 * they are answering, plus one for the estates' tolerances.
 */
final class GuardPins
{
    /** What the newest reading's `source` says, in words: where the reading came from. */
    private const SOURCE_WORDS = [
        'foreground' => 'App open',
        'background' => 'App in the background',
        'significant_change' => 'A significant move',
        'geofence' => 'A geofence crossing',
        // The first address's words (POST shifts/{shift}/positions): why the reading was taken.
        'interval' => 'Background service',
        'movement' => 'Background service',
        'clock_in' => 'Taken at clock-in',
        'checkpoint' => 'Taken at a checkpoint scan',
        'gate' => 'Taken at a gate scan',
        'duress' => 'Taken at a duress alert',
        'clock_out' => 'Taken at clock-out',
    ];

    private const STATE_WORDS = [
        'responding' => 'Responding to an alert',
        'on_break' => 'On break',
        'on_patrol' => 'On patrol',
        'at_post' => 'At post',
    ];

    /**
     * @param  Collection<int, Shift>  $shifts  under way, with `officer` and `post` loaded
     * @param  array<string, string>  $estateNames  tenant id => name
     * @param  bool  $positions  false: no consent and no reading is read at all (an estate that may not see positions)
     * @param  array<string, int>|null  $tolerances  tenant id => seconds, when the caller has read them already
     * @param  array<int, int>|null  $responding  guard id => the open alert they answer, when the caller has read the alerts
     * @return array{officers: list<array<string, mixed>>, guards: list<array<string, mixed>>, unshared: list<array<string, mixed>>, tracking: bool, stale_after_s: int, live_within_s: int}
     */
    public static function for(Collection $shifts, array $estateNames = [], ?Carbon $now = null, bool $positions = true, ?array $tolerances = null, ?array $responding = null): array
    {
        $now ??= Carbon::now();
        $tracking = app(GuardTracking::class);
        $enabled = $positions && GuardTracking::enabled();

        $shiftIds = $shifts->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
        $guardIds = $shifts->pluck('guard_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $tenantIds = $shifts->pluck('tenant_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all();

        $consents = $enabled ? $tracking->consentsOf($guardIds) : [];
        $readings = $enabled ? GuardTracking::newestReadings($shiftIds) : [];
        $tolerance = $tolerances ?? GuardFreshness::tolerances($tenantIds);
        $onBreak = self::onBreak($shifts, $shiftIds);
        $responding ??= self::responding($guardIds, $tenantIds);

        $officers = [];
        $placed = [];
        $unplaced = [];

        foreach ($shifts as $shift) {
            $guardId = (int) $shift->guard_id;
            $tenantId = (string) $shift->tenant_id;
            $name = (string) ($shift->officer->full_name ?? 'Unassigned');
            $post = $shift->post === null ? 'No post' : $shift->postLabel();
            $where = implode(' · ', array_filter([$shift->post?->label(), $estateNames[$tenantId] ?? null]));
            $shared = $enabled && ($consents[$guardId] ?? null) === GuardTracking::GRANTED;
            $reading = $shared ? ($readings[(int) $shift->id] ?? null) : null;
            // The stored UTC moment, not the cast (SiteDateTime), which reads every estate's clock to place it.
            $clockedIn = Carbon::parse((string) $shift->getRawOriginal('actual_start'), 'UTC');
            $toleranceS = $tolerance[$tenantId] ?? GuardFreshness::DEFAULT_TOLERANCE_SECONDS;

            $readingAt = $reading === null ? null : GuardFreshness::readingAt(Carbon::parse((string) $reading->captured_at, 'UTC'), Carbon::parse((string) $reading->received_at, 'UTC'));
            $arrivedAt = $reading === null ? null : Carbon::parse((string) $reading->arrived_at, 'UTC');
            $freshness = GuardFreshness::judge($shared, $readingAt, $arrivedAt, $clockedIn, $toleranceS, $now);
            // The estate's clock, read off the estate already loaded with the shift where it is (no read of its own).
            $zone = $shift->relationLoaded('estate') && $shift->estate instanceof Tenant ? SiteTime::zoneOf($shift->estate) : SiteTime::zone($tenantId);
            $timeWords = static fn (Carbon $at): string => SiteTime::fmt($at->copy()->setTimezone($zone), 'g:i A');

            $alertId = $responding[$guardId] ?? null;
            $state = match (true) {
                $alertId !== null => 'responding',
                isset($onBreak[(int) $shift->id]) => 'on_break',
                $shift->isPatrol() => 'on_patrol',
                default => 'at_post',
            };

            $officer = [
                // The dispatch map's own key, and the one both maps share: today's shift.
                'id' => 'guard-'.$guardId,
                'key' => 'shift-'.$shift->id,
                'shift_id' => (int) $shift->id,
                'guard_id' => $guardId,
                'tenant_id' => $tenantId,
                'name' => $name,
                'post' => $post,
                'estate' => $estateNames[$tenantId] ?? null,
                'title' => $where === '' ? $name : $name.' ('.$where.')',
                'detail' => $where,
                'clocked_in_at' => SiteTime::iso($clockedIn),
                'state' => $state,
                'state_label' => self::STATE_WORDS[$state],
                'responding_alert_id' => $alertId,
                'shared' => $shared,
                'reading_at' => SiteTime::iso($readingAt),
                'arrived_at' => SiteTime::iso($arrivedAt),
                'tolerance_s' => $toleranceS,
                'freshness' => $freshness,
                'freshness_label' => GuardFreshness::label($freshness, $readingAt, $clockedIn, $now, $timeWords),
                'stale' => $freshness !== GuardFreshness::LIVE,
                'href' => '/guards/'.$guardId,
            ];

            // What the phone holds unsent, where the shifts' read asked (withBreak) and the phone has said: the dispatcher's.
            $waiting = (int) ($shift->getAttribute('queue_waiting') ?? 0);
            $thinned = (int) ($shift->getAttribute('queue_thinned') ?? 0);

            if ($waiting > 0 || $thinned > 0) {
                $oldest = $shift->getAttribute('queue_oldest_at');
                $oldestAt = $oldest === null ? null : Carbon::parse((string) $oldest, 'UTC');

                $officer['queue'] = [
                    'waiting' => $waiting,
                    'oldest_at' => SiteTime::iso($oldestAt),
                    'thinned' => $thinned,
                    // Past either line, the phone's queue is an operational problem (GUARD_TRACKING.md section 9).
                    'warning' => $thinned > 0 || $waiting >= (int) config('tracking.queue_warn_after', 200)
                        || ($oldestAt !== null && $oldestAt->diffInSeconds($now, false) > (int) config('tracking.queue_warn_age_seconds', 1800)),
                ];
            }

            // The reading's own fields only when there is one: an officer with no position carries no position key at all.
            if ($reading !== null) {
                $lat = (float) $reading->latitude;
                $lng = (float) $reading->longitude;
                $accuracy = $reading->accuracy_m === null ? null : (int) $reading->accuracy_m;
                $source = (string) $reading->source;

                $officer += [
                    'lat' => $lat,
                    'lng' => $lng,
                    'accuracy_m' => $accuracy,
                    'off_post' => self::offPost($shift, $lat, $lng, $accuracy),
                    'captured_at' => SiteTime::iso(Carbon::parse((string) $reading->captured_at, 'UTC')),
                    'battery_pct' => $reading->battery_pct === null ? null : (int) $reading->battery_pct,
                    'is_moving' => $reading->is_moving === null ? null : (bool) $reading->is_moving,
                    'source' => $source,
                    'source_label' => self::SOURCE_WORDS[$source] ?? 'The phone',
                    'clock_flag' => $reading->clock_flag === null ? null : (string) $reading->clock_flag,
                ];
            }

            $officers[] = $officer;

            if ($reading !== null) {
                $placed[] = $officer;
            } else {
                // Named, never drawn at their post: "Position not shared", "Waiting for the first position", "No signal".
                $unplaced[] = [...$officer, 'why' => $officer['freshness_label']];
            }
        }

        return [
            'officers' => $officers,
            'guards' => $placed,
            'unshared' => $unplaced,
            'tracking' => GuardTracking::enabled(),
            'stale_after_s' => (int) config('tracking.stale_after_seconds', 300),
            'live_within_s' => GuardFreshness::LIVE_SECONDS,
        ];
    }

    /**
     * Ask the shifts' own read for what the pins say about each officer beyond their position, so the pins need no read
     * of their own for it: whether they are on a break now (`on_break`), and what their phone last said about the
     * readings it holds unsent (`queue_waiting`, `queue_oldest_at`, `queue_thinned`; guard_queue_reports).
     *
     * @param  Builder<Shift>  $query
     * @return Builder<Shift>
     */
    public static function withBreak(Builder $query): Builder
    {
        $report = static fn (string $column) => DB::connection('mysql')->table('guard_queue_reports')->select($column)
            ->whereColumn('guard_queue_reports.shift_id', 'shifts.id')->limit(1);

        return $query->addSelect([
            'on_break' => DB::connection('mysql')->table('shift_breaks')->selectRaw('1')
                ->whereColumn('shift_breaks.shift_id', 'shifts.id')->whereNull('shift_breaks.ended_at')->limit(1),
            'queue_waiting' => $report('waiting'),
            'queue_oldest_at' => $report('oldest_captured_at'),
            'queue_thinned' => $report('thinned_count'),
        ]);
    }

    /**
     * The shifts with a break begun and not ended: from the shifts' own `on_break` where their read asked for it
     * (`withBreak`), otherwise in one read.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  list<int>  $shiftIds
     * @return array<int, true>
     */
    private static function onBreak(Collection $shifts, array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        $open = [];

        if ($shifts->every(static fn (Shift $shift): bool => array_key_exists('on_break', $shift->getAttributes()))) {
            foreach ($shifts as $shift) {
                if ($shift->getAttribute('on_break') !== null) {
                    $open[(int) $shift->id] = true;
                }
            }

            return $open;
        }

        foreach (DB::connection('mysql')->table('shift_breaks')->whereIn('shift_id', $shiftIds)->whereNull('ended_at')->pluck('shift_id') as $id) {
            $open[(int) $id] = true;
        }

        return $open;
    }

    /**
     * The open alert each of these officers was sent to, or took from their own handset, at these estates.
     *
     * @param  list<int>  $guardIds
     * @param  list<string>  $tenantIds
     * @return array<int, int> guard id => alert id
     */
    private static function responding(array $guardIds, array $tenantIds): array
    {
        if ($guardIds === []) {
            return [];
        }

        return DuressAlert::query()->whereIn('responding_guard_id', $guardIds)->whereIn('tenant_id', $tenantIds)
            ->whereNotIn('status', ['resolved', 'false_alarm'])
            ->orderBy('id')->get(['id', 'responding_guard_id'])
            ->mapWithKeys(static fn (DuressAlert $a): array => [(int) $a->responding_guard_id => (int) $a->id])->all();
    }

    /** Outside their post's fence by more than the reading's own accuracy; null when the post has no place or they patrol. */
    private static function offPost(Shift $shift, float $lat, float $lng, ?int $accuracy): ?bool
    {
        $post = $shift->post;

        if ($post === null || $post->latitude === null || $post->longitude === null || $shift->isPatrol()) {
            return null;
        }

        return Geo::metresBetween((float) $post->latitude, (float) $post->longitude, $lat, $lng) > (int) ($post->geofence_radius_m ?? 10) + (int) ($accuracy ?? 0);
    }
}
