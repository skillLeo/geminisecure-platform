<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Services\Push\PushAnnouncer;
use App\Support\CalendarDate;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The forward roster — boards 26, 20 and 21's remaining writes (12 §2, Wave 5).
 *
 * AN OPEN SHIFT IS A REAL ROW WITH NO OFFICER ON IT. That is the whole shape of
 * this module: a post needing somebody is a fact the operations console has to
 * be able to hold, and the alternatives are both worse. A placeholder guard
 * would appear on the coverage board as somebody standing an empty gate; not
 * recording it at all would mean the gap exists only in a dispatcher's head.
 *
 * A SHIFT ALREADY WORKED IS NEVER TOUCHED, because its `actual_start` is the
 * evidence a post was covered. Releasing one to open clears the officer and says
 * why. Only a shift that has not started can be removed (M30 §5.2): it is a plan
 * nobody has acted on yet, and the audit log keeps what it was and why it went.
 */
class Roster
{
    /** How far ahead a shift may be posted. */
    public const HORIZON_DAYS = 180;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Post an open shift — a post, a window, and nobody yet.
     *
     * REFUSED IN THE PAST. A roster is forward-looking: posting a shift for
     * last Tuesday would be inventing a gap nobody could have filled, and the
     * coverage board would then report an estate as uncovered on a night it was
     * covered.
     */
    public function postOpenShift(Post $post, string $start, string $end, User $by, ?string $reason = null, string $duty = Shift::DUTY_POST): Shift
    {
        if (! in_array($duty, Shift::DUTIES, true)) {
            throw new DomainException('A shift stands its post, or patrols from it.');
        }

        /*
         * ONE TIME RULE (M4 §3.1). The dispatcher types a wall-clock time AT THE POST — "07:00"
         * is seven in the morning at that estate — so it is read in that estate's zone and
         * kept as the UTC moment it means. "In the past" is before that estate's today began.
         */
        $site = (string) $post->tenant_id;
        $from = SiteTime::parse($start, $site);
        $to = SiteTime::parse($end, $site);

        $this->checkWindow($post, $from, $to, $duty);

        $shift = Shift::create([
            'tenant_id' => (string) $post->tenant_id,
            'guard_id' => null,
            'post_id' => $post->id,
            'duty' => $duty,
            'rostered_start' => $from,
            'rostered_end' => $to,
            'status' => Shift::OPEN,
            'released_reason' => $reason,
            'posted_by_id' => $by->getKey(),
            'posted_by_name' => (string) $by->name,
        ]);

        $this->audit->record(
            action: 'roster.shift_posted',
            entityType: 'Shift',
            entityId: (string) $shift->id,
            after: [
                'post' => (string) $post->name,
                'duty' => $duty,
                'from' => SiteTime::iso($from),
                'to' => SiteTime::iso($to),
                'timezone' => SiteTime::zone($site),
                'reason' => $reason,
            ],
            tenantId: (string) $post->tenant_id,
        );

        return $shift;
    }

    /**
     * Post a shift WITH ITS OFFICER, in one act (M30 §5.1): the schedule's panel chooses the guard as it creates the
     * shift. Every rule an open shift is held to, and every rule an assignment is held to, before anything is written;
     * and one row written, so the estate's guards are not told a shift is open that was never open. With no officer it
     * is `postOpenShift()` exactly, with the reason it is open when one is given (M35 §2).
     */
    public function postShift(Post $post, string $start, string $end, User $by, string $duty = Shift::DUTY_POST, ?Guard $guard = null, ?string $reason = null): Shift
    {
        if ($guard === null) {
            return $this->postOpenShift($post, $start, $end, $by, $reason, $duty);
        }

        if (! in_array($duty, Shift::DUTIES, true)) {
            throw new DomainException('A shift stands its post, or patrols from it.');
        }

        $site = (string) $post->tenant_id;
        $from = SiteTime::parse($start, $site);
        $to = SiteTime::parse($end, $site);

        $this->checkWindow($post, $from, $to, $duty);
        $this->checkOfficer($guard, $site, $from, $to, null, (string) ($post->estate->name ?? $site));

        $shift = Shift::create([
            'tenant_id' => $site,
            'guard_id' => $guard->id,
            'post_id' => $post->id,
            'duty' => $duty,
            'rostered_start' => $from,
            'rostered_end' => $to,
            'status' => Shift::ROSTERED,
            'released_reason' => null,
            'posted_by_id' => $by->getKey(),
            'posted_by_name' => (string) $by->name,
        ]);

        $this->audit->record(
            action: 'roster.shift_posted',
            entityType: 'Shift',
            entityId: (string) $shift->id,
            after: [
                'post' => (string) $post->name,
                'duty' => $duty,
                'from' => SiteTime::iso($from),
                'to' => SiteTime::iso($to),
                'timezone' => SiteTime::zone($site),
                'guard' => (string) $guard->full_name,
                'by' => (string) $by->name,
            ],
            tenantId: $site,
        );

        return $shift;
    }

