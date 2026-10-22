<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\DunningTemplateDraft;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\PaymentPlanInstalment;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitCollectionFlag;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\SmsSettings;
use App\Support\MoneyFormatter;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use App\Support\TableQuery;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payment plans and dunning — boards 7 and 8.
 *
 * TWO THINGS AN ESTATE DOES WITH A DEBT IT CANNOT SIMPLY DEMAND: agree a way to
 * clear it, or chase it. They sit in one class because they are the same
 * decision seen from two ends, and because the first switches the second off.
 *
 * NOTHING HERE POSTS A JOURNAL ENTRY, and that is not an omission. The
 * receivable was recognised when the charges were raised; scheduling a debt and
 * writing to a household about it change nothing about what is owed. The money
 * moves when an instalment is actually paid, and it moves through
 * `Dues::receive` like any other receipt. A payment plan that posted an entry
 * would restate the arrears on every board without anyone paying anything.
 *
 * WHAT IT DOES CHANGE IS A GATE. While an agreed plan is active and being met,
 * `isProtected()` returns true and `RestrictionPolicy` admits guest passes for a
 * household whose `access_restricted` flag is still set. Miss an instalment and
 * the plan defaults, the shield goes, and the flag underneath it is exactly
 * where it was. That is why the flag is never cleared on activation: the arrears
 * did not go away, they were given a schedule, and a module that erased the fact
 * would have nothing to reinstate.
 *
 * AND A NOTICE IS FROZEN AT SEND. `send()` renders the merge fields against the
 * unit NOW and writes the finished subject and body onto the notice row. Nothing
 * stores a reference and re-renders later, because the log exists to settle a
 * dispute about what a resident was actually told.
 */
class Collections
{
    /** Board 7's default instalment count. */
    public const DEFAULT_INSTALMENTS = 4;

    /**
     * Which day of the month a plan is offered to start on.
     *
     * Board 7 defaults to the 28th. Dues fall on the first, so the 28th gives a
     * household the whole month to find the instalment and keeps the plan's
     * payment from arriving in the same week as the bill it is clearing.
     */
    public const DEFAULT_START_DAY = 28;

    /** A plan longer than this is a write-off with extra steps. */
    private const MAX_INSTALMENTS = 36;

    /** Who the dunning log names for a notice nobody pressed a button to send. */
    public const AUTOMATED_SENDER = 'Automated dunning run';

    /*
     * What the plan register and the dunning log say in place of their rows
     * (M10 §5) — `Dues::ARREARS_WITHHELD`'s reasoning, about the two other
     * lists that say which households are behind. Neither names a household or
     * a figure; both ship beside the empty list they explain. Only a viewer who
     * holds the ledger reaches either screen, so "does not hold both" is true of
     * everyone who reads them.
     */
    // One line (M53 Law 2): no permission algebra on a lock screen.
    public const PLANS_WITHHELD = 'This section is not part of your role.';

    public const SENDS_WITHHELD = 'Which households the estate has sent reminders to says which are behind with their dues, and that is part of the estate\'s own picture. Reading it needs Reports access together with Dues & ledger, and your role does not hold both. The wording on the right names no household and is still yours to draft.';

    public function __construct(
        private readonly Dues $dues,
        private readonly AuditLogger $audit,
    ) {}

    /* ------------------------------------------------------------------ */
    /* payment plans — board 7 */
    /* ------------------------------------------------------------------ */

    /**
     * The schedule board 7 draws before anything is agreed. WRITES NOTHING.
     *
     * The total comes from the unit's CURRENT LEDGER BALANCE and from nowhere
     * else — a plan schedules the debt the accounts say exists, not a figure
     * typed into a modal. Once agreed it is snapshotted onto the plan, because
     * from that moment it is what the household consented to rather than what
     * they happen to owe today.
     *
     * @return array{
     *     total_minor: int,
     *     currency: string,
     *     instalments: int,
     *     frequency: string,
     *     starts_on: string,
     *     starts_on_label: string,
     *     instalment_minor: int,
     *     first_instalment_minor: int,
     *     is_even: bool,
     *     rows: list<array{sequence: int, amount_minor: int, due_on: string, due_label: string}>
     * }
     *
     * @throws DomainException when the unit owes nothing to schedule
     */
    public function schedule(Unit $unit, int $instalments = self::DEFAULT_INSTALMENTS, Carbon|string|null $startsOn = null): array
    {
        $total = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();

        if ($total <= 0) {
            throw new DomainException(
                'There is nothing to schedule: '.$unit->reference.' owes nothing on the ledger. A payment '.
                'plan clears an existing balance and cannot be drawn against a debt that has not been billed.'
            );
        }

        if ($instalments < 1 || $instalments > self::MAX_INSTALMENTS) {
            throw new DomainException(
                'A plan runs between 1 and '.self::MAX_INSTALMENTS.' instalments. Anything longer is a '.
                'write-off the committee has not voted on, dressed as a schedule.'
            );
        }

        $start = $this->asDate($startsOn ?? $this->defaultStart());

        $base = intdiv($total, $instalments);
        $remainder = $total - ($base * $instalments);

        $rows = [];

        for ($i = 0; $i < $instalments; $i++) {
            /*
             * THE ROUNDING REMAINDER GOES ON THE FIRST INSTALMENT, NOT THE LAST.
             *
             * Two reasons, and the second is the one that decides it. The last
             * instalment is the figure a resident checks against the agreement
             * on the day they finish paying, and an odd amount there reads as a
             * fee added at the end — which is the argument a payment plan exists
             * to stop having. And a plan is a shield over a gate restriction
             * that only lasts as long as the household keeps to it: putting the
             * odd cents at the front means that at every point in the schedule
             * the household has paid at least its pro-rata share, so a plan
             * abandoned half way leaves the estate no worse off than the
             * arithmetic promised.
             */
            $amount = $base + ($i === 0 ? $remainder : 0);

            $due = $start->copy()->addMonthsNoOverflow($i);

            $rows[] = [
                'sequence' => $i + 1,
                'amount_minor' => $amount,
                'due_on' => $due->toDateString(),
                'due_label' => $due->format('M j, Y'),
            ];
        }

        return [
            'total_minor' => $total,
            'currency' => 'JMD',
            'instalments' => $instalments,
            'frequency' => PaymentPlan::FREQUENCY,
            'starts_on' => $start->toDateString(),
            'starts_on_label' => $start->format('M j, Y'),

            /*
             * The repeating figure board 7 prints as "$3,100.00 per instalment",
             * and the first one beside it. They are the same number whenever the
             * division is clean, which is the case the board drew; where it is
             * not, the screen has both and can say so rather than printing an
             * average nobody will ever be asked to pay.
             */
            'instalment_minor' => $base,
            'first_instalment_minor' => $base + $remainder,
            'is_even' => $remainder === 0,
            'rows' => $rows,
        ];
    }

    /**
     * Write the plan and its instalments as a DRAFT.
     *
     * Draft, never active: the household has not agreed to it yet, and a plan
     * that lifted a gate restriction the moment a treasurer pressed a button
     * would be the estate deciding on their behalf.
     *
     * NO ACTOR IS TAKEN. `payment_plans` records who APPROVED a plan, and
     * drawing one up is not approving it — there is nowhere for a drafter to
     * go, and a parameter with nowhere to go reads as an attribution the row
     * does not carry.
     *
     * ONE LIVE PLAN A UNIT (bug hunt B019). Board 7 always refused to draw up a
     * second plan beside a draft or an active one — two live plans over one
     * balance are two schedules a household can be held to — and the route did
     * not. It is refused here, under the unit's lock so two presses cannot both
     * find none; a stale draft is withdrawn, and an active plan ends when its
     * instalments are ruled.
     */
    public function draft(
        Unit $unit,
        int $instalments = self::DEFAULT_INSTALMENTS,
        Carbon|string|null $startsOn = null,
        ?string $terms = null,
    ): PaymentPlan {
        $preview = $this->schedule($unit, $instalments, $startsOn);

        return DB::connection('tenant')->transaction(function () use ($unit, $preview, $terms): PaymentPlan {
            Unit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();

            $live = PaymentPlan::query()
                ->where('unit_id', $unit->id)
                ->whereIn('status', [PaymentPlan::DRAFT, PaymentPlan::ACTIVE])
                ->orderByDesc('id')
                ->first();

            if ($live !== null) {
                throw new DomainException($live->status === PaymentPlan::ACTIVE
                    ? $unit->reference.' is on plan '.$live->reference.'. It ends when every instalment is ruled met, or '.
                        'when one is ruled missed; a new plan can be drawn up after that. Two live plans over one balance '.
                        'would be two schedules a household can be held to.'
                    : $unit->reference.' already has plan '.$live->reference.' drafted. Record the household\'s agreement '.
                        'and activate it, or withdraw it, before drawing up another: two live plans over one balance would '.
                        'be two schedules a household can be held to.');
            }

            $plan = PaymentPlan::create([
                'unit_id' => $unit->id,
                'reference' => $this->nextReference(),
                'total_minor' => $preview['total_minor'],
                'currency' => $preview['currency'],
                'instalments' => $preview['instalments'],
                'starts_on' => $preview['starts_on'],
                'status' => PaymentPlan::DRAFT,
                'terms' => $terms,
            ]);

            foreach ($preview['rows'] as $row) {
                $plan->schedule()->create([
                    'sequence' => $row['sequence'],
                    'amount_minor' => $row['amount_minor'],
                    'currency' => $preview['currency'],
                    'due_on' => $row['due_on'],
                    'status' => PaymentPlanInstalment::DUE,
                ]);
            }

            return $plan->fresh(['schedule']) ?? $plan;
        });
    }

