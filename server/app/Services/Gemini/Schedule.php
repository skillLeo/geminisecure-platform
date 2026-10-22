<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Post;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Support\CalendarDate;
use App\Support\SiteTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE SCHEDULE (M30 §4): one client's week of shifts, as the person planning it reads a week.
 *
 * Rows are the client's posts, gates first and then the places a patrol starts, each in name order, with the name the
 * estate also uses beneath it. Columns are the seven days of the week, Monday first, each with its date. A cell holds
 * every shift that STARTS at that post on that day, stacked: its time, and the guard on it or "Needs a guard". A
 * shift that has ended is drawn quietly; one under way says so.
 *
 * WHOSE CLOCK. The client's, always, unless the viewer switched the console to "My time" (M19A §7, the header's
 * switch): then every shift is drawn, and falls in the day, on the viewer's own clock. The data never moves; only
 * the clock it is read on. Every absolute time carries its clock's tag ("6 AM – 6 PM JA").
 *
 * This is the classic roster's information rebuilt around the week, at a new address, so the client can compare the
 * two. The classic roster (`SecurityOperations::roster()`, `Roster::day()`) is not touched.
 */
final class Schedule
{
    /** Post types in the order their rows are drawn: gates, then reliefs, then the older patrol posts. */
    private const ROW_ORDER = [Post::GATE => 0, 'relief' => 1, Post::PATROL => 2];

    /**
     * Everything the schedule draws for one client and one week.
     *
     * @param  string|null  $client  the client asked for; the first real client the viewer may see when it is not one
     * @param  string|null  $week  any day of the week asked for (`YYYY-MM-DD`); this week when it is not a real day
     * @return array<string, mixed>
     */
    public function board(User $viewer, ?string $client, ?string $week): array
    {
        $clients = $this->clients($viewer);
        $chosen = $this->choose($clients, $client);

        if ($chosen === null) {
            return ['clients' => $clients, 'client' => null];
        }

        // THE WEEK AND ITS DAYS ARE ALWAYS THE CLIENT'S (§4.5, M31 B1.5): My time re-draws the times on the blocks at the
        // viewer's clock, and never moves a shift to another day. A Monday night shift in Jamaica stays on Monday.
        $estateZone = SiteTime::zone($chosen['id']);
        $zone = SiteTime::viewerZone() ?? $estateZone;

        $today = Carbon::now($estateZone)->startOfDay();
        $asked = $week === null ? null : CalendarDate::from(substr(trim($week), 0, 10));
        $monday = ($asked !== null ? Carbon::parse($asked, $estateZone) : $today->copy())->startOfDay()->startOfWeek(Carbon::MONDAY);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->copy()->addDays($i);
            $days[] = [
                'date' => $day->toDateString(),
                'weekday' => mb_strtoupper($day->format('D')),
                'day' => $day->format('j'),
                'month' => $day->format('M'),
                'today' => $day->isSameDay($today),
            ];
        }

        $posts = $this->posts($chosen['id']);
        $shifts = $this->shifts($chosen['id'], $posts->pluck('id')->all(), $monday->copy()->utc(), $monday->copy()->addWeek()->utc());
        $asks = $this->asks($shifts->whereNull('guard_id')->pluck('id')->all());

        // A shift is in the day it STARTS at the client, on either clock — by its date, which a clock change cannot shift.
        $dayIndex = array_flip(array_column($days, 'date'));
        $cells = [];
        foreach ($shifts as $shift) {
            $index = $dayIndex[$shift->rostered_start->copy()->setTimezone($estateZone)->toDateString()] ?? null;

            if ($index === null) {
                continue;
            }

            $cells[(int) $shift->post_id][$index][] = $this->block($shift, $zone, $estateZone, $asks[(int) $shift->id] ?? 0);
        }

        $rows = $posts->map(static fn (Post $post): array => [
            'id' => (int) $post->id,
            'name' => (string) $post->name,
            'also_called' => $post->also_called,
            'type' => (string) $post->type,
            'cells' => array_map(static fn (int $i): array => $cells[(int) $post->id][$i] ?? [], range(0, 6)),
        ])->values()->all();