    /**
     * Whether a window may be rostered at a post, by the rules an open shift is held to; refused with the sentence if
     * not. `$except` is a shift being changed, which is never its own clash.
     */
    public function checkWindow(Post $post, Carbon $from, Carbon $to, string $duty, ?int $except = null): void
    {
        $site = (string) $post->tenant_id;

        if ($to->lessThanOrEqualTo($from)) {
            throw new DomainException('A shift ends after it starts. A night shift running past midnight is still a start before an end.');
        }

        if ($from->lessThan(SiteTime::dayBounds(null, $site)[0])) {
            throw new DomainException('A roster is forward-looking. Posting a shift in the past would invent a gap nobody could have filled.');
        }

        if ($from->greaterThan(Carbon::now()->addDays(self::HORIZON_DAYS))) {
            throw new DomainException('A shift is posted within the next six months. Further out is a plan rather than a roster.');
        }

        if ($to->diffInHours($from, absolute: true) > 24) {
            throw new DomainException('A shift runs up to twenty-four hours. Longer than that is two shifts, and posting it as one would hide the handover.');
        }

        /*
         * ONE OFFICER STANDS A POST AT A TIME, and one patrols from it (M27). The patrol that starts at the Main Gate
         * is not the gate officer, so a patrol shift and a gate shift side by side are the roster as it should be;
         * two of the same duty at one time are still two officers told the same job is theirs.
         */
        $clash = $this->postClash($post, $from, $to, $duty, $except);

        if ($clash !== null) {
            throw new DomainException(sprintf(
                $duty === Shift::DUTY_PATROL
                    ? '%s already has a patrol shift covering part of that window (%s to %s). Two patrols from one post at one time is two officers told the same round is theirs.'
                    : '%s already has a shift covering part of that window (%s to %s). Two shifts on one post at one time is two officers told the same gate is theirs.',
                $post->label(),
                $clash->rostered_start->format('M j, g:i A'),
                $clash->rostered_end->format('g:i A').SiteTime::label($clash->rostered_end),
            ));
        }
    }

    /** The shift of the same duty at this post whose window overlaps this one, if any. */
    public function postClash(Post $post, Carbon $from, Carbon $to, string $duty, ?int $except = null): ?Shift
    {
        return $this->clashesAt($post, $from, $to, $duty, $except)->first();
    }

    /**
     * Every shift of the same duty at this post overlapping this span, in one read: what `postClash()` asks, for many
     * windows at once (a repeat), each then judged by the same overlap.
     *
     * @return Collection<int, Shift>
     */
    public function postClashes(Post $post, Carbon $from, Carbon $to, string $duty, ?int $except = null): Collection
    {
        return $this->clashesAt($post, $from, $to, $duty, $except)->orderBy('rostered_start')->get();
    }

    /** @return Builder<Shift> */
    private function clashesAt(Post $post, Carbon $from, Carbon $to, string $duty, ?int $except): Builder
    {
        return Shift::query()
            ->where('post_id', $post->id)
            ->where('duty', $duty)
            ->when($except !== null, static fn ($q) => $q->whereKeyNot($except))
            ->whereNotIn('status', Shift::SETTLED)
            ->where('rostered_start', '<', $to)
            ->where('rostered_end', '>', $from);
    }

