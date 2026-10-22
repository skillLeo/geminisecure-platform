<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\AuditEntry;
use App\Models\DispatchMessage;
use App\Models\GuardRequest;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Support\SiteTime;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * What guards are asking dispatch for — board screen super-admin-16.
 *
 * Leave, equipment and messages, in one inbox, because a dispatcher's question
 * is "what is waiting on me" rather than "what kind of thing is waiting on me".
 * The board tabs them and counts each, so the counts have to be real: a tab
 * reading "(2)" that opens onto three rows is worse than no count.
 *
 * A DECISION HERE CHANGES A ROSTER. Approving leave takes a guard off post for
 * eight days, which is why deciding needs `dispatch.update` rather than
 * `dispatch.view` — an Admin Assistant may watch this queue all day and may not
 * empty it — and why every decision is audited with the decider's name.
 *
 * THE LEAVE BALANCE IS SHOWN BEFORE THE DECISION, NOT AFTER. "Balance after: 6
 * days remaining" is the fact the dispatcher is deciding on, and computing it
 * afterwards would be telling them what they already did.
 */
class RequestInbox
{
    /**
     * The leave subjects that draw down an entitlement.
     *
     * Matched on `subject`, not `kind`. `kind` says whether this is leave or
     * equipment; `subject` says which leave — Vacation, Sick, Bereavement — and
     * only vacation comes off the annual entitlement. Sick leave is a statutory
     * right in Jamaica and is not deducted from it.
     */
    private const DEDUCTS_BALANCE = ['vacation'];

