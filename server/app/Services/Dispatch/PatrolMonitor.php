<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\AlertnessCheck;
use App\Models\CheckpointScan;
use App\Models\Guard;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Services\Tracking\GuardTracking;
use App\Services\Tracking\TrackingGaps;
use App\Support\SiteTime;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guard alertness and patrol monitoring — board screen super-admin-15.
 *
 * ONE QUESTION: is every guard who is supposed to be on duty right now still
 * demonstrably awake, on their feet and reporting?
 *
 * It is answered from EVIDENCE THE GUARD PRODUCED, never from the roster. A
 * shift row says somebody was rostered and clocked in; it says nothing about
 * the four hours since. Two kinds of evidence exist and they are alternatives
 * rather than a pair, which is why the board draws them as two columns and why
 * a row usually carries a dash in one of them:
 *
 *   a patrol guard proves it by SCANNING the next checkpoint on the tour
 *   a static guard has no checkpoint to reach, so the periodic alertness
 *   CHALLENGE is the only signal there is
 *
 * SILENCE IS THE FINDING, and it is the whole point of the screen. A guard who
 * has stopped scanning has either sat down, been hurt, or lost their handset,
 * and all three need a dispatcher. So an absent scan is escalated on its own
 * timer rather than waiting for something to arrive — which is why the overdue
 * calculation below is driven by the clock rather than by a row appearing.
 *
 * NO POSITION IS READ, DERIVED OR RETURNED HERE. A checkpoint scan proves a guard
 * physically reached a fixed, named point. Since M21 §1.1 a scan row also keeps
 * the phone's fix it was judged by (how far from the tag), but this screen reads
 * none of those columns: what it shows is which point was reached and when, and
 * the live map — not this board — is where a guard's position is shown. Since
 * M22 §1.1 it reads one more thing, WHEN the newest on-duty position was taken
 * (never where), because a phone that stopped recording is silence too.
 *
 * NOTHING HERE HOLDS AN AMOUNT, and none of the tables it reads has a money
 * column. Invariant 2 stands on structure rather than on remembering.
 *
 * The screen polls this class every five seconds, so it stays cheap: five
 * indexed queries, no per-row lookups, and all the grouping done in PHP.
 */
class PatrolMonitor
{
    /**
     * The first KPI card's threshold, and it is printed on the card's face:
     * "Guards active, verified <15 min". Kept here so the number a dispatcher
     * reads and the number this class counts cannot drift apart.
     */
    public const VERIFIED_MINUTES = 15;

    /**
     * How often a static post is challenged, and how long it may run over.
     *
     * Longer than the patrol cadence on purpose. A patrol guard is walking, and
     * a missed checkpoint is a fact about a place they did not reach; a gate
     * guard is standing still, and prompting them every fifteen minutes all
     * night teaches them to dismiss the prompt without reading it.
     */
    public const CHALLENGE_CADENCE_MINUTES = 45;

    public const CHALLENGE_GRACE_MINUTES = 10;

    /**
     * How far back the challenge streak is counted.
     *
     * A streak longer than this is already the worst thing on the screen, so
     * reading further changes no decision and only costs rows.
     */
    private const STREAK_DEPTH = 10;

    /**
     * Post types in the order the board lists them, matching the coverage
     * board: gates first, then patrols, then relief. Two dispatch screens that
     * disagree about the order of the same guards make a dispatcher read both.
     *
     * @var array<string, int>
     */
    private const TYPE_ORDER = ['gate' => 0, 'patrol' => 1, 'relief' => 2];

    /** The board's word for each post type, as drawn in the "Post type" cell. */
    private const TYPE_LABEL = ['gate' => 'Static', 'patrol' => 'Patrol', 'relief' => 'Relief'];

