<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Services\Tracking\EstateBounds;
use App\Services\Tracking\GuardPins;
use App\Support\SiteTime;
use Illuminate\Support\Collection;

/**
 * The dispatch live map — board screen super-admin-12.
 *
 * THE MAP IS A SCHEMATIC, NOT A SURVEY, and the board draws it that way: fixed
 * dashed boxes for parishes, a labelled card per site, a pin per guard. It is
 * read the way an airport departure board is read — what is where, in which
 * state — and every position on it comes from a fixed slot in the canvas rather
 * than from a coordinate.
 *
 * THAT IS DELIBERATE ON THE SCHEMATIC: a pin there says exactly what its label
 * says — this guard is standing this post — and that is a fact the roster can
 * prove.
 *
 * WHERE A GUARD ACTUALLY IS lives on the real map beside it (`geo`, M11 §3–§6;
 * D-174): an officer on duty who has agreed to the location policy shares their
 * position from clock-in to clock-out, and their pin is drawn where the newest
 * fix put them, with that fix's age — greyed, with its time, once it is older
 * than `tracking.stale_after_seconds`, so a position from forty minutes ago is
 * never read as where they are now. An officer who refused, or whose phone has
 * sent nothing yet, is NAMED as "position not shared" / "no position yet" rather
 * than drawn at their post. The pins move over the live channel (`guard.moved`,
 * a reference: the position itself is read again here, through this viewer's
 * scope), never by polling.
 *
 * Everything else is real: the parishes are the estates' own, the guard pins
 * are guards on a shift that is actually underway, the alert pins are alerts
 * that are actually open, and a site with nobody on duty draws the board's
 * offline pin rather than being quietly left off.
 */
class LiveMap
{
    /**
     * The canvas positions, lifted from the board.
     *
     * The board's map is hand-drawn: every element carries an inline left/top
     * because its stylesheet defines no class for them. Those numbers are the
     * design, so they are copied verbatim and estates are placed INTO them —
     * rather than a layout of my own invention being substituted for one the
     * designer drew.
     *
     * Each site slot carries two guard positions and one alert position, which
     * is the density the board draws. A site with more guards than that gets
     * positions stepped off its cluster; a site with none draws the board's
     * offline pin instead.
     *
     * @var list<array{cluster: array{left: int, top: int}, guards: list<array{left: int, top: int}>, alert: array{left: int, top: int}}>
     */
    private const SITE_SLOTS = [
        [
            'cluster' => ['left' => 70, 'top' => 60],
            'guards' => [['left' => 130, 'top' => 170], ['left' => 230, 'top' => 150]],
            'alert' => ['left' => 180, 'top' => 230],
        ],
        [
            'cluster' => ['left' => 130, 'top' => 320],
            'guards' => [['left' => 180, 'top' => 420], ['left' => 280, 'top' => 400]],
            'alert' => ['left' => 230, 'top' => 480],
        ],
        [
            'cluster' => ['left' => 470, 'top' => 250],
            'guards' => [['left' => 540, 'top' => 340], ['left' => 640, 'top' => 320]],
            'alert' => ['left' => 590, 'top' => 400],
        ],
        [
            'cluster' => ['left' => 660, 'top' => 400],
            'guards' => [['left' => 700, 'top' => 490], ['left' => 800, 'top' => 470]],
            'alert' => ['left' => 750, 'top' => 550],
        ],
    ];

    /**
     * The parish boxes, also lifted verbatim.
     *
     * @var list<array{box: array{left: int, top: int, width: int, height: int}, label: array{left: int, top: int}}>
     */
    private const REGION_SLOTS = [
        [
            'box' => ['left' => 40, 'top' => 40, 'width' => 340, 'height' => 280],
            'label' => ['left' => 48, 'top' => 18],
        ],
        [
            'box' => ['left' => 420, 'top' => 200, 'width' => 340, 'height' => 300],
            'label' => ['left' => 428, 'top' => 178],
        ],
    ];

    /** How far back the acknowledgement-time figure looks. */
    private const ACKNOWLEDGEMENT_WINDOW_DAYS = 30;