    /**
     * Everything the inbox draws.
     *
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer): array
    {
        $pending = $this->pending($viewer);

        $leave = array_values(array_filter(
            $pending,
            static fn (array $row): bool => $row['group'] === GuardRequest::LEAVE,
        ));

        $equipment = array_values(array_filter(
            $pending,
            static fn (array $row): bool => $row['group'] === GuardRequest::EQUIPMENT,
        ));

        return [
            'leave' => $leave,
            'equipment' => $equipment,
            'messages' => $this->messages($viewer),
            'canDecide' => $viewer->can('gemini.dispatch.update'),
            'decideBlockedReason' => 'Deciding a request changes a roster, and needs Dispatch update access. You are able to read this queue.',
        ];
    }

    /**
     * Approve or deny one request.
     *
     * @param  array<string, mixed>  $input
     */
    public function decide(User $actor, int $requestId, array $input): void
    {
        $denial = ($input['decision'] ?? null) === 'denied';

        $data = Validator::make($input, [
            'decision' => ['required', Rule::in(['approved', 'denied'])],

            /*
             * Required on a denial, optional on an approval. A guard told no
             * without a reason has been given a decision they can neither act
             * on nor appeal; a guard told yes does not need one.
             *
             * The trim is not decoration. A space satisfies `required` and
             * satisfies nobody reading the refusal.
             */
            'note' => $denial
                ? ['required', 'string', 'max:500', new class implements ValidationRule
                {
                    public function validate(string $attribute, mixed $value, Closure $fail): void
                    {
                        if (trim((string) $value) === '') {
                            $fail('A denial needs a reason. The guard sees it, and it is what they act on.');
                        }
                    }
                }]
                : ['nullable', 'string', 'max:500'],
        ], [
            'note.required' => 'A denial needs a reason. The guard sees it, and it is what they act on.',
        ])->validate();

        /*
         * Normalised here, once. `validate()` returns only the keys that were
         * actually sent, so an approval submitted without a note field at all
         * has no `note` key — not a null one — and everything downstream would
         * have to remember that.
         */
        $decision = (string) $data['decision'];
        $note = trim((string) ($data['note'] ?? ''));
        $note = $note === '' ? null : $note;

        DB::connection('mysql')->transaction(function () use ($actor, $requestId, $decision, $note): void {
            /*
             * NARROWED, EXACTLY AS THE INBOX THAT DREW THE BUTTON IS. This read took the id
             * from the URL and nothing else, so a Head of Security scoped to one estate could
             * decide any estate's leave by typing its number — ids are sequential — and the
             * audit entry below was filed under the other estate. A request outside the
             * decider's scope, or at the demonstration estate `scoped()` names, is answered
             * exactly as one that does not exist: the same sentence, nothing written, and no
             * way to tell from outside which of the two it was.
             *
             * LOCKED, because "two dispatchers on the same queue is normal" (below) was true
             * and not handled. A plain read let both of them see `pending`; the second UPDATE
             * then waited on the first one's row lock, matched on the id alone and overwrote
             * the decision, and both wrote an audit entry. FOR UPDATE makes the second reader
             * wait here instead, so it reads what the first one decided and is told so.
             *
             * The request row alone is locked. The officer's name is read after it, without a
             * lock: a decision is about the request, and holding the guard's row for it would
             * block a roster save for no reason.
             */
            $request = $this->scoped($actor, DB::connection('mysql')->table('guard_requests'))
                ->where('guard_requests.id', $requestId)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw new RuntimeException('That request no longer exists.');
            }

            if ($request->status !== 'pending') {
                /*
                 * Two dispatchers on the same queue is normal, and the second
                 * one must not silently overwrite the first. The message names
                 * the outcome so they know it was handled rather than lost.
                 */
                throw new RuntimeException(
                    sprintf('That request was already %s by someone else.', $request->status),
                );
            }

            /*
             * Still conditional on `pending`, and the count is checked. The lock above is what
             * makes a second decision wait; this is what makes a write that somehow got past it
             * — a caller that reached this row another way — change nothing rather than
             * overwrite, and say so rather than audit a decision that did not land.
             */
            $changed = DB::connection('mysql')->table('guard_requests')
                ->where('id', $requestId)
                ->where('status', 'pending')
                ->update([
                    'status' => $decision,
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                    'decision_note' => $note,
                    'updated_at' => now(),
                ]);

            if ($changed !== 1) {
                throw new RuntimeException('That request was decided by someone else while you were deciding it. Reload the inbox to see what was decided.');
            }

            $guard = DB::connection('mysql')->table('guards')->where('id', $request->guard_id)->value('full_name');

            AuditEntry::create([
                'tenant_id' => $request->tenant_id,
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'actor_role' => $actor->roles->isEmpty() ? 'No role assigned' : $actor->roles->first()->label,
                'action' => 'dispatch.request_'.$decision,
                'entity_type' => 'guard_request',
                'entity_id' => (string) $requestId,
                'before' => ['status' => 'pending'],
                'after' => [
                    'status' => $decision,
                    'guard' => $guard === null ? null : (string) $guard,
                    'kind' => $request->kind,
                    'note' => $note,
                ],
            ]);
        });
    }

    /**
     * Requests already decided, newest decision first — the inbox's history
     * (12 §2, Wave 4).
     *
     * READ FROM THE ROWS, NOT FROM THE AUDIT LOG. Every decision writes an audit
     * entry, and the audit screen already lists those; this is the dispatch
     * view of the same decisions — what was asked, what was decided, by whom
     * and when — and the row carries all four. A second read of the audit log
     * would be the history of the log rather than of the requests.
     *
     * Paged, because an inbox that has run for a year has a year of decisions.
     *
     * PAGED BY A CURSOR, NOT BY AN OFFSET, and that is a correctness fix rather than a
     * tidy-up. This list is ordered by WHEN A DECISION WAS TAKEN, newest first, so every
     * new decision lands at the TOP of it — and two dispatchers on one queue is the normal
     * case (see `decide`). With `OFFSET 25` a decision taken while somebody was reading
     * page one pushed every row down by one, and row 25 was then served again as row 26:
     * the reader saw the same decision twice, on the one screen anybody reads later. The
     * cursor names the last row the reader actually SAW — its decision time and its id —
     * so the page after it starts where they stopped, whatever arrived above.
     *
     * The id is half the cursor because `decided_at` is not unique: two dispatchers
     * clearing a queue land in the same second often, and a cursor of time alone would
     * skip whichever of them sorted second. Same shape as the Resident App's long lists
     * (`Api\V1\Resident\CommunityController`) — time AND id, compared in the same
     * direction as the order.
     *
     * @param  string|null  $before  a `next_before` this method handed out; anything else is read as "from the newest"
     * @return array{rows: list<array<string, mixed>>, next_before: string|null}
     */
    public function history(User $viewer, ?string $before = null, int $perPage = 25): array
    {
        $cursor = self::cursor($before);

        $rows = $this->scoped($viewer, DB::connection('mysql')
            ->table('guard_requests')
            ->join('guards', 'guards.id', '=', 'guard_requests.guard_id')
            ->leftJoin('users', 'users.id', '=', 'guard_requests.decided_by'))
            ->where('guard_requests.status', '!=', 'pending')
            ->when($cursor !== null, static fn ($q) => $q->where(static fn ($older) => $older
                ->where('guard_requests.decided_at', '<', $cursor[0])
                ->orWhere(static fn ($same) => $same
                    ->where('guard_requests.decided_at', $cursor[0])
                    ->where('guard_requests.id', '<', $cursor[1]))))
            ->orderByDesc('guard_requests.decided_at')
            ->orderByDesc('guard_requests.id')
            ->limit($perPage + 1)
            ->select([
                'guard_requests.id',
                'guard_requests.kind',
                'guard_requests.subject',
                'guard_requests.quantity',
                'guard_requests.starts_on',
                'guard_requests.ends_on',
                'guard_requests.reason',
                'guard_requests.certificate_attached',
                'guard_requests.status',
                'guard_requests.decided_at',
                'guard_requests.decision_note',
                'guards.id as guard_id',
                'guards.tenant_id',
                'guards.full_name',
                'guards.leave_entitlement_days',
                'users.name as decided_by_name',
            ])
            ->get();

        $page = $rows->take($perPage);
        $last = $page->last();
        $taken = $this->leaveTakenBy($page);

        return [
            'rows' => $page->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'group' => (string) $row->kind,
                'initials' => $this->initials((string) $row->full_name),
                'title' => sprintf('%s — %s', $row->full_name, $row->subject),

                // What was asked, in the same words the inbox used at the time.
                'detail' => $row->kind === GuardRequest::EQUIPMENT
                    ? $this->equipmentDetail($row)
                    : $this->leaveDetail($row, $taken),
                'status' => (string) $row->status,
                'status_label' => ucfirst((string) $row->status),
                'decided_by' => $row->decided_by_name === null ? 'A former user' : (string) $row->decided_by_name,
                /*
                 * WHEN, ON THE CLOCK OF THE ESTATE THIS OFFICER STANDS AT (M4 §3.1). The column
                 * holds a true UTC instant, so its raw digits name the wrong hour on every board
                 * that prints them.
                 */
                'decided_at' => $row->decided_at === null
                    ? ''
                    : SiteTime::stamp(Carbon::parse((string) $row->decided_at, 'UTC'), (string) $row->tenant_id, 'M j, Y g:i A'),
                'note' => $row->decision_note === null ? null : (string) $row->decision_note,
            ])->values()->all(),

            /*
             * Where the next page starts, or null when there is nothing older. A row with no
             * `decided_at` at all (a status set by something other than `decide`) cannot be
             * paged past, so the list ends there rather than handing out a cursor that would
             * serve it again for ever.
             */
            'next_before' => $rows->count() > $perPage && $last !== null && $last->decided_at !== null
                ? self::cursorOf((string) $last->decided_at, (int) $last->id)
                : null,
        ];
    }

    /* ── the cursor ───────────────────────────────────────────────────────────
     *
     * Opaque on purpose: it is a place in a list, not an address a reader should be
     * composing by hand. Kept in this class rather than shared, because the Resident
     * App's is part of a published API contract and this one is a console link — the
     * two must be free to change without either breaking the other.
     */

    /**
     * A cursor this class handed out, or null.
     *
     * A STALE OR HAND-TYPED `before` IS NOT AN ERROR HERE. This is a page reached by a
     * link, not an API call: a dispatcher who bookmarked "Older" months ago is shown the
     * newest decisions rather than a refusal they can do nothing with. (The Resident App's
     * identical cursor answers `422 cursor_invalid`, because a phone that sends a cursor
     * it was never given has a bug worth surfacing.)
     *
     * @return array{0: string, 1: int}|null
     */
    private static function cursor(?string $before): ?array
    {
        if ($before === null || $before === '' || strlen($before) > 120) {
            return null;
        }

        $decoded = base64_decode(strtr($before, '-_', '+/'), true);

        if ($decoded === false || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,18})$/', $decoded, $found) !== 1) {
            return null;
        }

        return [$found[1], (int) $found[2]];
    }

    /** The stored UTC digits and the id — compared against the column, so never the zone it is shown in. */
    private static function cursorOf(string $decidedAt, int $id): string
    {
        return rtrim(strtr(base64_encode(Carbon::parse($decidedAt, 'UTC')->format('Y-m-d H:i:s').'|'.$id), '+/', '-_'), '=');
    }

    /**
     * Every pending request, the one waiting longest first (the rule below; M32 F8 keeps it, and says why).
     *
     * @return list<array<string, mixed>>
     */
    private function pending(User $viewer): array
    {
        $rows = $this->scoped($viewer, DB::connection('mysql')
            ->table('guard_requests')
            ->join('guards', 'guards.id', '=', 'guard_requests.guard_id'))
            ->where('guard_requests.status', 'pending')

            /*
             * Oldest first. This is a queue, and a queue is ordered by how long
             * something has been waiting on a decision — not by when the leave
             * starts, which would push a request made months ago behind one
             * raised this morning for tomorrow.
             */
            ->orderBy('guard_requests.created_at')
            ->orderBy('guard_requests.id')
            ->select([
                'guard_requests.id',
                'guard_requests.kind',
                'guard_requests.subject',
                'guard_requests.quantity',
                'guard_requests.starts_on',
                'guard_requests.ends_on',
                'guard_requests.reason',
                'guard_requests.certificate_attached',
                'guards.id as guard_id',
                'guards.full_name',
                'guards.leave_entitlement_days',
            ])
            ->get();

        $taken = $this->leaveTakenBy($rows);

        return $rows
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'group' => (string) $row->kind,
                'initials' => $this->initials((string) $row->full_name),
                'title' => sprintf('%s — %s', $row->full_name, $row->subject),
                'detail' => $row->kind === GuardRequest::EQUIPMENT
                    ? $this->equipmentDetail($row)
                    : $this->leaveDetail($row, $taken),
                'status' => 'pending',
                'status_label' => 'Pending',
            ])
            ->all();
    }

    /**
     * "Sep 15 – Sep 22, 2026 · 8 days · Balance after: 6 days remaining".
     *
     * @param  array<int, int>  $taken  `leaveTakenBy()` for the rows on this page
     */
    private function leaveDetail(object $row, array $taken): string
    {
        $from = Carbon::parse((string) $row->starts_on);
        $to = $row->ends_on === null ? $from : Carbon::parse((string) $row->ends_on);
        /*
         * Cast, because Carbon 3 returns a FLOAT here. Left alone, a one-day
         * request reads "1 days" — the singular branch below compares against
         * an integer and can never match a float — and the balance arithmetic
         * carries a fraction into a figure a dispatcher decides on.
         *
         * Inclusive of both ends: leave from Monday to Monday is one day off,
         * not none.
         */
        $days = (int) $from->diffInDays($to) + 1;

        $span = $from->isSameDay($to)
            ? $from->format('M j, Y')
            : sprintf('%s – %s', $from->format('M j'), $to->format('M j, Y'));

        $parts = [$span, $days === 1 ? '1 day' : $days.' days'];

        if (self::deductsBalance($row)) {
            /*
             * The entitlement is the guard's own, off their employment record,
             * not a platform-wide figure. Jamaica's Holidays with Pay Act sets a
             * floor; a contract can sit above it, and a dispatcher deciding
             * against the floor when the contract says more would refuse leave
             * the guard has actually earned.
             */
            $entitlement = (int) ($row->leave_entitlement_days ?? 0);
            $parts[] = sprintf('Balance after: %d days remaining', max(0, $entitlement - ($taken[(int) $row->guard_id] ?? 0) - $days));
        } elseif ($row->certificate_attached) {
            /*
             * Sick leave does not draw down the entitlement, so the fact that
             * matters instead is whether it is evidenced. A dispatcher
             * approving uncertified sick leave is making a different decision
             * from one approving certified.
             */
            $parts[] = 'Medical certificate attached';
        } else {
            $parts[] = 'No medical certificate';
        }

        return implode(' · ', $parts);
    }

    /** "Qty 1 · Reason: worn out · Routes to Admin Assistant on approval". */
    private function equipmentDetail(object $row): string
    {
        return implode(' · ', array_filter([
            'Qty '.(int) ($row->quantity ?? 1),
            $row->reason === null ? null : 'Reason: '.$row->reason,
            'Routes to Admin Assistant on approval',
        ]));
    }

    /** Whether this is leave that comes off the annual entitlement — vacation, not sick. */
    private static function deductsBalance(object $row): bool
    {
        return in_array(strtolower((string) $row->subject), self::DEDUCTS_BALANCE, true);
    }

    /**
     * Days of vacation already approved this year, for every guard whose balance a page
     * shows, keyed by guard id. A guard with none approved is absent: nought days.
     *
     * DERIVED, never stored. A running balance column would have to be
     * decremented on approval and put back on every cancellation, reversal and
     * mis-key; the one somebody forgot is the one that refuses a guard leave
     * they are owed. Summing the approved rows cannot fall out of step with
     * them.
     *
     * ONE READ FOR THE PAGE (M11 A2). Each vacation row used to ask this sum for its own
     * guard — sixty queries on an inbox at twelve clients. The same sum, grouped by guard,
     * over only the guards a vacation row on the page belongs to.
     *
     * @param  iterable<object>  $rows  the page's request rows
     * @return array<int, int>
     */
    private function leaveTakenBy(iterable $rows): array
    {
        $guardIds = [];

        foreach ($rows as $row) {
            if ($row->kind !== GuardRequest::EQUIPMENT && self::deductsBalance($row)) {
                $guardIds[(int) $row->guard_id] = true;
            }
        }

        if ($guardIds === []) {
            return [];
        }

        return DB::connection('mysql')
            ->table('guard_requests')
            ->whereIn('guard_id', array_keys($guardIds))
            ->where('status', 'approved')
            ->where(static function (Builder $subjects): void {
                // Compared in lower case explicitly. MySQL's default collation
                // would match "Vacation" anyway, and a schema that changed
                // collation later would silently stop deducting leave.
                foreach (self::DEDUCTS_BALANCE as $subject) {
                    $subjects->orWhereRaw('LOWER(subject) = ?', [$subject]);
                }
            })
            ->whereYear('starts_on', now()->year)
            ->groupBy('guard_id')
            ->selectRaw('guard_id, COALESCE(SUM(DATEDIFF(COALESCE(ends_on, starts_on), starts_on) + 1), 0) as days')
            ->pluck('days', 'guard_id')
            ->mapWithKeys(static fn (mixed $days, mixed $guardId): array => [(int) $guardId => (int) $days])
            ->all();
    }

    /**
     * Recent traffic between dispatch and the posts.
     *
     * `dispatch_messages`, NOT `client_messages`. The two look alike and are
     * not: client messages are correspondence with an estate's committee about
     * their account, and belong on the client screen. These are operational
     * traffic to and from guards on shift, and a dispatcher reading their inbox
     * needs the second and would be misled by the first.
     *
     * Two directions and two different headings, because a broadcast is
     * addressed to a post and an inbound message comes from a named guard.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(User $viewer, int $limit = 4): array
    {
        return $this->scoped($viewer, DB::connection('mysql')
            ->table('dispatch_messages')
            ->join('tenants', 'tenants.id', '=', 'dispatch_messages.tenant_id')
            ->leftJoin('guards', 'guards.id', '=', 'dispatch_messages.guard_id'), 'dispatch_messages.tenant_id')
            ->orderByDesc('dispatch_messages.sent_at')
            ->limit($limit)
            ->select([
                'dispatch_messages.direction',
                'dispatch_messages.body',
                'dispatch_messages.sent_at',
                'guards.full_name',
                'tenants.name as estate',
                'tenants.timezone as zone',
            ])
            ->get()
            ->map(function (object $row): array {
                $broadcast = $row->direction === DispatchMessage::BROADCAST;

                return [
                    /*
                     * A broadcast carries a single "D" on a solid navy chip
                     * rather than a person's initials, because it was not sent
                     * by a person to a person — it went to every guard on the
                     * estate, and initialling it with the dispatcher who typed
                     * it would read as a private message.
                     */
                    'initials' => $broadcast ? 'D' : $this->initials((string) ($row->full_name ?? 'Guard')),
                    'broadcast' => $broadcast,
                    'title' => $broadcast
                        ? sprintf('Broadcast — %s, all guards', $row->estate)
                        : sprintf('%s → Dispatch', $row->full_name ?? 'Unnamed guard'),
                    'body' => '"'.$this->truncate((string) $row->body).'"',
                    // "Today" is the ESTATE'S today, and the hour is the estate's hour (M4 §3.1).
                    'time' => $this->relativeTime(
                        Carbon::parse((string) $row->sent_at, 'UTC')->setTimezone(SiteTime::valid($row->zone)),
                    ),
                ];
            })
            ->all();
    }

    /** "Today, 6:15 AM" — the board's own form, naming the estate's zone where it is not Gemini's (M11 §4.1). */
    private function relativeTime(Carbon $at): string
    {
        return match (true) {
            $at->isToday() => 'Today, '.$at->format('g:i A'),
            $at->isYesterday() => 'Yesterday, '.$at->format('g:i A'),
            default => $at->format('M j, g:i A'),
        }.SiteTime::label($at);
    }

    private function truncate(string $body, int $length = 160): string
    {
        return mb_strlen($body) <= $length ? $body : mb_substr($body, 0, $length - 1).'…';
    }

    /**
     * Narrow to the estates this viewer may see — in the query, never the render.
     *
     * Scope and the demonstration estate together (M5 §3). A reviewer tapping
     * "Request leave" in the Guard App is the product working; a Gemini
     * supervisor approving that leave for an officer who does not exist is not.
     */
    private function scoped(User $viewer, Builder $query, string $column = 'guard_requests.tenant_id'): Builder
    {
        OperatorEstates::narrow($query, $viewer, $column);

        return $query;
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }
}