    /**
     * Put an officer on an open shift.
     *
     * THE OFFICER HAS TO BE ABLE TO STAND IT. A suspended guard, or one whose
     * PSRA licence has expired, cannot legally be posted — and a roster that
     * let one be assigned would be the console producing the very breach the
     * compliance screens exist to prevent.
     *
     * AND BE ABLE TO CLOCK IN TO IT (bug hunt B005). A shift is at one estate, and
     * an officer's handset reaches only the shifts of the estate they are posted
     * at: `ResolveDevice` builds its context from the guard's own `tenant_id`, and
     * clock-in and pre-flight answer 404 for any other estate's shift. Rostering
     * an officer from Estate A onto Estate B's gate made the coverage board read
     * the gate as covered by somebody who could never start the shift. So the
     * officer must be posted at the shift's estate — the rule `reassign()` already
     * keeps when it releases a moved officer's shifts, and open-shift claiming
     * keeps by offering a guard only their own estate's.
     *
     * The statuses and the licence are `Guard::OFF_POST_STATUSES` and the licence
     * on file — the same rule the picker applies, so nothing the picker withholds
     * can arrive here by a hand-made POST (bug hunt B071).
     */
    public function assign(Shift $shift, Guard $guard, User $by): Shift
    {
        if ($shift->status !== Shift::OPEN) {
            throw new DomainException('That shift already has an officer on it. Release it first if somebody else is to stand it.');
        }

        $this->checkOfficer($guard, (string) $shift->tenant_id, $shift->rostered_start, $shift->rostered_end, (int) $shift->id, (string) ($shift->estate->name ?? $shift->tenant_id));

        $shift->forceFill([
            'guard_id' => $guard->id,
            'status' => Shift::ROSTERED,
            'released_reason' => null,
        ])->save();

        $this->audit->record(
            action: 'roster.shift_assigned',
            entityType: 'Shift',
            entityId: (string) $shift->id,
            after: ['guard' => (string) $guard->full_name, 'by' => (string) $by->name],
            tenantId: (string) $shift->tenant_id,
        );

        return $shift;
    }

    /**
     * Whether this officer may stand a shift in this window at this estate, by every rule an assignment is held to;
     * refused with the sentence if not. `$except` is a shift being changed, never its own clash.
     */
    public function checkOfficer(Guard $guard, string $tenantId, Carbon $from, Carbon $to, ?int $except = null, ?string $here = null): void
    {
        $here ??= $tenantId;

        if ($guard->status === 'suspended') {
            throw new DomainException($guard->full_name.' is suspended from duty and cannot be posted.');
        }

        if ($guard->status === 'inactive') {
            throw new DomainException($guard->full_name.' is no longer an active employee and cannot be posted.');
        }

        if ($guard->status === 'licence_expired') {
            throw new DomainException($guard->full_name.'\'s record is marked licence expired. Record the renewed licence on their record before posting them.');
        }

        if ((string) $guard->tenant_id !== $tenantId) {
            throw new DomainException($guard->tenant_id === null
                ? sprintf('%s is not posted to any client, and an officer\'s handset reaches only the shifts of the estate they are posted at. Post them to %s first.', $guard->full_name, $here)
                : sprintf('%s is posted at %s, and their handset reaches only that estate\'s shifts, so they could not clock in to one at %s. Reassign them first, or choose an officer posted at %s.', $guard->full_name, (string) ($guard->estate->name ?? $guard->tenant_id), $here, $here));
        }

        $expires = $guard->psra_expires_on;

        if ($expires === null) {
            throw new DomainException(sprintf(
                '%s has no PSRA licence expiry on file, so %s cannot be shown to be licensed. Record the licence on their record first.',
                $guard->psra_number,
                $guard->full_name,
            ));
        }

        /*
         * THE LICENCE HAS TO COVER THE WHOLE SHIFT, ON THE ESTATE'S CALENDAR (bug hunt B008).
         *
         * A licence dated the 24th is good through the end of the 24th where the post is, and
         * lapses at the first moment of the 25th there (`Guard::licenceDaysLeft()`: 0 is "expires
         * today and is still good today"). This compared the expiry date — read as midnight UTC —
         * with the shift's start, so from 19:00 in Kingston the evening before, every shift was
         * refused, a 07:00 shift on the licence's last good day included, with a message saying
         * the licence expired before it started. A night shift that starts on the last good day
         * and runs past midnight is still refused, and now says why: for the rest of it the
         * officer holds no licence.
         */
        $lapsesAt = self::licenceLapsesAt($expires, $tenantId);

        if ($to->greaterThan($lapsesAt)) {
            throw new DomainException(sprintf(
                '%s\'s PSRA licence runs to the end of %s, %s. Posting them would be rostering an officer who cannot legally stand the post.',
                $guard->full_name,
                $expires->format('M j, Y'),
                $from->greaterThanOrEqualTo($lapsesAt) ? 'before this shift starts' : 'part-way through this shift',
            ));
        }

        /*
         * THE TWO RULES M30 ADDS (§10), after every rule that was here before them, so both the classic roster and the
         * schedule are held to them: an officer stands one shift at a time, and is not put on a shift on a day of
         * approved leave.
         */
        $conflicts = app(ShiftConflicts::class);

        if (($clash = $conflicts->clash($guard, $from, $to, $except)) !== null) {
            throw new DomainException(sprintf(
                '%s is already on %s, which overlaps this shift. One officer cannot stand two shifts at once.',
                $guard->full_name,
                ShiftConflicts::describe($clash),
            ));
        }

        if (($leave = $conflicts->leave($guard, $from, $to, $tenantId)) !== null) {
            throw new DomainException(sprintf(
                '%s is on approved leave from %s to %s, so cannot be put on this shift.',
                $guard->full_name,
                $leave->starts_on?->format('D j M'),
                $leave->ends_on?->format('D j M Y'),
            ));
        }
    }