    /**
     * Everything the live map draws.
     *
     * `$maySeeTier` is the caller's answer from `PlatformMoney::maySeeRevenue()` (M10 §5). The
     * site card reads "Premium · 4 guards", and the first half of that is the client's
     * subscription tier — a price list, on a card the Admin Assistant opens through Dispatch.
     * Without it the card carries no `plan` key, says what dispatch needs — how many guards are
     * standing there — and the plans table is never read. Defaults to true for the callers that
     * were never asked.
     *
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer, bool $maySeeTier = true): array
    {
        $estates = $this->estates($viewer);
        $onDuty = $this->onDutyShifts($estates->pluck('id')->all());
        $openAlerts = $this->openAlerts($estates->pluck('id')->all());

        return [
            'banner' => $this->banner($openAlerts),
            'kpis' => $this->kpis($estates, $onDuty, $openAlerts),
            'regions' => $this->regions($estates),
            'sites' => $this->sites($estates, $onDuty, $openAlerts, $maySeeTier),
            'legend' => $this->legend(),
            'onDuty' => $this->onDutyPanel($onDuty),
            'geo' => $this->geo($estates, $openAlerts, $onDuty),
            'roster' => $this->roster($onDuty, $openAlerts),
        ];
    }

    /* -------------------------------------------------------------- roster */

    /**
     * THE DISPATCHER'S ROSTER (Hassam, 12 October): every guard clocked in, as the security company sees its own staff.
     *
     * THE FULL VERSION, NOT THE ESTATE'S WITH KEYS PUT BACK. The estate's map shows a deliberately short allow-list
     * (Security's, built field by field in its own service); this console owns the data, so the dispatcher reads all of
     * it: who the guard is, their staff number and phone, their licence and when it lapses, the post and the shift, how
     * they clocked in and anything a supervisor must look at, and an alert they raised. Where they are, how fresh that
     * is and what they are doing (at post, on patrol, on break, responding) come from `GuardPins`, the one source both
     * consoles read, merged in by the shift; the freshness words are the shared rule's (`guardFreshness.js`).
     *
     * No coordinate and no fence geometry here: those stay in `geo`, where the map draws them. Every time is stamped on
     * the guard's estate's clock with its tag, or on the viewer's own in My time (`SiteTime::stamp()`).
     *
     * @param  Collection<int, Shift>  $onDuty
     * @param  Collection<int, DuressAlert>  $openAlerts
     * @return list<array<string, mixed>>
     */
    private function roster(Collection $onDuty, Collection $openAlerts): array
    {
        // An open alert this guard raised, newest first (the alerts arrive newest first).
        $raised = $openAlerts->filter(static fn (DuressAlert $a): bool => $a->guard_id !== null)
            ->unique('guard_id')
            ->keyBy('guard_id');

        // Each estate's clock read off the row already loaded with its shift, so stamping a time asks nothing more of
        // the database (the map's query budget).
        foreach ($onDuty as $shift) {
            if ($shift->estate !== null) {
                SiteTime::zoneOf($shift->estate);
            }
        }

        return $onDuty->map(function (Shift $shift) use ($raised): array {
            $guard = $shift->officer;
            $tenant = (string) $shift->tenant_id;
            $alert = $raised->get($shift->guard_id);

            // The clock this row's times are read on (the viewer's own in My time), sent so a freshness label the page
            // moves on with its own clock ("Last seen 4 min ago · 9:38 PM JA") reads as the row's stamps do.
            $zone = SiteTime::viewerZone() ?? SiteTime::zone($tenant);

            return [
                'key' => 'shift-'.$shift->id,
                'shift_id' => (int) $shift->id,
                'href' => '/guards/'.$shift->guard_id,
                'name' => (string) ($guard->full_name ?? 'Unassigned'),
                'employee_number' => $guard?->employee_number,
                'phone' => $guard?->phone,
                'licence' => $this->licence($guard),
                'post' => $shift->post === null ? null : $shift->postLabel(),
                'estate' => $shift->estate?->name,
                'patrol' => $shift->isPatrol(),
                'shift' => 'Shift '.SiteTime::stamp($shift->rostered_start, $tenant, 'g:i A').' to '.SiteTime::stamp($shift->rostered_end, $tenant, 'g:i A'),
                'clocked_in' => $this->clockedIn($shift, $tenant),
                'clock_in_check' => $this->clockInCheck($shift),
                'review' => ($shift->review_reasons ?? []) === [] ? null : 'To review: '.ShiftClock::reviewWords($shift->review_reasons, $shift->clock_offset_s),
                'simulated' => (bool) $shift->is_simulated,
                'clock' => ['zone' => $zone, 'tag' => SiteTime::clockTag($zone)],
                'alert' => $alert === null ? null : [
                    'href' => '/dispatch/alerts/'.$alert->id,
                    'label' => $alert->drillPrefix().$this->headline($alert),
                ],
            ];
        })->values()->all();
    }

