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
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE SHIFT PANEL'S RULES, BEFORE THE ACT (M30 §5, §6, §10).
 *
 * The panel asks this, as the form changes, what the server would say about the shift it describes: one line per
 * rule, a tick or a cross with the reason; and every guard at the client, free ones first, the others crossed with
 * why on the same row. Saving asks it again and then writes through `Roster`, which enforces every rule itself — so
 * this is guidance that cannot drift from the guard: the same primitives (`Roster::postClash`, `ShiftConflicts`,
 * `Roster::licenceLapsesAt`, the horizon, today's start), and the server's own sentence wins if the two ever differ.
 *
 * TIMES ARE THE CLIENT'S. "06:00" is six in the morning at the client's estate, whoever types it and wherever they
 * are; an end at or before the start ends the next day, so a night shift is typed as it is said.
 *
 * @phpstan-type Line array{ok: bool, text: string}
 * @phpstan-type Window array{date: string, from: Carbon, to: Carbon}
 * @phpstan-type Judged array{id: int, name: string, employee_number: string, phone: string|null, free: bool, reason: string|null, lines: list<Line>}
 * @phpstan-type Answer array{
 *     ok: bool,
 *     count: int,
 *     hours: string,
 *     zone: string|null,
 *     occurrences: list<array{date: string, starts_at: string|null, ends_at: string|null}>,
 *     guards: list<array{id: int, name: string, employee_number: string, phone: string|null, free: bool, reason: string|null, asked?: bool}>,
 *     checks: list<Line>,
 * }
 */
final class ShiftPlanner
{
    /** The most shifts one repeat may create: a little over two months of days. */
    public const MAX_REPEAT = 62;

    public function __construct(private readonly Roster $roster) {}

    /**
     * What the server would say about this shift, or these shifts.
     *
     * @param  array<string, mixed>  $in  post_id, date, starts, ends, duty, guard_id, repeat (once|daily|days), until, days[], shift_id
     * @return Answer
     */
    public function check(User $viewer, array $in): array
    {
        return $this->assess($viewer, $in)['answer'];
    }

    /**
     * Create the shift, or every shift of a repeat, with its guard, in one act: asked again first, then written
     * through `Roster`, which refuses anything the rules refuse. All or nothing.
     *
     * @param  array<string, mixed>  $in
     * @return list<Shift>
     */
    public function create(User $viewer, array $in): array
    {
        ['answer' => $answer, 'post' => $post, 'windows' => $windows, 'guard_id' => $guardId, 'duty' => $duty] = $this->assess($viewer, $in);

        if (! $answer['ok'] || $post === null) {
            foreach ($answer['checks'] as $line) {
                if (! $line['ok']) {
                    throw new DomainException($line['text']);
                }
            }

            throw new DomainException('That shift cannot be added.');
        }

        $guard = $guardId !== null ? Guard::query()->find($guardId) : null;
        $zone = SiteTime::zone((string) $post->tenant_id);

        // Why an open shift is open, read by whoever picks it up (M35 §2: the classic roster's own field, kept).
        $why = $guard === null && is_string($in['reason'] ?? null) && trim($in['reason']) !== '' ? mb_substr(trim($in['reason']), 0, 190) : null;

        return DB::connection('mysql')->transaction(function () use ($windows, $post, $guard, $duty, $viewer, $zone, $why): array {
            $made = [];

            foreach ($windows as $w) {
                $made[] = $this->roster->postShift(
                    $post,
                    $w['from']->copy()->setTimezone($zone)->format('Y-m-d H:i:s'),
                    $w['to']->copy()->setTimezone($zone)->format('Y-m-d H:i:s'),
                    $viewer,
                    $duty,
                    $guard,
                    $why,
                );
            }

            return $made;
        });
    }

    /**
     * Change one shift from the panel (§5.2): its gate, day, times, what he does there and who stands it, in one act —
     * asked again first, then written through `Roster::changeShift`, which refuses anything the rules refuse.
     *
     * @param  array<string, mixed>  $in  as `check()`, with `reason` when the guard on it is replaced or taken off
     */
    public function change(User $viewer, Shift $shift, array $in): Shift
    {
        ['answer' => $answer, 'post' => $post, 'windows' => $windows, 'guard_id' => $guardId, 'duty' => $duty] = $this->assess($viewer, [...$in, 'shift_id' => $shift->id, 'repeat' => 'once']);

        if (! $answer['ok'] || $post === null || $windows === []) {
            foreach ($answer['checks'] as $line) {
                if (! $line['ok']) {
                    throw new DomainException($line['text']);
                }
            }

            throw new DomainException('That change cannot be made.');
        }

        $guard = $guardId !== null ? Guard::query()->find($guardId) : null;

        return $this->roster->changeShift($shift, $post, $windows[0]['from'], $windows[0]['to'], $duty, $guard, $viewer, is_string($in['reason'] ?? null) ? $in['reason'] : null);
    }

    /**
     * One shift as the panel opens on it (§5.2): the form's values on the client's clock, what can still change, why
     * it has no guard if it was taken off, who asked for it, and its history from the audit log — each entry with its
     * clock's tag and how long ago.
     *
     * @return array<string, mixed>
     */
    public function view(User $viewer, Shift $shift): array
    {
        $tenant = (string) $shift->tenant_id;
        $start = SiteTime::local($shift->rostered_start, $tenant);
        $end = SiteTime::local($shift->rostered_end, $tenant);
        $locked = Roster::whyLocked($shift);
        $started = ! $shift->rostered_start->isFuture();
        $guard = $shift->officer;

        return [
            'id' => (int) $shift->id,
            'title' => trim($shift->postLabel().' · '.$start->format('D j M').' · '.$start->format('g:i A').' – '.$end->format('g:i A').($end->isSameDay($start) ? '' : ' '.$end->format('D')).SiteTime::label($end)),
            'form' => [
                'post_id' => (int) $shift->post_id,
                'date' => $start->toDateString(),
                'starts' => $start->format('H:i'),
                'ends' => $end->format('H:i'),
                'duty' => (string) ($shift->duty ?? Shift::DUTY_POST),
                'guard_id' => $guard?->id,
            ],
            'guard' => $guard === null ? null : ['id' => (int) $guard->id, 'name' => (string) $guard->full_name],
            'locked' => $locked,
            'started' => $started,
            'can' => [
                'change_times' => $locked === null && ! $started,
                'change_guard' => $locked === null,
                'take_off' => $locked === null && $guard !== null,
                'remove' => $shift->actual_start === null && ! $started,
            ],
            'note' => $guard === null && is_string($shift->released_reason) && $shift->released_reason !== '' ? $shift->released_reason : null,
            'history' => $this->history($shift),
        ];
    }

    /**
     * What happened to a shift, oldest first, from the audit log: who added it, who changed it and how, who took a guard
     * off it and why. One read, by the log's own index on the entity.
     *
     * @return list<array{text: string, at: string, ago: string}>
     */
    private function history(Shift $shift): array
    {
        $tenant = (string) $shift->tenant_id;

        $rows = DB::connection('mysql')->table('audit_log')
            ->where('entity_type', 'Shift')
            ->where('entity_id', (string) $shift->id)
            ->whereIn('action', ['roster.shift_posted', 'roster.shift_assigned', 'roster.claim_approved', 'roster.shift_changed', 'roster.shift_taken_off'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(30)
            ->get(['action', 'actor_name', 'before', 'after', 'created_at']);

        $out = [];

        foreach ($rows as $row) {
            $before = json_decode((string) $row->before, true);
            $after = json_decode((string) $row->after, true);
            $before = is_array($before) ? $before : [];
            $after = is_array($after) ? $after : [];
            $who = (string) ($row->actor_name ?? $after['by'] ?? 'The system');

            $text = match ($row->action) {
                'roster.shift_posted' => 'Added by '.$who.(isset($after['guard']) ? ', with '.$after['guard'].' on it' : ', with nobody on it yet'),
                'roster.shift_assigned' => ($after['guard'] ?? 'A guard').' put on it by '.$who,
                'roster.claim_approved' => ($after['guard'] ?? 'A guard').'\'s request for it approved by '.$who,
                'roster.shift_taken_off' => ($before['guard'] ?? 'The guard').' taken off by '.$who.(isset($after['reason']) ? ': '.$after['reason'] : ''),
                default => 'Changed by '.$who.': '.self::changes($before, $after, $tenant),
            };

            $at = Carbon::parse((string) $row->created_at, 'UTC');
            $local = SiteTime::local($at, $tenant);

            $out[] = ['text' => $text, 'at' => $local->format('D j M, g:i A').SiteTime::label($local), 'ago' => $at->diffForHumans()];
        }

        return $out;
    }

    /**
     * "guard Andre Campbell to Kemoy Allen; 6:00 PM to 7:00 PM" — what a change changed, in a few words.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private static function changes(array $before, array $after, string $tenant): string
    {
        $parts = [];
        $time = static fn (mixed $iso): string => is_string($iso) ? SiteTime::local(Carbon::parse($iso), $tenant)->format('D j M, g:i A') : '';

        if (($before['post'] ?? null) !== ($after['post'] ?? null)) {
            $parts[] = 'gate '.($before['post'] ?? '').' to '.($after['post'] ?? '');
        }

        if (($before['from'] ?? null) !== ($after['from'] ?? null) || ($before['to'] ?? null) !== ($after['to'] ?? null)) {
            $parts[] = 'time '.$time($before['from'] ?? null).' to '.$time($after['from'] ?? null);
        }

        if (($before['duty'] ?? null) !== ($after['duty'] ?? null)) {
            $parts[] = ($after['duty'] ?? '') === Shift::DUTY_PATROL ? 'now starts a patrol' : 'now stands the gate';
        }

        $was = is_string($before['guard'] ?? null) ? $before['guard'] : null;
        $now = is_string($after['guard'] ?? null) ? $after['guard'] : null;

        if ($was !== $now) {
            $parts[] = match (true) {
                $was === null => $now.' put on it',
                $now === null => $was.' taken off',
                default => 'guard '.$was.' to '.$now,
            };
        }

        $said = $parts === [] ? 'nothing that shows' : implode('; ', $parts);

        return $said.(isset($after['reason']) && is_string($after['reason']) && $after['reason'] !== '' ? ' ('.$after['reason'].')' : '');
    }

    /**
     * The answer, and what it was worked out from: the gate, the windows in UTC, the chosen guard and the duty.
     *
     * @param  array<string, mixed>  $in
     * @return array{answer: Answer, post: Post|null, windows: list<Window>, guard_id: int|null, duty: string}
     */
    private function assess(User $viewer, array $in): array
    {
        $duty = in_array($in['duty'] ?? null, Shift::DUTIES, true) ? (string) $in['duty'] : Shift::DUTY_POST;
        $refused = static fn (string $why): array => ['answer' => self::refused($why), 'post' => null, 'windows' => [], 'guard_id' => null, 'duty' => $duty];

        // The shift being changed, when there is one (§5.2): never its own clash, and only ever one day.
        $except = isset($in['shift_id']) && is_numeric($in['shift_id']) ? (int) $in['shift_id'] : null;
        $shift = $except === null ? null : Shift::query()->with(['post', 'officer'])->find($except);

        if ($except !== null && ($shift === null || ! $viewer->canAccessEstate((string) $shift->tenant_id))) {
            return $refused('That shift is not one this role can change.');
        }

        $post = Post::query()->with('estate')->find((int) ($in['post_id'] ?? 0));

        if (! $post instanceof Post || ! $post->is_active || ! $viewer->canAccessEstate((string) $post->tenant_id)) {
            return $refused('Choose a gate at this client.');
        }

        $tenant = (string) $post->tenant_id;
        $zone = SiteTime::zone($tenant);
        $date = CalendarDate::from(is_string($in['date'] ?? null) ? $in['date'] : null);
        $starts = self::clockTime($in['starts'] ?? null);
        $ends = self::clockTime($in['ends'] ?? null);

        if ($date === null) {
            return $refused('Choose a date.');
        }

        if ($starts === null || $ends === null) {
            return $refused('Choose when it starts and when it ends.');
        }

        $repeat = $shift !== null ? 'once' : (string) ($in['repeat'] ?? 'once');
        $until = CalendarDate::from(is_string($in['until'] ?? null) ? $in['until'] : null);

        if ($repeat !== 'once' && ($until === null || $until < $date)) {
            return $refused('Choose the last day it repeats on, on or after '.Carbon::parse($date)->format('D j M').'.');
        }

        $dates = $this->dates($date, $repeat, $until, is_array($in['days'] ?? null) ? array_values($in['days']) : []);

        if ($dates === []) {
            return $refused('Choose at least one day of the week for it to repeat on.');
        }

        if (count($dates) > self::MAX_REPEAT) {
            return $refused('That repeats more than '.self::MAX_REPEAT.' times. Repeat it for two months at most, then add the next two.');
        }

        $windows = array_map(static function (string $day) use ($zone, $starts, $ends): array {
            $from = Carbon::parse($day.' '.$starts, $zone);
            $to = Carbon::parse($day.' '.$ends, $zone);
            if ($to->lessThanOrEqualTo($from)) {
                $to->addDay();
            }

            return ['date' => $day, 'from' => $from->copy()->utc(), 'to' => $to->copy()->utc()];
        }, $dates);

        /*
         * One line per rule (§10), in the order the milestone draws them (§5.1): the gate, then the guard, then the
         * time. The server's own rules, every one, each a tick or a cross with the reason.
         */
        $checks = [];
        $first = $windows[0];
        $last = $windows[count($windows) - 1];
        $minutes = (int) $first['from']->diffInMinutes($first['to'], absolute: true);

        // A shift being worked, or worked, changes no more (Roster::changeShift says the same, and refuses).
        $locked = $shift === null ? null : Roster::whyLocked($shift);
        if ($locked !== null) {
            $checks[] = ['ok' => false, 'text' => $locked];
        }

        // Its gate and times as they already are: those rules were met when it was added, and are not asked again.
        $unchanged = $shift !== null
            && (int) $post->id === (int) $shift->post_id
            && $duty === (string) ($shift->duty ?? Shift::DUTY_POST)
            && $first['from']->equalTo($shift->rostered_start)
            && $first['to']->equalTo($shift->rostered_end);

        if ($shift !== null && ! $unchanged && ! $shift->rostered_start->isFuture()) {
            $checks[] = ['ok' => false, 'text' => 'This shift has started, so its gate and times stay as they are. Who stands it can still change.'];
        }

        if ($unchanged) {
            $checks[] = ['ok' => true, 'text' => 'Same gate and times as before'];
        } else {
            // The gate is free: no shift of the same duty there overlaps any of these windows (`Roster::postClash`,
            // read once for the whole span, so a two-month repeat costs one query, not sixty-two).
            $atGate = $this->roster->postClashes($post, $first['from'], $last['to'], $duty, $except);
            $postClash = null;
            foreach ($windows as $w) {
                $postClash = $atGate->first(static fn (Shift $s): bool => $s->rostered_start->lessThan($w['to']) && $s->rostered_end->greaterThan($w['from']));
                if ($postClash !== null) {
                    break;
                }
            }
            $checks[] = $postClash === null
                ? ['ok' => true, 'text' => $post->label().' is free at '.(count($windows) === 1 ? 'this time' : 'these times')]
                : ['ok' => false, 'text' => $post->label().' already has a shift at that time: '.ShiftConflicts::when($postClash)];
        }

        $guards = $this->guards($tenant, $windows, $except, (string) ($post->estate->name ?? $tenant));
        $wanted = isset($in['guard_id']) && is_numeric($in['guard_id']) ? (int) $in['guard_id'] : null;
        $chosen = null;
        foreach ($guards as $g) {
            if ($g['id'] === $wanted) {
                $chosen = $g;
                break;
            }
        }

        if ($wanted !== null && $chosen === null) {
            $checks[] = ['ok' => false, 'text' => 'That guard is not one this client has.'];
        } elseif ($chosen !== null) {
            foreach ($chosen['lines'] as $line) {
                $checks[] = $line;
            }
        } else {
            $checks[] = ['ok' => true, 'text' => 'Left open: guards at this client can ask for it'];
        }

        // The guard on it now, replaced or taken off, is told on his phone, with the reason (§5.2).
        $current = $shift?->officer;
        if ($current !== null && $wanted !== (int) $current->id) {
            $given = mb_strlen(trim((string) ($in['reason'] ?? ''))) >= 3;
            $checks[] = $given
                ? ['ok' => true, 'text' => $current->full_name.' is told on their phone, with your reason']
                : ['ok' => false, 'text' => 'Say why '.$current->full_name.' comes off this shift. Their phone is told.'];
        }

        if (! $unchanged) {
            // Starts today or later, by the server's own rule (before today began at the estate is the past).
            $past = null;
            foreach ($windows as $w) {
                if ($w['from']->lessThan(SiteTime::dayBounds(null, $tenant)[0])) {
                    $past = $w;
                    break;
                }
            }
            $checks[] = $past === null
                ? ['ok' => true, 'text' => 'Starts today or later']
                : ['ok' => false, 'text' => self::day($past['from'], $tenant).' is in the past. A shift is never added in the past.'];

            $checks[] = $minutes > 24 * 60
                ? ['ok' => false, 'text' => 'A shift runs up to 24 hours.']
                : ['ok' => true, 'text' => self::hours($minutes).', within 24'];

            $checks[] = $last['from']->greaterThan(Carbon::now()->addDays(Roster::HORIZON_DAYS))
                ? ['ok' => false, 'text' => 'That is more than six months ahead. Add it nearer the time.']
                : ['ok' => true, 'text' => 'Within the next six months'];
        }

        // Who asked for it from the Guard App ("Asked for this shift", §9), so the picker can say so on their row.
        $asked = $except === null ? [] : DB::connection('mysql')->table('shift_claims')
            ->where('shift_id', $except)->where('status', ShiftClaims::PENDING)
            ->pluck('guard_id')->map(static fn ($id): int => (int) $id)->all();

        $ok = true;
        foreach ($checks as $line) {
            $ok = $ok && $line['ok'];
        }

        return [
            'answer' => [
                'ok' => $ok,
                'count' => count($windows),
                'hours' => self::hours($minutes),
                'zone' => $zone,
                'occurrences' => array_map(static fn (array $w): array => [
                    'date' => $w['date'],
                    'starts_at' => SiteTime::iso($w['from']),
                    'ends_at' => SiteTime::iso($w['to']),
                ], $windows),
                'guards' => array_map(static fn (array $g): array => [
                    'id' => $g['id'],
                    'name' => $g['name'],
                    'employee_number' => $g['employee_number'],
                    // M32 F4: the panel's search finds a guard by phone too, however it is typed.
                    'phone' => $g['phone'],
                    'free' => $g['free'],
                    'reason' => $g['reason'],
                    'asked' => in_array($g['id'], $asked, true),
                ], $guards),
                'checks' => $checks,
            ],
            'post' => $post,
            'windows' => $windows,
            'guard_id' => $chosen['id'] ?? null,
            'duty' => $duty,
        ];
    }

    /**
     * Every guard at the client — and any not yet posted to a client — with whether they can stand all of these
     * windows, and why not. Free ones first, then the rest, each group by name. Three reads whatever the count.
     *
     * @param  list<Window>  $windows
     * @return list<Judged>
     */
    private function guards(string $tenant, array $windows, ?int $except, string $clientName): array
    {
        $officers = Guard::query()
            ->where(static fn ($q) => $q->where('tenant_id', $tenant)->orWhereNull('tenant_id'))
            ->whereNotIn('status', ['inactive'])
            ->orderBy('full_name')
            ->get();

        $ids = $officers->modelKeys();
        $from = $windows[0]['from'];
        $to = $windows[count($windows) - 1]['to'];

        $shifts = Shift::query()->with('post')
            ->whereIn('guard_id', $ids)
            ->when($except !== null, static fn ($q) => $q->whereKeyNot($except))
            ->whereNotIn('status', Shift::SETTLED)
            ->where('rostered_start', '<', $to)
            ->where('rostered_end', '>', $from)
            ->orderBy('rostered_start')
            ->get()
            ->groupBy('guard_id');

        $firstDay = SiteTime::local($from, $tenant)->toDateString();
        $lastDay = SiteTime::local($to->copy()->subSecond(), $tenant)->toDateString();
        $leave = GuardRequest::query()
            ->whereIn('guard_id', $ids)
            ->where('kind', GuardRequest::LEAVE)
            ->where('status', 'approved')
            ->whereDate('starts_on', '<=', $lastDay)
            ->whereDate('ends_on', '>=', $firstDay)
            ->get()
            ->groupBy('guard_id');

        $judged = [];
        foreach ($officers as $g) {
            $judged[] = $this->judge($g, $tenant, $clientName, $windows, $shifts->get($g->id, new Collection), $leave->get($g->id, new Collection));
        }

        usort($judged, static fn (array $a, array $b): int => [! $a['free'], mb_strtolower($a['name'])] <=> [! $b['free'], mb_strtolower($b['name'])]);

        return $judged;
    }

    /**
     * One guard against every window: a line per rule, for the panel's foot once he is chosen — whether he is free,
     * his licence, whether he works at this client — and the one reason his row in the picker gives when he cannot,
     * the most basic first (not at this client, suspended, licence, leave, another shift).
     *
     * @param  list<Window>  $windows
     * @param  Collection<int, Shift>  $shifts  their shifts overlapping the whole span
     * @param  Collection<int, GuardRequest>  $leave  their approved leave touching the whole span
     * @return Judged
     */
    private function judge(Guard $g, string $tenant, string $clientName, array $windows, Collection $shifts, Collection $leave): array
    {
        $name = (string) $g->full_name;
        $reasons = [];

        // Whether he works at this client, and may stand a post at all.
        $here = (string) $g->tenant_id === $tenant;
        $standing = $here
            ? ['ok' => true, 'text' => $name.' works at '.$clientName]
            : ['ok' => false, 'text' => $name.' is not at this client'];
        if (! $here) {
            $reasons[] = 'not at this client';
        }

        $status = match ($g->status) {
            'suspended' => ['ok' => false, 'text' => $name.' is suspended'],
            'licence_expired' => ['ok' => false, 'text' => $name.'\'s record is marked licence expired'],
            default => null,
        };
        if ($status !== null) {
            $reasons[] = $g->status === 'suspended' ? 'suspended' : 'licence ran out';
        }

        // His licence, to the end of every window.
        if ($g->psra_expires_on === null) {
            $licence = ['ok' => false, 'text' => $name.' has no licence expiry on record'];
            $reasons[] = 'no licence date on record';
        } else {
            $lapses = Roster::licenceLapsesAt($g->psra_expires_on, $tenant);
            $date = $g->psra_expires_on->format('j M Y');

            if (collect($windows)->contains(static fn (array $w): bool => $w['to']->greaterThan($lapses))) {
                $ranOut = $lapses->lessThanOrEqualTo(Carbon::now());
                $licence = ['ok' => false, 'text' => $name.'\'s licence '.($ranOut ? 'ran out on ' : 'runs out before this shift ends, on ').$date];
                $reasons[] = $ranOut ? 'licence ran out '.$date : 'licence runs out before this shift ends, '.$date;
            } else {
                $licence = ['ok' => true, 'text' => $name.'\'s licence runs to '.$date];
            }
        }

        // Free: on no approved leave those days, and on no other shift at those times.
        $free = ['ok' => true, 'text' => $name.' is free'];

        foreach ($windows as $w) {
            $a = SiteTime::local($w['from'], $tenant)->toDateString();
            $b = SiteTime::local($w['to']->copy()->subSecond(), $tenant)->toDateString();
            $onLeave = $leave->first(static fn (GuardRequest $r): bool => $r->starts_on !== null && $r->ends_on !== null && $r->starts_on->toDateString() <= $b && $r->ends_on->toDateString() >= $a);
            if ($onLeave !== null) {
                $free = ['ok' => false, 'text' => $name.' is on leave '.$onLeave->starts_on?->format('D j M').' to '.$onLeave->ends_on?->format('D j M')];
                $reasons[] = 'on leave this day';
                break;
            }
        }

        if ($free['ok']) {
            foreach ($windows as $w) {
                $clash = $shifts->first(static fn (Shift $s): bool => $s->rostered_start->lessThan($w['to']) && $s->rostered_end->greaterThan($w['from']));
                if ($clash !== null) {
                    $start = SiteTime::local($clash->rostered_start, $tenant);
                    $end = SiteTime::local($clash->rostered_end, $tenant);
                    $free = ['ok' => false, 'text' => $name.' is on another shift: '.ShiftConflicts::describe($clash)];
                    // Every absolute time with its clock's tag (§4.5): "on another shift 9:00 AM–5:00 PM JA, Back Gate".
                    $reasons[] = 'on another shift '.$start->format('g:i A').'–'.$end->format('g:i A').SiteTime::label($end).', '.$clash->postLabel();
                    break;
                }
            }
        }

        $lines = [$free, $licence, $standing];
        if ($status !== null) {
            $lines[] = $status;
        }

        return [
            'id' => (int) $g->id,
            'name' => $name,
            'employee_number' => (string) $g->employee_number,
            'phone' => $g->phone === null ? null : (string) $g->phone,
            'free' => $reasons === [],
            'reason' => $reasons[0] ?? null,
            'lines' => $lines,
        ];
    }

    /**
     * The days a shift falls on: one, every day to an end date, or chosen weekdays to an end date.
     *
     * @param  list<mixed>  $weekdays  ISO weekday numbers, 1 Monday … 7 Sunday
     * @return list<string>
     */
    private function dates(string $date, string $repeat, ?string $until, array $weekdays): array
    {
        if ($repeat === 'once') {
            return [$date];
        }

        if ($until === null || $until < $date) {
            return [];
        }

        $days = array_values(array_unique(array_map('intval', array_filter($weekdays, 'is_numeric'))));
        $out = [];
        $cursor = Carbon::parse($date);
        $stop = Carbon::parse($until);

        while ($cursor->lessThanOrEqualTo($stop) && count($out) <= self::MAX_REPEAT) {
            if ($repeat === 'daily' || in_array($cursor->dayOfWeekIso, $days, true)) {
                $out[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return $out;
    }

    /** "06:00" or "6:00" → "06:00"; anything else is no time. */
    private static function clockTime(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m) !== 1) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    /** "12 hours", "8 hours 30 minutes". */
    private static function hours(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h > 0 ? $h.' hour'.($h === 1 ? '' : 's') : '').($m > 0 ? ' '.$m.' minutes' : ''));
    }

    private static function day(CarbonInterface $at, string $tenant): string
    {
        return SiteTime::local($at, $tenant)->format('D j M');
    }

    /** @return Answer */
    private static function refused(string $why): array
    {
        return ['ok' => false, 'count' => 0, 'hours' => '', 'zone' => null, 'occurrences' => [], 'guards' => [], 'checks' => [['ok' => false, 'text' => $why]]];
    }
}