    /**
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer): array
    {
        $estates = $this->estates($viewer);
        $onDuty = $this->onDutyGuards($estates->pluck('id')->all());

        $shifts = $this->currentShifts($onDuty);
        $checkpoints = $this->checkpointsFor($onDuty, $shifts);
        $scans = $this->scansFor($onDuty->modelKeys(), $checkpoints);
        $challenges = $this->challengesFor($onDuty->modelKeys());

        // Each estate's own patrol interval (M27 §4.4), in one read: the Director's, else the platform's.
        $cadences = $this->cadences($estates->pluck('id')->all());

        // Positions (M22 §1.1): two reads for every officer on the board — each shift's newest fix, and who agreed.
        $tracking = app(GuardTracking::class);
        $fixes = GuardTracking::enabled() ? $tracking->newestTakenOn($shifts->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all()) : [];
        $consents = GuardTracking::enabled() ? $tracking->consentsOf($onDuty->modelKeys()) : [];

        /*
         * Estate order is an INDEX, not a comparison on the estate itself.
         *
         * The estates arrive already sorted the way the live map and the
         * coverage board sort them — parish, then name — so their position in
         * that list is the ordering, and re-deriving it here is one more place
         * for the three dispatch screens to start disagreeing.
         */
        $estateOrder = $estates->pluck('id')->flip();