    /**
     * The guard's licence, and how close it is to lapsing: amber within thirty days, and once lapsed (the three
     * weights: a warning, never the panic's red).
     *
     * @return array{label: string, tone: string}
     */
    private function licence(?Guard $guard): array
    {
        $number = $guard?->psra_number;
        $expires = $guard?->psra_expires_on;
        $words = fn (string $state): string => $this->join(['Licence '.($number ?? 'number not on file'), $state]);

        if ($expires === null) {
            return ['label' => $words('no expiry on file'), 'tone' => 'warn'];
        }

        $days = (int) SiteTime::date()->diffInDays($expires, false);

        return match (true) {
            $days < 0 => ['label' => $words('lapsed '.$expires->format('j M Y')), 'tone' => 'warn'],
            $days <= 30 => ['label' => $words('lapses in '.$days.' day'.($days === 1 ? '' : 's')), 'tone' => 'warn'],
            default => ['label' => $words('valid to '.$expires->format('j M Y')), 'tone' => 'ok'],
        };
    }

    /** "Clocked in 5:52 AM JA, 14 min late", and who started it when it was not the guard's own phone. */
    private function clockedIn(Shift $shift, string $tenant): string
    {
        $start = $shift->actual_start;
        $late = $start === null ? 0 : (int) floor($shift->rostered_start->diffInMinutes($start, false));

        return 'Clocked in '.($start === null ? 'at a time not recorded' : SiteTime::stamp($start, $tenant, 'g:i A'))
            .($late >= 5 ? ', '.$late.' min late' : '')
            .match (true) {
                $shift->overridden_by !== null => ' on a supervisor\'s PIN: '.match ($shift->override_reason) {
                    SupervisorOverride::REASON_NO_BIOMETRICS => 'phone has no fingerprint or face set up',
                    SupervisorOverride::REASON_OUTSIDE_GEOFENCE => 'outside the post\'s fence',
                    SupervisorOverride::REASON_POSITION_UNCONFIRMED => 'position could not be established',
                    default => 'fingerprint or face did not match',
                },
                $shift->start_method === ShiftClock::METHOD_MANUAL => ', started by a supervisor',
                default => '',
            };
    }

    /**
     * Where the phone put the guard at clock-in, against the post's fence, and whether that is a warning (amber, the
     * three weights). Null when nothing was judged.
     *
     * @return array{label: string, tone: string}|null
     */
    private function clockInCheck(Shift $shift): ?array
    {
        $distance = $shift->geofence_distance_m;

        $label = match ($shift->geofence_outcome) {
            ClockInFence::INSIDE => 'Inside the post\'s fence at clock-in',
            ClockInFence::INSIDE_MARGIN => 'At the edge of the post\'s fence at clock-in',
            ClockInFence::OUTSIDE => ($distance === null ? 'Outside' : $distance.' m from the post, outside').' the post\'s fence at clock-in',
            ClockInFence::POOR_FIX => 'Position too rough to judge at clock-in'.($shift->clock_in_accuracy_m === null ? '' : ' (±'.$shift->clock_in_accuracy_m.' m)'),
            ClockInFence::NO_POSITION => 'No position at clock-in',
            ClockInFence::NO_GEOFENCE => 'No fence set for the post, so clock-in was not checked',
            default => null,
        };

        if ($label === null) {
            return null;
        }

        return [
            'label' => $label,
            'tone' => in_array($shift->geofence_outcome, [ClockInFence::INSIDE, ClockInFence::INSIDE_MARGIN], true) ? 'ok' : 'warn',
        ];
    }

