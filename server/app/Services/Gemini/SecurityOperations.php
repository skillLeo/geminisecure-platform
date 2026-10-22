<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Models\User;
use App\Services\Estate\EstateSecurity;
use App\Services\Platform\OperatorEstates;
use App\Support\CalendarDate;
use App\Support\SiteTime;
use App\Support\SourceBadge;
use App\Support\TableQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cross-client operational picture — board screens super-admin-24 to 27.
 *
 * Four questions a security company asks about every client at once: who is
 * standing which post this week, which written orders are in force at each
 * post, what the posts are reporting, and what has gone wrong.
 *
 * EVERY QUERY HERE READS gs_platform AND NOTHING ELSE. Guards, posts, shifts
 * and standing orders belong to Gemini Security Limited, not to an estate, so
 * they are central by construction. An estate database is never opened, and
 * where a screen wants a fact that only an estate holds, the screen says so
 * rather than reaching for it — see gateActivity() below, which is the whole
 * of that argument.
 *
 * SCOPE IS APPLIED IN SQL, NEVER IN THE RENDER. A Head of Security is scoped
 * to assigned sites, and both cross-client screens must therefore narrow the
 * QUERY: filtering rows out of a rendered list would leave every other estate
 * reachable by paging, sorting or deep-linking. ClientDirectory sets the
 * pattern and this follows it.
 */