    /**
     * Record that the household agreed, and who said so.
     *
     * A FACT WITH A TIME ON IT, which is what the migration reserved the two
     * columns for. It is the only thing that makes the next step lawful.
     *
     * ONLY A DRAFT TAKES ONE (bug hunt B038). A draft's agreement may be
     * corrected — a name misspelt, a spouse recorded instead — because nobody has
     * acted on it yet. Once a plan is ACTIVE an approver has lifted a gate
     * restriction on the word of the person named here, and `activate()` refuses
     * to overwrite whose word that is; this used to let `create` do exactly that
     * with a second POST, leaving an active plan resting on an agreement its
     * approver never saw, with no trail but the new name and date.
     *
     * UNDER THE PLAN'S LOCK (bug hunt B020), for the reason `activate()` gives.
     */
    public function agree(PaymentPlan $plan, string $agreedByName): PaymentPlan
    {
        $name = trim($agreedByName);

        if ($name === '') {
            throw new DomainException(
                'Record who agreed to this plan. An agreement with no name on it cannot be shown to the '.
                'household it is supposed to bind.'
            );
        }

        $agreed = DB::connection('tenant')->transaction(function () use ($plan, $name): PaymentPlan {
            $plan = $this->lockPlan($plan->id);

            if ($plan->status === PaymentPlan::ACTIVE) {
                throw new DomainException(
                    'Plan '.$plan->reference.' was approved'.($plan->approved_by_name === null ? '' : ' by '.$plan->approved_by_name).
                    ' on '.$plan->agreed_by_name.'\'s agreement, and it stays that agreement: the approval lifted the '.
                    'arrears restriction on those terms. Different terms, or a different signatory, are a new plan '.
                    'once this one has completed or defaulted.'
                );
            }

            if ($plan->status !== PaymentPlan::DRAFT) {
                throw new DomainException(
                    'Plan '.$plan->reference.' is '.$plan->status.' and cannot take a new agreement. A plan that '.
                    'has run its course or been defaulted is history; agreeing terms again means a new plan.'
                );
            }

            $plan->forceFill([
                'agreed_at' => now(),
                'agreed_by_name' => $name,
            ])->save();

            return $plan;
        });

        // The caller's copy, brought up to date with what was written.
        $plan->setRawAttributes($agreed->getAttributes(), true);

        return $plan;
    }

    /**
     * Approve an agreed plan, and let it lift the arrears restriction.
     *
     * THIS IS THE DECISION, WHICH IS WHY THE ROUTE NEEDS `approve` AND NOT
     * `create`. Everything before it is paperwork; this is the moment a
     * household in arrears can have guests through the gate again.
     *
     * `$agreedByName` is required even though `agree()` already recorded one,
     * and it must match. The approver is asserting whose agreement they are
     * acting on at the instant the restriction comes off — activating "Andrea
     * Fletcher's agreement" against a plan that records somebody else's is
     * approving something other than what is on the screen, and it is refused
     * rather than quietly overwritten.
     *
     * DECIDED ON THE PLAN AS IT STANDS, UNDER ITS LOCK (bug hunt B020). The name
     * used to be compared with the copy of the plan the approver's request had
     * loaded, so an agreement re-recorded under another name while the approval
     * was in flight was approved on terms the approver never saw. The row is now
     * held `FOR UPDATE` and every check reads it as it is: whichever of the two
     * writes lands second sees the first, and `agree()` takes the same lock.
     *
     * An already-active plan is left as it was approved — a second press does not
     * rewrite who approved it.
     *
     * @throws DomainException when the household has not agreed, or agreed to something else
     */
    public function activate(PaymentPlan $plan, string $agreedByName, User $by): PaymentPlan
    {
        $claimed = trim($agreedByName);

        $activated = DB::connection('tenant')->transaction(function () use ($plan, $claimed, $by): PaymentPlan {
            $plan = $this->lockPlan($plan->id);

            if (! $plan->isAgreed()) {
                throw new DomainException(
                    'Plan '.$plan->reference.' cannot be activated: the household has not agreed to it. An '.
                    'unagreed plan is a demand, and activating one would lift a gate restriction on terms '.
                    'nobody in the household ever accepted.'
                );
            }

            if (mb_strtolower($claimed) !== mb_strtolower((string) $plan->agreed_by_name)) {
                throw new DomainException(
                    'Plan '.$plan->reference.' records an agreement by '.$plan->agreed_by_name.', not '.
                    $claimed.'. Activate the agreement that exists or record a new one; do not overwrite '.
                    'whose word the estate is relying on.'
                );
            }

            if ($plan->status !== PaymentPlan::DRAFT && $plan->status !== PaymentPlan::ACTIVE) {
                throw new DomainException(
                    'Plan '.$plan->reference.' is '.$plan->status.' and cannot be activated. A finished or '.
                    'defaulted plan is a record of what happened; a household getting a second chance gets a '.
                    'second plan, so that the first one still says what went wrong.'
                );
            }

            if ($plan->status === PaymentPlan::DRAFT) {
                $plan->forceFill([
                    'status' => PaymentPlan::ACTIVE,
                    'approved_by' => $by->getKey(),
                    'approved_by_name' => $by->name,
                ])->save();
            }

            return $plan;
        });

        $plan->setRawAttributes($activated->getAttributes(), true);

        return $plan;
    }