        $rows = $onDuty
            ->map(fn (Guard $guard): array => $this->row(
                $guard,
                $estates->firstWhere('id', $guard->tenant_id),
                $estateOrder->get((string) $guard->tenant_id, PHP_INT_MAX),
                $shifts->get($guard->id),
                $checkpoints,
                $scans->get($guard->id) ?? collect(),
                $challenges->get($guard->id) ?? collect(),
                $this->position($shifts->get($guard->id), $fixes, $consents),
                $cadences[(string) $guard->tenant_id] ?? PatrolCheckpoint::CADENCE_MINUTES,
            ))
            /*
             * Column names, NOT closures.
             *
             * Collection::sortBy's array form treats a callable as a COMPARATOR
             * — it is handed ($a, $b) and its return value is the comparison —
             * so the obvious `fn (array $row) => $row['sort_site']` accessors
             * that stood here returned an estate index as if it were a
             * comparison result and put the guards in an order nobody chose.
             * The string form is the accessor form, and it is the only one that
             * gives this board the site-then-post-type order the coverage board
             * beside it uses.
             */
            ->sortBy([
                ['sort_site', 'asc'],
                ['sort_type', 'asc'],
                ['name', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'banner' => $this->banner($rows),
            'kpis' => $this->kpis($rows),

            // The sort and threshold keys were only ever for this class. They
            // do not travel to the browser, where a second copy of these rules
            // could start disagreeing with this one.
            'rows' => array_map($this->visible(...), $rows),
            'policy' => $this->policy(),
        ];
    }

    /**
     * The written alertness policy, generated from the constants that enforce
     * it.
     *
     * A policy sentence typed by hand beside a constant is a sentence that goes
     * stale the first time the constant moves, and the person it misleads is
     * the dispatcher deciding whether a quiet guard is late.
     */
    public function policy(): string
    {
        return sprintf(
            'Patrol checkpoints are expected every %d minutes, or at the interval an estate has set for its own patrol in Sites & geofences, with a %d-minute grace period; static posts are challenged every %d minutes with a %d-minute grace period. These are the figures this screen enforces right now.',
            PatrolCheckpoint::CADENCE_MINUTES,
            PatrolCheckpoint::GRACE_MINUTES,
            self::CHALLENGE_CADENCE_MINUTES,
            self::CHALLENGE_GRACE_MINUTES,
        );
    }

    /* ---------------------------------------------------------------- rows */

    /**
     * One guard's row: what they last proved, and how long ago.
     *
     * @param  Collection<int, PatrolCheckpoint>  $checkpoints
     * @param  Collection<int, CheckpointScan>  $scans
     * @param  Collection<int, AlertnessCheck>  $challenges
     * @param  array{label: string, gap: bool, since: string|null}  $position
     * @return array<string, mixed>
     */
    private function row(
        Guard $guard,
        ?Tenant $estate,
        int $estateOrder,
        ?Shift $shift,
        Collection $checkpoints,
        Collection $scans,
        Collection $challenges,
        array $position,
        int $cadence = PatrolCheckpoint::CADENCE_MINUTES,
    ): array {
        /*
         * THE DUTY THEY ARE STANDING NOW, not the post on their record (M26, M27). A patrol is a shift's duty at the gate
         * it starts from ("Main Gate · patrol"), so the shift says whether this officer is patrolling tonight; the
         * record's post is only the fallback when there is no shift to read. Their tour is the estate's patrol route.
         */
        $post = $shift->post ?? $guard->post;
        $patrol = $shift !== null ? $shift->isPatrol() : ($post?->isPatrolRoute() ?? false);

        $tour = $patrol
            ? $this->tour($post, $shift, $checkpoints->filter(static fn (PatrolCheckpoint $c): bool => (string) $c->tenant_id === (string) $guard->tenant_id), $scans, $cadence)
            : null;

        $challenge = $this->lastChallenge($challenges);
        $overdue = $tour !== null && $tour['overdue'];

        /*
         * The freshest thing this guard has proved, whichever kind it was.
         *
         * A minimum across both, because a patrol guard who also answered a
         * challenge has demonstrably not gone quiet, and reading only their
         * scans would raise them anyway.
         */
        $verified = collect([$tour['minutes'] ?? null, $challenge['minutes'] ?? null])
            ->filter(static fn (?int $minutes): bool => $minutes !== null)
            ->min();

        [$stateLabel, $stateClass] = $this->state($guard, $overdue, $verified, $patrol, $cadence);

        return [
            'sort_site' => $estateOrder,
            'sort_type' => $patrol ? self::TYPE_ORDER['patrol'] : (self::TYPE_ORDER[$post->type ?? ''] ?? 9),

            'key' => 'guard-'.$guard->id,
            'name' => $guard->full_name,
            'post' => $this->postLabel($post, $estate, $patrol),

            'last_scan' => $tour === null ? '—' : $tour['label'],
            'last_challenge' => $challenge === null ? '—' : $challenge['label'],
            'tour' => $tour['dots'] ?? null,

            // M22 §1.1: when the phone last recorded where this officer is, or since when it has not.
            'position' => $position['label'],
            'position_gap' => $position['gap'],
            'position_since' => $position['since'],

            'state' => $stateLabel,
            'state_class' => $stateClass,

            // "No handset" rather than an empty cell: an unbound guard is a
            // monitoring gap a dispatcher can act on, not a missing field.
            'device' => $guard->device_label ?? 'No handset',
            'binding' => $guard->deviceIsBound() ? 'bound' : 'unbound',

            'overdue' => $overdue,
            'verified_minutes' => $verified,
            'bound_and_reporting' => $guard->deviceIsBound() && $verified !== null,
            'missed_streak' => $this->missedChallengeStreak($challenges),
            'guard_href' => '/guards/'.$guard->id,
            'banner_meta' => $tour === null ? null : $this->join([
                $estate?->name,
                // A tour is only ever a patrol's.
                self::TYPE_LABEL['patrol'],
                'Checkpoint '.min($tour['reached'] + 1, $tour['total']).' of '.$tour['total'].' not scanned within window',
                $tour['expected'] === null ? null : 'expected '.$this->ago($tour['expected']),
            ]),
        ];
    }

    /**
     * What the browser is allowed to see of a row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function visible(array $row): array
    {
        return collect($row)->except([
            'sort_site',
            'sort_type',
            'overdue',
            'verified_minutes',
            'bound_and_reporting',
            'missed_streak',
            'guard_href',
            'banner_meta',
            'position_since',
        ])->all();
    }

    /**
     * THE POSITION CELL (M22 §1.1). What the phone last recorded — "3 min ago" — or, once nothing has been TAKEN for
     * `tracking.gap_after_seconds`, "None since 3:14 PM JA · 71 min", marked as a gap. An officer who has not agreed
     * to share reads "Not shared", which is an HR matter and never a fault; before the clock-in there is nothing to ask.
     *
     * @param  array<int, string>  $fixes  shift id => when its newest fix was taken (UTC)
     * @param  array<int, string>  $consents  guard id => granted|refused
     * @return array{label: string, gap: bool, since: string|null}
     */
    private function position(?Shift $shift, array $fixes, array $consents): array
    {
        if (! GuardTracking::enabled()) {
            return ['label' => 'Not tracked', 'gap' => false, 'since' => null];
        }

        if ($shift === null || $shift->actual_start === null) {
            return ['label' => '—', 'gap' => false, 'since' => null];
        }

        if (($consents[(int) $shift->guard_id] ?? null) !== GuardTracking::GRANTED) {
            return ['label' => 'Not shared', 'gap' => false, 'since' => null];
        }

        $newest = $fixes[(int) $shift->id] ?? null;
        $open = TrackingGaps::openSince($shift, $newest);

        if ($open !== null) {
            $since = SiteTime::fmt(SiteTime::local($open, (string) $shift->tenant_id), 'g:i A');
            $for = TrackingGaps::length((int) $open->diffInSeconds(now()));

            return ['label' => 'None since '.$since.' · '.$for, 'gap' => true, 'since' => 'no position received since '.$since.' ('.$for.')'];
        }

        return $newest === null
            ? ['label' => 'None yet', 'gap' => false, 'since' => null]
            : ['label' => $this->ago(Carbon::parse($newest, 'UTC')), 'gap' => false, 'since' => null];
    }

    /**
     * The board's "Post type" cell: the kind of post, then where it is.
     *
     * A patrol post named "Patrol - Phase 2-5" carries its route in its name,
     * so repeating the word under a Patrol heading reads as a stutter; a patrol
     * post named only "Patrol" has no route to name, and the estate is the
     * useful second half. Both shapes are the board's own.
     */
    private function postLabel(?Post $post, ?Tenant $estate, bool $patrol = false): string
    {
        if ($post === null) {
            return 'Unposted';
        }

        // A patrol duty at a gate (M27): "Patrol — from Main Gate", the gate it started from and clocked in at.
        if ($patrol && ! $post->isPatrolRoute()) {
            return self::TYPE_LABEL['patrol'].' — from '.$post->label();
        }

        $label = self::TYPE_LABEL[$post->type] ?? Str::ucfirst($post->type);
        $where = trim(Str::after($post->label(), $label), " \t-–—");

        // "Static — Back Gate (Gate 5, Phase 5 Gate)" (M26: the estate's own names).
        return $label.' — '.($where !== '' ? $where : ($estate === null ? 'Unassigned' : $estate->name));
    }

    /**
     * How far round the tour this guard is, and whether the next checkpoint is
     * already late.
     *
     * A tour is an ORDERED WALK, not a set of tags: "checkpoint 4 of 6" is only
     * answerable because checkpoints carry a sequence, and a guard who scanned
     * the last one first has not walked it. Progress is therefore the count of
     * DISTINCT checkpoints reached since the shift started, not the number of
     * scan rows.
     *
     * @param  Collection<int, PatrolCheckpoint>  $checkpoints
     * @param  Collection<int, CheckpointScan>  $scans
     * @return array{label: string, dots: list<array{class: string}>, reached: int, total: int, minutes: int|null, expected: Carbon|null, overdue: bool}|null
     */
    private function tour(?Post $post, ?Shift $shift, Collection $checkpoints, Collection $scans, int $cadence = PatrolCheckpoint::CADENCE_MINUTES): ?array
    {
        // The rule the Guard App's tour follows (PatrolCheckpoint::tourFor): the estate's own route for a patrol duty.
        $route = PatrolCheckpoint::routeOf($checkpoints->values(), $post?->id === null ? null : (int) $post->id, ($shift->duty ?? Shift::DUTY_POST) === Shift::DUTY_PATROL);

        if ($route->isEmpty()) {
            return null;
        }

        /*
         * The tour begins when the guard actually clocked in, not when the
         * shift was rostered. A guard who came on two hours late has not missed
         * the checkpoints from before they arrived, and counting those would
         * open every late handover on a tour that is already failing.
         */
        $since = $shift === null ? null : ($shift->actual_start ?? $shift->rostered_start);

        /*
         * WHEN EACH CHECKPOINT WAS REACHED, not when its scan synced (D-192, M21 §1.1): a round walked with no signal
         * reaches the server all at once when signal returns, and timing it by the sync would show a guard who was
         * late as on time.
         */
        $onThisTour = $scans
            ->filter(static fn (CheckpointScan $scan): bool => $route->contains('id', $scan->patrol_checkpoint_id)
                && ($since === null || $scan->reachedAt()->greaterThanOrEqualTo($since)))
            ->sortBy(static fn (CheckpointScan $scan): int => $scan->reachedAt()->getTimestamp())
            ->values();

        $total = $route->count();

        /*
         * ROUND BY ROUND (M21 §1.1). A shift walks the route many times. Progress is how far round THIS round the
         * guard is: once every checkpoint has been reached the round is complete and the next begins from nothing.
         * Counted over the whole shift instead, a guard who walked one round at 22:00 and then sat down read
         * "6 of 6" until morning, and was never late again.
         */
        $round = [];
        $rounds = 0;
        foreach ($onThisTour as $scan) {
            $round[$scan->patrol_checkpoint_id] = true;
            if (count($round) >= $total) {
                $rounds++;
                $round = [];
            }
        }
        $reached = count($round);
        $last = $onThisTour->last();

        /*
         * When the next scan was due.
         *
         * From the last scan, or from the start of the shift when the tour has
         * not begun at all — a guard who clocked in an hour ago and has scanned
         * nothing is exactly the case this screen exists to catch, and anchoring
         * only to the last scan would leave them permanently un-late. A complete
         * round does not stop the clock: the next round's first checkpoint is due
         * on the same cadence.
         */
        $anchor = $last === null ? $since : $last->reachedAt();
        $expected = $anchor?->copy()->addMinutes($cadence);

        $overdue = $expected !== null
            && $expected->copy()->addMinutes(PatrolCheckpoint::GRACE_MINUTES)->isPast();

        return [
            'label' => match (true) {
                $last === null => 'Tour not started',
                $reached === 0 => ($rounds === 1 ? 'Round 1' : $rounds.' rounds').' complete · '.$this->ago($last->reachedAt()),
                default => 'Checkpoint '.$reached.' of '.$total.($rounds > 0 ? ' · round '.($rounds + 1) : '').' · '.$this->ago($last->reachedAt()),
            },
            'dots' => $this->dots($reached, $total, $overdue),
            'reached' => $reached,
            'total' => $total,
            'minutes' => $last === null ? null : $this->minutesSince($last->reachedAt()),
            'expected' => $expected,
            'overdue' => $overdue,
        ];
    }

    /**
     * The board's tour dots: walked, the one that is late, and the ones still
     * ahead.
     *
     * Exactly one dot can be red. The checkpoints beyond the late one are not
     * missed — nobody was due at them yet — and colouring them red would report
     * one guard running late as four separate failures.
     *
     * @return list<array{class: string}>
     */
    private function dots(int $reached, int $total, bool $overdue): array
    {
        $dots = [];

        for ($i = 1; $i <= $total; $i++) {
            $dots[] = ['class' => match (true) {
                $i <= $reached => '',
                $i === $reached + 1 && $overdue => 'missed',
                default => 'pending',
            }];
        }

        return $dots;
    }

    /**
     * The most recent alertness challenge, and how it went.
     *
     * `declined` is reported like any other outcome. Refusing the camera check
     * degrades monitoring and is recorded as such — it never blocks duty, and a
     * screen that hid the refusal would hide the degradation with it.
     *
     * @param  Collection<int, AlertnessCheck>  $challenges
     * @return array{label: string, minutes: int}|null
     */
    private function lastChallenge(Collection $challenges): ?array
    {
        $check = $challenges->first();

        if ($check === null) {
            return null;
        }

        return [
            'label' => $this->ago($check->server_time).' · '.$check->outcomeLabel(),
            'minutes' => $this->minutesSince($check->server_time),
        ];
    }

    /**
     * Consecutive unanswered challenges, counted backwards from the latest.
     *
     * A STREAK, not a total. One missed prompt is a guard with their hands full;
     * three in a row is a guard who is not answering, and only the second is
     * worth waking somebody for. Counting backwards from the most recent is what
     * makes it a streak — a running total would keep last month's misses on the
     * board forever.
     *
     * @param  Collection<int, AlertnessCheck>  $challenges  newest first
     */
    private function missedChallengeStreak(Collection $challenges): int
    {
        $streak = 0;

        foreach ($challenges as $check) {
            if (! in_array($check->outcome, AlertnessCheck::UNANSWERED, true)) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * The state pill.
     *
     * Three states and no more, because a dispatcher glancing down this column
     * is deciding one thing: whether to pick up a radio. Someone is late,
     * someone has gone quiet, or everything is fine.
     *
     * @return array{0: string, 1: string}
     */
    private function state(Guard $guard, bool $overdue, ?int $verified, bool $patrol, int $cadence = PatrolCheckpoint::CADENCE_MINUTES): array
    {
        if ($overdue) {
            return ['Missed checkpoint', 'alert'];
        }

        if ($verified === null) {
            // On duty and has never reported. Amber rather than red: a handset
            // that has not checked in yet is a monitoring gap, not evidence
            // that anything has happened to the guard.
            return [$guard->deviceIsBound() ? 'No check yet' : 'No handset', 'stationary'];
        }

        $tolerance = $patrol
            ? $cadence + PatrolCheckpoint::GRACE_MINUTES
            : self::CHALLENGE_CADENCE_MINUTES + self::CHALLENGE_GRACE_MINUTES;

        return $verified <= $tolerance
            ? ['Active', 'active']
            : ['No recent check', 'stationary'];
    }

    /* -------------------------------------------------------------- banner */

    /**
     * The one thing being asked for right now, or nothing.
     *
     * Drawn ONLY when a guard is actually late. A red interruption bar that is
     * permanently on screen has stopped interrupting anybody, which is the
     * failure this banner exists to avoid.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>|null
     */
    private function banner(array $rows): ?array
    {
        foreach ($rows as $row) {
            if ($row['overdue'] !== true) {
                continue;
            }

            return [
                'title' => $row['name'].' — missed checkpoint',
                'meta' => (string) $row['banner_meta'],

                /*
                 * The board's label is "Contact guard", and the guard's own
                 * record is the only place on this platform that answers it: it
                 * carries their phone number, their post and their supervisor.
                 * There is no dispatch-to-one-guard message channel in this
                 * console — the Guard App owns that — so the button goes where
                 * the phone number is rather than pretending to send something.
                 */
                'href' => (string) $row['guard_href'],
                'label' => 'Contact guard',
            ];
        }

        /*
         * NO MISSED CHECKPOINT, BUT A PHONE GONE QUIET (M22 §1.1). Positions stopped reaching the control room: the
         * phone is off, out of signal and not sampling, signed out, or its on-duty sharing stopped by itself. The
         * officer's own phone is told as well (`tracking:watch`); the dispatcher is who can ring them.
         */
        foreach ($rows as $row) {
            if ($row['position_gap'] !== true) {
                continue;
            }

            return [
                'title' => $row['name'].' — no position received',
                'meta' => $this->join([(string) $row['post'], ucfirst((string) $row['position_since'])]),
                'href' => (string) $row['guard_href'],
                'label' => 'Contact guard',
            ];
        }

        return null;
    }

    /* ---------------------------------------------------------------- kpis */

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{icon: string, stroke: float, tone: string, value: string, label: string}>
     */
    private function kpis(array $rows): array
    {
        $verified = count(array_filter(
            $rows,
            static fn (array $row): bool => is_int($row['verified_minutes'])
                && $row['verified_minutes'] <= self::VERIFIED_MINUTES,
        ));

        $missed = count(array_filter($rows, static fn (array $row): bool => $row['overdue'] === true));
        $reporting = count(array_filter($rows, static fn (array $row): bool => $row['bound_and_reporting'] === true));

        /*
         * The WORST streak on the platform, not the sum of them.
         *
         * Three guards who have each missed one challenge are three separate
         * hiccups; one guard who has missed three in a row is a guard who is not
         * answering. Adding them together reports the first as the second.
         */
        $streak = 0;

        foreach ($rows as $row) {
            $streak = max($streak, (int) $row['missed_streak']);
        }

        return [
            [
                'icon' => 'check-ring',
                'stroke' => 1.6,
                'tone' => '',
                'value' => (string) $verified,
                'label' => 'Guards active, verified <'.self::VERIFIED_MINUTES.' min',
            ],
            [
                'icon' => 'alert',
                'stroke' => 1.6,
                'tone' => 'alert',
                'value' => (string) $missed,
                'label' => 'Missed checkpoint alerts',
            ],
            [
                'icon' => 'lock',
                'stroke' => 1.7,
                'tone' => '',
                'value' => (string) $reporting,
                'label' => 'Devices bound & reporting',
            ],
            [
                'icon' => 'plus',
                'stroke' => 1.9,
                'tone' => '',
                'value' => (string) $streak,
                'label' => 'Consecutive missed challenges, platform-wide',
            ],
        ];
    }

    /* ------------------------------------------------------------- queries */

    /**
     * The estates this viewer may watch, in the order the other two dispatch
     * screens list them.
     *
     * @return Collection<int, Tenant>
     */
    private function estates(User $viewer): Collection
    {
        return Tenant::estates(function ($query) use ($viewer): void {
            // Scope AND the demonstration estate, in one call (M5 §3). Silence
            // is the finding on this screen, and a demonstration officer who
            // has stopped scanning because nobody is holding the handset would
            // read as exactly the emergency this board exists to catch.
            OperatorEstates::narrow($query, $viewer, 'tenants.id');

            $query->orderBy('parish')->orderBy('name');
        });
    }

    /**
     * Guards whose rostered shift covers this moment.
     *
     * FROM THE ROSTER, not from `guards.post_id`. A standing assignment carries
     * no time at all, so reading it as "on duty now" would put every guard on
     * the platform onto this screen at four in the morning and bury the one who
     * has actually gone quiet.
     *
     * @param  list<mixed>  $estateIds
     * @return EloquentCollection<int, Guard>
     */
    private function onDutyGuards(array $estateIds): EloquentCollection
    {
        $guardIds = Shift::query()
            ->whereIn('tenant_id', $estateIds)
            ->where('rostered_start', '<=', now())
            ->where('rostered_end', '>', now())
            ->pluck('guard_id')
            ->unique()
            ->all();

        return Guard::query()
            ->with('post')
            ->whereIn('id', $guardIds)
            ->orderBy('full_name')
            ->get();
    }

    /**
     * The shift each of those guards is standing, keyed by guard — with its post (M27: whether it is a patrol is the
     * shift's), handed the posts already read with the guards, and reading only the ones that are not among them.
     *
     * @param  EloquentCollection<int, Guard>  $guards
     * @return Collection<int, Shift>
     */
    private function currentShifts(EloquentCollection $guards): Collection
    {
        $shifts = Shift::query()
            ->whereIn('guard_id', $guards->modelKeys())
            ->where('rostered_start', '<=', now())
            ->where('rostered_end', '>', now())
            ->orderBy('rostered_start')
            ->get()
            ->keyBy('guard_id');

        $known = $guards->pluck('post')->filter()->keyBy('id');
        $missing = $shifts->pluck('post_id')->filter(static fn ($id): bool => $id !== null && ! $known->has($id))->unique()->values()->all();
        $posts = $missing === [] ? $known : $known->union(Post::query()->whereIn('id', $missing)->get()->keyBy('id'));

        foreach ($shifts as $shift) {
            $shift->setRelation('post', $posts->get($shift->post_id));
        }

        return $shifts;
    }

    /**
     * Every active checkpoint at the estates where somebody is patrolling tonight (M27): the estate's own route and any
     * older route on a post, from which `tour()` picks each officer's by the shared rule. One read.
     *
     * @param  Collection<int, Guard>  $guards
     * @param  Collection<int, Shift>  $shifts  keyed by guard
     * @return Collection<int, PatrolCheckpoint>
     */
    private function checkpointsFor(Collection $guards, Collection $shifts): Collection
    {
        $estates = $guards
            ->filter(static function (Guard $guard) use ($shifts): bool {
                $shift = $shifts->get($guard->id);

                return $shift !== null ? $shift->isPatrol() : ($guard->post?->isPatrolRoute() ?? false);
            })
            ->map(static fn (Guard $guard): string => (string) $guard->tenant_id)
            ->unique()
            ->values()
            ->all();

        if ($estates === []) {
            return collect();
        }

        return PatrolCheckpoint::query()
            ->whereIn('tenant_id', $estates)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * Each estate's patrol interval, the Director's or the platform's (M27 §4.4).
     *
     * @param  list<mixed>  $estateIds
     * @return array<string, int>
     */
    private function cadences(array $estateIds): array
    {
        if ($estateIds === []) {
            return [];
        }

        return DB::connection('mysql')->table('estate_sites')->whereIn('tenant_id', $estateIds)->whereNotNull('patrol_interval_minutes')
            ->pluck('patrol_interval_minutes', 'tenant_id')
            ->map(static fn ($m): int => max(1, (int) $m))
            ->all();
    }

    /**
     * Today's scans by those guards on those checkpoints, grouped by guard.
     *
     * Bounded to the last day rather than to all history: a tour cannot be
     * longer than a shift, and an unbounded scan table is the one query on this
     * screen that would grow without limit.
     *
     * @param  list<int|string>  $guardIds
     * @param  Collection<int, PatrolCheckpoint>  $checkpoints
     * @return Collection<int|string, Collection<int, CheckpointScan>>
     */
    private function scansFor(array $guardIds, Collection $checkpoints): Collection
    {
        if ($guardIds === [] || $checkpoints->isEmpty()) {
            return collect();
        }

        return CheckpointScan::query()
            ->whereIn('guard_id', $guardIds)
            ->whereIn('patrol_checkpoint_id', $checkpoints->pluck('id')->all())
            ->where('server_time', '>=', now()->subDay())
            ->orderBy('server_time')
            ->get()
            // toBase() BEFORE grouping, not after. Grouping an Eloquent
            // collection yields Eloquent groups, and narrowing them afterwards
            // loses the element type with them; narrowing first keeps
            // CheckpointScan all the way down.
            ->toBase()
            ->groupBy('guard_id');
    }

    /**
     * Recent challenges for those guards, newest first, grouped by guard.
     *
     * @param  list<int|string>  $guardIds
     * @return Collection<int|string, Collection<int, AlertnessCheck>>
     */
    private function challengesFor(array $guardIds): Collection
    {
        if ($guardIds === []) {
            return collect();
        }

        return AlertnessCheck::query()
            ->whereIn('guard_id', $guardIds)
            ->where('server_time', '>=', now()->subDay())
            ->orderByDesc('server_time')
            ->get()
            ->toBase()
            ->groupBy('guard_id')
            ->map(static fn (Collection $checks): Collection => $checks->take(self::STREAK_DEPTH));
    }

    /* --------------------------------------------------------------- utils */

    /** Whole minutes since an instant, never negative. */
    private function minutesSince(Carbon $at): int
    {
        return max(0, (int) $at->diffInMinutes(now()));
    }

    /**
     * The board's phrasing for elapsed time.
     *
     * Minutes, because a dispatcher is judging a fifteen-minute cadence and
     * "an hour ago" is not a number they can compare against it. Hours appear
     * only once minutes have stopped being readable.
     */
    private function ago(Carbon $at): string
    {
        $minutes = $this->minutesSince($at);

        if ($minutes < 60) {
            return $minutes.' min ago';
        }

        return intdiv($minutes, 60).' hr '.($minutes % 60).' min ago';
    }

    /**
     * The board's meta separator, skipping anything absent.
     *
     * @param  array<int, string|null>  $parts
     */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
