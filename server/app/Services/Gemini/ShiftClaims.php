<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Guard;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Services\Push\PushAnnouncer;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A GUARD'S CLAIM ON AN OPEN SHIFT, ANSWERED (the client's report G3, 26 Sep: a guard could claim an open shift and
 * nobody could approve it, so every claim stayed pending for ever).
 *
 * A guard claims from Leave & duty → Open shifts (`shifts.claim`), and the claim waits in `shift_claims`. This is the
 * supervisor's side, on the Roster:
 *
 *   APPROVE   puts that officer on the shift through `Roster::assign()` — the same checks as Assign: posted at the
 *             shift's estate, not suspended, a licence that covers the whole shift — and declines every other claim
 *             on it ("another officer was given this shift"). One transaction: the shift has one officer or none.
 *   DECLINE   answers one claim with an optional note; the shift stays open for the others.
 *
 * Each officer is told on their phone (`claim_decided`), and the Guard App reads the answer from `sync.pull` too.
 * A claim on a shift that is no longer open, or has started, cannot be approved: it is declined with the reason.
 */
class ShiftClaims
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public function __construct(
        private readonly Roster $roster,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The pending claims on these shifts, oldest first, by shift.
     *
     * @param  list<int>  $shiftIds
     * @return array<int, list<array{id: int, guard_id: int, guard: string, employee_number: string, claimed_at: string}>>
     */
    public function pendingOn(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        $rows = DB::connection('mysql')->table('shift_claims')
            ->join('guards', 'guards.id', '=', 'shift_claims.guard_id')
            ->whereIn('shift_claims.shift_id', $shiftIds)
            ->where('shift_claims.status', self::PENDING)
            ->orderBy('shift_claims.claimed_at')
            ->orderBy('shift_claims.id')
            ->get(['shift_claims.id', 'shift_claims.shift_id', 'shift_claims.guard_id', 'shift_claims.claimed_at', 'guards.full_name', 'guards.employee_number']);

        $by = [];

        foreach ($rows as $row) {
            $by[(int) $row->shift_id][] = [
                'id' => (int) $row->id,
                'guard_id' => (int) $row->guard_id,
                'guard' => (string) $row->full_name,
                'employee_number' => (string) $row->employee_number,
                'claimed_at' => Carbon::parse((string) $row->claimed_at)->toIso8601String(),
            ];
        }

        return $by;
    }

    /**
     * Every claim still waiting on a shift that is still open and still ahead, in the viewer's scope — the Roster's
     * "Claims waiting", whatever day each shift is on. Oldest claim first.
     *
     * @return list<array{id: int, shift_id: int, guard: string, employee_number: string, claimed_at: string, post: string, estate: string, day: string, window: string}>
     */
    public function waiting(User $viewer): array
    {
        $rows = DB::connection('mysql')->table('shift_claims')
            ->join('shifts', 'shifts.id', '=', 'shift_claims.shift_id')
            ->join('guards', 'guards.id', '=', 'shift_claims.guard_id')
            ->where('shift_claims.status', self::PENDING)
            ->whereNull('shifts.guard_id')
            ->where('shifts.status', Shift::OPEN)
            ->where('shifts.rostered_start', '>', Carbon::now())
            ->when(
                $viewer->widestScope() === AccessScope::AssignedSites,
                static fn ($query) => $query->whereIn('shifts.tenant_id', $viewer->accessibleEstateIds()),
            )
            // Not the demonstration estate, as the Roster's day view leaves it out (M5 §3).
            ->tap(static fn ($query) => OperatorEstates::exclude($query, 'shifts.tenant_id'))
            ->orderBy('shift_claims.claimed_at')
            ->orderBy('shift_claims.id')
            ->limit(100)
            ->get(['shift_claims.id', 'shift_claims.shift_id', 'shift_claims.claimed_at', 'guards.full_name', 'guards.employee_number']);

        $shifts = Shift::query()->with(['post', 'estate'])->whereKey($rows->pluck('shift_id')->unique()->all())->get()->keyBy('id');
        $out = [];

        foreach ($rows as $row) {
            $shift = $shifts->get((int) $row->shift_id);

            if ($shift === null) {
                continue;
            }

            $out[] = [
                'id' => (int) $row->id,
                'shift_id' => (int) $row->shift_id,
                'guard' => (string) $row->full_name,
                'employee_number' => (string) $row->employee_number,
                'claimed_at' => Carbon::parse((string) $row->claimed_at)->toIso8601String(),
                // On the estate's clock, as every time on the roster is.
                'claimed' => SiteTime::stamp(Carbon::parse((string) $row->claimed_at), (string) $shift->tenant_id, 'M j, g:i A'),
                'post' => $shift->postLabel(),
                'estate' => (string) ($shift->estate->name ?? $shift->tenant_id),
                'day' => $shift->rostered_start->toDateString(),
                'window' => $shift->rostered_start->format('D j M, g:i A').' – '.$shift->rostered_end->format('g:i A').SiteTime::label($shift->rostered_end),
            ];
        }

        return $out;
    }

    /** Put the claiming officer on the shift, and decline every other claim on it. */
    public function approve(int $claimId, User $by): Shift
    {
        [$claim, $shift, $guard] = $this->load($claimId, $by);

        if ($shift->status !== Shift::OPEN || $shift->guard_id !== null) {
            $this->answer($claim, self::DECLINED, $by, 'Another officer was given this shift.');

            throw new DomainException('That shift already has an officer on it, so this claim was declined. Nothing else changed.');
        }

        if ($shift->rostered_start->isPast()) {
            $this->answer($claim, self::DECLINED, $by, 'The shift started before the claim was answered.');

            throw new DomainException('That shift has already started, so the claim can no longer be approved. It was declined, and the officer is told.');
        }

        $declined = [];

        DB::connection('mysql')->transaction(function () use ($claim, $shift, $guard, $by, &$declined): void {
            // The same checks as Assign — the officer must be able to stand the shift and clock in to it.
            $this->roster->assign($shift, $guard, $by);

            $this->answer($claim, self::APPROVED, $by, null, announce: false);

            $declined = DB::connection('mysql')->table('shift_claims')
                ->where('shift_id', $shift->id)
                ->where('id', '!=', $claim->id)
                ->where('status', self::PENDING)
                ->pluck('guard_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            DB::connection('mysql')->table('shift_claims')
                ->where('shift_id', $shift->id)
                ->where('id', '!=', $claim->id)
                ->where('status', self::PENDING)
                ->update($this->decision(self::DECLINED, $by, 'Another officer was given this shift.'));

            $this->audit->record(
                action: 'roster.claim_approved',
                entityType: 'Shift',
                entityId: (string) $shift->id,
                after: ['guard' => (string) $guard->full_name, 'claim' => (int) $claim->id, 'other_claims_declined' => count($declined), 'by' => (string) $by->name],
                tenantId: (string) $shift->tenant_id,
            );
        });

        $announcer = app(PushAnnouncer::class);
        $announcer->claimDecided($shift, (int) $guard->id, true);

        foreach ($declined as $guardId) {
            $announcer->claimDecided($shift, $guardId, false);
        }

        return $shift->refresh();
    }

    /** Answer one claim no; the shift stays open for anybody else. */
    public function decline(int $claimId, User $by, ?string $note = null): void
    {
        [$claim, $shift] = $this->load($claimId, $by);

        $this->answer($claim, self::DECLINED, $by, $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 190));

        $this->audit->record(
            action: 'roster.claim_declined',
            entityType: 'Shift',
            entityId: (string) $shift->id,
            after: ['claim' => (int) $claim->id, 'guard_id' => (int) $claim->guard_id, 'note' => $note, 'by' => (string) $by->name],
            tenantId: (string) $shift->tenant_id,
        );
    }

    /**
     * The claim, its shift and its officer — a pending claim on a shift this viewer may roster, or refused in the same
     * words as a claim that does not exist.
     *
     * @return array{0: object, 1: Shift, 2: Guard}
     */
    private function load(int $claimId, User $by): array
    {
        $claim = DB::connection('mysql')->table('shift_claims')->where('id', $claimId)->first();
        $shift = $claim === null ? null : Shift::query()->find((int) $claim->shift_id);
        $guard = $claim === null ? null : Guard::query()->find((int) $claim->guard_id);

        if ($claim === null || $shift === null || $guard === null || ! $by->canAccessEstate((string) $shift->tenant_id)) {
            throw new DomainException('That claim is not one this role can answer.');
        }

        if ($claim->status !== self::PENDING) {
            throw new DomainException('That claim has already been answered ('.$claim->status.').');
        }

        return [$claim, $shift, $guard];
    }

    private function answer(object $claim, string $status, User $by, ?string $note, bool $announce = true): void
    {
        DB::connection('mysql')->table('shift_claims')->where('id', $claim->id)->update($this->decision($status, $by, $note));

        if ($announce) {
            $shift = Shift::query()->find((int) $claim->shift_id);

            if ($shift !== null) {
                app(PushAnnouncer::class)->claimDecided($shift, (int) $claim->guard_id, $status === self::APPROVED);
            }
        }
    }

    /** @return array<string, mixed> */
    private function decision(string $status, User $by, ?string $note): array
    {
        return [
            'status' => $status,
            'decided_by' => $by->getKey(),
            'decided_by_name' => mb_substr((string) $by->name, 0, 120),
            'decided_at' => Carbon::now(),
            'decision_note' => $note,
            'updated_at' => Carbon::now(),
        ];
    }
}