class SecurityOperations
{
    /**
     * The cross-client roster — board screen super-admin-24: THE WEEK'S SHIFTS (3 Oct). A guard week runs Monday to
     * Sunday here rather than following the viewer's locale, so two people reading the same roster see the same day
     * under the same heading.
     *
     * WHAT A ROW AND A CELL ARE. A row is a post, under its client, named as the estate names it ("Back Gate (Gate 5,
     * Phase 5 Gate)"). A column is one day of the week, WITH ITS DATE. A cell holds every shift that STARTS at that post
     * on that day on the estate's own clock: its window, and the officer on it or "Open". A day with nothing posted
     * says so.
     *
     * WHY SHIFTS, NOT THE STANDING PATTERN. This grid used to draw each officer's usual post — `guards.post_id` — the
     * same name in all seven columns, one line per officer. People read it as a schedule, and it is not one: no date,
     * no time, and a post with five officers posted to it read as five officers standing it every day. The shifts are
     * what an officer's phone clocks in to, so they are the roster, and they are what is drawn. An officer's usual post
     * is still on their record and in the Directory.
     *
     * AN OFFICER WHO CANNOT STAND THE SHIFT IS A GAP. A shift whose officer has an expired licence, is suspended or has
     * left reads Open, with the reason on it: drawing coverage that legally does not exist is the one thing this screen
     * must not do.
     *
     * Four reads, whatever the week holds: the posts with their clients, the week's shifts with their officers, and the
     * two counts above the grid.
     *
     * @param  string|null  $date  any day of the week to show (`YYYY-MM-DD`); this week when it is not a real day
     * @return array<string, mixed>
     */
    public function roster(User $viewer, ?string $date = null): array
    {
        $asked = $date === null ? null : CalendarDate::from(substr(trim($date), 0, 10));
        $monday = Carbon::parse($asked ?? SiteTime::today()->toDateString())->startOfDay()->startOfWeek(Carbon::MONDAY);
        $dates = array_map(static fn (int $i): string => $monday->copy()->addDays($i)->toDateString(), range(0, 6));

        $posts = $this->scoped($viewer, DB::connection('mysql')
            ->table('posts')
            ->join('tenants', 'tenants.id', '=', 'posts.tenant_id')
            ->where('posts.is_active', true)
            ->select([
                'posts.id',
                'posts.type',
                'posts.tenant_id',
                // As the estate names it, "Back Gate (Gate 5, Phase 5 Gate)" (M26).
                DB::raw(Post::labelSql().' as post_name'),
                'tenants.name as estate_name',
            ])
            ->orderByRaw("CASE WHEN tenants.status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('tenants.name')
            ->orderByRaw("CASE WHEN posts.type = 'patrol' THEN 1 ELSE 0 END")
            ->orderBy('posts.name'), 'posts.tenant_id')
            ->get();

        /*
         * A day either side of the week, then each shift kept on the day it starts ON ITS OWN ESTATE'S CLOCK (the cast
         * reads `rostered_start` in the estate's zone) — the same rule as the day view below the grid.
         */
        $shifts = Shift::query()
            ->leftJoin('guards', 'guards.id', '=', 'shifts.guard_id')
            ->whereIn('shifts.post_id', $posts->pluck('id')->all())
            ->whereNotIn('shifts.status', Shift::SETTLED)
            ->whereBetween('shifts.rostered_start', [$monday->copy()->subDay(), $monday->copy()->addDays(8)])
            ->orderBy('shifts.rostered_start')
            ->select(['shifts.*', 'guards.full_name as officer_name', 'guards.status as officer_status'])
            ->get();

        $cells = [];
        $open = 0;

        foreach ($shifts as $shift) {
            $day = $shift->rostered_start->toDateString();

            if (! in_array($day, $dates, true)) {
                continue;
            }

            $cell = $this->shiftCell($shift);
            $open += $cell['tone'] === 'open' ? 1 : 0;
            $cells[(int) $shift->post_id][$day][] = $cell;
        }

        $groups = [];

        foreach ($posts as $post) {
            $estate = (string) $post->tenant_id;

            $groups[$estate] ??= ['id' => $estate, 'name' => (string) $post->estate_name, 'lines' => []];
            $groups[$estate]['lines'][] = [
                'key' => $estate.'-'.$post->id,
                'post' => (string) $post->post_name,
                'patrol' => $post->type === 'patrol',
                'cells' => array_map(static fn (string $day): array => $cells[(int) $post->id][$day] ?? [], $dates),
            ];
        }

        $onDuty = $this->scoped($viewer, DB::connection('mysql')->table('shifts')
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->whereNotIn('status', Shift::SETTLED), 'shifts.tenant_id')
            ->count();

        return [
            // "Mon 29 Sep": the date under every heading, so the week can never be read as a different one.
            'days' => array_map(static fn (string $day): string => Carbon::parse($day)->format('D j M'), $dates),
            'week' => $monday->format('M j').' – '.$monday->copy()->addDays(6)->format('M j, Y'),
            'weekDates' => $dates,
            'previousWeek' => $monday->copy()->subWeek()->toDateString(),
            'nextWeek' => $monday->copy()->addWeek()->toDateString(),
            'thisWeek' => in_array(SiteTime::today()->toDateString(), $dates, true),
            'groups' => array_values($groups),
            'kpis' => $this->rosterKpis($onDuty, $shifts->filter(static fn (Shift $s): bool => in_array($s->rostered_start->toDateString(), $dates, true))->count(), $open, $posts->count()),
            'scoped' => $this->isScoped($viewer),
        ];
    }

    /**
     * One shift in a day's cell: its window on the estate's clock, and who is on it — or "Open", with why.
     *
     * @return array{id: int, time: string, label: string, tone: string, title: string}
     */
    private function shiftCell(Shift $shift): array
    {
        $patrol = ($shift->duty ?? Shift::DUTY_POST) === Shift::DUTY_PATROL;
        $time = ($patrol ? 'Patrol · ' : '').$shift->rostered_start->format('g:i A').' – '.$shift->rostered_end->format('g:i A').SiteTime::label($shift->rostered_end);
        $name = $shift->getAttribute('officer_name');

        if ($name === null) {
            return [
                'id' => (int) $shift->id,
                'time' => $time,
                'label' => 'Open',
                'tone' => 'open',
                'title' => 'Open: nobody is on this shift yet.'.($shift->released_reason ? ' Why: '.$shift->released_reason : '').' Open the day to assign somebody.',
            ];
        }

        $unfit = $this->unfit((string) $name, (string) $shift->getAttribute('officer_status'));

        if ($unfit !== null) {
            return ['id' => (int) $shift->id, 'time' => $time, 'label' => 'Open', 'tone' => 'open', 'title' => $unfit];
        }

        $onDuty = $shift->actual_start !== null && $shift->actual_end === null;

        return [
            'id' => (int) $shift->id,
            'time' => $time,
            'label' => $this->shortName((string) $name).($onDuty ? ' · on duty' : ''),
            'tone' => $patrol ? 'night' : 'day',
            'title' => $name.', '.$time.($onDuty ? ', clocked in now' : ''),
        ];
    }

    /**
     * Why the officer on a shift cannot stand it, or null when they can.
     *
     * `licence_expired` is a HARD BLOCK rather than a warning: the spec sets an expired PSRA licence to un-rosterable,
     * and a rota that quietly showed that officer covering the shift would be the platform asserting coverage that
     * legally does not exist. The reason travels with the cell rather than being reduced to a colour, because "nobody is
     * on it" and "the officer on it has a lapsed licence" are the same chip and different problems to fix.
     */
    private function unfit(string $name, string $status): ?string
    {
        return match ($status) {
            'licence_expired' => $name.' is on this shift but cannot stand it: their PSRA licence has expired.',
            'on_leave' => $name.' is on this shift but is marked on leave.',
            'suspended' => $name.' is on this shift but is suspended from active duty.',
            'inactive' => $name.' is on this shift but is no longer an active employee.',
            default => null,
        };
    }

    /**
     * The four cards above the grid, each a fact about shifts rather than about who is posted where.
     *
     * @return list<array{icon: string, stroke: float, value: string, label: string, warn: bool, title: string}>
     */
    private function rosterKpis(int $onDuty, int $shifts, int $open, int $posts): array
    {
        return [
            [
                'icon' => 'guards',
                'stroke' => 1.7,
                'value' => (string) $onDuty,
                'label' => 'On duty now, platform-wide',
                'warn' => false,
                'title' => 'Officers clocked in to a shift right now, and not yet clocked out.',
            ],
            [
                'icon' => 'clock',
                'stroke' => 1.7,
                'value' => (string) $shifts,
                'label' => $shifts === 1 ? 'Shift this week' : 'Shifts this week',
                'warn' => false,
                'title' => 'Every shift on the roster that starts in the week shown.',
            ],
            [
                'icon' => 'alert',
                'stroke' => 1.6,
                'value' => (string) $open,
                'label' => $open === 1 ? 'Open shift this week' : 'Open shifts this week',
                'warn' => $open > 0,
                'title' => 'Shifts in the week shown with nobody on them, or with an officer who cannot stand them.',
            ],
            [
                'icon' => 'check-circle',
                'stroke' => 1.6,
                'value' => (string) $posts,
                'label' => 'Posts, platform-wide',
                'warn' => false,
                'title' => 'Every active guard post, whether or not anything is rostered at it.',
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* scope */
    /* ------------------------------------------------------------------ */

    /**
     * The written orders in force at every post — board screen 25.
     *
     * Two kinds of set, and the difference is the whole screen. A MASTER
     * TEMPLATE applies to every post at every client and is acknowledged by
     * nobody in particular; a POST-SPECIFIC set belongs to one gate and has to
     * be acknowledged by the guard standing it. An unacknowledged post-specific
     * set is a guard working to orders they have not read, which is why the
     * board gives it a red badge and why that state is computed rather than
     * stored.
     *
     * @return array<string, mixed>
     */
    public function standingOrders(User $viewer): array
    {
        $query = DB::connection('mysql')
            ->table('standing_order_sets')
            ->leftJoin('tenants', 'tenants.id', '=', 'standing_order_sets.tenant_id')
            ->leftJoin('posts', 'posts.id', '=', 'standing_order_sets.post_id')
            ->orderByRaw('standing_order_sets.tenant_id IS NOT NULL')
            ->orderBy('tenants.name')
            ->orderBy('posts.name')
            ->select([
                'standing_order_sets.id',
                'standing_order_sets.title',
                'standing_order_sets.category',
                'standing_order_sets.summary',
                'standing_order_sets.version',
                'standing_order_sets.reviewed_on',
                'standing_order_sets.tenant_id',
                'standing_order_sets.post_id',
                'tenants.name as estate',
                DB::raw(Post::labelSql().' as post'),
            ]);

        /*
         * A master template has no tenant_id and belongs to everyone, so a
         * scoped viewer keeps it. Scoping it away would hide the company's own
         * general orders from the person enforcing them.
         */
        if ($this->isScoped($viewer)) {
            $ids = $viewer->accessibleEstateIds();
            $query->where(function (Builder $q) use ($ids): void {
                $q->whereNull('standing_order_sets.tenant_id')
                    ->orWhereIn('standing_order_sets.tenant_id', $ids);
            });
        }

        /*
         * And not the demonstration gate's orders (M5 §3). `demo:seed` writes a
         * POST-SPECIFIC set there — deliberately, so the Guard App's orders
         * screen and its "orders to acknowledge" row are not empty for a
         * reviewer — and this library listed it beside the orders real officers
         * are held to, with the red "not acknowledged" badge that means somebody
         * should go and chase it.
         *
         * `OperatorEstates::exclude()` keeps a NULL `tenant_id`, which is what
         * makes it the right tool here rather than a bare NOT IN: a master
         * template belongs to everyone and must survive, for the same reason the
         * scope above lets it through.
         */
        OperatorEstates::exclude($query, 'standing_order_sets.tenant_id');

        $sets = $query->get();

        $acknowledgements = DB::connection('mysql')
            ->table('standing_order_acknowledgements')
            ->join('guards', 'guards.id', '=', 'standing_order_acknowledgements.guard_id')
            ->whereIn('standing_order_set_id', $sets->pluck('id'))
            ->orderByDesc('acknowledged_at')
            ->select([
                'standing_order_acknowledgements.standing_order_set_id as set_id',
                'standing_order_acknowledgements.version',
                'standing_order_acknowledgements.acknowledged_at',
                'guards.full_name',
                'guards.tenant_id',
            ])
            ->get()
            ->groupBy('set_id');

        /*
         * Which posts have somebody who can LEGALLY stand them, so an order set
         * nobody has acknowledged can be told apart from one at a post nobody
         * can be assigned to.
         *
         * An expired PSRA licence is a hard block, not a warning: Devon Palmer
         * is posted at Phoenix Park's Service Gate and cannot be rostered
         * there, which is why the board's badge for that set reads "Unassigned
         * post" rather than "not yet acknowledged". Counting him as staffed
         * would turn a gate nobody can stand into a guard who has not got round
         * to reading the orders.
         */
        $staffed = DB::connection('mysql')
            ->table('guards')
            ->whereNotNull('post_id')
            ->where('status', 'active')
            // Still licensed on the officer's own site calendar, not the server's UTC date (B008).
            ->where(function (Builder $licence): void {
                $licence->whereNull('psra_expires_on')
                    ->orWhere(static fn (Builder $good) => Guard::applyLicenceDaysLeft($good, '>=', 0));
            })
            ->pluck('post_id')
            ->flip();

        return [
            'sets' => $sets->map(function (object $set) use ($acknowledgements, $staffed): array {
                $master = $set->tenant_id === null;
                $ack = $acknowledgements->get($set->id, collect())
                    ->firstWhere('version', $set->version);

                return [
                    'id' => (int) $set->id,
                    /*
                     * The board draws three glyphs and means three things by
                     * them: a ledger for the company's own general orders, a
                     * speaker for the emergency protocols that get broadcast,
                     * and a shield for a set that belongs to one gate.
                     */
                    'icon' => $master ? ($set->category === 'emergency' ? 'broadcast' : 'ledger') : 'shield',
                    'name' => $master
                        ? (string) $set->title
                        : sprintf('%s — %s', $set->estate, $set->post),
                    'meta' => $this->orderMeta($set, $master, $ack, $staffed),
                    'badge' => $master ? 'Master template' : ($ack === null && ! $staffed->has($set->post_id) ? 'Unassigned post' : 'Active'),
                    'variant' => $master
                        ? 'template'
                        : ($ack === null && ! $staffed->has($set->post_id) ? 'unassigned' : 'active'),
                ];
            })->all(),
        ];
    }

    /**
     * The line under a set's name.
     *
     * Three different sentences for three different facts: a template says
     * where it applies, an acknowledged set says who read it and when, and an
     * unacknowledged one says why nobody has.
     *
     * @param  Collection<int, int>  $staffed
     */
    private function orderMeta(object $set, bool $master, ?object $ack, $staffed): string
    {
        if ($master) {
            return sprintf(
                '%s · Version %d · Reviewed %s',
                $set->summary ?? 'Applied to every post at every client',
                (int) $set->version,
                $set->reviewed_on === null ? 'not yet' : Carbon::parse((string) $set->reviewed_on)->format('M j, Y'),
            );
        }

        if ($ack !== null) {
            return sprintf(
                'Post-specific orders · Version %d · Acknowledged by %s, %s',
                (int) $set->version,
                $ack->full_name,
                // The day at the guard's estate: the column is UTC, and an evening's acknowledgement read as the next day.
                SiteTime::local(Carbon::parse((string) $ack->acknowledged_at, 'UTC'), $ack->tenant_id === null ? null : (string) $ack->tenant_id)->format('M j'),
            );
        }

        return sprintf(
            'Post-specific orders · Version %d · %s',
            (int) $set->version,
            $staffed->has($set->post_id)
                ? 'Not yet acknowledged by the guard on post'
                : 'No guard currently assigned to acknowledge',
        );
    }

    /**
     * How many rows the feed carries.
     *
     * The board's own figure. It is a live surface a dispatcher glances at, not
     * a log they read — seven rows is what fits above the fold, and a feed that
     * scrolled would put the most recent event out of sight the moment the next
     * one landed.
     */
    private const FEED_ROWS = 7;

    /**
     * What the gates are reporting, across every client — board screen 26.
     *
     * READ CENTRALLY, which is the only way this screen can exist. A
     * cross-client feed built by opening each estate's own database in turn
     * would be a tenant-isolation breach wearing a report's clothes.
     *
     * THREE SOURCES, MERGED, and deliberately not one. A gate DECISION has no
     * central home, so `gate_events` holds it. A checkpoint scan and a shift
     * start already have one — `checkpoint_scans` and `shifts`, written by the
     * screens that own them — and copying those into a fourth table so this
     * feed could be a single query would create two records of one event that
     * are free to disagree. Each fact is read from the table that owns it.
     *
     * Every branch is indexed and capped at FEED_ROWS before the merge, so the
     * five-second poll costs three small reads rather than three table scans.
     *
     * @return array<string, mixed>
     */
    public function gateActivity(User $viewer): array
    {
        $feed = array_merge(
            $this->gateDecisions($viewer),
            $this->checkpointScans($viewer),
            $this->shiftStarts($viewer),
        );

        usort($feed, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        $today = $this->scoped(
            $viewer,
            DB::connection('mysql')->table('gate_events'),
            'tenant_id',
        )
            // "Today" is the operations room's day on the site clock, as two UTC moments (M4 §3.1).
            ->whereBetween('occurred_at', SiteTime::dayBounds())
            ->selectRaw("SUM(verdict = 'admit') as admits, SUM(verdict = 'deny') as denies")
            ->first();

        /*
         * ON POST means standing one right now, not assigned to one on paper.
         *
         * Counting `guards.post_id` would include Devon Palmer, whose licence
         * has lapsed and who is therefore not rostered anywhere — and the
         * coverage board two screens away says his gate is uncovered. Two
         * screens disagreeing about whether a post is manned is worse than
         * either number on its own.
         */
        $onPost = $this->scoped(
            $viewer,
            DB::connection('mysql')->table('shifts'),
            'tenant_id',
        )
            ->where('status', 'active')
            ->whereNotNull('actual_start')
            ->distinct()
            ->count('guard_id');

        $duress = $this->scoped(
            $viewer,
            DB::connection('mysql')->table('duress_alerts'),
            'tenant_id',
        )
            ->whereNull('resolved_at')
            ->count();

        return [
            'kpis' => [
                ['key' => 'on_post', 'icon' => 'guards', 'value' => (string) $onPost, 'label' => 'Guards on post, platform-wide'],
                ['key' => 'admits', 'icon' => 'check', 'value' => (string) (int) ($today->admits ?? 0), 'label' => 'Admits today, all clients'],
                ['key' => 'denies', 'icon' => 'close', 'value' => (string) (int) ($today->denies ?? 0), 'label' => 'Denied today, all clients'],
                ['key' => 'duress', 'icon' => 'shield', 'value' => (string) $duress, 'label' => 'Active duress alerts'],
            ],
            'feed' => array_map(
                static fn (array $row): array => Arr::except($row, ['at', 'is_simulated']),
                array_slice($feed, 0, self::FEED_ROWS),
            ),

            /*
             * Every row in this feed is something a handset did — a gate
             * decision, a checkpoint scan, a shift starting — so the screen says
             * whether a real device produced any of it. Asked of the rows on
             * screen rather than of the table: this feed is the most recent
             * handful, and a simulated event from a fortnight ago is not what a
             * reviewer is looking at.
             */
            'sourceBadge' => SourceBadge::guard(
                SourceBadge::anySimulated(array_slice($feed, 0, self::FEED_ROWS)),
            ),
        ];
    }

    /**
     * Admits and denials — the only branch `gate_events` owns.
     *
     * @return list<array<string, mixed>>
     */
    private function gateDecisions(User $viewer): array
    {
        return $this->scoped(
            $viewer,
            DB::connection('mysql')
                ->table('gate_events')
                ->join('tenants', 'tenants.id', '=', 'gate_events.tenant_id'),
            'gate_events.tenant_id',
        )
            ->orderByDesc('gate_events.occurred_at')
            ->limit(self::FEED_ROWS)
            ->select([
                'gate_events.verdict',
                'gate_events.subject',
                'gate_events.basis',
                'gate_events.guard_name',
                'gate_events.post_name',
                'gate_events.occurred_at',
                'gate_events.is_simulated',
                'tenants.name as estate',
                'tenants.timezone as zone',
            ])
            ->get()
            ->map(fn (object $row): array => $this->feedRow(
                // An override is a guard admitting somebody against standing
                // orders. It is still an admission, and the board has no fourth
                // colour, so it is drawn as one — and its basis says what it was.
                $row->verdict === 'deny' ? 'deny' : 'admit',
                // Who, then why: "Kimberly Douglas: Pass verified · Lot 18". A colon, never an em dash (M53 Law 3).
                $row->basis === null
                    ? (string) $row->subject
                    : sprintf('%s: %s', $row->subject, $row->basis),
                $this->joinDetail([$row->post_name, $row->guard_name]),
                (string) $row->estate,
                Carbon::parse((string) $row->occurred_at, 'UTC'),
                (bool) $row->is_simulated,
                $row->zone,
            ))
            ->all();
    }

    /**
     * Patrol checkpoints reached.
     *
     * Joined to the estate through the checkpoint, because `checkpoint_scans`
     * holds no tenant of its own — a scan belongs to a checkpoint, and the
     * checkpoint belongs to a post at an estate.
     *
     * @return list<array<string, mixed>>
     */
    private function checkpointScans(User $viewer): array
    {
        return $this->scoped(
            $viewer,
            DB::connection('mysql')
                ->table('checkpoint_scans')
                ->join('patrol_checkpoints', 'patrol_checkpoints.id', '=', 'checkpoint_scans.patrol_checkpoint_id')
                ->join('guards', 'guards.id', '=', 'checkpoint_scans.guard_id')
                ->join('tenants', 'tenants.id', '=', 'patrol_checkpoints.tenant_id')
                ->leftJoin('posts', 'posts.id', '=', 'patrol_checkpoints.post_id'),
            'patrol_checkpoints.tenant_id',
        )
            ->orderByDesc('checkpoint_scans.server_time')
            ->limit(self::FEED_ROWS)
            ->select([
                'patrol_checkpoints.label',
                'guards.full_name',
                DB::raw(Post::labelSql().' as post'),
                'checkpoint_scans.server_time',
                'tenants.name as estate',
                'tenants.timezone as zone',
            ])
            ->get()
            ->map(fn (object $row): array => $this->feedRow(
                'patrol',
                sprintf('Checkpoint scanned: %s', $row->label),
                $this->joinDetail([$row->post, $row->full_name]),
                (string) $row->estate,
                Carbon::parse((string) $row->server_time, 'UTC'),
                false,
                $row->zone,
            ))
            ->all();
    }

    /**
     * Shifts that actually started.
     *
     * `actual_start`, never `rostered_start`. A roster is an intention; this
     * feed reports what happened, and a post that stood empty for two hours is
     * exactly the thing a rostered time would hide.
     *
     * The geofence line comes from `shifts` rather than from a copy of it,
     * which is the whole reason this branch exists instead of a fourth column
     * on `gate_events`.
     *
     * @return list<array<string, mixed>>
     */
    private function shiftStarts(User $viewer): array
    {
        return $this->scoped(
            $viewer,
            DB::connection('mysql')
                ->table('shifts')
                ->join('guards', 'guards.id', '=', 'shifts.guard_id')
                ->join('posts', 'posts.id', '=', 'shifts.post_id')
                ->join('tenants', 'tenants.id', '=', 'shifts.tenant_id'),
            'shifts.tenant_id',
        )
            ->whereNotNull('shifts.actual_start')
            ->orderByDesc('shifts.actual_start')
            ->limit(self::FEED_ROWS)
            ->select([
                'shifts.actual_start',
                'shifts.rostered_start',
                'shifts.geofence_distance_m',
                'shifts.mock_location_flag',
                'guards.full_name',
                DB::raw(Post::labelSql().' as post'),
                'tenants.name as estate',
                'tenants.timezone as zone',
            ])
            ->get()
            ->map(fn (object $row): array => $this->feedRow(
                'patrol',
                sprintf(
                    'Shift clocked in: %s, %s',
                    $row->post,
                    // The hour ON THE ESTATE'S CLOCK — the stored time is UTC (M4 §3.1).
                    Carbon::parse((string) $row->rostered_start, 'UTC')->setTimezone(SiteTime::valid($row->zone))->hour < 12 ? 'Day Shift' : 'Night Shift',
                ),
                $this->joinDetail([$row->full_name, $this->geofenceNote($row)]),
                (string) $row->estate,
                Carbon::parse((string) $row->actual_start, 'UTC'),
                false,
                $row->zone,
            ))
            ->all();
    }

    /**
     * What the clock-in proved about where it happened.
     *
     * A DISTANCE, NOT A COORDINATE. `geofence_distance_m` is how far outside the
     * post's fence the handset was; it cannot be turned back into a position,
     * which is why the platform stores it and not the fix it came from. A
     * reported mock location outranks the distance — a spoofed handset can
     * report nought metres from anywhere on earth.
     */
    private function geofenceNote(object $shift): string
    {
        if ($shift->mock_location_flag) {
            return 'mock location reported';
        }

        return $shift->geofence_distance_m === null
            ? 'geofence not verified'
            : 'geofence verified';
    }

    /**
     * One row of the feed.
     *
     * `at` is the sort key and is dropped before the response leaves. It is a
     * Carbon rather than a formatted string because "9:40 AM" sorts before
     * "2:14 PM", and a live feed sorted alphabetically would put the afternoon
     * under the morning.
     *
     * `is_simulated` is dropped with it, and is here for the same kind of
     * reason: the screen's source badge has to be computed from the rows that
     * actually made the cut, and a shift start carries no such flag at all —
     * `shifts` has no column for it — so a row that cannot say is treated as
     * real rather than assumed simulated.
     *
     * @return array<string, mixed>
     */
    private function feedRow(
        string $verdict,
        string $headline,
        string $detail,
        string $estate,
        Carbon $at,
        bool $isSimulated = false,
        ?string $zone = null,
    ): array {
        return [
            'verdict' => $verdict,
            'headline' => $headline,
            'detail' => $detail,
            'estate' => $estate,

            // Printed on the clock of the estate it happened at, named where that is not Gemini's own
            // (M11 §4.1); `at` stays the moment, for the sort.
            'time' => SiteTime::stampIn($at, $zone, 'g:i A'),
            'at' => $at,
            'is_simulated' => $isSimulated,
        ];
    }

    /**
     * The board's separator, skipping anything absent.
     *
     * @param  array<int, string|null>  $parts
     */
    private function joinDetail(array $parts): string
    {
        return implode(' · ', array_filter(
            $parts,
            static fn (?string $part): bool => $part !== null && $part !== '',
        ));
    }

    /**
     * What has gone wrong on a Gemini post — board screen 27.
     *
     * @return array<string, mixed>
     */
    public function incidents(User $viewer, ?TableQuery $table = null): array
    {
        $table ??= self::incidentsTable();

        $query = $this->scoped(
            $viewer,
            DB::connection('mysql')
                ->table('security_incidents')
                ->join('tenants', 'tenants.id', '=', 'security_incidents.tenant_id'),
            'security_incidents.tenant_id',
        )
            ->select([
                'security_incidents.id',
                'security_incidents.kind',
                'security_incidents.severity',
                'security_incidents.status',
                'security_incidents.occurred_at',
                'security_incidents.guard_name',
                'tenants.name as estate',
                'tenants.timezone as zone',
            ]);

        // M32 F5: newest first unless a heading asks otherwise, a page at a time, ordered over every incident.
        $table->order($query, 'security_incidents.id');
        $page = $table->paginateRows($query);
        $rows = $page->getCollection();

        /*
         * Duress is counted, not listed. It is a life-safety path with its own
         * table and its own screen; the board draws the count here as the
         * reassurance it is, and folding those alerts into an incident table
         * would put a panic button behind case management.
         */
        $duress = $this->scoped(
            $viewer,
            DB::connection('mysql')->table('duress_alerts'),
            'tenant_id',
        )->count();

        return [
            'incidents' => $rows->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'date' => Carbon::parse((string) $row->occurred_at, 'UTC')->setTimezone(SiteTime::valid($row->zone))->format('M j, Y'),
                'estate' => (string) $row->estate,
                // In words, never the stored code ("property" reads Estate property), as the estate's own page reads it.
                'kind' => EstateSecurity::incidentKindLabel((string) $row->kind),
                'guard' => $row->guard_name ?? 'Not recorded',
                'severity' => (string) $row->severity,
                'severity_label' => match ((string) $row->severity) {
                    'med' => 'Medium',
                    'high' => 'High',
                    default => 'Low',
                },
                'status' => $row->status === 'resolved' ? 'closed' : 'open',
                // The vocabulary's words (M98 §3.5): an incident nobody has closed waits on somebody, so Action required.
                'status_label' => $row->status === 'resolved' ? 'Resolved' : 'Action required',
            ])->values()->all(),
            'table' => $table->meta($page),
            'duress_count' => $duress,
        ];
    }

    /**
     * What the incident table orders by (M32 F5): when it happened (newest first, its default), where, what, who
     * reported it, how severe (the ladder's own order) and whether it is open.
     *
     * @return array<string, string|\Closure>
     */
    public static function incidentSorts(): array
    {
        return [
            'date' => 'security_incidents.occurred_at',
            'estate' => 'tenants.name',
            'kind' => 'security_incidents.kind',
            'guard' => 'security_incidents.guard_name',
            'severity' => static fn ($query, string $dir) => $query->orderByRaw("FIELD(security_incidents.severity, 'low', 'med', 'high') ".$dir),
            'status' => 'security_incidents.status',
        ];
    }

    public static function incidentsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::incidentSorts(), 'date', 'desc');
    }

    /**
     * Narrow a query to the estates this viewer may see.
     *
     * In the QUERY. A Head of Security scoped to assigned sites must not be
     * able to reach an estate outside that scope by any route the screen
     * offers, and the only way to guarantee that is for the rows never to be
     * selected. An empty assignment list selects nothing, which is the correct
     * answer for a scoped account with no sites yet — not everything.
     */
    private function scoped(User $viewer, Builder $query, string $column): Builder
    {
        /*
         * AND the demonstration estate, in the same call (M5 §3).
         *
         * The figures this narrows are the ones an operations manager reads as
         * the state of the country: "Admits today, all clients", "Guards on
         * post, platform-wide", "Active duress alerts". A reviewer walking
         * through the Guard App writes into every one of those tables — gate
         * events, checkpoint scans, a clocked-on shift, an incident — so the
         * platform-wide figures moved whenever somebody at Apple opened the app.
         */
        OperatorEstates::narrow($query, $viewer, $column);

        return $query;
    }

    private function isScoped(User $viewer): bool
    {
        return $viewer->widestScope() === AccessScope::AssignedSites;
    }

    /* ------------------------------------------------------------------ */
    /* small formatters */
    /* ------------------------------------------------------------------ */

    /** "Marcus Whyte" -> "M. Whyte", which is what fits a rota cell. */
    private function shortName(string $name): string
    {
        $parts = array_values(array_filter(explode(' ', $name)));

        if (count($parts) < 2) {
            return $name;
        }

        return mb_substr((string) $parts[0], 0, 1).'. '.$parts[count($parts) - 1];
    }
}