        return [
            'clients' => $clients,
            'client' => $chosen,
            'zone' => self::zoneOf($chosen['id']),
            'week' => [
                'monday' => $monday->toDateString(),
                'label' => $monday->format('D j M').' – '.$monday->copy()->addDays(6)->format('D j M Y'),
                'previous' => $monday->copy()->subWeek()->toDateString(),
                'next' => $monday->copy()->addWeek()->toDateString(),
                'this' => $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                'isThisWeek' => $monday->isSameDay($today->copy()->startOfWeek(Carbon::MONDAY)),
            ],
            'days' => $days,
            'rows' => $rows,
            'cards' => $this->cards($chosen['id'], $shifts, $posts),
            'leave' => $this->leaveBands($chosen['id'], $days),
            'leaveWaiting' => app(LeaveCover::class)->waiting($chosen['id']),
        ];
    }

    /**
     * Approved leave in the week on screen, as bands across the days it covers (M30 §8.1): each guard's name and
     * "on leave", never a shift and never opened in the shift panel. Leave is days on the estate's calendar, laid on
     * the days of the grid by their dates. One read.
     *
     * @param  list<array{date: string, weekday: string, day: string, month: string, today: bool}>  $days
     * @return list<array{id: int, name: string, short: string, from: int, to: int, days: string}>
     */
    private function leaveBands(string $client, array $days): array
    {
        $first = $days[0]['date'];
        $last = $days[count($days) - 1]['date'];
        $index = array_flip(array_column($days, 'date'));

        return DB::connection('mysql')->table('guard_requests')
            ->join('guards', 'guards.id', '=', 'guard_requests.guard_id')
            ->where('guard_requests.tenant_id', $client)
            ->where('guard_requests.kind', 'leave')
            ->where('guard_requests.status', 'approved')
            ->whereDate('guard_requests.starts_on', '<=', $last)
            ->whereDate('guard_requests.ends_on', '>=', $first)
            ->orderBy('guard_requests.starts_on')
            ->orderBy('guards.full_name')
            ->get(['guard_requests.id', 'guard_requests.starts_on', 'guard_requests.ends_on', 'guards.full_name'])
            ->map(static function (object $row) use ($first, $last, $index): array {
                $from = max((string) Carbon::parse((string) $row->starts_on)->toDateString(), $first);
                $to = min((string) Carbon::parse((string) $row->ends_on)->toDateString(), $last);

                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->full_name,
                    'short' => self::shortName((string) $row->full_name),
                    'from' => (int) $index[$from],
                    'to' => (int) $index[$to],
                    'days' => Carbon::parse((string) $row->starts_on)->format('D j M').' to '.Carbon::parse((string) $row->ends_on)->format('D j M'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The clients this viewer may plan for: the real ones first, by name; then, under a divider, any estate kept for
     * testing (`listed = false`), never chosen by default (M30 §11). The App Store reviewer's estate is not offered at
     * all, as on every operator screen (M5 §3).
     *
     * @return list<array{id: string, name: string, internal: bool}>
     */
    public function clients(User $viewer): array
    {
        $scoped = $viewer->widestScope() === AccessScope::AssignedSites;

        return OperatorEstates::estates()
            ->when($scoped, static fn (Collection $estates) => $estates->whereIn('id', $viewer->accessibleEstateIds()))
            ->map(static fn (Tenant $estate): array => [
                'id' => (string) $estate->getTenantKey(),
                'name' => (string) $estate->name,
                // `listed` false: an estate kept for testing, out of the residents' list (M22) and out of the default here.
                'internal' => $estate->getAttribute('listed') === false,
            ])
            ->sort(static fn (array $a, array $b): int => [$a['internal'], mb_strtolower($a['name'])] <=> [$b['internal'], mb_strtolower($b['name'])])
            ->values()
            ->all();
    }

    /**
     * The client asked for when the viewer may see it, else the first real one, else nothing.
     *
     * @param  list<array{id: string, name: string, internal: bool}>  $clients
     * @return array{id: string, name: string, internal: bool}|null
     */
    public function choose(array $clients, ?string $asked): ?array
    {
        foreach ($clients as $client) {
            if ($asked !== null && $client['id'] === $asked) {
                return $client;
            }
        }

        foreach ($clients as $client) {
            if (! $client['internal']) {
                return $client;
            }
        }

        return null;
    }

    /**
     * THE SHIFT PANEL'S FOOTING, FOR EVERY CLIENT THIS VIEWER REACHES (M35 §2, §3). The classic roster spans every client,
     * and opens the schedule's own panel — to post an open shift, to change who stands one, to remove one — so it is given
     * what the panel is given here for one client: the client, its clock, and its gates in the schedule's order. Two
     * reads whatever the count.
     *
     * @return array<string, array{client: array{id: string, name: string, internal: bool}, zone: array{estate: string, estateTag: string, shown: string, shownTag: string, mine: bool}, posts: list<array{id: int, name: string, also_called: string|null, type: string}>}>
     */
    public function panels(User $viewer): array
    {
        $clients = $this->clients($viewer);
        $posts = Post::query()->whereIn('tenant_id', array_column($clients, 'id'))->where('is_active', true)->get()->groupBy('tenant_id');
        $out = [];

        foreach ($clients as $client) {
            $out[$client['id']] = [
                'client' => $client,
                'zone' => self::zoneOf($client['id']),
                'posts' => self::inRowOrder($posts->get($client['id']) ?? new Collection)
                    ->map(static fn (Post $post): array => [
                        'id' => (int) $post->id,
                        'name' => (string) $post->name,
                        'also_called' => $post->also_called,
                        'type' => (string) $post->type,
                    ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * The client's clock, and the one the times are drawn on: the viewer's own under "My time" (M19A §7).
     *
     * @return array{estate: string, estateTag: string, shown: string, shownTag: string, mine: bool}
     */
    private static function zoneOf(string $client): array
    {
        $estateZone = SiteTime::zone($client);
        $zone = SiteTime::viewerZone() ?? $estateZone;

        return [
            'estate' => $estateZone,
            'estateTag' => SiteTime::clockTag($estateZone),
            'shown' => $zone,
            'shownTag' => SiteTime::clockTag($zone),
            'mine' => SiteTime::viewerZone() !== null,
        ];
    }

    /** @return Collection<int, Post> */
    private function posts(string $client): Collection
    {
        return self::inRowOrder(Post::query()->where('tenant_id', $client)->where('is_active', true)->get());
    }

    /**
     * Gates first, then reliefs, then the patrol posts, each in name order.
     *
     * @param  Collection<int, Post>  $posts
     * @return Collection<int, Post>
     */
    private static function inRowOrder(Collection $posts): Collection
    {
        return $posts
            ->sort(static fn (Post $a, Post $b): int => [self::ROW_ORDER[$a->type] ?? 3, mb_strtolower((string) $a->name)] <=> [self::ROW_ORDER[$b->type] ?? 3, mb_strtolower((string) $b->name)])
            ->values();
    }

    /**
     * Every shift at these posts that starts in the window, with its guard's name, in one read.
     *
     * @param  list<int>  $postIds
     * @return Collection<int, Shift>
     */
    private function shifts(string $client, array $postIds, CarbonInterface $from, CarbonInterface $to): Collection
    {
        if ($postIds === []) {
            return new Collection;
        }

        return Shift::query()
            ->leftJoin('guards', 'guards.id', '=', 'shifts.guard_id')
            ->where('shifts.tenant_id', $client)
            ->whereIn('shifts.post_id', $postIds)
            ->where('shifts.rostered_start', '>=', $from)
            ->where('shifts.rostered_start', '<', $to)
            ->orderBy('shifts.rostered_start')
            ->orderBy('shifts.id')
            ->select(['shifts.*', 'guards.full_name as officer_name'])
            ->get();
    }

    /**
     * How many guards have asked, from the Guard App, for each open shift.
     *
     * @param  list<int>  $openIds
     * @return array<int, int>
     */
    private function asks(array $openIds): array
    {
        if ($openIds === []) {
            return [];
        }

        return DB::connection('mysql')->table('shift_claims')
            ->whereIn('shift_id', $openIds)
            ->where('status', 'pending')
            ->groupBy('shift_id')
            ->selectRaw('shift_id, COUNT(*) as asks')
            ->pluck('asks', 'shift_id')
            ->mapWithKeys(static fn (mixed $n, mixed $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /**
     * One shift as a block in its day's cell: the client's day, its times on the clock being read. When that clock's
     * start falls on another day than the client's (My time, a night in Jamaica), the block says which day it is.
     *
     * @return array<string, mixed>
     */
    private function block(Shift $shift, string $zone, string $estateZone, int $asks): array
    {
        $start = $shift->rostered_start->copy()->setTimezone($zone);
        $end = $shift->rostered_end->copy()->setTimezone($zone);
        $otherDay = ! $start->isSameDay($shift->rostered_start->copy()->setTimezone($estateZone));
        $name = $shift->getAttribute('officer_name');
        $now = Carbon::now();

        $state = match (true) {
            $shift->actual_start !== null && $shift->actual_end === null => 'on_duty',
            $shift->actual_end !== null || $shift->rostered_end->lessThanOrEqualTo($now) => 'ended',
            default => 'upcoming',
        };

        $crosses = ! $end->isSameDay($start);

        return [
            'id' => (int) $shift->id,
            'post_id' => (int) $shift->post_id,
            'starts_at' => SiteTime::iso($shift->rostered_start),
            'ends_at' => SiteTime::iso($shift->rostered_end),
            'time' => ($otherDay ? $start->format('D').' ' : '').self::clock($start).' – '.self::clock($end).($crosses ? ' '.$end->format('D') : '').SiteTime::label($end),
            'guard' => $name === null ? null : ['id' => (int) $shift->guard_id, 'name' => (string) $name, 'short' => self::shortName((string) $name)],
            'duty' => (string) ($shift->duty ?? Shift::DUTY_POST),
            'state' => $state,
            'asks' => $asks,
        ];
    }

    /**
     * The four cards (M30 §4.7), each a fact about this client, named so there is no doubt what it counts.
     *
     * @param  Collection<int, Shift>  $shifts  the week's shifts at this client
     * @param  Collection<int, Post>  $posts
     * @return list<array{key: string, value: int, label: string, title: string}>
     */
    private function cards(string $client, Collection $shifts, Collection $posts): array
    {
        $clockedIn = (int) DB::connection('mysql')->table('shifts')
            ->where('tenant_id', $client)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->distinct()
            ->count('guard_id');

        return [
            ['key' => 'clocked_in', 'value' => $clockedIn, 'label' => 'Clocked in right now', 'title' => 'Guards at this client who have clocked in and not yet clocked out, this moment.'],
            ['key' => 'shifts', 'value' => $shifts->count(), 'label' => 'Shifts this week', 'title' => 'Every shift at this client that starts in the week on screen.'],
            ['key' => 'no_guard', 'value' => $shifts->whereNull('guard_id')->count(), 'label' => 'Shifts with no guard', 'title' => 'Of this week\'s shifts, the ones nobody is on yet.'],
            ['key' => 'gates', 'value' => $posts->where('type', Post::GATE)->count(), 'label' => 'Gates at this client', 'title' => 'Every gate at this client.'],
        ];
    }

    /** "6 AM", or "6:30 AM" when it is not on the hour. */
    private static function clock(CarbonInterface $at): string
    {
        return $at->minute === 0 ? $at->format('g A') : $at->format('g:i A');
    }

    /** "Andre Campbell" → "A. Campbell", what fits a block. */
    public static function shortName(string $name): string
    {
        $parts = array_values(array_filter(explode(' ', trim($name))));

        return count($parts) < 2 ? $name : mb_substr($parts[0], 0, 1).'. '.$parts[count($parts) - 1];
    }
}
