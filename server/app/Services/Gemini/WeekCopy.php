<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Post;
use App\Models\Shift;
use App\Models\User;
use App\Support\CalendarDate;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * COPY LAST WEEK INTO THIS WEEK (M30 §4.6): what would happen, said before it happens, and then exactly that.
 *
 * Every shift last week at the client is copied seven days on, same gate, same times on the client's clock, same duty.
 * NEVER OVERWRITES: a copy whose gate already has a shift of the same duty at that time this week is not made, and
 * every shift already in this week is left alone. NEVER DROPS A GUARD SILENTLY: a guard who cannot stand the copy —
 * on leave that day, his licence running out, suspended, no longer at the client, on another shift then — comes across
 * as "Needs a guard", and the plan says who and why. A copy whose day this week has already passed is not made: a
 * shift is never added in the past.
 *
 * The plan carries a fingerprint of exactly what it would create. Copying asks for the plan again and refuses if the
 * fingerprint differs ("the week changed since you looked"), so what is created is what the person was shown. Every
 * shift is written through `Roster::postShift`, which holds each one to every rule on its own; all or nothing.
 */
final class WeekCopy
{
    public function __construct(private readonly Roster $roster) {}

    /**
     * What copying last week into the week beginning `$monday` would do.
     *
     * @return array<string, mixed>
     */
    public function plan(User $viewer, string $client, string $monday): array
    {
        return $this->work($viewer, $client, $monday)['plan'];
    }

    /**
     * Copy it, as planned. `$fingerprint` is the plan the person saw.
     *
     * @return list<Shift>
     */
    public function copy(User $viewer, string $client, string $monday, string $fingerprint): array
    {
        ['plan' => $plan, 'copies' => $copies] = $this->work($viewer, $client, $monday);

        if (! hash_equals($plan['fingerprint'], $fingerprint)) {
            throw new DomainException('The week changed since you looked. Nothing was copied: open "Copy last week" again to see what it would do now.');
        }

        if ($copies === []) {
            throw new DomainException('There is nothing to copy into this week.');
        }

        $zone = SiteTime::zone($client);

        return DB::connection('mysql')->transaction(function () use ($copies, $viewer, $zone): array {
            $made = [];

            foreach ($copies as $copy) {
                $made[] = $this->roster->postShift(
                    $copy['post'],
                    $copy['from']->copy()->setTimezone($zone)->format('Y-m-d H:i:s'),
                    $copy['to']->copy()->setTimezone($zone)->format('Y-m-d H:i:s'),
                    $viewer,
                    $copy['duty'],
                    $copy['guard'],
                );
            }

            return $made;
        });
    }