    /**
     * The moment a licence with this expiry date stops covering an officer at this
     * estate: the first moment of the following day, on the estate's own clock.
     */
    public static function licenceLapsesAt(Carbon $expiresOn, string $tenantId): Carbon
    {
        return SiteTime::dayBounds($expiresOn->copy()->addDay()->toDateString(), $tenantId)[0];
    }

    /**
     * Release an officer's future shifts back to open — boards 20 and 21.
     *
     * FUTURE ONLY, AND NEVER ONE ALREADY WORKED. A shift with an `actual_start`
     * is evidence that a post was covered; clearing its officer would erase who
     * covered it. What this does is make tomorrow's gaps visible so somebody
     * can fill them.
     *
     * @return int how many shifts were released
     */
    public function releaseFutureShifts(Guard $guard, string $reason, User $by): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why these shifts are being released. A dispatcher picking one up needs to know whether the estate asked for cover or an officer was stood down.');
        }

        $shifts = Shift::query()
            ->where('guard_id', $guard->id)
            ->whereNull('actual_start')
            ->where('rostered_start', '>', Carbon::now())
            ->whereNotIn('status', Shift::SETTLED)
            ->get();

        foreach ($shifts as $shift) {
            $shift->forceFill([
                'guard_id' => null,
                'status' => Shift::OPEN,
                'released_reason' => $reason,
                'posted_by_id' => $by->getKey(),
                'posted_by_name' => (string) $by->name,
            ])->save();
        }

        if ($shifts->isNotEmpty()) {
            $this->audit->record(
                action: 'roster.shifts_released',
                entityType: 'Guard',
                entityId: (string) $guard->id,
                after: [
                    'guard' => (string) $guard->full_name,
                    'shifts' => $shifts->count(),
                    'reason' => $reason,
                ],
                tenantId: $guard->tenant_id === null ? null : (string) $guard->tenant_id,
            );
        }

        return $shifts->count();
    }

    /**
     * CHANGE ONE SHIFT (M30 §5.2): its gate, its window, what he does there and who stands it, in one act, held to every
     * rule a new shift and an assignment are held to (this shift never its own clash).
     *
     * A shift that has started keeps its gate and times; only who stands it can change, which is how a no-show is
     * covered. Once its guard has clocked in, or it has ended, nothing on it changes: it is the record of a post worked.
     * Replacing a guard, or taking him off, needs a reason, because his phone is told ("A shift is no longer yours").
     * The guard put on it is told by the shift's own change ("Your roster changed"); claims on an open shift that is
     * given to somebody are answered as the claims screen answers them.
     */
    public function changeShift(Shift $shift, Post $post, Carbon $from, Carbon $to, string $duty, ?Guard $guard, User $by, ?string $reason = null): Shift
    {
        $current = $shift->officer;
        self::refuseIfWorked($shift);

        if (! in_array($duty, Shift::DUTIES, true)) {
            throw new DomainException('A shift stands its post, or patrols from it.');
        }

        $tenant = (string) $shift->tenant_id;

        if ((string) $post->tenant_id !== $tenant) {
            throw new DomainException('A shift moves between this client\'s own gates. Add a new shift at the other client instead.');
        }

        $windowChanged = (int) $post->id !== (int) $shift->post_id
            || $duty !== (string) ($shift->duty ?? Shift::DUTY_POST)
            || ! $from->equalTo($shift->rostered_start)
            || ! $to->equalTo($shift->rostered_end);

        if ($windowChanged && ! $shift->rostered_start->isFuture()) {
            throw new DomainException('This shift has started, so its gate and times stay as they are. Who stands it can still change.');
        }

        if ($windowChanged) {
            $this->checkWindow($post, $from, $to, $duty, (int) $shift->id);
        }

        $previous = $current?->id;
        $guardChanged = ($guard?->id) !== $previous;

        if ($guardChanged && $current !== null) {
            $reason = self::reasonOf((string) $reason, 'Say why '.$current->full_name.' comes off this shift. Their phone is told.');
        }

        if ($guard !== null && ($guardChanged || $windowChanged)) {
            $this->checkOfficer($guard, $tenant, $from, $to, (int) $shift->id, (string) ($shift->estate->name ?? $tenant));
        }

        $before = self::snapshot($shift);
        $answered = ['approved' => null, 'declined' => []];

        DB::connection('mysql')->transaction(function () use ($shift, $post, $from, $to, $duty, $guard, $current, $guardChanged, $reason, $by, $before, &$answered): void {
            $shift->forceFill([
                'post_id' => $post->id,
                'duty' => $duty,
                'rostered_start' => $from,
                'rostered_end' => $to,
                'guard_id' => $guard?->id,
                'status' => $guard === null ? Shift::OPEN : Shift::ROSTERED,
                'released_reason' => $guard !== null ? null : ($current !== null ? $reason : $shift->released_reason),
            ])->save();

            if ($guardChanged) {
                $answered = $this->answerClaims($shift, $current, $guard, $by, $reason);
            }

            $shift->setRelation('post', $post);

            $this->audit->record(
                action: 'roster.shift_changed',
                entityType: 'Shift',
                entityId: (string) $shift->id,
                before: $before,
                after: [...self::snapshot($shift), 'reason' => $reason, 'by' => (string) $by->name],
                tenantId: (string) $shift->tenant_id,
            );
        });

        DB::connection('mysql')->afterCommit(function () use ($shift, $current, $guardChanged, $reason, $answered): void {
            $announcer = app(PushAnnouncer::class);

            if ($guardChanged && $current !== null) {
                $announcer->shiftTakenOff($shift, (int) $current->id, (string) $reason);
            }

            if ($answered['approved'] !== null) {
                $announcer->claimDecided($shift, $answered['approved'], true);
            }

            foreach ($answered['declined'] as $guardId) {
                $announcer->claimDecided($shift, $guardId, false);
            }
        });

        return $shift->refresh();
    }

    /**
     * Take the guard off one shift (M30 §5.2). The shift goes back to "Needs a guard" with the reason on it, so somebody
     * else can be put on it, and his phone is told. Refused once he has clocked in: he is on duty, and clocks out to end
     * it. A shift that has started with nobody clocked in CAN be taken off — that is how a no-show is covered.
     */
    public function takeOff(Shift $shift, string $reason, User $by): Guard
    {
        $guard = $shift->officer;

        if ($guard === null) {
            throw new DomainException('Nobody is on this shift, so there is nobody to take off it.');
        }

        // On duty, or worked, first: that is the sentence the person needs, whatever the status says.
        self::refuseIfWorked($shift);

        if ($shift->status !== Shift::ROSTERED) {
            throw new DomainException('Nobody is on this shift, so there is nobody to take off it.');
        }

        $reason = self::reasonOf($reason, 'Say why '.$guard->full_name.' comes off this shift. Their phone is told.');

        DB::connection('mysql')->transaction(function () use ($shift, $guard, $reason, $by): void {
            $shift->forceFill([
                'guard_id' => null,
                'status' => Shift::OPEN,
                'released_reason' => $reason,
            ])->save();

            // His approved claim on it, if he claimed it, is answered "withdrawn": it is no longer his.
            DB::connection('mysql')->table('shift_claims')
                ->where('shift_id', $shift->id)->where('guard_id', $guard->id)
                ->update(['status' => 'withdrawn', 'decision_note' => mb_substr($reason, 0, 190), 'updated_at' => now()]);

            $this->audit->record(
                action: 'roster.shift_taken_off',
                entityType: 'Shift',
                entityId: (string) $shift->id,
                before: ['guard' => (string) $guard->full_name],
                after: ['guard' => null, 'status' => Shift::OPEN, 'reason' => $reason, 'by' => (string) $by->name],
                tenantId: (string) $shift->tenant_id,
            );
        });

        DB::connection('mysql')->afterCommit(static fn () => app(PushAnnouncer::class)->shiftTakenOff($shift, (int) $guard->id, $reason));

        return $guard;
    }

    /**
     * Remove one shift that has not started (M30 §5.2). Nothing was ever done on it, so it goes; the audit log keeps
     * what it was, who removed it and why. A guard on it is told on his phone. Claims on it go with it. One that has
     * started stays: an empty gate that was on the roster is a gap the record must keep.
     */
    public function removeShift(Shift $shift, string $reason, User $by): void
    {
        if ($shift->actual_start !== null || ! $shift->rostered_start->isFuture()) {
            throw new DomainException('This shift has started, so it stays on the record. Only a shift that has not started can be removed.');
        }

        $reason = self::reasonOf($reason, 'Say why this shift is removed. It is kept on the audit log.');
        $guard = $shift->officer;
        $tenant = (string) $shift->tenant_id;
        $local = SiteTime::local($shift->rostered_start, $tenant);
        $what = trim($shift->postLabel().' · '.$local->format('D j M, g:i A').SiteTime::label($local), ' ·');
        $shiftId = (int) $shift->id;
        $simulated = (bool) $shift->is_simulated;

        DB::connection('mysql')->transaction(function () use ($shift, $reason, $by, $tenant): void {
            $this->audit->record(
                action: 'roster.shift_removed',
                entityType: 'Shift',
                entityId: (string) $shift->id,
                before: [...self::snapshot($shift), 'timezone' => SiteTime::zone($tenant)],
                after: ['reason' => $reason, 'by' => (string) $by->name],
                tenantId: $tenant,
            );

            $shift->delete();
        });

        if ($guard !== null && ! $simulated) {
            DB::connection('mysql')->afterCommit(static fn () => app(PushAnnouncer::class)->shiftRemoved($tenant, $shiftId, (int) $guard->id, $what, $reason));
        }
    }

    /**
     * The claims on a shift whose guard changed, answered: the new guard's own claim approved and every other pending
     * one declined ("Another officer was given this shift"), as approving a claim does; the guard taken off has his
     * approved claim withdrawn. Who is to be told, for after the commit.
     *
     * @return array{approved: int|null, declined: list<int>}
     */
    private function answerClaims(Shift $shift, ?Guard $previous, ?Guard $next, User $by, ?string $reason): array
    {
        $claims = DB::connection('mysql')->table('shift_claims');
        $decided = ['decided_by' => $by->getKey(), 'decided_by_name' => (string) $by->name, 'decided_at' => now(), 'updated_at' => now()];

        if ($previous !== null) {
            (clone $claims)->where('shift_id', $shift->id)->where('guard_id', $previous->id)
                ->update(['status' => 'withdrawn', 'decision_note' => mb_substr((string) $reason, 0, 190), 'updated_at' => now()]);
        }

        if ($next === null) {
            return ['approved' => null, 'declined' => []];
        }

        $pending = (clone $claims)->where('shift_id', $shift->id)->where('status', ShiftClaims::PENDING)->pluck('guard_id')->map(static fn ($id): int => (int) $id)->all();

        (clone $claims)->where('shift_id', $shift->id)->where('guard_id', $next->id)->where('status', ShiftClaims::PENDING)
            ->update(['status' => ShiftClaims::APPROVED, 'decision_note' => null, ...$decided]);

        $declined = array_values(array_filter($pending, static fn (int $id): bool => $id !== (int) $next->id));

        (clone $claims)->where('shift_id', $shift->id)->whereIn('guard_id', $declined)->where('status', ShiftClaims::PENDING)
            ->update(['status' => ShiftClaims::DECLINED, 'decision_note' => 'Another officer was given this shift.', ...$decided]);

        return ['approved' => in_array((int) $next->id, $pending, true) ? (int) $next->id : null, 'declined' => $declined];
    }

    /**
     * Why nothing on this shift can change any more, or null when it still can: a shift being worked, or worked, is the
     * record of a post covered. The panel shows the same sentence before anyone presses anything.
     */
    public static function whyLocked(Shift $shift): ?string
    {
        if ($shift->actual_start !== null && $shift->actual_end === null) {
            return ($shift->officer->full_name ?? 'The guard').' has clocked in to this shift. Nothing on it changes until they clock out.';
        }

        if ($shift->actual_end !== null || in_array($shift->status, Shift::SETTLED, true) || ! $shift->rostered_end->isFuture()) {
            return 'This shift has ended. It stays as it was worked.';
        }

        return null;
    }

    private static function refuseIfWorked(Shift $shift): void
    {
        if (($why = self::whyLocked($shift)) !== null) {
            throw new DomainException($why);
        }
    }

    /** A reason a person reading the shift or the audit log can act on: at least three characters, at most 190. */
    private static function reasonOf(string $reason, string $ask): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new DomainException($ask);
        }

        return mb_substr($reason, 0, 190);
    }

    /** @return array{post: string, duty: string, from: string|null, to: string|null, guard: string|null} */
    private static function snapshot(Shift $shift): array
    {
        return [
            'post' => $shift->postLabel(),
            'duty' => (string) ($shift->duty ?? Shift::DUTY_POST),
            'from' => SiteTime::iso($shift->rostered_start),
            'to' => SiteTime::iso($shift->rostered_end),
            'guard' => $shift->guard_id === null ? null : (string) (Guard::query()->whereKey($shift->guard_id)->value('full_name') ?? ''),
        ];
    }

    /**
     * How many future shifts an officer holds, for a screen that is about to
     * offer to release them.
     */
    public function futureShiftCount(Guard $guard): int
    {
        return Shift::query()
            ->where('guard_id', $guard->id)
            ->whereNull('actual_start')
            ->where('rostered_start', '>', Carbon::now())
            ->whereNotIn('status', Shift::SETTLED)
            ->count();
    }

    /**
     * The roster for one day, by post.
     *
     * @return array{date: string, previous: string, next: string, rows: list<array<string, mixed>>, open: int}
     */
    public function day(string $date, ?User $viewer = null): array
    {
        /*
         * A calendar date. Which moments it covers depends on the estate — see below.
         *
         * One the calendar has, or the operations room's today (bug hunt B014): this is
         * not a second way in for a date the controller would not take. Carbon threw on
         * 2026-13-45 and rolled 2026-02-30 over to 2 March.
         */
        $on = Carbon::parse(CalendarDate::from(substr(trim($date), 0, 10)) ?? SiteTime::today()->toDateString())->startOfDay();

        $posts = Post::query()
            ->where('is_active', true)
            ->when(
                $viewer !== null && $viewer->widestScope() === AccessScope::AssignedSites,
                static fn ($query) => $query->whereIn('tenant_id', $viewer->accessibleEstateIds()),
            )
            /*
             * Not the demonstration gate (M5 §3). `demo:seed` now lays down a
             * ROLLING FORTNIGHT of twelve-hour watches plus an unassigned open
             * shift, so this board drew a fortnight of invented duty and an
             * "open shift" somebody at Gemini would try to fill — every day,
             * whether or not a reviewer ever signed in.
             */
            ->tap(static fn ($query) => OperatorEstates::exclude($query, 'posts.tenant_id'))
            ->with('estate')
            ->orderBy('name')
            ->get();

        /*
         * THE DAY IS EACH ESTATE'S OWN DAY. This console spans every client, and one date is a
         * different pair of moments in a different zone — so the query reaches a day either
         * side, and a shift is kept when it starts on that date ON ITS OWN ESTATE'S CLOCK
         * (`rostered_start` is read in the estate's zone).
         */
        /*
         * EACH SHIFT IS HANDED THE POST ALREADY LOADED ABOVE, WITH ITS ESTATE (M11 A2). Every
         * shift is on one of `$posts` — that is the `whereIn` — and those carry their estate.
         * Eager-loading `post` again gave each shift a fresh Post WITHOUT one, so the row's
         * estate name was a query per shift: twenty-four on a day at twelve clients.
         */
        $postsById = $posts->keyBy('id');
        $onDate = $on->toDateString();

        $shifts = Shift::query()
            ->with(['officer'])
            ->whereIn('post_id', $posts->modelKeys())
            ->whereBetween('rostered_start', [$on->copy()->subDay(), $on->copy()->addDays(2)])
            ->orderBy('rostered_start')
            ->get()
            ->each(static fn (Shift $shift) => $shift->setRelation('post', $postsById->get($shift->post_id)))
            ->filter(static fn (Shift $shift): bool => $shift->rostered_start->toDateString() === $onDate)
            ->values();

        $rows = [];
        $open = 0;

        // Who has claimed each open shift from the Guard App, oldest first (26 Sep: claims had nowhere to be answered).
        $claims = app(ShiftClaims::class)->pendingOn($shifts->filter(static fn (Shift $s): bool => $s->guard_id === null)->modelKeys());

        foreach ($shifts as $shift) {
            $isOpen = $shift->guard_id === null;
            $open += $isOpen ? 1 : 0;

            $rows[] = [
                'id' => $shift->id,

                /*
                 * What the Assign picker narrows by (bug hunt B005): an officer posted at this
                 * estate, whose licence runs to at least the last day this shift touches on the
                 * estate's calendar — the two things `assign()` refuses without.
                 */
                'tenant_id' => (string) $shift->tenant_id,
                'licence_needed_to' => SiteTime::local($shift->rostered_end->copy()->subSecond(), (string) $shift->tenant_id)->toDateString(),

                // "Back Gate (Gate 5, Phase 5 Gate)"; "Main Gate · patrol" for a patrol from it (M26, M27).
                'post' => $shift->postLabel(),
                'duty' => $shift->duty ?? Shift::DUTY_POST,
                'estate' => $shift->post->estate->name ?? 'Unassigned',
                // On the estate's clock (the cast), named where that is not Gemini's own (M11 §4.1).
                'window' => $shift->rostered_start->format('g:i A').' – '.$shift->rostered_end->format('g:i A').SiteTime::label($shift->rostered_end),
                'officer' => $shift->officer->full_name ?? null,
                'is_open' => $isOpen,
                'released_reason' => $shift->released_reason,
                'status' => $shift->status,
                'claims' => $isOpen ? ($claims[$shift->id] ?? []) : [],

                // What the row's own controls may do (M35 §2): why nothing on it changes, when nothing does; and Remove
                // only while the shift has not started — `removeShift()`'s own rule, asked before the button is pressed.
                'locked' => self::whyLocked($shift),
                'can_remove' => $shift->actual_start === null && $shift->rostered_start->isFuture() && self::whyLocked($shift) === null,
            ];
        }

        return [
            'date' => $on->toDateString(),

            /*
             * The day either side, worked out on the calendar here (bug hunt B114). The page
             * built them from `new Date('…T00:00:00')` — local midnight — and printed them with
             * `toISOString()`, which is UTC, so in a browser east of UTC "Next day" reloaded the
             * same day and "Previous day" skipped one. A day is a date, not a moment, and the
             * browser's zone has no part in which date follows another.
             */
            'previous' => $on->copy()->subDay()->toDateString(),
            'next' => $on->copy()->addDay()->toDateString(),

            'rows' => $rows,
            'open' => $open,
        ];
    }
}
