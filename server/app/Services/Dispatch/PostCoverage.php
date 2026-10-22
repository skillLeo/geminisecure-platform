<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Support\SiteTime;
use App\Support\SourceBadge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The post coverage board — board screen super-admin-13.
 *
 * ONE QUESTION, ASKED TWICE A DAY: is there a guard on this post between 7 AM
 * and 7 PM, and is there one between 7 PM and 7 AM?
 *
 * It is answered from the ROSTER, not from `guards.post_id`. A standing
 * assignment says which post a guard belongs to and carries no time at all,
 * so answering both windows from it would print one fact under two headings
 * and let a dispatcher read it as knowledge of tonight. On the screen whose
 * entire purpose is showing which posts stand empty, that is the one mistake
 * that costs something.
 *
 * A shift nobody started is a gap, not coverage — but only once it should have
 * begun. A night shift rostered for later this evening is a plan that has not
 * come due, and reporting it as a hole would make every afternoon look like a
 * crisis. So a window is covered when it has a rostered shift AND, if that
 * window is already underway, someone actually clocked in.
 *
 * An expired licence is a hard block rather than a warning: an unlicensed guard
 * is un-rosterable, so the post they used to stand reads as uncovered and names
 * why.
 */
class PostCoverage
{
    /** The two windows the board draws, and the hours they run. */
    private const WINDOWS = [
        'day' => ['label' => '7 AM – 7 PM', 'from' => 7, 'to' => 19],
        'night' => ['label' => '7 PM – 7 AM', 'from' => 19, 'to' => 31],
    ];

    /**
     * Post types in the order the board lists them: gates, then patrols, then
     * relief. A dispatcher reads the perimeter before the rounds.
     *
     * @var array<string, int>
     */
    private const TYPE_ORDER = ['gate' => 0, 'patrol' => 1, 'relief' => 2];

    /** @var list<int> the posts on the board being drawn — see `firstPostedTo()` */
    private array $boardPostIds = [];

    /** @var array<int, Guard>|null the first guard posted to each of them, once asked */
    private ?array $postedGuards = null;

    /** @var array<string, array{0: Carbon, 1: Carbon}> `window()`'s answers, by date, window and zone */
    private array $windows = [];

    /**
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer, ?Carbon $on = null): array
    {
        /*
         * ANY DAY, NOW THAT A FORWARD ROSTER EXISTS (12 §2, Wave 5). The board
         * used to report today only, because a date control that answered
         * "uncovered" for a day nobody had rostered would report a failure that
         * never happened. Open shifts changed that: a gap ahead is now a row on
         * the rota, so another day is a question this board can answer truly.
         */
        /*
         * A CALENDAR DATE, AND EACH ESTATE'S OWN 7 AM (M4 §3.1). Today is today on the site
         * clock, not UTC's; the two windows are then cut on the clock of the estate each post
         * is at — see `cell()` — because 7 AM is a wall-clock rule and the stored times are UTC.
         */
        $day = Carbon::parse(($on ?? SiteTime::today())->format('Y-m-d'), 'UTC')->startOfDay();

        $estates = $this->estates($viewer);
        $posts = $this->posts($estates->pluck('id')->all());
        $shifts = $this->shiftsOn($estates->pluck('id')->all(), $day);

        $this->boardPostIds = $posts->map(static fn (Post $post): int => (int) $post->getKey())->values()->all();
        $this->postedGuards = null;

        $rows = $this->rows($estates, $posts, $shifts, $day);