    /**
     * The plan and the copies it describes.
     *
     * @return array{plan: array<string, mixed>, copies: list<array{post: Post, from: Carbon, to: Carbon, duty: string, guard: Guard|null}>}
     */
    private function work(User $viewer, string $client, string $monday): array
    {
        if (! $viewer->canAccessEstate($client)) {
            throw new DomainException('That client is not one this role can plan for.');
        }

        $day = CalendarDate::from($monday);
        if ($day === null) {
            throw new DomainException('Choose the week to copy into.');
        }

        $zone = SiteTime::zone($client);
        $to = Carbon::parse($day, $zone)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $from = $to->copy()->subWeek();

        $posts = Post::query()->where('tenant_id', $client)->where('is_active', true)->get()->keyBy('id');
        $inWeek = static fn (Carbon $monday) => Shift::query()
            ->with('officer')
            ->where('tenant_id', $client)
            ->whereIn('post_id', $posts->keys()->all())
            ->where('rostered_start', '>=', $monday->copy()->utc())
            ->where('rostered_start', '<', $monday->copy()->addWeek()->utc())
            ->orderBy('rostered_start')
            ->orderBy('id')
            ->get();

        $last = $inWeek($from);
        $already = $inWeek($to);
        $todayBegan = SiteTime::dayBounds(null, $client)[0];

        /*
         * EVERYTHING THE RULES READ, IN FOUR QUERIES WHATEVER THE WEEK HOLDS (config/perf.php): the shifts at these
         * gates around the week (a night copy runs into the Monday after), and the guards' own other shifts and approved
         * leave over the same span. Each copy is then judged in memory by the Roster's own rules, in their order; the
         * copy itself is still written through `Roster::postShift`, which judges each one again on its own.
         */
        $spanFrom = $to->copy()->subDay()->utc();
        $spanTo = $to->copy()->addWeek()->addDay()->utc();
        $guardIds = $last->pluck('guard_id')->filter()->unique()->values()->all();

        $atGates = Shift::query()
            ->whereIn('post_id', $posts->keys()->all())
            ->whereNotIn('status', Shift::SETTLED)
            ->where('rostered_start', '<', $spanTo)
            ->where('rostered_end', '>', $spanFrom)
            ->get();

        $theirs = $guardIds === [] ? new Collection : Shift::query()
            ->with('post')
            ->whereIn('guard_id', $guardIds)
            ->whereNotIn('status', Shift::SETTLED)
            ->where('rostered_start', '<', $spanTo)
            ->where('rostered_end', '>', $spanFrom)
            ->get()
            ->groupBy('guard_id');

        $leave = $guardIds === [] ? new Collection : GuardRequest::query()
            ->whereIn('guard_id', $guardIds)
            ->where('kind', GuardRequest::LEAVE)
            ->where('status', 'approved')
            ->whereDate('starts_on', '<=', $to->copy()->addWeek()->toDateString())
            ->whereDate('ends_on', '>=', $to->toDateString())
            ->get()
            ->groupBy('guard_id');

        $copies = [];
        $past = 0;
        $taken = 0;
        $wasOpen = 0;
        $kept = 0;
        $dropped = [];

        foreach ($last as $shift) {
            $post = $posts->get($shift->post_id);
            if (! $post instanceof Post) {
                continue;
            }

            // The same wall-clock times, seven days on, on the client's clock.
            $start = SiteTime::local($shift->rostered_start, $client);
            $end = SiteTime::local($shift->rostered_end, $client);
            $copyFrom = Carbon::parse($start->copy()->addWeek()->format('Y-m-d H:i:s'), $zone)->utc();
            $copyTo = Carbon::parse($end->copy()->addWeek()->format('Y-m-d H:i:s'), $zone)->utc();
            $duty = (string) ($shift->duty ?? Shift::DUTY_POST);

            if ($copyFrom->lessThan($todayBegan)) {
                $past++;

                continue;
            }

            // The gate already has a shift of the same duty then (`Roster::postClash`), or an earlier copy does.
            $overlaps = static fn (int $postId, string $d, Carbon $a, Carbon $b): bool => $postId === (int) $post->id && $d === $duty && $a->lessThan($copyTo) && $b->greaterThan($copyFrom);
            $clashesHere = $atGates->contains(static fn (Shift $s): bool => $overlaps((int) $s->post_id, (string) ($s->duty ?? Shift::DUTY_POST), $s->rostered_start, $s->rostered_end))
                || collect($copies)->contains(static fn (array $c): bool => $overlaps((int) $c['post']->id, $c['duty'], $c['from'], $c['to']));

            if ($clashesHere) {
                $taken++;

                continue;
            }

            $guard = $shift->officer;
            $keep = null;

            if ($guard === null) {
                $wasOpen++;
            } else {
                $why = self::cannotStand($guard, $client, $copyFrom, $copyTo, $theirs->get($guard->id, new Collection), $leave->get($guard->id, new Collection), $copies);

                if ($why === null) {
                    $keep = $guard;
                    $kept++;
                } else {
                    $dropped[$why[0]] ??= ['text' => $why[1], 'days' => []];
                    $dropped[$why[0]]['days'][] = SiteTime::local($copyFrom, $client)->format('D j M');
                }
            }

            $copies[] = ['post' => $post, 'from' => $copyFrom, 'to' => $copyTo, 'duty' => $duty, 'guard' => $keep];
        }

        // One line per guard and reason: "K. Blake is on leave Mon 5 Oct and Tue 6 Oct".
        $reasons = [];
        $droppedCount = 0;
        foreach ($dropped as $entry) {
            $droppedCount += count($entry['days']);
            $reasons[] = str_replace(':days', self::listed(array_values(array_unique($entry['days']))), $entry['text']);
        }

        $fingerprint = hash('sha256', (string) json_encode(array_map(static fn (array $c): array => [
            (int) $c['post']->id, $c['from']->toIso8601String(), $c['to']->toIso8601String(), $c['duty'], $c['guard']?->id,
        ], $copies)));

        return [
            'plan' => [
                'client' => $client,
                'week' => $to->toDateString(),
                'from' => ['label' => $from->format('D j M').' – '.$from->copy()->addDays(6)->format('D j M'), 'shifts' => $last->count()],
                'to' => ['label' => $to->format('D j M').' – '.$to->copy()->addDays(6)->format('D j M'), 'shifts' => $already->count()],
                'create' => count($copies),
                'keep_guard' => $kept,
                'was_open' => $wasOpen,
                'no_guard' => $droppedCount,
                'no_guard_why' => $reasons,
                'skipped_past' => $past,
                'skipped_taken' => $taken,
                'left_alone' => $already->count(),
                'fingerprint' => $fingerprint,
            ],
            'copies' => $copies,
        ];
    }