    /**
     * A plan's row, held `FOR UPDATE` until the caller's transaction ends (bug hunt B020).
     *
     * Taken BEFORE any other read in the transaction: under REPEATABLE READ the
     * snapshot is fixed at the first consistent read, so a check made after this
     * lock sees whatever the request it waited behind committed.
     */
    private function lockPlan(int $id): PaymentPlan
    {
        return PaymentPlan::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Accept an instalment as met.
     *
     * A DECISION, NOT AN OBSERVATION. A treasurer may accept a short payment as
     * meeting an instalment, and no rule over the payments table could express
     * that without being asked — see the instalment model.
     *
     * IT POSTS NOTHING, and must not. The money is recorded where every other
     * receipt is, through `Dues::receive`, which raises the entry. If this
     * posted the instalment's face value, a short payment accepted as meeting
     * an instalment would credit the unit with money it never sent.
     *
     * AN APPROVER RULES IT, FROM BOARD 7 (bug hunt B019). Nothing called this
     * before: no route, no screen and no command could rule an instalment met or
     * missed, so an activated plan never ended — a household that stopped paying
     * was shielded from the ladder, the gate and the amenity diary for good, and
     * one that paid it all off was never completed. The ruling is `approve`,
     * like activating the plan, because either end of it changes what happens at
     * the gate. Only an ACTIVE plan's instalments are ruled; ruling one met twice
     * leaves it as it was.
     *
     * WHO RULED IS ON THE AUDIT LOG. `payment_plan_instalments` records
     * `settled_at` and nothing else, so the person goes where the estate's other
     * decisions go, rather than onto a column the row does not have.
     */
    public function recordInstalmentMet(PaymentPlanInstalment $instalment, ?User $by = null): PaymentPlanInstalment
    {
        $ruled = DB::connection('tenant')->transaction(function () use ($instalment, $by): PaymentPlanInstalment {
            // Under the plan's lock, then the instalment's, and decided on both as they stand (bug hunt B020).
            $plan = $this->lockPlan($instalment->payment_plan_id);
            $instalment = PaymentPlanInstalment::query()->whereKey($instalment->getKey())->lockForUpdate()->firstOrFail();

            if ($instalment->status === PaymentPlanInstalment::MET) {
                return $instalment;
            }

            $this->assertRulable($plan, $instalment);

            $instalment->forceFill([
                'status' => PaymentPlanInstalment::MET,
                'settled_at' => now(),
            ])->save();

            $outstanding = $plan->schedule()
                ->where('status', '!=', PaymentPlanInstalment::MET)
                ->count();

            $this->audit->record(
                action: 'estate.payment_plan_instalment_met',
                entityType: 'PaymentPlanInstalment',
                entityId: (string) $instalment->id,
                after: ['plan' => $plan->reference, 'sequence' => $instalment->sequence, 'completes_plan' => $outstanding === 0],
                actor: $by,
            );

            if ($outstanding > 0) {
                return $instalment;
            }

            $plan->forceFill(['status' => PaymentPlan::COMPLETED])->save();

            /*
             * THE LAST INSTALMENT ENDS THE RESTRICTION, not just the shield.
             *
             * `isProtected()` stops being true the moment a plan completes, so
             * without this a household would finish paying and be restricted
             * again by the flag the plan had been standing over — which is the
             * exact opposite of what completing a plan means.
             *
             * Conditional on the LEDGER, not on the plan: a household that met
             * every instalment while falling behind on the months that accrued
             * during the plan is in fresh arrears, and fresh arrears get their
             * own eligibility and their own notice period rather than being
             * cleared by an unrelated agreement finishing.
             */
            if ($this->dues->balanceOf($plan->unit)->getMinorAmount()->toInt() <= 0) {
                $plan->unit->household?->forceFill(['access_restricted' => false])->save();
            }

            return $instalment;
        });

        // The caller's copy, brought up to date with the ruling.
        $instalment->setRawAttributes($ruled->getAttributes(), true);
        $instalment->unsetRelation('plan');

        return $instalment;
    }

    /**
     * Rule an instalment missed, which defaults the plan.
     *
     * AND THIS IS WHAT PUTS THE RESTRICTION BACK. It is a ruling somebody makes,
     * never an elapsed timer: a bank holding a transfer over a weekend must not
     * be able to stop a household's visitors at a gate, for the same reason
     * restriction never follows a declined card (D-024).
     *
     * The household's `access_restricted` flag is not touched — it was never
     * cleared. Defaulting removes the shield and what was underneath it is
     * exactly what was there before.
     *
     * AN APPROVER RULES IT, FROM BOARD 7, AND ONLY ONCE ITS DATE HAS PASSED (bug
     * hunt B019). Before its due date an instalment cannot have been missed —
     * the household still has until that day at the estate — so it is refused
     * until the day after. Only an ACTIVE plan's instalment is ruled; ruling one
     * missed twice leaves it as it was. Who ruled is on the audit log.
     */
    public function markMissed(PaymentPlanInstalment $instalment, ?User $by = null): PaymentPlanInstalment
    {
        $ruled = DB::connection('tenant')->transaction(function () use ($instalment, $by): PaymentPlanInstalment {
            $plan = $this->lockPlan($instalment->payment_plan_id);
            $instalment = PaymentPlanInstalment::query()->whereKey($instalment->getKey())->lockForUpdate()->firstOrFail();

            if ($instalment->status === PaymentPlanInstalment::MISSED) {
                return $instalment;
            }

            $this->assertRulable($plan, $instalment);

            if (! $instalment->isOverdue(Dues::siteDate())) {
                throw new DomainException(
                    'Instalment '.$instalment->sequence.' of '.$plan->reference.' falls due on '.
                    $instalment->due_on->format('M j, Y').'. It can be ruled missed only once that date has passed — '.
                    'a transfer the bank is still holding is not a missed instalment.'
                );
            }

            $instalment->forceFill(['status' => PaymentPlanInstalment::MISSED])->save();

            $plan->forceFill(['status' => PaymentPlan::DEFAULTED])->save();

            $this->audit->record(
                action: 'estate.payment_plan_instalment_missed',
                entityType: 'PaymentPlanInstalment',
                entityId: (string) $instalment->id,
                after: ['plan' => $plan->reference, 'sequence' => $instalment->sequence, 'plan_status' => PaymentPlan::DEFAULTED],
                actor: $by,
            );

            return $instalment;
        });

        $instalment->setRawAttributes($ruled->getAttributes(), true);
        $instalment->unsetRelation('plan');

        return $instalment;
    }

    /**
     * Withdraw a draft nobody has activated (bug hunt B019).
     *
     * A draft lifts nothing and binds nobody, so withdrawing one is the office's
     * `create`, like drawing it up. Without it a draft the household never
     * agreed to blocked a new plan on the unit for ever. The row stays, as
     * `cancelled` — board 7 and the register say it was withdrawn before it ran.
     */
    public function withdraw(PaymentPlan $plan, ?User $by = null): PaymentPlan
    {
        $withdrawn = DB::connection('tenant')->transaction(function () use ($plan, $by): PaymentPlan {
            $plan = $this->lockPlan($plan->id);

            if ($plan->status !== PaymentPlan::DRAFT) {
                throw new DomainException(
                    'Plan '.$plan->reference.' is '.$plan->status.', not a draft. Only a plan nobody has activated can be '.
                    'withdrawn; an active plan ends when every instalment is ruled met, or one is ruled missed.'
                );
            }

            $plan->forceFill(['status' => PaymentPlan::CANCELLED])->save();

            $this->audit->record(
                action: 'estate.payment_plan_withdrawn',
                entityType: 'PaymentPlan',
                entityId: (string) $plan->id,
                after: ['reference' => $plan->reference, 'status' => PaymentPlan::CANCELLED],
                actor: $by,
            );

            return $plan;
        });

        $plan->setRawAttributes($withdrawn->getAttributes(), true);

        return $plan;
    }

    /**
     * The plans with an instalment past its date that nobody has ruled met or
     * missed, by reference (bug hunt B019).
     *
     * NOTHING RULES ONE BY ITSELF, which is the point of a ruling — so the
     * morning run and the register name every one of them, and a plan still
     * shielding a household that has stopped paying is never left standing
     * where nobody can see it.
     *
     * @return list<string>
     */
    public function awaitingRuling(?Carbon $asAt = null): array
    {
        $today = ($asAt?->copy() ?? Dues::siteDate())->startOfDay();

        return PaymentPlan::query()
            ->where('status', PaymentPlan::ACTIVE)
            ->whereHas('schedule', static fn ($query) => $query
                ->where('status', PaymentPlanInstalment::DUE)
                ->where('due_on', '<', $today->toDateString()))
            ->orderBy('reference')
            ->get(['id', 'reference'])
            ->map(static fn (PaymentPlan $plan): string => $plan->reference)
            ->values()
            ->all();
    }

    /** Why this instalment cannot be ruled on now, thrown; nothing when it can. */
    private function assertRulable(PaymentPlan $plan, PaymentPlanInstalment $instalment): void
    {
        if ($plan->status !== PaymentPlan::ACTIVE) {
            throw new DomainException(match ($plan->status) {
                PaymentPlan::DRAFT => 'Plan '.$plan->reference.' has not been activated, so nothing is owed under it yet. Its instalments are ruled once it is active.',
                PaymentPlan::COMPLETED => 'Plan '.$plan->reference.' is complete: every instalment was ruled met.',
                PaymentPlan::DEFAULTED => 'Plan '.$plan->reference.' defaulted when an instalment was ruled missed. It stays as it was; a household given a second chance gets a second plan.',
                default => 'Plan '.$plan->reference.' was withdrawn before it ran, so nothing on it is ruled.',
            });
        }

        if ($instalment->status !== PaymentPlanInstalment::DUE) {
            throw new DomainException('Instalment '.$instalment->sequence.' of '.$plan->reference.' is already ruled '.$instalment->status.'.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* what the gate asks */
    /* ------------------------------------------------------------------ */

    /**
     * Does an active, non-defaulted plan currently shield this unit?
     *
     * THE ONE METHOD `RestrictionPolicy` CONSULTS, and the whole reason a plan
     * is a row rather than a note. It is deliberately a question about the unit
     * and not about the household: the unit is what owes the dues, households
     * come and go, and a plan agreed by the family living there in March is
     * still a plan over that property's debt in September.
     */
    public function isProtected(Unit $unit): bool
    {
        return $this->activePlanFor($unit) !== null;
    }

    /**
     * The plan currently shielding this unit, if any.
     *
     * TWO INDEPENDENT CONDITIONS, and the redundancy is deliberate. `status`
     * says the plan is live; the absence of a missed instalment says the
     * household is keeping to it. `markMissed` sets both, so in a healthy
     * system the second never bites — but the one it exists for is the day a
     * status is written by hand, or by a future path nobody has read this file
     * before adding, and a gate restriction is not something to leave resting
     * on a single mutable string.
     */
    public function activePlanFor(Unit $unit): ?PaymentPlan
    {
        return PaymentPlan::query()
            ->where('unit_id', $unit->id)
            ->where('status', PaymentPlan::ACTIVE)
            ->whereNotNull('agreed_at')
            ->whereDoesntHave('schedule', fn ($query) => $query->where('status', PaymentPlanInstalment::MISSED))
            ->orderByDesc('id')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /* dunning — board 8 */
    /* ------------------------------------------------------------------ */

    /**
     * Send one notice, and log it AS SENT.
     *
     * The merge fields are resolved against the unit at this instant and the
     * finished text is written onto the notice. The template id is kept as a
     * trail back to the wording that was in force, and nothing ever reads the
     * body through it — that is the entire guarantee this module carries.
     *
     * DELIVERY STARTS AT `queued`, not `sent`. Nothing in this application has
     * yet handed anything to a push, mail or SMS provider, and a log that
     * claimed delivery on the strength of a database insert would be the first
     * thing a dispute disproved.
     */
    public function send(Unit $unit, DunningTemplate $template, ?User $by = null, ?string $senderName = null): DunningNotice
    {
        $rendered = $this->render($template, $unit);

        return DunningNotice::create([
            'unit_id' => $unit->id,
            'dunning_template_id' => $template->id,

            // Copied, not joined. Asked in a year which step went out today,
            // this row has to answer on its own.
            'template_label' => $template->label,
            'stage' => $template->stage,
            'channel' => $template->channel,

            'subject' => $rendered['subject'],
            'body' => $rendered['body'],

            'delivery_state' => DunningNotice::QUEUED,
            'sent_at' => now(),
            'sent_by' => $by?->getKey(),
            'sent_by_name' => $by !== null ? $by->name : $senderName,
        ]);
    }

    /**
     * The template's merge fields, resolved against this unit right now.
     *
     * @return array{subject: string, body: string}
     */
    public function render(DunningTemplate $template, Unit $unit): array
    {
        $merge = $this->mergeFields($unit);

        return [
            'subject' => strtr($template->subject, $merge),
            'body' => strtr($template->body, $merge),
        ];
    }

    /**
     * What each of board 8's five chips resolves to for one unit.
     *
     * AN UNKNOWN TOKEN IS LEFT STANDING. Blanking it would put a hole in a
     * sentence that nobody could trace back to a template, and a resident
     * reading "your fee of  is overdue" cannot tell whether the estate means
     * nothing or means everything. Left visible, the mistake is legible in the
     * log and fixable in the template.
     *
     * @return array<string, string>
     */
    public function mergeFields(Unit $unit): array
    {
        $balance = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();
        $oldest = $this->dues->oldestOpenChargeDate($unit);

        return [
            '{resident_first_name}' => $this->residentFirstName($unit),

            // Board 8 prints the lot without its phase prefix.
            '{unit_label}' => (string) $unit->reference,

            '{amount_due}' => $this->formatMoney($balance),
            '{due_date}' => $oldest?->format('M j, Y') ?? '—',
            // Counted to the estate's today, not UTC's — a day more after 7 PM in Kingston (bug hunt B023).
            '{days_overdue}' => (string) ($oldest === null ? 0 : (int) $oldest->diffInDays(Dues::siteDate(), absolute: false)),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* what the screens read */
    /* ------------------------------------------------------------------ */

    /**
     * Everything board 7 draws for one unit.
     *
     * @return array<string, mixed>
     */
    public function planBoard(Unit $unit, ?int $instalments = null, ?string $startsOn = null): array
    {
        $balance = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();
        $plan = $this->latestPlanFor($unit);

        /*
         * THE SCHEDULE FOR WHAT WAS TYPED (Hassam, 12 Oct, on live: "when we change the instalment plan the amount does
         * not change": 2 instalments and 4 both read $2,962.50). The page asks this GET again with the count and the
         * start it holds; they were never read, so the preview stayed at four from the 28th. A count outside 1 to 36,
         * or a start that is not a date, falls back to the defaults rather than refusing a read.
         */
        $count = $instalments !== null && $instalments >= 1 && $instalments <= self::MAX_INSTALMENTS
            ? $instalments
            : self::DEFAULT_INSTALMENTS;
        $start = $startsOn !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) === 1 && strtotime($startsOn) !== false
            ? $startsOn
            : null;

        return [
            'unit' => $this->dues->unitHeader($unit),
            'balance_minor' => $balance,

            // Board 7's callout names the resident — "Andrea will see this plan
            // and its due dates in her Dues statement" — so the screen needs the
            // first name on its own, not the full name it already has.
            'resident_first_name' => $this->residentFirstName($unit),

            /*
             * Null rather than an empty schedule when there is nothing owing.
             * `schedule()` refuses a non-positive balance, and a modal offering
             * to spread a debt of nothing over four months is a screen that
             * should not have opened.
             */
            'preview' => $balance > 0 ? $this->schedule($unit, $count, $start) : null,

            'plan' => $plan === null ? null : $this->planRow($plan),
            'protected' => $this->isProtected($unit),

            // One entry, because there is one. See PaymentPlan::FREQUENCY.
            'frequencies' => [PaymentPlan::FREQUENCY],
            'maxInstalments' => self::MAX_INSTALMENTS,
        ];
    }

    /**
     * Every payment plan in the estate — board 5's "Payment plans" tab (12 §2,
     * item 18).
     *
     * A REGISTER, NOT A SECOND PLAN SCREEN. Each row says whose plan it is,
     * where it stands and what is next, and opens that household's own plan
     * (board 7), which is where a plan is drawn up, agreed and activated. The
     * register writes nothing.
     *
     * PROGRESS IS COUNTED FROM THE INSTALMENTS, not stored on the plan: met,
     * missed and still due, so "on track" can never be a flag somebody forgot
     * to clear after a missed month.
     *
     * A HOUSEHOLD ON A PLAN IS A HOUSEHOLD IN ARREARS (M10 §5). The register
     * is the arrears register for the households on a plan, with or without
     * its totals, so a viewer who may not read the estate's position
     * (`Dues::maySeeEstatePosition()`, asked by the controller) gets no rows,
     * no counts — "Defaulted (3)" is how many households broke one — and the
     * reason, and no plan is read for them. One household's plan is still
     * theirs on board 7, reached from that household's ledger.
     *
     * ORDERED AND PAGED OVER THE WHOLE REGISTER (M32 F5). Progress is counted from each plan's instalments, which no
     * ORDER BY reaches, so the register is built whole and `TableQuery::pageOf()` orders it; by default in the order it
     * always had — live plans, then drafts, then defaulted, then the rest, the newest start first within each.
     *
     * @param  bool  $maySeePosition  whether the viewer may read which households are on a plan — see `Dues::maySeeEstatePosition()`
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>|object, filter: string, positionWithheld: bool, positionReason: string, table: array<string, mixed>|null}
     */
    public function planRegister(string $status = '', bool $maySeePosition = true, ?TableQuery $table = null): array
    {
        if (! $maySeePosition) {
            return [
                'rows' => [],

                // An object and not a list, so the page's `counts` prop keeps
                // its type; there is nothing in it to count out.
                'counts' => (object) [],
                'filter' => '',
                'positionWithheld' => true,
                'positionReason' => self::PLANS_WITHHELD,
                'table' => null,
            ];
        }

        $table ??= self::plansTable();

        $statuses = [PaymentPlan::DRAFT, PaymentPlan::ACTIVE, PaymentPlan::COMPLETED, PaymentPlan::DEFAULTED, PaymentPlan::CANCELLED];
        $status = in_array($status, $statuses, true) ? $status : '';

        $all = PaymentPlan::query()->with(['unit.household', 'schedule'])->get();
        $today = Dues::siteDate();

        $rows = $all
            ->when($status !== '', static fn ($plans) => $plans->where('status', $status))
            ->map(function (PaymentPlan $plan) use ($today): array {
                $met = $plan->schedule->where('status', PaymentPlanInstalment::MET)->count();
                $missed = $plan->schedule->where('status', PaymentPlanInstalment::MISSED)->count();

                // Past its date and not ruled on — a decision owed on board 7 (bug hunt B019). Only a live plan owes one.
                $pastDue = $plan->status === PaymentPlan::ACTIVE
                    ? $plan->schedule->filter(static fn (PaymentPlanInstalment $instalment): bool => $instalment->isOverdue($today))->count()
                    : 0;
                $next = $plan->schedule
                    ->where('status', PaymentPlanInstalment::DUE)
                    ->sortBy('due_on')
                    ->first();

                return [
                    'id' => $plan->id,
                    'reference' => $plan->reference,
                    'unit_id' => $plan->unit_id,
                    'unit' => (string) ($plan->unit->reference ?? '—'),
                    'household' => (string) ($plan->unit->household->name ?? 'Vacant unit'),
                    'total' => MoneyFormatter::fromMinor($plan->total_minor, $plan->currency),
                    'instalments' => $plan->instalments,
                    'progress' => $met.' of '.$plan->instalments.' met'
                        .($missed > 0 ? ' · '.$missed.' missed' : '')
                        .($pastDue > 0 ? ' · '.$pastDue.' past due, not ruled' : ''),
                    'missed' => $missed,
                    'past_due' => $pastDue,
                    'next' => $next === null
                        ? '—'
                        : MoneyFormatter::fromMinor($next->amount_minor, $next->currency).' due '.$next->due_on->format('M j, Y'),
                    // What the register orders by (M32 F5).
                    'total_minor' => (int) $plan->total_minor,
                    'met_share' => $plan->instalments > 0 ? $met / $plan->instalments : 0.0,
                    'next_due' => $next?->due_on->toDateString(),
                    'starts_sort' => $plan->starts_on->toDateString(),
                    'status' => $plan->status,
                    'status_label' => match ($plan->status) {
                        // Read on hover beside the badge's word, so in plain words and without a dash (M53 Law 3).
                        PaymentPlan::DRAFT => $plan->agreed_at === null ? 'Draft, not agreed yet' : 'Agreed, waiting for approval',
                        PaymentPlan::ACTIVE => 'Active',
                        PaymentPlan::COMPLETED => 'Completed',
                        PaymentPlan::DEFAULTED => 'Defaulted',
                        default => 'Cancelled',
                    },
                    'approved_by' => $plan->approved_by_name,
                ];
            })
            ->values()
            ->all();

        $page = $table->pageOf($rows);

        return [
            'rows' => array_values($page->items()),
            'counts' => collect($statuses)->mapWithKeys(static fn (string $s): array => [$s => $all->where('status', $s)->count()])->all(),
            'filter' => $status,
            'positionWithheld' => false,
            'positionReason' => '',
            'table' => $table->meta($page),
        ];
    }

    /**
     * What the plan register orders by (M32 F5). Status is its standing order: live, draft, defaulted, then completed
     * and cancelled, and the newest start first within each — the order the register has always been read in.
     *
     * @return array<string, string|\Closure>
     */
    public static function planSorts(): array
    {
        $rank = [PaymentPlan::ACTIVE => 0, PaymentPlan::DRAFT => 1, PaymentPlan::DEFAULTED => 2];

        return [
            'reference' => 'reference',
            'unit' => 'unit',
            'household' => 'household',
            'total' => 'total_minor',
            'progress' => 'met_share',
            'next' => 'next_due',
            'status' => static fn (array $row): array => [
                $rank[$row['status']] ?? 3,
                // Newest start first, then the newest plan: a date and an id, each turned around.
                -(int) str_replace('-', '', (string) ($row['starts_sort'] ?? '0')),
                -(int) $row['id'],
            ],
        ];
    }

    /** The plan register as the address asks for it (M32 F5). */
    public static function plansTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::planSorts(), 'status', 'asc');
    }

    /**
     * What the dunning log orders by (M32 F5). The balance is not one: it is read from the ledger as the page is
     * drawn, for the units on that page alone (M11 A2), and no ORDER BY reaches it. Sent is the log's own order —
     * newest batch first, and within a batch the order the notices went out — so turning it round reverses the log.
     *
     * @return array<string, string|\Closure>
     */
    public static function sendSorts(): array
    {
        $unit = '(SELECT reference FROM units WHERE units.id = dunning_notices.unit_id)';

        return [
            'unit' => static fn ($query, string $dir) => $query->orderByRaw('CHAR_LENGTH('.$unit.') '.$dir)->orderByRaw($unit.' '.$dir),
            'step' => 'dunning_notices.template_label',
            'channel' => 'dunning_notices.channel',
            'sent' => static fn ($query, string $dir) => $query
                ->orderBy('dunning_notices.sent_at', $dir)
                ->orderBy('dunning_notices.id', $dir === 'desc' ? 'asc' : 'desc'),
            'status' => 'dunning_notices.delivery_state',
        ];
    }

    /** The dunning log as the address asks for it (M32 F5): the newest batch first. */
    public static function sendsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::sendSorts(), 'sent', 'desc');
    }

    /**
     * Everything board 8 draws: the recent sends and the template editor.
     *
     * THE LOG IS A LIST OF HOUSEHOLDS THE ESTATE HAS CHASED (M10 §5). "Final
     * notice — Lot 47" says a household is behind as plainly as its balance
     * does, so a viewer who may not read the estate's position
     * (`Dues::maySeeEstatePosition()`, asked by the controller) gets no sends
     * and the reason, and neither the log nor the ledger is read for them. The
     * template editor on the right stays: wording a step for the committee is
     * the office's work, and a template names no household.
     *
     * THE WHOLE LOG, A PAGE AT A TIME (M32 F5). It used to be the latest five, the five the board draws, and the sixth
     * notice the estate sent was nowhere on the screen that exists to settle a dispute about it.
     *
     * @param  bool  $maySeePosition  whether the viewer may read which households have been chased — see `Dues::maySeeEstatePosition()`
     * @return array<string, mixed>
     */
    public function dunningBoard(?TableQuery $table = null, bool $maySeePosition = true): array
    {
        $table ??= self::sendsTable();
        $page = null;

        /*
         * Newest batch first, and within a batch the order the notices went
         * out (`sendSorts()`). Board 8 draws its five rows in an order that is
         * neither — a send from three weeks ago sits below yesterday's, but one
         * of today's sits below it — and there is no column for a hand-set
         * position. A delivery log that is not chronological cannot be read at
         * all, so it is chronological, and the drawn sequence is a mock's
         * arrangement rather than a rule.
         */
        if ($maySeePosition) {
            $query = DunningNotice::query()->with('unit');
            $table->order($query, 'dunning_notices.id');
            $page = $table->paginate($query);
        }

        $notices = $page === null ? new EloquentCollection : $page->getCollection();

        /*
         * Balances read from the LEDGER at draw time, in one query for the
         * whole log. No notice stores an amount — see the model — so the
         * Balance column cannot drift from the arrears board beside it.
         *
         * For the units on the page only (M11 A2): five notices used to have
         * the whole estate's receivable summed to print five balances.
         */
        $balances = $maySeePosition
            ? $this->dues->unitBalances(null, $notices->pluck('unit_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all())
            : [];

        $sends = [];

        foreach ($notices as $notice) {
            $sends[] = [
                'id' => $notice->id,
                'unit' => (string) $notice->unit->reference,
                'unit_id' => $notice->unit_id,
                'step' => $notice->template_label,
                'channel' => $notice->channelLabel(),
                'sent' => $this->sentLabel($notice->sent_at),
                'balance_minor' => $balances[$notice->unit_id] ?? 0,
                'status' => $notice->statusLabel(),
                'tone' => $notice->statusTone(),
            ];
        }

        $templates = DunningTemplate::query()
            ->where('is_active', true)
            ->orderBy('stage')
            ->get()
            ->map(static fn (DunningTemplate $template): array => [
                'id' => $template->id,
                'key' => $template->key,
                'label' => $template->label,
                'stage' => $template->stage,
                'channel' => $template->channel,
                'channel_label' => DunningTemplate::channelLabel($template->channel),
                'subject' => $template->subject,
                'body' => $template->body,
                'days_overdue' => $template->days_overdue,
            ])
            ->all();

        /*
         * WHETHER AN "SMS" STEP CAN TEXT ANYBODY (M10 §1). The ladder offers
         * "SMS" and "Push + Email + SMS" as ways a step goes out, and a committee
         * choosing one would reasonably believe households get a text. None do
         * while no provider is connected, so the editor says so beside the choice.
         * The options stay: a step is the estate's own wording and ladder, and it
         * becomes true on its own the day a provider is connected.
         */
        $smsAvailable = app(SmsSettings::class)->canSend();

        return [
            'sends' => $sends,
            // Null where the log is withheld: there is no page of it to describe.
            'table' => $page === null ? null : $table->meta($page),
            'templates' => $templates,
            'mergeFields' => DunningTemplate::MERGE_FIELDS,
            'channels' => DunningTemplate::CHANNEL_LABELS,
            'drafts' => $this->draftBoard(),
            'sms_available' => $smsAvailable,
            'sms_note' => $smsAvailable
                ? null
                : 'No SMS provider is connected, so a step that goes out by SMS texts nobody — its other channels are unaffected.',
            'positionWithheld' => ! $maySeePosition,
            'positionReason' => $maySeePosition ? '' : self::SENDS_WITHHELD,
        ];
    }

    /**
     * One household's deliberate send — what board 8 shows when it is opened from
     * that household's ledger by "Send reminder now" (bug hunt B111).
     *
     * The ledger's control used to land on the estate's log and its wording, with
     * no household and no send, so the reminder it promised could not be sent from
     * the console at all. This is the household, every step in force rendered
     * against it by `render()` — the same call `send()` freezes onto the log, so the
     * office reads exactly what the household will get — its flag if it has one,
     * and its own last reminders.
     *
     * THE SUGGESTED STEP is only where the form opens, and the office may choose any
     * step in force: the furthest step whose days-overdue threshold the household's
     * oldest open charge has reached, or null when it owes nothing that has fallen
     * due. One household's own reminders are the office's to see, as its ledger and
     * its plan are; the estate-wide log stays behind `SENDS_WITHHELD`.
     *
     * @return array{
     *     unit: array{id: int, reference: string, title: string},
     *     steps: list<array{id: int, label: string, stage: int, channel: string, channel_label: string, subject: string, body: string}>,
     *     suggested: int|null,
     *     flag: string|null,
     *     notices: list<array{id: int, step: string, channel: string, sent: string, status: string}>
     * }
     */
    public function sendPanel(Unit $unit): array
    {
        $templates = DunningTemplate::query()->where('is_active', true)->orderBy('stage')->get();

        $oldest = $this->dues->oldestOpenChargeDate($unit);
        $daysOverdue = $oldest === null
            ? null
            : max(0, (int) floor($oldest->copy()->startOfDay()->diffInDays(Dues::siteDate()->startOfDay(), false)));

        // The furthest threshold reached, and the later stage where two share one.
        $reached = $daysOverdue === null
            ? null
            : $templates
                ->filter(static fn (DunningTemplate $template): bool => (int) $template->days_overdue <= $daysOverdue)
                ->sortBy([['days_overdue', 'desc'], ['stage', 'desc']])
                ->first();

        $suggested = $reached === null ? null : (int) $reached->id;

        $flag = $this->flagFor($unit);

        return [
            'unit' => [
                'id' => (int) $unit->id,
                'reference' => (string) $unit->reference,
                'title' => $unit->reference.' · '.($unit->household->name ?? 'Vacant unit'),
            ],
            'steps' => $templates->map(fn (DunningTemplate $template): array => [
                'id' => (int) $template->id,
                'label' => (string) $template->label,
                'stage' => (int) $template->stage,
                'channel' => (string) $template->channel,
                'channel_label' => DunningTemplate::channelLabel($template->channel),
                ...$this->render($template, $unit),
            ])->values()->all(),
            'suggested' => $suggested,
            'flag' => $flag === null ? null : (UnitCollectionFlag::KINDS[$flag->kind] ?? (string) $flag->kind),
            'notices' => DunningNotice::query()
                ->where('unit_id', $unit->id)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (DunningNotice $notice): array => [
                    'id' => (int) $notice->id,
                    'step' => (string) $notice->template_label,
                    'channel' => $notice->channelLabel(),
                    'sent' => $this->sentLabel($notice->sent_at),
                    'status' => $notice->statusLabel(),
                ])->values()->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* hardship and dispute (12 §1) */
    /* ------------------------------------------------------------------ */

    /** The flag in force on a unit, or null. */
    public function flagFor(Unit $unit): ?UnitCollectionFlag
    {
        return UnitCollectionFlag::query()
            ->where('unit_id', $unit->id)
            ->whereNull('lifted_at')
            ->latest('raised_at')
            ->first();
    }

    /**
     * Flag a household. THE DEBT DOES NOT MOVE.
     *
     * No journal is raised, no charge reversed, no ageing bucket changed. The
     * arrears board goes on reporting this household among those who owe,
     * because it does. What stops is the estate's own automated chasing — see
     * `automatedQueue()`, which is the only thing that reads this.
     */
    public function flag(Unit $unit, string $kind, string $reason, string $minute, User $by): UnitCollectionFlag
    {
        if (! array_key_exists($kind, UnitCollectionFlag::KINDS)) {
            throw new DomainException('A flag is a hardship or a dispute. They end differently, so the estate has to say which this is.');
        }

        $reason = trim($reason);
        $minute = trim($minute);

        if ($reason === '') {
            throw new DomainException('Say why, in the estate\'s own words. Next year\'s committee has to be able to read why this household stopped being chased.');
        }

        if ($minute === '') {
            throw new DomainException('A flag needs the committee minute that agreed it. Without one it is an office decision wearing a committee\'s clothes.');
        }

        /*
         * ONE FLAG IN FORCE, DECIDED UNDER THE UNIT'S LOCK (bug hunt B020). Two
         * approvers flagging the same household at once both used to find no flag
         * and both write one — two episodes at once, which the rule above exists
         * to stop.
         */
        return DB::connection('tenant')->transaction(function () use ($unit, $kind, $reason, $minute, $by): UnitCollectionFlag {
            Unit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();

            if ($this->flagFor($unit) !== null) {
                throw new DomainException($unit->reference.' is already flagged. Lift the flag in force before raising another, so the record reads as one episode after another rather than two at once.');
            }

            return UnitCollectionFlag::create([
                'unit_id' => $unit->id,
                'kind' => $kind,
                'reason' => $reason,
                'minute_reference' => $minute,
                'raised_by_id' => $by->getKey(),
                'raised_by_name' => (string) $by->name,
                'raised_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Lift a flag. The row stays; nothing here is deleted.
     *
     * A REASON AND A MINUTE, EXACTLY AS RAISING ONE NEEDS (13 A4). The committee
     * agreed to stop chasing this household; resuming final demands is the same
     * size of decision, and a lift nobody can trace to a minute is the office
     * overruling the committee.
     */
    public function liftFlag(UnitCollectionFlag $flag, string $reason, string $minute, User $by): UnitCollectionFlag
    {
        if (! $flag->isInForce()) {
            throw new DomainException('That flag has already been lifted.');
        }

        $reason = trim($reason);
        $minute = trim($minute);

        if ($reason === '') {
            throw new DomainException('Say why the flag is lifted. Automated reminders resume on this household from the next run, and next year\'s committee has to be able to read why.');
        }

        if ($minute === '') {
            throw new DomainException('Lifting a flag needs the committee minute that agreed it, as raising one did. Without one, the office is resuming final demands the committee stopped.');
        }

        /*
         * Asked again under the flag's lock (bug hunt B020): a second lift used to
         * pass the check above on its own copy and overwrite who lifted it, when
         * and under which minute.
         */
        $lifted = DB::connection('tenant')->transaction(function () use ($flag, $reason, $minute, $by): UnitCollectionFlag {
            $flag = UnitCollectionFlag::query()->whereKey($flag->getKey())->lockForUpdate()->firstOrFail();

            if (! $flag->isInForce()) {
                throw new DomainException('That flag has already been lifted.');
            }

            $flag->forceFill([
                'lifted_at' => Carbon::now(),
                'lifted_by_name' => (string) $by->name,
                'lifted_reason' => $reason,
                'lifted_minute_reference' => $minute,
            ])->save();

            return $flag;
        });

        $flag->setRawAttributes($lifted->getAttributes(), true);

        return $flag;
    }

    /**
     * The flag in force on each unit that has one, keyed by unit id.
     *
     * @return array<int, UnitCollectionFlag>
     */
    public function flagsInForce(): array
    {
        return UnitCollectionFlag::query()
            ->whereNull('lifted_at')
            ->get()
            ->keyBy('unit_id')
            ->all();
    }

    /**
     * The units an AUTOMATED dunning run would chase, and the ones it skips.
     *
     * THIS IS WHERE THE SUPPRESSION LIVES, and it is deliberately not inside
     * `send()`: a person pressing "Send reminder now" on a flagged household is
     * making a decision with their name against it, and the estate is allowed
     * to make it. What a flag stops is the machine sending a final demand at
     * 09:00 on Tuesday to a household that is in front of the committee.
     *
     * @return array{due: list<int>, suppressed: list<array{unit_id: int, reference: string, headline: string}>}
     */
    public function automatedQueue(): array
    {
        $owing = array_keys(array_filter($this->dues->unitBalances(), static fn (int $minor): bool => $minor > 0));

        $flags = UnitCollectionFlag::query()
            ->with('unit')
            ->whereNull('lifted_at')
            ->whereIn('unit_id', $owing)
            ->get();

        $suppressedIds = $flags->pluck('unit_id')->all();

        return [
            'due' => array_values(array_diff($owing, $suppressedIds)),
            'suppressed' => $flags->map(static fn (UnitCollectionFlag $flag): array => [
                'unit_id' => $flag->unit_id,
                'reference' => (string) $flag->unit->reference,
                'headline' => $flag->headline(),
            ])->all(),
        ];
    }

    /**
     * The automated dunning run — what `dunning:run` does each morning (13 A4).
     *
     * Until this existed, `automatedQueue()` named the households a run would
     * chase and nothing ever ran, so the hardship flag suppressed nothing in
     * production. For each household the queue leaves in:
     *
     *   ON AN AGREED PLAN      skipped. A household keeping to a plan the estate
     *                          agreed is not sent a demand for the debt the plan
     *                          is paying — the same shield the gate and the
     *                          amenity diary already give it.
     *   NO STEP REACHED        skipped. The ladder's first step is due at its own
     *                          `days_overdue`, and a household behind by less has
     *                          nothing to be sent.
     *   STEP ALREADY SENT      skipped. One notice per step per arrears episode,
     *                          the episode beginning at the oldest charge still
     *                          open; a step sent by hand counts, and so does any
     *                          later step. Running twice in a morning sends nothing
     *                          the second time.
     *   OTHERWISE              the highest step its days overdue has reached, sent
     *                          through `send()` — the same verbatim log as a
     *                          deliberate send, signed as the automated run.
     *
     * Notices land `queued`: no delivery adapter exists yet, and the log does
     * not claim a delivery nobody made (see `send()`).
     *
     * AND IT NAMES EVERY PLAN WAITING ON A RULING (bug hunt B019) — an active
     * plan with an instalment past its date that nobody has ruled met or missed.
     * The run skips those households because the plan shields them; it cannot
     * rule for anybody, so it says which plans need an approver on board 7.
     *
     * @return array{sent: list<array{unit: string, step: string}>, suppressed: int, on_plan: int, not_due: int, already_sent: int, awaiting_ruling: list<string>}
     */
    public function runAutomated(?Carbon $today = null, bool $dryRun = false): array
    {
        // The estate's today: at 09:00 in Kingston UTC agrees, but a run started after 7 PM would age every unit a day early (bug hunt B023).
        $today = ($today?->copy() ?? Dues::siteDate())->startOfDay();
        $queue = $this->automatedQueue();

        $ladder = DunningTemplate::query()
            ->where('is_active', true)
            ->orderByDesc('days_overdue')
            ->orderByDesc('stage')
            ->get();

        $oldest = $this->dues->oldestOpenChargeDates($today);
        $report = [
            'sent' => [],
            'suppressed' => count($queue['suppressed']),
            'on_plan' => 0,
            'not_due' => 0,
            'already_sent' => 0,
            'awaiting_ruling' => $this->awaitingRuling($today),
        ];

        foreach (Unit::query()->whereIn('id', $queue['due'])->orderBy('id')->get() as $unit) {
            if ($this->isProtected($unit)) {
                $report['on_plan']++;

                continue;
            }

            $since = $oldest[$unit->id] ?? null;
            $overdue = $since === null ? 0 : (int) $since->diffInDays($today, absolute: false);

            $step = $ladder->first(static fn (DunningTemplate $template): bool => $template->days_overdue <= $overdue);

            if ($since === null || $step === null) {
                $report['not_due']++;

                continue;
            }

            $sent = DunningNotice::query()
                ->where('unit_id', $unit->id)
                ->where('stage', '>=', $step->stage)
                // The episode starts when the estate's own day of the oldest due date does (bug hunt B023).
                ->where('sent_at', '>=', SiteTime::dayBounds($since)[0])
                ->exists();

            if ($sent) {
                $report['already_sent']++;

                continue;
            }

            if (! $dryRun) {
                $this->send($unit, $step, senderName: self::AUTOMATED_SENDER);
            }

            $report['sent'][] = ['unit' => (string) $unit->reference, 'step' => $step->label];
        }

        return $report;
    }

    /* ------------------------------------------------------------------ */
    /* the template editor (12 §1) */
    /* ------------------------------------------------------------------ */

    /**
     * The drafts waiting on the committee, oldest first.
     *
     * ADOPTED DRAFTS ARE NOT HERE. Once a draft is in force, what it says is
     * readable from the ladder itself, and leaving it on a "waiting" list would
     * make a decided thing look undecided. Its resolution reference survives on
     * the row, which is where an audit of why the wording changed reads it.
     *
     * @return list<array<string, mixed>>
     */
    public function draftBoard(): array
    {
        return DunningTemplateDraft::query()
            ->whereNull('adopted_at')
            ->orderBy('stage')
            ->orderBy('id')
            ->get()
            ->map(static fn (DunningTemplateDraft $draft): array => [
                'id' => $draft->id,
                'template_id' => $draft->dunning_template_id,
                'target' => $draft->targetLabel(),
                'label' => $draft->label,
                'stage' => $draft->stage,
                'channel' => $draft->channel,
                'channel_label' => DunningTemplate::channelLabel($draft->channel),
                'subject' => $draft->subject,
                'body' => $draft->body,
                'days_overdue' => $draft->days_overdue,
                'resolution_reference' => $draft->resolution_reference,
                'drafted_by' => $draft->drafted_by_name,
                'drafted_at' => $draft->created_at === null ? null : SiteTime::local($draft->created_at)->format('M j, Y'),
            ])
            ->all();
    }

    /**
     * Save a proposed step. NOTHING THE ESTATE SENDS CHANGES.
     *
     * This is where the ruling lands: a new or edited stage is a draft, full
     * stop. A treasurer can reword a final demand at four in the afternoon and
     * the four-o'clock collections run still sends what the committee agreed.
     *
     * The merge tokens are checked HERE, against the closed catalogue, because
     * this is the last moment a mistake is cheap. A token nobody can resolve is
     * left standing when the notice renders, and it arrives on a resident's
     * phone as its own literal text.
     *
     * @param  array<string, mixed>  $fields
     */
    public function saveDraft(array $fields, User $by, ?DunningTemplate $against = null): DunningTemplateDraft
    {
        $label = trim((string) ($fields['label'] ?? ''));
        $subject = trim((string) ($fields['subject'] ?? ''));
        $body = (string) ($fields['body'] ?? '');
        $stage = (int) ($fields['stage'] ?? 0);
        $days = (int) ($fields['days_overdue'] ?? 0);
        $channel = (string) ($fields['channel'] ?? 'email');

        if ($label === '' || $subject === '' || trim($body) === '') {
            throw new DomainException('A step has a name, a subject line and a body. A notice with any of them missing is one a resident cannot act on.');
        }

        if (! array_key_exists($channel, DunningTemplate::CHANNEL_LABELS)) {
            throw new DomainException('Choose one of the channel combinations this estate can actually send on.');
        }

        /*
         * ZERO IS A REAL STAGE and not an empty one: the estate's pre-due
         * courtesy — "Day 20" — fires BEFORE anything is overdue, which is why
         * it carries stage 0 and `days_overdue` 0. Refusing it here would make
         * the one step every estate on this platform already has uneditable.
         */
        if ($stage < 0 || $stage > 20) {
            throw new DomainException('A stage is a rung on the ladder: 0 for a courtesy note before anything is due, then 1 upward.');
        }

        if ($days < 0 || $days > 365) {
            throw new DomainException('A step fires between the due date and a year past it.');
        }

        $unknown = [];

        if (preg_match_all('/\{[a-z0-9_]+\}/i', $body.' '.$subject, $found) === false) {
            $found = [[]];
        }

        foreach (array_unique($found[0]) as $token) {
            if (! in_array(strtolower($token), DunningTemplate::MERGE_FIELDS, true)) {
                $unknown[] = $token;
            }
        }

        if ($unknown !== []) {
            throw new DomainException(sprintf(
                '%s cannot be resolved against a household, so it would arrive on a resident\'s phone as its own literal text. The fields this estate can fill are %s.',
                implode(', ', $unknown),
                implode(', ', DunningTemplate::MERGE_FIELDS),
            ));
        }

        /*
         * ONE OPEN DRAFT PER STEP. A second proposal against the same rung is
         * the first one rewritten — two of them would put the committee in
         * front of a choice nobody meant to offer them.
         */
        $draft = DunningTemplateDraft::query()
            ->whereNull('adopted_at')
            ->when(
                $against !== null,
                static fn ($query) => $query->where('dunning_template_id', $against?->id),
                static fn ($query) => $query->whereNull('dunning_template_id')->where('stage', $stage),
            )
            ->first() ?? new DunningTemplateDraft;

        $draft->fill([
            'dunning_template_id' => $against?->id,
            'label' => $label,
            'stage' => $stage,
            'channel' => $channel,
            'subject' => $subject,
            'body' => $body,
            'days_overdue' => $days,

            // Cleared on every save: a reference belongs to the wording the
            // committee actually saw, and a reworded draft is not that wording.
            'resolution_reference' => null,
            'drafted_by_id' => $by->getKey(),
            'drafted_by_name' => (string) $by->name,
        ])->save();

        return $draft;
    }

    /**
     * Put a draft in force, against a committee resolution.
     *
     * THE REFERENCE IS THE WHOLE GATE. What a household is told about its debt
     * is a decision the committee makes, not the office, and the reference is
     * how the notice that goes out next week ties back to the minute that
     * agreed it.
     *
     * NO NOTICE ALREADY SENT MOVES. `dunning_notices` holds the subject and
     * body as sent and nothing here touches that table — a log that followed
     * the wording would show a resident a message they never received, which is
     * the one thing it exists to prevent.
     */
    public function adoptDraft(DunningTemplateDraft $draft, string $resolution, User $by): DunningTemplate
    {
        $resolution = trim($resolution);

        if ($resolution === '') {
            throw new DomainException('A wording change needs the committee resolution that agreed it — the minute or resolution number from the estate\'s own book. Without it, nobody can say later who decided what a household was told.');
        }

        if ($draft->isAdopted()) {
            throw new DomainException('That draft is already in force. Reword the step again to propose a further change.');
        }

        return DB::connection('tenant')->transaction(function () use ($draft, $resolution, $by): DunningTemplate {
            $template = $draft->dunning_template_id === null
                ? new DunningTemplate(['key' => $this->draftKey($draft)])
                : DunningTemplate::query()->findOrFail($draft->dunning_template_id);

            $template->fill([
                'label' => $draft->label,
                'stage' => $draft->stage,
                'channel' => $draft->channel,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'days_overdue' => $draft->days_overdue,
                'is_active' => true,
            ])->save();

            $draft->forceFill([
                'dunning_template_id' => $template->id,
                'resolution_reference' => $resolution,
                'adopted_at' => Carbon::now(),
                'adopted_by_name' => (string) $by->name,
            ])->save();

            return $template;
        });
    }

    /** A stable key for a step the estate is inventing: stage-3, stage-3-2 if taken. */
    private function draftKey(DunningTemplateDraft $draft): string
    {
        $base = 'stage-'.$draft->stage;
        $key = $base;
        $n = 1;

        while (DunningTemplate::query()->where('key', $key)->exists()) {
            $n++;
            $key = $base.'-'.$n;
        }

        return $key;
    }

    /**
     * One unit's dunning history, newest first — the tab board 6 links from.
     *
     * @return list<array<string, mixed>>
     */
    public function noticesFor(Unit $unit, int $limit = 20): array
    {
        return DunningNotice::query()
            ->where('unit_id', $unit->id)
            ->orderByDesc('sent_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (DunningNotice $notice): array => [
                'id' => $notice->id,
                'step' => $notice->template_label,
                'channel' => $notice->channelLabel(),
                'sent' => $this->sentLabel($notice->sent_at),
                'status' => $notice->statusLabel(),
                'tone' => $notice->statusTone(),
                'subject' => $notice->subject,

                // The body AS SENT. This is the field a dispute is settled from.
                'body' => $notice->body,
            ])
            ->all();
    }

    /**
     * The latest plan on a unit, whatever state it is in.
     *
     * Board 7 has to show a defaulted plan as well as a live one — a household
     * asking to be put on a plan when they broke the last one is the case the
     * screen most needs to make visible.
     */
    public function latestPlanFor(Unit $unit): ?PaymentPlan
    {
        return PaymentPlan::query()
            ->with('schedule')
            ->where('unit_id', $unit->id)
            ->orderByDesc('id')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function planRow(PaymentPlan $plan): array
    {
        $today = Dues::siteDate();

        return [
            'id' => $plan->id,
            'reference' => $plan->reference,
            'total_minor' => $plan->total_minor,
            'instalments' => $plan->instalments,
            'frequency' => PaymentPlan::FREQUENCY,
            'starts_on_label' => $plan->starts_on->format('M j, Y'),
            'status' => $plan->status,
            'agreed_by_name' => $plan->agreed_by_name,
            // Instants, printed as the day it was at the estate (bug hunt B023).
            'agreed_on_label' => $plan->agreed_at === null ? null : SiteTime::local($plan->agreed_at)->format('M j, Y'),
            'approved_by_name' => $plan->approved_by_name,
            'terms' => $plan->terms,
            'rows' => $plan->schedule->map(static fn (PaymentPlanInstalment $i): array => [
                'id' => $i->id,
                'sequence' => $i->sequence,
                'amount_minor' => $i->amount_minor,
                'due_on' => $i->due_on->toDateString(),
                'due_label' => $i->due_on->format('M j, Y'),
                'status' => $i->status,

                // Past its date at the estate and not ruled — what board 7 may rule missed (bug hunt B019).
                'overdue' => $i->isOverdue($today),
                'settled_on_label' => $i->settled_at === null ? null : SiteTime::local($i->settled_at)->format('M j, Y'),
            ])->all(),
        ];
    }

    /**
     * The name a letter opens with.
     *
     * Falls back to the household and then to the unit rather than to an empty
     * greeting: nine of Phoenix Park's 450 units are vacant, they are billed
     * like every other unit, and "Hi ," is not a letter.
     */
    private function residentFirstName(Unit $unit): string
    {
        $resident = $unit->household?->residents()->where('is_primary', true)->first();

        $firstName = explode(' ', trim((string) ($resident->full_name ?? '')))[0];

        return $firstName !== ''
            ? $firstName
            : (string) ($unit->household->name ?? $unit->reference);
    }

    /**
     * Board 8's Sent column: "Today, 9:00 AM", "Yesterday", "Sep 20, 9:00 AM".
     *
     * ON THE ESTATE'S CLOCK (bug hunt B028). `sent_at` is a UTC instant, and it
     * used to be printed as it was stored: the 09:00 Kingston run read "Today,
     * 2:00 PM", and after 7 PM "Today" and "Yesterday" were a day out — on the
     * log a dispute is settled from. "Today" is the estate's today, which is the
     * day `SiteTime::local()` compares against.
     */
    private function sentLabel(Carbon $sentAt): string
    {
        $local = SiteTime::local($sentAt);

        return match (true) {
            $local->isToday() => 'Today, '.SiteTime::fmt($local, 'g:i A'),
            $local->isYesterday() => 'Yesterday',
            default => SiteTime::fmt($local, 'M j, g:i A'),
        };
    }

    /**
     * "$12,400.00", composed from the minor units by integer arithmetic.
     *
     * Never through a float. This string goes into a demand letter that is then
     * frozen on the notice, and a rounding artefact in it is a figure a resident
     * can hold the estate to.
     */
    private function formatMoney(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.'$'.number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The 28th of this month, or of next month once it has passed — at the estate.
     *
     * Asked of the estate's calendar (bug hunt B023): on the evening of the 27th
     * in Kingston UTC is already on the 28th, and "has it passed" used to answer
     * yes, starting the plan a month late.
     */
    private function defaultStart(): Carbon
    {
        $today = Dues::siteDate();
        $candidate = $today->copy()->startOfMonth()->addDays(self::DEFAULT_START_DAY - 1);

        return $candidate->greaterThan($today) ? $candidate : $candidate->addMonthNoOverflow();
    }

    private function asDate(Carbon|string $date): Carbon
    {
        return $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
    }

    /**
     * "PLAN-2026-09-0001" — sequential within the month, under a lock.
     *
     * The same shape as a charge or a receipt reference, because a resident
     * reads it back over the phone and a treasurer looks it up beside the
     * others.
     */
    private function nextReference(): string
    {
        // Numbered in the estate's month, which on the last evening of one is not UTC's (bug hunt B023).
        $stem = 'PLAN-'.Dues::siteDate()->format('Y-m').'-';

        // Past every reference the unique index holds, a retired sample plan's too (M33 §5).
        $last = Retirements::seeingEverything(static fn (): mixed => PaymentPlan::query()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference'));

        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($stem))) + 1;

        return $stem.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