        return [
            'day' => $day->format('D, M j'),
            'date' => $day->toDateString(),
            'isToday' => $day->toDateString() === SiteTime::today()->toDateString(),
            'kpis' => $this->kpis($rows, $shifts, $posts),
            'windows' => array_column(self::WINDOWS, 'label'),
            'rows' => $rows,

            /*
             * Whether any post on this board was manned by the simulator. The
             * clock-in is device data like an alert or a gate event, and a
             * board reading "covered" across every post because `simulate:gate
             * --shift-change` ran is the one this badge exists for.
             */
            'sourceBadge' => SourceBadge::guard(SourceBadge::anySimulated($shifts)),
        ];
    }

    /* ---------------------------------------------------------------- rows */

    /**
     * One row per post, and one row for an estate that has no posts at all.
     *
     * The postless estate keeps its row deliberately. A client absent from a
     * coverage board reads as a client with nothing to report; a client
     * present and saying "not a Gemini post" reads as a decision somebody made.
     *
     * @param  Collection<int, Tenant>  $estates
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, Shift>  $shifts
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $estates, Collection $posts, Collection $shifts, Carbon $day): array
    {
        $rows = [];

        /*
         * GROUPED ONCE, NOT FILTERED ONCE PER ROW (M11 A2). Every estate filtered the whole
         * post list and every post the whole day's shifts — posts × shifts closure calls, which
         * at twice Phoenix Park's operational volume was most of this board's 500 ms. A group
         * keeps its members in the order the list held them, so each row sees the same posts
         * and the same shifts, in the same order, as its filter returned.
         */
        $postsByEstate = $posts->groupBy(static fn (Post $post): string => (string) $post->tenant_id);

        /*
         * A PATROL IS NOT THE GATE STOOD (M27). The patrol that starts at the Main Gate is out across five phases, so its
         * shift never makes the Main Gate read "Covered": the gate's line counts only the shifts that stand it, and the
         * patrol has a line of its own beneath, "Main Gate · patrol", on a day it is rostered.
         */
        $patrols = $shifts->filter(static fn (Shift $shift): bool => ($shift->duty ?? Shift::DUTY_POST) === Shift::DUTY_PATROL);
        $shiftsByPost = $shifts->reject(static fn (Shift $shift): bool => ($shift->duty ?? Shift::DUTY_POST) === Shift::DUTY_PATROL)
            ->groupBy(static fn (Shift $shift): int => (int) $shift->post_id);
        $patrolsByPost = $patrols->groupBy(static fn (Shift $shift): int => (int) $shift->post_id);

        foreach ($estates as $estate) {
            $key = (string) $estate->getTenantKey();
            $here = $postsByEstate->get($key, collect())->values();

            if ($here->isEmpty()) {
                $rows[] = $this->postlessRow($estate);

                continue;
            }

            foreach ($here as $post) {
                $rows[] = $this->postRow(
                    $estate,
                    $post,
                    $shiftsByPost->get((int) $post->id, collect()),
                    $day,
                );

                if ($patrolsByPost->has((int) $post->id)) {
                    $rows[] = $this->postRow($estate, $post, $patrolsByPost->get((int) $post->id), $day, patrol: true);
                }
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array<string, mixed>
     */
    private function postRow(Tenant $estate, Post $post, Collection $shifts, Carbon $day, bool $patrol = false): array
    {
        $cells = [];
        $covered = 0;

        foreach (self::WINDOWS as $window => $hours) {
            $cell = $this->cell($shifts, $hours, $day, SiteTime::zone((string) $estate->getTenantKey()));
            $cells[] = $cell;

            if ($cell['class'] === 'covered') {
                $covered++;
            }
        }

        $names = $shifts
            ->map(fn (Shift $shift): string => $shift->officer->full_name ?? '')
            ->filter()
            ->unique()
            ->values();

        return [
            'key' => ($patrol ? 'patrol-' : 'post-').$post->id,
            'site' => $estate->name,
            'post' => $post->label().($patrol ? ' · patrol' : ''),
            'cells' => $cells,
            'assigned' => $names->isNotEmpty()
                ? $names->implode(', ')
                : ($patrol ? 'No officer on the patrol' : $this->whyUnassigned($post)),
            'fullyCovered' => $covered === count(self::WINDOWS),
        ];
    }

    /**
     * The state of one window, as one pill.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  array{label: string, from: int, to: int}  $hours
     * @return array{label: string, class: string}
     */
    private function cell(Collection $shifts, array $hours, Carbon $day, string $zone): array
    {
        [$start, $end] = $this->windowOn($day, $hours, $zone);

        $overlapping = $shifts->filter(
            fn (Shift $shift): bool => $shift->rostered_start->lessThan($end)
                && $shift->rostered_end->greaterThan($start)
        );

        $rostered = $overlapping->first(fn (Shift $shift): bool => $shift->guard_id !== null);

        /*
         * AN OPEN SHIFT IS NOT COVER. It says the post needs somebody and
         * nobody is on it — so it reads "Open" rather than "Uncovered", because
         * the difference is whether anybody has noticed the gap.
         */
        if ($rostered === null) {
            return $overlapping->isNotEmpty()
                ? ['label' => 'Open', 'class' => 'uncovered']
                : ['label' => 'Uncovered', 'class' => 'uncovered'];
        }

        /*
         * Due, but nobody clocked in.
         *
         * Only checked once the window has actually begun. A shift rostered for
         * this evening has not failed to start; it has not started yet, and
         * colouring it red would make every afternoon read as a crisis.
         */
        if ($start->lessThanOrEqualTo(now()) && $rostered->actual_start === null) {
            return ['label' => 'Not started', 'class' => 'uncovered'];
        }

        return ['label' => 'Covered', 'class' => 'covered'];
    }

    /**
     * Why nobody is standing this post, named rather than left blank.
     *
     * "Unassigned" tells a dispatcher nothing they can act on. Naming the guard
     * and what happened to them is the difference between a gap and a gap with
     * a cause — and it is the sentence a supervisor needs when they ask why the
     * Service Gate is empty.
     */
    private function whyUnassigned(Post $post): string
    {
        $guard = $this->firstPostedTo($post);

        if ($guard === null) {
            return 'Unassigned — no guard posted here';
        }

        $because = match (true) {
            $guard->licenceState() === 'expired' => "'s licence expired",
            $guard->status === 'on_leave' => ' went on leave',
            $guard->status === 'suspended' => "'s suspension",
            $guard->status === 'inactive' => ' left the roster',
            default => ' was last rostered',
        };

        return 'Unassigned since '.$guard->full_name.$because;
    }

    /**
     * The guard posted here, first by name — for every post on the board, from ONE read.
     *
     * It was a query per post nobody is rostered on (M11 A2), so a board with a dozen idle
     * posts asked a dozen times. It is now asked the first time any post needs it, for every
     * post the board shows (`forViewer()` sets them), and answered from memory after that; a
     * board with no idle post asks not at all. `id` breaks a tie between two officers of the
     * same name, where the bare `LIMIT 1` named whichever row the database met first.
     */
    private function firstPostedTo(Post $post): ?Guard
    {
        if ($this->postedGuards === null) {
            $this->postedGuards = [];

            $ids = array_values(array_unique([...$this->boardPostIds, (int) $post->id]));

            foreach (Guard::query()->whereIn('post_id', $ids)->orderBy('full_name')->orderBy('id')->get() as $guard) {
                $this->postedGuards[(int) $guard->post_id] ??= $guard;
            }
        }

        return $this->postedGuards[(int) $post->id] ?? null;
    }

    /**
     * An estate with no Gemini posts at all.
     *
     * Two different sentences, because they are two different situations: a
     * client that guards itself has made a commercial choice, and a client
     * still onboarding has posts that nobody has built yet. Both read as "no
     * coverage" if collapsed, and only one of them is anybody's problem.
     *
     * @return array<string, mixed>
     */
    private function postlessRow(Tenant $estate): array
    {
        $onboarding = $estate->status === 'onboarding';

        $cell = [
            'label' => $onboarding ? 'Not yet live' : 'Self-managed',
            'class' => 'na',
        ];

        return [
            'key' => 'estate-'.$estate->getTenantKey(),
            'site' => $estate->name,
            'post' => '—',
            'cells' => [$cell, $cell],
            'assigned' => $onboarding ? 'Onboarding — posts not yet built' : 'Not a Gemini post',
            'fullyCovered' => false,
        ];
    }

    /* ---------------------------------------------------------------- kpis */

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, Post>  $posts
     * @return list<array{icon: string, stroke: float, tone: string, value: string, label: string}>
     */
    private function kpis(array $rows, Collection $shifts, Collection $posts): array
    {
        // Posts, not patrols (M27): a patrol line is the patrol's, and it is not a post to be covered or uncovered.
        $postRows = array_filter($rows, static fn (array $row): bool => $row['post'] !== '—' && ! str_starts_with((string) $row['key'], 'patrol-'));

        $fullyCovered = count(array_filter($postRows, static fn (array $row): bool => $row['fullyCovered'] === true));

        return [
            [
                'icon' => 'check-ring',
                'stroke' => 1.6,
                'tone' => '',
                'value' => (string) $fullyCovered,
                'label' => 'Posts fully covered',
            ],
            [
                'icon' => 'alert',
                'stroke' => 1.6,
                'tone' => 'alert',
                'value' => (string) (count($postRows) - $fullyCovered),
                'label' => 'Posts uncovered',
            ],
            [
                'icon' => 'guards',
                'stroke' => 1.7,
                'tone' => '',
                'value' => (string) $shifts->pluck('guard_id')->unique()->count(),
                'label' => 'Guards assigned, today',
            ],
            [
                'icon' => 'clients',
                'stroke' => 1.7,
                'tone' => '',
                'value' => (string) $posts->pluck('tenant_id')->unique()->count(),
                'label' => 'Client sites with Gemini posts',
            ],
        ];
    }

    /* ------------------------------------------------------------- queries */

    /**
     * @return Collection<int, Tenant>
     */
    private function estates(User $viewer): Collection
    {
        return Tenant::estates(function ($query) use ($viewer): void {
            // Scope AND the demonstration estate, in one call (M5 §3): a board
            // that reported the demonstration gate uncovered would send somebody
            // to staff a post that is not there.
            OperatorEstates::narrow($query, $viewer, 'tenants.id');

            // Parish then name, which is the order the live map lays the same
            // sites out in. Two screens in one module that disagree about the
            // order of the same list make a dispatcher re-read both.
            $query->orderBy('parish')->orderBy('name');
        });
    }

    /**
     * @param  list<string>  $estateIds
     * @return Collection<int, Post>
     */
    private function posts(array $estateIds): Collection
    {
        return Post::query()
            ->whereIn('tenant_id', $estateIds)
            ->where('is_active', true)
            ->get()
            ->sortBy([
                fn (Post $post): int => self::TYPE_ORDER[$post->type] ?? 9,
                fn (Post $post): string => $post->name,
            ])
            ->values();
    }

    /**
     * Every shift touching today's two windows.
     *
     * Reaches back to yesterday evening because the night window crosses
     * midnight: a shift that began at 7 PM yesterday is the one covering this
     * morning's small hours, and a query bounded by today's date would report
     * every gate on the platform as unmanned overnight.
     *
     * @param  list<string>  $estateIds
     * @return Collection<int, Shift>
     */
    private function shiftsOn(array $estateIds, Carbon $day): Collection
    {
        // A day either side: which moments "7 AM to 7 AM" are depends on each estate's zone,
        // and `cell()` does the exact cut. This only has to not miss a shift.
        //
        // IN THE ORDER THE SHIFTS START, AND SAID SO (M11 A2). `cell()` names the FIRST rostered
        // shift that overlaps a window and `assigned` lists the officers in this order, so where two
        // shifts share a window, it is whose name the board shows. There was no ORDER BY: the order
        // was whichever index MySQL read — by start through (tenant_id, rostered_start) at today's
        // size, by id when it read the table whole, and by END time through
        // `shifts_tenant_id_rostered_end_index` once the roster has history (all three measured).
        // Asked for, it is the start, as the board shows today, and MySQL still reads only the index
        // range and sorts what it found.
        return Shift::query()
            ->with(['officer', 'post'])
            ->whereIn('tenant_id', $estateIds)
            ->where('rostered_end', '>', $day->copy()->subDay())
            ->where('rostered_start', '<', $day->copy()->addDays(3))
            ->orderBy('rostered_start')
            ->orderBy('id')
            ->get()
            ->filter(function (Shift $shift) use ($day): bool {
                $zone = SiteTime::zone((string) $shift->tenant_id);
                [$from] = $this->windowOn($day, self::WINDOWS['day'], $zone);
                [, $to] = $this->windowOn($day, self::WINDOWS['night'], $zone);

                return $shift->rostered_end->greaterThan($from) && $shift->rostered_start->lessThan($to);
            })
            ->values();
    }

    /**
     * `window()`, worked out once per date, window and zone for the board being drawn (M11 A2).
     *
     * It is a pure function of those three, and it was worked out afresh for every shift and
     * every cell — two date parses each, a third of this board's time at volume. Each caller
     * is handed its own copies, so nothing one of them does to a moment reaches another.
     *
     * @param  array{label: string, from: int, to: int}  $hours
     * @return array{0: Carbon, 1: Carbon}
     */
    private function windowOn(Carbon $day, array $hours, string $zone): array
    {
        $key = $day->toDateString().'|'.$hours['from'].'|'.$hours['to'].'|'.$zone;

        [$from, $to] = $this->windows[$key] ??= self::window($day, $hours, $zone);

        return [$from->copy(), $to->copy()];
    }

    /**
     * One window of one date, as two moments, on a site's clock.
     *
     * Built from the wall-clock hour rather than by adding hours to midnight, so a day with a
     * daylight-saving change in it still has its 7 AM at 7 AM.
     *
     * @param  array{label: string, from: int, to: int}  $hours
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function window(Carbon $day, array $hours, string $zone): array
    {
        $at = static fn (int $hour): Carbon => Carbon::parse($day->toDateString(), $zone)
            ->addDays(intdiv($hour, 24))
            ->setTime($hour % 24, 0);

        return [$at($hours['from']), $at($hours['to'])];
    }
}