    /**
     * THE MAP ITSELF (M11 §6): what has a real position, on OpenStreetMap — every post that has been placed (its
     * surveyed position and the geofence a guard clocks in within, set in Operations → Sites & geofences), and every
     * open alert that was raised with a position. What has none is NAMED as unplaced rather than quietly left off:
     * the schematic below stays for the at-a-glance picture, and this is where a dispatcher sees where things are.
     *
     * @param  Collection<int, Tenant>  $estates
     * @param  Collection<int, DuressAlert>  $openAlerts
     * @param  Collection<int, Shift>  $onDuty
     * @return array{posts: list<array<string, mixed>>, alerts: list<array<string, mixed>>, unplaced: list<array{id: string, title: string}>, unconfirmed: list<array{id: string, title: string, why: string, href?: string}>, boundaries: list<array{id: string, points: list<array<int, float>>, label: string}>, guards: list<array<string, mixed>>, unshared: list<array{id: string, title: string, why: string}>, tracking: bool, stale_after_s: int}
     */
    private function geo(Collection $estates, Collection $openAlerts, Collection $onDuty): array
    {
        $names = $estates->mapWithKeys(static fn (Tenant $e): array => [(string) $e->getKey() => (string) $e->name])->all();

        $posts = Post::query()->whereIn('tenant_id', array_keys($names))->where('is_active', true)->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'also_called', 'latitude', 'longitude', 'geofence_radius_m']);

        /*
         * A POSITION OUTSIDE ITS ESTATE'S BOUNDARY IS NAMED, NEVER PLOTTED (Hassam, 12 Oct; EstateBounds). The map
         * frames what it draws, so one gate stored 36 km away, or a panic pressed on a phone abroad, stretched it across
         * two continents. Each is listed beside the map as "Position not confirmed", with how far out it is; an open
         * alert there still links to its page. Officers stay drawn: a fix far out is refused when it arrives.
         */
        // Each estate's boundary, and its guards' No signal tolerance, in one read (EstateBounds::sites).
        $sites = EstateBounds::sites(array_keys($names));
        $bounds = $sites['boundaries'];
        $notConfirmed = static fn (string $tenantId, mixed $lat, mixed $lng): ?string => EstateBounds::notConfirmed($bounds[$tenantId] ?? null, (float) $lat, (float) $lng);

        // Each post by the estate's own names (M26). A patrol officer shows where they are, from the position trail.
        [$placed, $offPosts] = $posts->filter(static fn (Post $p): bool => $p->isPlaced())
            ->partition(static fn (Post $p): bool => $notConfirmed((string) $p->tenant_id, $p->latitude, $p->longitude) === null);
        [$pinned, $offAlerts] = $openAlerts->filter(static fn (DuressAlert $a): bool => $a->latitude !== null && $a->longitude !== null)
            ->partition(static fn (DuressAlert $a): bool => $notConfirmed((string) $a->tenant_id, $a->latitude, $a->longitude) === null);

        return [
            'posts' => $placed->map(static fn (Post $p): array => [
                'id' => 'post-'.$p->id,
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'radius_m' => (int) ($p->geofence_radius_m ?? 5),
                'title' => $p->label(),
                'estate' => $names[(string) $p->tenant_id] ?? '',
            ])->values()->all(),

            'alerts' => $pinned->map(fn (DuressAlert $a): array => [
                'id' => 'alert-'.$a->id,
                'lat' => (float) $a->latitude,
                'lng' => (float) $a->longitude,
                'title' => $a->drillPrefix().$this->headline($a),
                'detail' => $this->join([$a->estate->name ?? null, $a->unit_reference, $a->acknowledged_at === null ? 'unacknowledged' : 'acknowledged']),
                'href' => '/dispatch/alerts/'.$a->id,
            ])->values()->all(),

            'unplaced' => $posts->reject(static fn (Post $p): bool => $p->isPlaced())
                ->map(static fn (Post $p): array => ['id' => 'post-'.$p->id, 'title' => $p->label().' ('.($names[(string) $p->tenant_id] ?? '').')'])
                ->values()->all(),

            'unconfirmed' => [
                ...$offPosts->map(static fn (Post $p): array => [
                    'id' => 'post-'.$p->id,
                    'title' => $p->label().' ('.($names[(string) $p->tenant_id] ?? '').')',
                    'why' => (string) $notConfirmed((string) $p->tenant_id, $p->latitude, $p->longitude),
                ])->values()->all(),
                ...$offAlerts->map(fn (DuressAlert $a): array => [
                    'id' => 'alert-'.$a->id,
                    'title' => ($a->is_drill ? 'DRILL · ' : '').$this->headline($a).' ('.$this->join([$a->estate->name ?? null, $a->unit_reference]).')',
                    'why' => (string) $notConfirmed((string) $a->tenant_id, $a->latitude, $a->longitude),
                    'href' => '/dispatch/alerts/'.$a->id,
                ])->values()->all(),
            ],

            // Each estate's boundary as its site plan draws it (M57.5 §4.5), where one is recorded.
            'boundaries' => collect($bounds)->map(static fn (array $points, int|string $tenantId): array => [
                'id' => 'boundary-'.$tenantId,
                'points' => $points,
                'label' => ($names[(string) $tenantId] ?? '').', as its site plan draws it',
            ])->values()->all(),

            // The officers, with what this map has already read: the tolerances above, and which alert each is answering.
            ...GuardPins::for($onDuty, $names, tolerances: $sites['tolerances'], responding: $openAlerts
                ->filter(static fn (DuressAlert $a): bool => $a->responding_guard_id !== null)
                ->mapWithKeys(static fn (DuressAlert $a): array => [(int) $a->responding_guard_id => (int) $a->id])->all()),
        ];
    }

    /* -------------------------------------------------------------- banner */

    /**
     * The one alert a dispatcher is being asked to act on right now.
     *
     * One, not a list: the banner is an interruption, and an interruption that
     * scrolls has stopped interrupting. The rest of the queue is one click
     * away and the count is in the KPI beside it.
     *
     * @param  Collection<int, DuressAlert>  $openAlerts
     * @return array<string, string>|null
     */
    private function banner(Collection $openAlerts): ?array
    {
        $alert = $openAlerts
            ->sortBy([
                fn (DuressAlert $a): int => $a->priority(),
                fn (DuressAlert $a): string => $a->server_time->format('Y-m-d H:i:s'),
            ])
            ->first();

        if ($alert === null) {
            return null;
        }

        $who = $alert->raisedByGuard->full_name ?? $alert->raised_by_name ?? 'Unknown';

        return [
            // What, then where: "Panic button: Phoenix Park Village 1". A colon, never an em dash (M53 Law 3).
            'title' => $alert->drillPrefix().$this->headline($alert).': '.($alert->estate->name ?? 'Unknown estate'),
            'meta' => $this->join([
                $who,
                $alert->unit_reference,
                $alert->kindLabel(),
                'fired '.$alert->server_time->diffForHumans(),
                $alert->acknowledged_at === null ? 'unacknowledged' : 'acknowledged',
            ]),
            'href' => '/dispatch/alerts/'.$alert->id,
        ];
    }

    /* ---------------------------------------------------------------- kpis */

    /**
     * @param  Collection<int, Tenant>  $estates
     * @param  Collection<int, Shift>  $onDuty
     * @param  Collection<int, DuressAlert>  $openAlerts
     * @return list<array{icon: string, stroke: float, tone: string, value: string, label: string}>
     */
    private function kpis(Collection $estates, Collection $onDuty, Collection $openAlerts): array
    {
        return [
            [
                'icon' => 'guards',
                'stroke' => 1.7,
                'tone' => '',
                'value' => (string) $onDuty->pluck('guard_id')->unique()->count(),
                'label' => 'Guards on duty, platform-wide',
            ],
            [
                'icon' => 'warning',
                'stroke' => 1.7,
                'tone' => 'alert',
                'value' => (string) $openAlerts->count(),
                'label' => 'Active alerts',
            ],
            [
                'icon' => 'clients',
                'stroke' => 1.7,
                'tone' => '',
                'value' => (string) $estates->count(),
                'label' => 'Sites monitored',
            ],
            [
                'icon' => 'clock',
                'stroke' => 1.6,
                'tone' => '',
                'value' => $this->averageAcknowledgement($estates->pluck('id')->all()),
                'label' => 'Avg. acknowledgement time, 30d',
            ],
        ];
    }

    /**
     * How long dispatch takes to acknowledge an alert, over thirty days.
     *
     * An em dash when nothing has been acknowledged in the window, never a
     * zero. Zero seconds would read as instant response, which is the opposite
     * of "no alert has been answered this month".
     *
     * NOT A DRILL (M10 §1.3). `panic:walk` raises a real alert to prove the
     * fan-out, and it may sit open for an hour while somebody walks the consoles
     * — or be acknowledged in two seconds by the person who raised it. Either way
     * it says nothing about how fast dispatch answers a person in trouble, and
     * one of them would move this figure further than a month of real alerts.
     *
     * @param  list<string>  $estateIds
     */
    private function averageAcknowledgement(array $estateIds): string
    {
        /*
         * SUMMED BY THE DATABASE, NOT HYDRATED AND AVERAGED HERE (M11 A2). This loaded every
         * acknowledged alert of the last thirty days as a model to average two columns — on a
         * screen that polls every five seconds for every dispatcher watching it. The same
         * rows, the same seconds (the two instants' difference, whole seconds, which is what
         * the columns hold), summed and counted in SQL; the average is taken here exactly as
         * before, so a count of zero still reads as an em dash.
         */
        $totals = DuressAlert::query()
            ->whereIn('tenant_id', $estateIds)
            ->where('is_drill', false)
            ->whereNotNull('acknowledged_at')
            ->where('server_time', '>=', now()->subDays(self::ACKNOWLEDGEMENT_WINDOW_DAYS))
            ->toBase()
            ->selectRaw('COUNT(*) as alerts, SUM(UNIX_TIMESTAMP(acknowledged_at) - UNIX_TIMESTAMP(server_time)) as seconds')
            ->first();

        $alerts = (int) ($totals->alerts ?? 0);

        if ($alerts === 0) {
            return '—';
        }

        $average = (int) round((int) $totals->seconds / $alerts);

        return $average < 60
            ? $average.'s'
            : intdiv($average, 60).'m '.($average % 60).'s';
    }

    /* --------------------------------------------------------------- canvas */

    /**
     * One dashed box per parish the platform actually guards.
     *
     * Ordered by parish name so the layout is stable between requests — a map
     * whose regions swap places on a refresh is not a map anyone can learn.
     *
     * @param  Collection<int, Tenant>  $estates
     * @return list<array{key: string, label: string, box: array{left: int, top: int, width: int, height: int}, labelAt: array{left: int, top: int}}>
     */
    private function regions(Collection $estates): array
    {
        $parishes = $estates
            ->map(fn (Tenant $estate): string => $estate->parish ?? 'Parish not recorded')
            ->unique()
            ->sort()
            ->values();

        $regions = [];

        foreach ($parishes as $index => $parish) {
            $slot = self::REGION_SLOTS[$index] ?? null;

            // More parishes than the board drew boxes for. The sites are still
            // plotted and the side panel still lists every guard, so nobody
            // disappears; only the decorative box is missing.
            if ($slot === null) {
                continue;
            }

            $regions[] = [
                'key' => $parish,
                'label' => $parish,
                'box' => $slot['box'],
                'labelAt' => $slot['label'],
            ];
        }

        return $regions;
    }

    /**
     * The site cards and every pin around them.
     *
     * @param  Collection<int, Tenant>  $estates
     * @param  Collection<int, Shift>  $onDuty
     * @param  Collection<int, DuressAlert>  $openAlerts
     * @return list<array<string, mixed>>
     */
    private function sites(Collection $estates, Collection $onDuty, Collection $openAlerts, bool $maySeeTier = true): array
    {
        $tiers = $maySeeTier ? $this->tiers() : [];
        $sites = [];

        foreach ($estates->values() as $index => $estate) {
            $slot = self::SITE_SLOTS[$index] ?? null;

            if ($slot === null) {
                continue;
            }

            $key = (string) $estate->getTenantKey();
            $here = $onDuty->filter(fn (Shift $s): bool => $s->tenant_id === $key)->values();
            $alerts = $openAlerts->filter(fn (DuressAlert $a): bool => $a->tenant_id === $key)->values();

            /*
             * THE CARD'S LINE IN TWO HALVES, and the first is Billing's (M10 §5). "Premium · 4
             * guards" was one string under a key named `tier`; the plan is now its own key, sent
             * only to a viewer who may read it, and the page joins what arrived. A reader without
             * billing gets a card that says how many guards are standing there and nothing about
             * what the client is on — not the plan with its name blanked, which would still say
             * there is one.
             */
            $sites[] = [
                'key' => $key,
                'name' => $estate->name,
                ...($maySeeTier ? ['plan' => $tiers[$key] ?? 'No plan yet'] : []),
                'staffing' => $this->staffing($estate, $here->count()),
                'cluster' => $slot['cluster'],
                'pins' => $this->pins($slot, $here, $alerts),
            ];
        }

        return $sites;
    }

    /**
     * The second half of "Premium · 4 guards", or what is true instead.
     *
     * An onboarding estate has no tier in force — the plan is prepared, not
     * billed — so it says onboarding, which is what a dispatcher needs to know
     * before anything else about that site.
     */
    private function staffing(Tenant $estate, int $onDuty): string
    {
        return match (true) {
            $estate->status === 'onboarding' => 'onboarding',
            $onDuty === 0 => 'self-managed',
            $onDuty === 1 => '1 guard',
            default => $onDuty.' guards',
        };
    }

    /**
     * Guard pins, then one alert pin, then the offline pin if nobody is there.
     *
     * ONE ALERT PIN PER SITE, not one per alert. The map answers "where", and
     * a site is one place however many alerts are open at it; the label says
     * how many and the queue says what they are. Stacking five pins on one
     * cluster would make the busiest site the hardest to read.
     *
     * @param  array{cluster: array{left: int, top: int}, guards: list<array{left: int, top: int}>, alert: array{left: int, top: int}}  $slot
     * @param  Collection<int, Shift>  $onDuty
     * @param  Collection<int, DuressAlert>  $alerts
     * @return list<array{key: string, left: int, top: int, dot: string, label: string, labelClass: string, labelStyle: string|null}>
     */
    private function pins(array $slot, Collection $onDuty, Collection $alerts): array
    {
        $pins = [];

        foreach ($onDuty as $index => $shift) {
            $at = $slot['guards'][$index] ?? [
                // Beyond the positions the board drew: stepped diagonally off
                // the cluster so pins never land on top of one another.
                'left' => $slot['cluster']['left'] + 40 + ($index * 46),
                'top' => $slot['cluster']['top'] + 150 + ($index * 22),
            ];

            $pins[] = [
                'key' => 'shift-'.$shift->id,
                'left' => $at['left'],
                'top' => $at['top'],
                'dot' => $shift->isPatrol() ? 'patrol' : 'post',
                'label' => ($shift->officer->full_name ?? 'Unassigned').' · '.($shift->post === null ? 'No post' : $shift->postLabel()),
                'labelClass' => 'pin-label',
                'labelStyle' => null,
            ];
        }

        if ($alerts->isNotEmpty()) {
            /** @var DuressAlert $first */
            $first = $alerts->first();

            $pins[] = [
                'key' => 'alerts-'.$first->tenant_id,
                'left' => $slot['alert']['left'],
                'top' => $slot['alert']['top'],
                'dot' => 'alert',
                'label' => $alerts->count() === 1
                    ? $this->join([
                        $first->raisedByGuard->full_name ?? $first->raised_by_name ?? 'Unknown',
                        $first->unit_reference,
                    ])
                    : $alerts->count().' active alerts',
                'labelClass' => 'pin-label alert-label',
                'labelStyle' => null,
            ];
        }

        if ($onDuty->isEmpty()) {
            $pins[] = [
                'key' => 'offline-empty',
                'left' => $slot['guards'][0]['left'],
                'top' => $slot['guards'][0]['top'],
                'dot' => 'offline',
                'label' => 'No Gemini guards on site',
                'labelClass' => 'pin-label',

                // The board's own inline style on this one pin, verbatim: its
                // stylesheet has no modifier for a grey label.
                'labelStyle' => 'background:var(--slate-500);',
            ];
        }

        return $pins;
    }

    /**
     * The legend, which is the board's and is not derived from anything.
     *
     * @return list<array{colour: string, label: string}>
     */
    private function legend(): array
    {
        return [
            ['colour' => 'var(--navy-600)', 'label' => 'On post'],
            ['colour' => 'var(--navy-300)', 'label' => 'On patrol'],
            ['colour' => 'var(--slate-300)', 'label' => 'Offline / not applicable'],
            ['colour' => 'var(--red-700)', 'label' => 'Active alert'],
        ];
    }

    /**
     * Every guard on duty, whether or not a pin could be drawn for them.
     *
     * This list is the safety valve for the canvas. The canvas has four slots
     * because the board drew four; this has no limit, so a fifth site's guards
     * are still on the screen and still reachable.
     *
     * @param  Collection<int, Shift>  $onDuty
     * @return list<array{key: string, colour: string, name: string, where: string, href: string}>
     */
    private function onDutyPanel(Collection $onDuty): array
    {
        return $onDuty->map(fn (Shift $shift): array => [
            'key' => 'duty-'.$shift->id,
            // A patrol shift is drawn "On patrol" (M27): its officer is out across the estate, not at the gate.
            'colour' => $shift->isPatrol() ? 'var(--navy-300)' : 'var(--navy-600)',
            'name' => $shift->officer->full_name ?? 'Unassigned',
            'where' => $this->join([
                $shift->estate?->name,
                $shift->post === null ? null : $shift->postLabel(),
            ]),
            'href' => '/guards/'.$shift->guard_id,
        ])->values()->all();
    }

    /* -------------------------------------------------------------- queries */

    /**
     * @return Collection<int, Tenant>
     */
    private function estates(User $viewer): Collection
    {
        $estates = Tenant::estates(function ($query) use ($viewer): void {
            // Scope AND the demonstration estate, in one call (M5 §3). A site
            // pin for an estate that does not exist would put a guard who does
            // not exist on a dispatcher's map.
            OperatorEstates::narrow($query, $viewer, 'tenants.id');

            // Parish first, then name: the canvas slots are filled in this
            // order, so this is the map's layout as much as its sort.
            $query->orderBy('parish')->orderBy('name');
        });

        return $estates;
    }

    /**
     * Shifts underway right now.
     *
     * Rostered is not the same as present. A shift nobody started is a gap,
     * and drawing a pin for it would put a guard on the map who is not there.
     *
     * @param  list<string>  $estateIds
     * @return Collection<int, Shift>
     */
    private function onDutyShifts(array $estateIds): Collection
    {
        // Whether each is on a break, in the same read (GuardPins::withBreak): the pins say what each officer is doing.
        return GuardPins::withBreak(Shift::query())
            ->with(['officer', 'post', 'estate'])
            ->whereIn('tenant_id', $estateIds)
            ->covering(now())
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->whereHas('officer', fn ($query) => $query->where('status', 'active'))
            ->get()
            ->sortBy(fn (Shift $shift): string => $shift->officer->full_name ?? '')
            ->values();
    }

    /**
     * @param  list<string>  $estateIds
     * @return Collection<int, DuressAlert>
     */
    private function openAlerts(array $estateIds): Collection
    {
        return DuressAlert::query()
            ->with(['raisedByGuard', 'estate'])
            ->whereIn('tenant_id', $estateIds)
            ->whereNotIn('status', ['resolved', 'false_alarm'])
            ->orderByDesc('server_time')
            ->get();
    }

    /**
     * Each estate's plan name, for the site card.
     *
     * @return array<string, string>
     */
    private function tiers(): array
    {
        $query = Tenant::query()
            ->join('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id');

        OperatorEstates::exclude($query, 'tenants.id');

        /** @var array<string, string> $tiers */
        $tiers = $query->pluck('plans.name', 'tenants.id')->all();

        return $tiers;
    }

    /* ---------------------------------------------------------------- utils */

    private function headline(DuressAlert $alert): string
    {
        return match ($alert->kind) {
            'panic' => 'Active panic alert',
            'duress' => 'Guard duress',
            'medical' => 'Medical alert',
            'fire' => 'Fire alert',
            'intrusion' => 'Intrusion alert',
            default => $alert->kindLabel(),
        };
    }

    /**
     * The board's meta separator, skipping anything absent.
     *
     * @param  array<int, string|null>  $parts
     */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
