<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Live\Events\GuardPositionLive;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Shift;
use App\Services\Tracking\EstateBounds;
use App\Services\Tracking\GuardFreshness;
use App\Services\Tracking\GuardPins;
use App\Support\Geo;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE ESTATE'S SECURITY MAP, ONE FEED (Hassam, 12 Oct 2026: "the one the client cares most about"; GUARD_TRACKING.md
 * 5.4). Read by the estate's Security page and by `GET security/map`, for this estate only.
 *
 * Three things at once. A committee member who has never been trained opens it at 2 a.m.: is something wrong, where,
 * who raised it, and is anyone going.
 *
 *   alerts   every open panic or duress at this estate: the lot and the name, where the phone was when it was pressed
 *            and where it is now (the newest `duress_alert_positions` row), when, its state in the Emergency list's
 *            words, who has been sent and how far away they are, and the three guards nearest it by their STORED
 *            positions: a suggestion, never an assignment. The system sends nobody; the control room does.
 *   guards   ONLY the guards clocked in at this estate and not yet out, on a shift begun within the last day: never a
 *            guard off shift, at another site, or the roster.
 *
 * WHAT A GUARD'S ENTRY MAY CARRY, AND NOTHING ELSE. The estate is a different company from the security company and
 * sees a guard's working presence, not their employment: each field below is COPIED BY NAME from `GuardPins`, so a
 * field added there never reaches an estate by accident. Never a guard id, employee number, TRN, NIS, bank, pay,
 * licence, certification, discipline, performance, score, address, phone, another shift or another site.
 *   always               key (today's shift), name, post, state ("On duty" or "Responding")
 *   with positions only  position, accuracy, when it was taken and arrived, freshness, off post, distance to each open
 *                        alert
 * TWO STATES AND NO CLOCK-IN TIME (Hassam, 12 Oct): `asTheEstateSeesIt()`, once, before any entry is made.
 * Never the battery or what a reading was taken for: those are the control room's (why a phone slowed, a clock-in or
 * a checkpoint scan), and the guards' consent words (2026-10-12, agreed by Security, Governance and Residents) tell an
 * officer the estate's management sees "where you are" and nothing more.
 * "With positions" is the estate's contract switch under the platform's hold (`GuardPositionLive::estateSees`,
 * TRACK-01, the client's question in GUARD_TRACKING.md section 3): off, no position, consent or reading is even read.
 * A guard has no photo on record, so none is sent.
 *
 * An alert's `position_moved` is true once the raiser's phone has SENT a position after the press (every 10 to 15
 * seconds while the alert is open, even standing still): it is not a claim that the person moved. A screen that wants
 * to say "moved" measures the distance from `raised_lat`/`raised_lng` itself, past the reading's accuracy.
 */
final class EstateSecurityMap
{
    /** A shift clocked in longer ago than this is not today's: it is a forgotten clock-out, not a guard on duty. */
    public const SHIFT_WITHIN_HOURS = 24;

    /** How many guards are named as nearest an open alert. */
    public const NEAREST = 3;

    /** The two states an estate sees of an officer (Hassam, 12 Oct). */
    private const ON_DUTY = 'On duty';

    private const RESPONDING = 'Responding';

    private const NO_LOT = 'Lot not recorded';

    private const NO_NAME = 'Name not recorded';

    /**
     * @param  Collection<int, Shift>|null  $onDuty  the guards on duty here, when the page has read them already
     * @param  object|false|null  $site  the estate's `estate_sites` row (its `boundary`) when the page has read it, false
     *                                   when it read that there is none, null to read it here
     * @return array<string, mixed>
     */
    public function for(string $estateId, ?Carbon $now = null, ?Collection $onDuty = null, object|false|null $site = null): array
    {
        $now ??= Carbon::now();
        $positions = GuardPositionLive::estateSees($estateId);
        $zone = SiteTime::local($now, $estateId)->getTimezone()->getName();

        // The estate's site, once: its boundary (EstateBounds' rule). Its No signal tolerance: GuardFreshness::tolerances.
        $site ??= DB::connection('mysql')->table('estate_sites')->where('tenant_id', $estateId)->first(['boundary']);
        $site = $site === false ? null : $site;
        $points = $site?->boundary === null ? null : json_decode((string) $site->boundary, true);
        $boundary = is_array($points) && count($points) >= 3 ? array_values($points) : null;
        $tolerance = GuardFreshness::tolerances([$estateId])[$estateId];

        // Every open alert here, once: the panics are drawn, and any alert a guard was sent to says they are responding.
        $open = DuressAlert::query()->with('respondingGuard')->where('tenant_id', $estateId)
            ->whereNotIn('status', ['resolved', 'false_alarm'])->orderByDesc('server_time')->get();
        $responding = [];

        foreach ($open as $alert) {
            if ($alert->responding_guard_id !== null) {
                $responding[(int) $alert->responding_guard_id] ??= (int) $alert->id;
            }
        }

        $officers = array_map(self::asTheEstateSeesIt(...), GuardPins::for($onDuty ?? self::onDuty($estateId, $now), [], $now, $positions, [$estateId => $tolerance], $responding)['officers']);
        $alerts = $this->openAlerts($open->filter(static fn (DuressAlert $a): bool => $a->isPanic())->values(), $boundary);

        return [
            'generated_at' => SiteTime::iso($now),
            'zone' => $zone,
            'zone_tag' => SiteTime::clockTag($zone),
            'positions' => $positions,
            'tolerance_s' => $tolerance,
            'live_within_s' => GuardFreshness::LIVE_SECONDS,
            'alerts' => array_map(fn (array $a): array => [...$this->alertEntry($a, $officers, $positions), ...self::raisedAt($a['alert'], $boundary)], $alerts),
            'guards' => array_map(fn (array $o): array => $this->guardEntry($o, $positions, $alerts), $officers),
        ];
    }

    /**
     * WHERE IT WAS PRESSED, beside where the phone is now (GUARD_TRACKING.md 5.4, asked by Residents for the screen): the
     * position the alert was raised with, null when the phone sent none, and why it cannot be drawn if it lies outside
     * the estate's boundary (EstateBounds, the rule every position on this map follows).
     *
     * @param  list<array{0: float, 1: float}>|null  $boundary
     * @return array{raised_lat: float|null, raised_lng: float|null, raised_off_estate: string|null}
     */
    private static function raisedAt(DuressAlert $alert, ?array $boundary): array
    {
        $lat = $alert->latitude === null ? null : (float) $alert->latitude;
        $lng = $alert->longitude === null ? null : (float) $alert->longitude;

        return [
            'raised_lat' => $lat,
            'raised_lng' => $lng,
            'raised_off_estate' => $lat === null || $lng === null ? null : EstateBounds::notConfirmed($boundary, $lat, $lng),
        ];
    }

    /**
     * The guards on duty at this estate now: clocked in, not out, on a shift begun within the last day.
     *
     * @return Collection<int, Shift>
     */
    public static function onDuty(string $estateId, Carbon $now): Collection
    {
        return GuardPins::withBreak(Shift::query())
            ->with(['officer', 'post'])
            ->where('tenant_id', $estateId)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->where('actual_start', '<=', $now)
            ->where('actual_start', '>=', $now->copy()->subHours(self::SHIFT_WITHIN_HOURS))
            ->whereHas('officer', static fn ($query) => $query->where('status', 'active'))
            ->get()
            ->filter(static fn (Shift $shift): bool => $shift->officer instanceof Guard)
            ->sortBy(static fn (Shift $shift): string => (string) $shift->officer->full_name)
            ->values();
    }

    /**
     * The open panics and duress alerts at this estate, newest first, each at its newest known position.
     *
     * @param  Collection<int, DuressAlert>  $alerts  open, panics only
     * @param  list<array{0: float, 1: float}>|null  $boundary
     * @return list<array<string, mixed>>
     */
    private function openAlerts(Collection $alerts, ?array $boundary): array
    {
        if ($alerts->isEmpty()) {
            return [];
        }

        // Where each raiser's phone is now: the newest position it sent while the alert stayed open.
        $newest = DB::connection('mysql')->table('duress_alert_positions')->whereIn('duress_alert_id', $alerts->pluck('id')->all())
            ->groupBy('duress_alert_id')->selectRaw('duress_alert_id, MAX(captured_at) AS taken');
        $moved = DB::connection('mysql')->table('duress_alert_positions as p')
            ->joinSub($newest, 'n', static fn ($join) => $join->on('n.duress_alert_id', '=', 'p.duress_alert_id')->on('n.taken', '=', 'p.captured_at'))
            ->orderBy('p.id')
            ->get(['p.duress_alert_id', 'p.latitude', 'p.longitude', 'p.accuracy_m', 'p.captured_at', 'p.received_at'])
            ->keyBy('duress_alert_id');

        return $alerts->map(static function (DuressAlert $alert) use ($moved, $boundary): array {
            $now = $moved->get($alert->id);
            $lat = $now !== null ? (float) $now->latitude : ($alert->latitude === null ? null : (float) $alert->latitude);
            $lng = $now !== null ? (float) $now->longitude : ($alert->longitude === null ? null : (float) $alert->longitude);
            $at = $now !== null
                ? GuardFreshness::readingAt(Carbon::parse((string) $now->captured_at, 'UTC'), Carbon::parse((string) $now->received_at, 'UTC'))
                : $alert->server_time;

            return [
                'alert' => $alert,
                'lat' => $lat,
                'lng' => $lng,
                'accuracy_m' => $now?->accuracy_m === null ? null : (int) $now->accuracy_m,
                'position_at' => $lat === null ? null : $at,
                'moved' => $now !== null,
                'off_estate' => $lat === null || $lng === null ? null : EstateBounds::notConfirmed($boundary, $lat, $lng),
            ];
        })->values()->all();
    }

    /**
     * AN OFFICER AS THE ESTATE SEES THEM (Hassam, 12 Oct): two states and no clock-in time. "On duty" whatever the duty
     * (at post, on patrol, on a break: "A guard taking a break is still on duty"), or "Responding" to an alert, the
     * estate's own emergency. The clock-in time leaves every entry made from it, the guards' and the alerts', and the
     * one freshness line that read it says "No signal since the shift began". The Gemini console keeps all of it.
     *
     * @param  array<string, mixed>  $officer  one of GuardPins' officers
     * @return array<string, mixed>
     */
    private static function asTheEstateSeesIt(array $officer): array
    {
        $responding = $officer['state'] === 'responding';
        $quietSinceStart = $officer['freshness'] === GuardFreshness::NO_SIGNAL && ($officer['reading_at'] ?? null) === null;
        unset($officer['clocked_in_at']);

        return [
            ...$officer,
            'state' => $responding ? 'responding' : 'on_duty',
            'state_label' => $responding ? self::RESPONDING : self::ON_DUTY,
            'freshness_label' => $quietSinceStart ? GuardFreshness::NO_SIGNAL_SINCE_START : $officer['freshness_label'],
        ];
    }

    /**
     * One guard, as the estate may see them: each field copied by name.
     *
     * @param  array<string, mixed>  $officer  one of GuardPins' officers
     * @param  list<array<string, mixed>>  $alerts
     * @return array<string, mixed>
     */
    private function guardEntry(array $officer, bool $positions, array $alerts): array
    {
        $entry = [
            'key' => $officer['key'],
            'name' => $officer['name'],
            'photo_url' => null,
            'post' => $officer['post'],
            'state' => $officer['state'],
            'state_label' => $officer['state_label'],
        ];

        if (! $positions) {
            return $entry;
        }

        $distances = [];
        $lat = $officer['lat'] ?? null;
        $lng = $officer['lng'] ?? null;

        foreach ($alerts as $alert) {
            if ($lat !== null && $lng !== null && $alert['lat'] !== null && $alert['off_estate'] === null) {
                $distances[] = ['alert_id' => (int) $alert['alert']->id, 'metres' => (int) round(Geo::metresBetween((float) $lat, (float) $lng, $alert['lat'], $alert['lng']))];
            }
        }

        return [
            ...$entry,
            'lat' => $lat,
            'lng' => $lng,
            'accuracy_m' => $officer['accuracy_m'] ?? null,
            'reading_at' => $officer['reading_at'],
            'arrived_at' => $officer['arrived_at'],
            'tolerance_s' => $officer['tolerance_s'],
            'freshness' => $officer['freshness'],
            'freshness_label' => $officer['freshness_label'],
            'off_post' => $officer['off_post'] ?? null,
            'distances' => $distances,
        ];
    }

    /**
     * One open alert, with who is going and who is nearest.
     *
     * @param  array<string, mixed>  $read  one of openAlerts()
     * @param  list<array<string, mixed>>  $officers
     * @return array<string, mixed>
     */
    private function alertEntry(array $read, array $officers, bool $positions): array
    {
        /** @var DuressAlert $alert */
        $alert = $read['alert'];
        $estateId = (string) $alert->tenant_id;
        $placed = $read['lat'] !== null && $read['off_estate'] === null;
        $distance = static fn (array $o): ?int => $placed && isset($o['lat'], $o['lng']) ? (int) round(Geo::metresBetween((float) $o['lat'], (float) $o['lng'], $read['lat'], $read['lng'])) : null;

        $responder = null;

        if ($alert->responding_guard_id !== null) {
            $onDuty = collect($officers)->firstWhere('guard_id', (int) $alert->responding_guard_id);
            $responder = [
                'name' => (string) ($alert->respondingGuard->full_name ?? 'An officer'),
                'state' => match (true) {
                    $alert->on_scene_at !== null => 'On scene',
                    $alert->en_route_at !== null => 'On the way',
                    $alert->dispatched_at !== null => 'Dispatched',
                    default => 'Responding',
                },
                'key' => $onDuty['key'] ?? null,
                ...($positions && $onDuty !== null ? [
                    'lat' => $onDuty['lat'] ?? null,
                    'lng' => $onDuty['lng'] ?? null,
                    'distance_m' => $distance($onDuty),
                    'reading_at' => $onDuty['reading_at'],
                    'arrived_at' => $onDuty['arrived_at'],
                    'tolerance_s' => $onDuty['tolerance_s'],
                    'freshness' => $onDuty['freshness'],
                    'freshness_label' => $onDuty['freshness_label'],
                ] : []),
            ];
        }

        // The nearest guards by where they actually are, never by their post: a suggestion for a person to act on.
        $nearest = ! $positions || ! $placed ? [] : collect($officers)
            ->filter(static fn (array $o): bool => isset($o['lat'], $o['lng']))
            ->map(static fn (array $o): array => [
                'key' => $o['key'],
                'name' => $o['name'],
                'distance_m' => $distance($o),
                'reading_at' => $o['reading_at'],
                'arrived_at' => $o['arrived_at'],
                'tolerance_s' => $o['tolerance_s'],
                'freshness' => $o['freshness'],
                'freshness_label' => $o['freshness_label'],
            ])
            ->sortBy('distance_m')
            ->take(self::NEAREST)
            ->values()
            ->all();

        return [
            'id' => (int) $alert->id,
            'kind_label' => $alert->kindLabel(),
            'is_drill' => (bool) $alert->is_drill,
            'lot' => $alert->unit_reference ?? self::NO_LOT,
            'who' => $alert->raised_by_name ?? self::NO_NAME,
            'raised_at' => SiteTime::iso($alert->server_time),
            'raised_words' => SiteTime::fmt(SiteTime::local($alert->server_time, $estateId), 'g:i A'),
            // The Emergency list's words (Hassam, 11 Oct), the same words dispatch uses.
            'state' => match (true) {
                $alert->on_scene_at !== null => 'On scene',
                $alert->en_route_at !== null => 'On the way',
                $alert->dispatched_at !== null => 'Dispatched',
                $alert->responding_guard_id !== null => 'Responding',
                $alert->acknowledged_at !== null => 'Acknowledged',
                default => 'Unacknowledged',
            },
            'lat' => $read['lat'],
            'lng' => $read['lng'],
            'accuracy_m' => $read['accuracy_m'],
            'position_at' => SiteTime::iso($read['position_at']),
            'position_moved' => $read['moved'],
            'off_estate' => $read['off_estate'],
            'responder' => $responder,
            'nearest' => $nearest,
        ];
    }
}