    /**
     * Why this guard cannot stand the copy, or null: `Roster::checkOfficer`'s rules, in its order, on what was read
     * once for the whole plan. The words are short, for the plan, grouped by guard and reason, with the days filled in
     * after (`:days`).
     *
     * @param  Collection<int, Shift>  $theirs  his other shifts around the week
     * @param  Collection<int, GuardRequest>  $leave  his approved leave touching it
     * @param  list<array{post: Post, from: Carbon, to: Carbon, duty: string, guard: Guard|null}>  $planned
     * @return array{0: string, 1: string}|null a grouping key, and the sentence with `:days` for the days it applies to
     */
    private static function cannotStand(Guard $guard, string $client, Carbon $from, Carbon $to, Collection $theirs, Collection $leave, array $planned): ?array
    {
        $id = (string) $guard->id;
        $short = Schedule::shortName((string) $guard->full_name);

        if ($guard->status === 'suspended') {
            return [$id.':suspended', $short.' is suspended'];
        }

        if ($guard->status === 'inactive') {
            return [$id.':inactive', $short.' is no longer an active guard'];
        }

        if ($guard->status === 'licence_expired') {
            return [$id.':licence', $short.'\'s licence has run out'];
        }

        if ((string) $guard->tenant_id !== $client) {
            return [$id.':client', $short.' no longer works at this client'];
        }

        $expires = $guard->psra_expires_on;

        if ($expires === null) {
            return [$id.':licence', $short.' has no licence date on record'];
        }

        if ($to->greaterThan(Roster::licenceLapsesAt($expires, $client))) {
            return [$id.':licence', $short.'\'s licence expires '.$expires->format('D j M')];
        }

        if ($theirs->contains(static fn (Shift $s): bool => $s->rostered_start->lessThan($to) && $s->rostered_end->greaterThan($from))) {
            return [$id.':clash', $short.' is on another shift then, :days'];
        }

        // Two copies for one guard at once: last week had him on two at once, and this week will not.
        foreach ($planned as $c) {
            if ($c['guard']?->id === $guard->id && $c['from']->lessThan($to) && $c['to']->greaterThan($from)) {
                return [$id.':twice', $short.' would be on two shifts at once on :days'];
            }
        }

        $first = SiteTime::local($from, $client)->toDateString();
        $last = SiteTime::local($to->copy()->subSecond(), $client)->toDateString();

        if ($leave->contains(static fn (GuardRequest $r): bool => $r->starts_on !== null && $r->ends_on !== null && $r->starts_on->toDateString() <= $last && $r->ends_on->toDateString() >= $first)) {
            return [$id.':leave', $short.' is on leave :days'];
        }

        return null;
    }

    /**
     * "Mon 5 Oct", "Mon 5 Oct and Tue 6 Oct", "Mon 5 Oct, Tue 6 Oct and Wed 7 Oct".
     *
     * @param  list<string>  $days
     */
    private static function listed(array $days): string
    {
        if (count($days) <= 1) {
            return (string) ($days[0] ?? '');
        }

        $last = array_pop($days);

        return implode(', ', $days).' and '.$last;
    }
}
