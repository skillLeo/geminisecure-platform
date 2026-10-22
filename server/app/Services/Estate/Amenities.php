<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\AmenitySlot;
use App\Models\Estate\Charge;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Journal;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Passes\VisitorPasses;
use App\Support\MoneyFormatter;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Brick\Math\Exception\MathException;
use Brick\Money\Money;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The booking diary and the rate card behind it — boards 19 and 20.
 *
 * A BOOKING CARRIES THE TERMS IT WAS MADE UNDER. The fee, the deposit and the
 * cancellation rule are copied off the amenity at the moment the booking is
 * made and never read back through the relation. The Build Spec states the rule
 * — "Changing a rule does not alter bookings already confirmed under the
 * previous rule" — and board 19's accounting note states what breaks without it:
 * a rate card edited on Tuesday would restate every deposit held since March,
 * and the deposits-held control account would stop agreeing with the sum of open
 * bookings.
 *
 * A HOUSEHOLD IN ARREARS IS BLOCKED, ON THE ESTATE'S ONE THRESHOLD. Not the
 * module's own: Q-008 was ruled with "one arrears threshold across the estate,
 * not two — a household is either in arrears or it is not", so `mayBook()` reads
 * the same `arrears_restriction_*` settings the gate uses and an active payment
 * plan lifts it exactly as it lifts the gate.
 *
 * `mayBook()` returns a BOOLEAN and never a figure — the person reading board 19
 * is the Property Manager, who holds `facilities` in full and is locked out of
 * `dues_ledger`, `payments` and `accounting_posting` by platform invariant
 * (D-010). "This household cannot book" is a facilities fact; "this household
 * owes J$44,900" is not theirs to see, and a refusal message that named the
 * amount would hand it to them through the back door.
 *
 * THE CHECK HAPPENS WHEN THE BOOKING IS MADE AND NOT AGAIN. A household that
 * booked the Club House while clear and fell behind afterwards keeps its
 * booking, for the same reason a confirmed booking keeps its fee: the terms were
 * settled when the estate accepted it. Re-testing at approval would make the
 * rule bite on a date nobody agreed to.
 *
 * AN AMENITY FEE IS A CHARGE ON A UNIT LIKE ANY OTHER. `recordFee()` posts it
 * through `Dues::charge` — Dr 1200 Dues Receivable, Cr 4100 Amenity Booking Fees
 * — and stores only the charge's id on the booking. It is not recorded a second
 * way and no amount is kept beside the pointer, so the fee on a resident's
 * statement and the fee on their booking cannot drift apart: they are one row
 * read two ways. The unique key on `fee_charge_id` and the refusal below mean it
 * can happen exactly once.
 *
 * A DEPOSIT IS A LIABILITY, AND IT REACHES THE LEDGER. Board 19's accounting
 * note is exact about what each state means in the books: only "held" belongs on
 * the deposits-held liability, and posting "awaiting" would overstate that
 * account by money the estate does not have. This class used to record the state
 * and post nothing at all — the client overruled that on Q-009, because cash had
 * moved and the ledger said nothing.
 *
 *     taken      Dr 1000 Bank            Cr 2200 Deposits Held
 *     refunded   Dr 2200 Deposits Held   Cr 1000 Bank
 *     forfeited  Dr 2200 Deposits Held   Cr 4100 Amenity Booking Fees
 *
 * Only the third is ever income, and NONE of them touches 1200 Dues Receivable.
 * A deposit is the estate holding somebody's money, not somebody owing the
 * estate money — the mirror image of a charge, and the half most easily got
 * wrong. It appears in no dues statement and on no arrears report.
 *
 * AND IT HAS TO CLEAR (M4 §4, defect 2). "held" and "awaiting" used to be left
 * only when somebody remembered to press a button, and nothing ever completed a
 * booking at all — so a deposit on a Saturday long over, or on a booking the
 * household cancelled, sat on 2200 for good: money in the wrong place on the
 * books for ever. Every deposit now moves on, and only ever to one of three
 * places it never leaves (`AmenityBooking::DEPOSIT_TERMINAL`):
 *
 *     held      → refunded    the booking was cancelled inside its terms or
 *                             declined (at once), or it finished and nobody made
 *                             a claim within `CLAIM_WINDOW_DAYS` (`settle()`)
 *     held      → forfeited   by staff, in whole or in part, with a reason —
 *                             never by the machine
 *     awaiting  → none        the booking is over and the money never came, so
 *                             nothing is awaited; no entry, as none was posted
 *
 * Each move is made once, under a row lock, posts through `Ledger::post` exactly
 * as above, and is written to the audit log.
 *
 * // ASSUMPTION Q-008 — one arrears threshold across the estate, not two. Ruled;
 * // `mayBook()` reads the gate's own settings and a payment plan lifts both.
 * // ASSUMPTION Q-009 — the deposit entries above. Ruled: a deposit is a
 * // liability, it posts, and it never reaches receivables. Who may make each
 * // entry is ruled too (D-086): Facilities update takes and refunds one and
 * // Facilities approve forfeits it, from the booking detail.
 */
class Amenities
{
    /** The income account an amenity fee credits — board 25's chart. */
    public const FEE_ACCOUNT = '4100';

    /**
     * Where a deposit sits while the estate is holding it.
     *
     * A LIABILITY, NOT INCOME, AND NOT A RECEIVABLE. The estate is holding
     * somebody else's money and owes it back; it becomes income only if it is
     * forfeited. 2200 Resident Deposits Held has been in the chart since board
     * 25 was transcribed and nothing posted to it until the client ruled on
     * Q-009 — which is why the account read zero while three bookings on board
     * 19 said "held".
     */
    public const DEPOSIT_ACCOUNT = '2200';

    /** Where the cash actually lands and leaves from. */
    public const BANK_ACCOUNT = '1000';

    /** Board 35's charge type for one of these. */
    public const CHARGE_TYPE = 'amenity';

    /**
     * How long after a booking ends the estate has to keep any of its deposit.
     *
     * THREE DAYS, WHICH IS BOARD 19'S OWN FIGURE: its one refunded row was used on
     * Aug 15 and reads "$5,000 refunded Aug 18". Long enough for somebody to walk
     * the Gazebo on Monday after a Saturday party; short enough that a household
     * is not left wondering. Inside it the deposit stays held and Facilities
     * approve may forfeit all or part of it; after it, with no claim made,
     * `settle()` gives it back.
     */
    public const CLAIM_WINDOW_DAYS = 3;

    /**
     * The most a booking fee or a deposit on the rate card may be, in minor units:
     * J$99,999,999.99, the same order as the J$99,999,999 the forfeit on the
     * booking detail is already capped at. Far past any amenity a household
     * books, and far inside what the columns and a PHP integer hold, so a
     * mistyped figure is refused in words instead of overflowing (B045).
     */
    public const MAX_TERM_MINOR = 99_999_999_99;

    /** Resolved on first use — see `mayBook()` for why it is not a constructor edge. */
    private ?Collections $collections = null;

    public function __construct(
        private readonly Dues $dues,
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /* ------------------------------------------------------------------ */
    /* the estate's own rule */
    /* ------------------------------------------------------------------ */

    /**
     * Whether this unit may book anything at all.
     *
     * A BOOLEAN, AND DELIBERATELY NOTHING ELSE. See the class docblock: the
     * caller is a facilities screen, and a facilities screen may not learn a
     * household's financial position — not the balance, not the bucket, not the
     * number of days. D-010 is a platform invariant rather than an estate
     * setting, and this signature is where it is kept.
     *
     * ONE ARREARS THRESHOLD ACROSS THE ESTATE, NOT TWO — the client's ruling on
     * Q-008. This used to read its own `amenity_arrears_block_enabled` and
     * `amenity_arrears_block_days`, defaulting to off, so an estate could have
     * ended up admitting a household's visitors at the gate on Friday and
     * refusing the household itself the Club House on Saturday. A household is
     * either in arrears or it is not. Both figures now come from the same
     * `arrears_restriction_*` settings the gate uses, and moving one moves both.
     *
     * AND A PAYMENT PLAN LIFTS IT, SAME AS THE GATE. A household that has agreed
     * a schedule and is meeting it is not a household to turn away from a
     * birthday party — the arrears were given a schedule, not forgiven, and
     * board 7's whole purpose is that keeping to one restores normal life.
     * `Collections::isProtected()` is the same check `RestrictionPolicy` makes,
     * called rather than reimplemented, so the two answers cannot drift.
     */
    public function mayBook(Unit $unit, ?Carbon $asAt = null): bool
    {
        $settings = EstateSetting::current();

        if (! $settings->arrears_restriction_enabled) {
            return true;
        }

        if ($this->dues->balanceOf($unit)->getMinorAmount()->toInt() <= 0) {
            return true;
        }

        $today = $asAt?->copy() ?? SiteTime::date();
        $oldest = $this->dues->oldestOpenChargeDate($unit, $today);

        /*
         * A balance with no open charge behind it is a unit that owes something
         * not yet due — a charge raised for next month. Owing money the estate
         * has not asked for yet is not arrears, and blocking on it would turn a
         * bill into a punishment before its own due date.
         */
        if ($oldest === null) {
            return true;
        }

        if ((int) $oldest->diffInDays($today, absolute: false) < $settings->arrears_restriction_days) {
            return true;
        }

        /*
         * Resolved here rather than in the constructor, the way
         * `RestrictionPolicy` does it: collections reads the dues ledger and
         * this class is constructed from a facilities route, and a constructor
         * edge between the two is a cycle waiting for somebody to add one more
         * dependency.
         */
        $this->collections ??= app(Collections::class);

        return $this->collections->isProtected($unit);
    }

    /* ------------------------------------------------------------------ */
    /* writing */
    /* ------------------------------------------------------------------ */

    /**
     * Take a booking request. It starts PENDING and holds the diary.
     *
     * Pending rather than confirmed, because board 19 draws an approve/reject
     * pair against a pending row: somebody decides. It still occupies the slot
     * while it waits, or two households would each be told the Club House was
     * theirs on the same Saturday.
     */
    public function book(
        Amenity $amenity,
        Unit $unit,
        string $residentName,
        Carbon|string $startsAt,
        Carbon|string $endsAt,
        ?int $guests = null,
        ?string $notes = null,
        ?int $residentId = null,
    ): AmenityBooking {
        // ONE TIME RULE (M4 §3.1): the office types a time on the estate's clock; a phone sends a
        // moment with its offset. Either way this is the UTC moment it means.
        $from = SiteTime::parse($startsAt);
        $to = SiteTime::parse($endsAt);

        if ($to->lessThanOrEqualTo($from)) {
            throw new DomainException(
                'A booking has to end after it starts. A slot of no length is not a reservation and '.
                'would sit invisibly across everybody else\'s.'
            );
        }

        if (! $amenity->is_active || ! $amenity->is_bookable) {
            throw new DomainException(sprintf(
                'The %s is not open for booking. An amenity is retired rather than deleted, so what was '.
                'booked while it was open still reads correctly.',
                $amenity->name,
            ));
        }

        if (! $amenity->isWithinHours($from, $to)) {
            throw new DomainException(sprintf(
                'The %s is open %s. A booking outside those hours is one nobody will be there to open '.
                'the gate for.',
                $amenity->name,
                $amenity->hoursLabel().' '.SiteTime::clockTag(SiteTime::zone()),
            ));
        }

        if ($guests !== null && $guests > $amenity->capacity) {
            throw new DomainException(sprintf(
                'The %s holds %d guests and this booking is for %d. Capacity is a safety limit before '.
                'it is a rule, and an estate that overrides it once has no answer the day it matters.',
                $amenity->name,
                $amenity->capacity,
                $guests,
            ));
        }

        $this->guardAvailability($amenity, $from, $to);

        if (! $this->mayBook($unit)) {
            /*
             * NO FIGURE, NO DATE, NO BUCKET. The refusal reaches a facilities
             * screen and, through it, possibly a resident. It says what the rule
             * is and where to go, and it says nothing about how much or how long
             * — the Property Manager reading it holds no dues access, and a
             * message naming an amount would be the invariant leaking through an
             * error string.
             */
            throw new DomainException(sprintf(
                'Bookings are closed to %s while the household is in arrears. The dues office can settle '.
                'it, or agree a payment plan; a plan being met lifts this the same way it lifts the gate.',
                $unit->reference,
            ));
        }

        return DB::connection('tenant')->transaction(function () use (
            $amenity, $unit, $residentName, $from, $to, $guests, $notes, $residentId
        ): AmenityBooking {
            $deposit = (int) ($amenity->deposit_minor ?? 0);

            return AmenityBooking::create([
                'reference' => $this->nextReference($from),
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'resident_name' => $residentName,
                'resident_id' => $residentId,
                'starts_at' => $from,
                'ends_at' => $to,
                'guests' => $guests,
                'status' => AmenityBooking::PENDING,

                /*
                 * THE SNAPSHOT. Copied here and never re-read from the amenity,
                 * so board 20's rate card can be edited tomorrow without moving
                 * a single figure on board 19. An amenity priced by the hour
                 * (M47 §8) is snapshotted as the rate times these hours.
                 */
                'fee_minor' => $amenity->feeMinorFor($from, $to),
                'deposit_minor' => $deposit,
                'currency' => $amenity->currency,
                'cancellation_hours' => $amenity->cancellation_hours,

                // Quoted and not received. It must not reach the deposits-held
                // account until somebody actually pays it.
                'deposit_state' => $deposit > 0
                    ? AmenityBooking::DEPOSIT_AWAITING
                    : AmenityBooking::DEPOSIT_NONE,

                'notes' => $notes,
            ]);
        });
    }

    /** Approve a pending booking — board 19's green tick. */
    public function approve(AmenityBooking $booking, ?User $by = null, Carbon|string|null $on = null): AmenityBooking
    {
        if ($booking->status !== AmenityBooking::PENDING) {
            throw new DomainException(sprintf(
                'Booking %s is %s and is not waiting on a decision. Approving it again would restate a '.
                'date the household has already been told about.',
                $booking->reference,
                $booking->status,
            ));
        }

        $booking->forceFill([
            'status' => AmenityBooking::CONFIRMED,
            'approved_by' => $by?->getKey(),
            'approved_by_name' => $by?->name,
            'approved_at' => $on === null ? now() : $this->asMoment($on),
        ])->save();

        return $booking;
    }

    /**
     * THE OFFICE APPROVES A REQUEST, AND ITS FEE IS CHARGED THEN (M53 §2, Hassam's Sunday order: "charged only on
     * approval"). Confirmed and charged in one transaction, so a request is never confirmed and left uncharged, nor
     * charged and left waiting; a nil-fee amenity charges nothing. The pay-in-app flow (`AmenityCheckout`) confirms
     * through `approve()` and charges itself, so this is the console's door.
     */
    public function approveRequest(AmenityBooking $booking, User $by): AmenityBooking
    {
        return DB::connection('tenant')->transaction(function () use ($booking, $by): AmenityBooking {
            $locked = AmenityBooking::query()->with(['amenity', 'unit'])->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            $this->approve($locked, $by);

            if ($locked->fee_minor > 0 && ! $locked->isCharged()) {
                $this->recordFee($locked, $by);
            }

            $booking->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Decline it — board 19's red cross.
     *
     * A REASON IS REQUIRED. A household told only "declined" has nothing to act
     * on and will ask a guard at a gate about it, which is the one place the
     * answer cannot be given.
     */
    public function decline(AmenityBooking $booking, string $reason, ?User $by = null): AmenityBooking
    {
        if (trim($reason) === '') {
            throw new DomainException(
                'Say why the booking is declined. "Declined" on its own tells the household nothing '.
                'they can do anything about.'
            );
        }

        if ($booking->status !== AmenityBooking::PENDING) {
            throw new DomainException(sprintf(
                'Booking %s is %s. A confirmed booking is cancelled rather than declined, and the two '.
                'are different things to have on a record.',
                $booking->reference,
                $booking->status,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($booking, $reason, $by): AmenityBooking {
            $booking->forceFill([
                'status' => AmenityBooking::DECLINED,
                'declined_reason' => trim($reason),
                'declined_at' => now(),
                'approved_by' => $by?->getKey(),
                'approved_by_name' => $by?->name,
            ])->save();

            // A deposit may be taken while a booking still waits on its decision.
            // The estate has now said no, so there is no event for it to secure:
            // it goes back in the same breath, or the refusal would leave the
            // estate holding the money of a household it turned away.
            $this->moveDepositOn($booking, 'the estate declined the booking', by: $by);
            $this->closeGatePass($booking);

            return $booking;
        });
    }

    /**
     * A request nobody decided before its time came is closed (M53 §2), by the morning run: `expired`, never
     * `declined`, because nobody declined it. Its deposit is the morning run's next step, which moves it on as a
     * declined one's and says so in its report. Asked again under the row's lock, so a decision made a moment before
     * is never overwritten.
     */
    public function expire(AmenityBooking $booking): AmenityBooking
    {
        return DB::connection('tenant')->transaction(function () use ($booking): AmenityBooking {
            $locked = AmenityBooking::query()->with('amenity')->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== AmenityBooking::PENDING) {
                throw new DomainException(sprintf('Booking %s is %s, so there is no request left to expire.', $locked->reference, $locked->status));
            }

            $locked->forceFill(['status' => AmenityBooking::EXPIRED])->save();
            $this->closeGatePass($locked);

            return $locked;
        });
    }

    /**
     * The household cancels its own booking — the Resident App (13 D3).
     *
     * INSIDE THE TERMS SNAPSHOTTED ONTO THE BOOKING. `cancellation_hours` was
     * copied when the booking was taken, so an estate that tightens its policy
     * tomorrow does not change what this household agreed to. Inside that window
     * the app refuses and the office decides — a late cancellation is a
     * conversation about a deposit, not a button.
     *
     * A CANCELLATION THIS METHOD ACCEPTS IS ONE INSIDE THE TERMS, SO THE DEPOSIT
     * GOES BACK WITH IT (M4 §4, defect 2). This used to move no money — "a
     * deposit already held stays held" — and nothing else ever moved it either,
     * so the estate went on owing a household for a party that was never going to
     * happen. There is nothing left to decide: the late cancellation, the one
     * that IS a conversation about a deposit, is refused above and never reaches
     * the last lines. A held deposit is refunded (Dr 2200, Cr 1000); one quoted
     * and never paid simply stops being awaited.
     */
    public function cancel(AmenityBooking $booking, ?Carbon $at = null): AmenityBooking
    {
        $now = $at?->copy() ?? Carbon::now();

        if (! in_array($booking->status, [AmenityBooking::PENDING, AmenityBooking::CONFIRMED], true)) {
            throw new DomainException(sprintf('Booking %s is %s, so there is nothing to cancel.', $booking->reference, $booking->status));
        }

        if ($booking->starts_at->lessThanOrEqualTo($now)) {
            throw new DomainException(sprintf('Booking %s has already started. Speak to the estate office.', $booking->reference));
        }

        /*
         * THE DEADLINE IS A MOMENT, JUDGED AGAINST A MOMENT (M4 §3.1). `starts_at` is stored true
         * UTC now, so "N hours before it starts" is counted in elapsed hours on the UTC clock and
         * compared with the server's `now()`. It used to be Kingston's digits read as UTC, which
         * closed the window five hours before the household was told it would close.
         */
        if ($booking->cancellation_hours !== null && $booking->starts_at->copy()->utc()->subHours($booking->cancellation_hours)->lessThan($now)) {
            throw new DomainException(sprintf(
                'Bookings are cancelled at least %d hours ahead, and %s starts sooner than that. The estate office can still cancel it.',
                $booking->cancellation_hours,
                $booking->reference,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($booking, $now): AmenityBooking {
            $booking->forceFill(['status' => AmenityBooking::CANCELLED, 'cancelled_at' => $now])->save();

            $this->moveDepositOn($booking, 'the household cancelled the booking inside its terms');
            $this->closeGatePass($booking);

            return $booking;
        });
    }

    /**
     * THE PERSON WHO MADE IT DELETED THEIR ACCOUNT (M18H §4.3). A future booking is cancelled — whatever the terms' notice
     * window, because nobody is left to turn up — and a deposit the estate holds for it goes back the way every other
     * cancellation's does. "Do not silently keep the money." The estate sees it on the booking and in the audit entry.
     */
    public function cancelForDeletedAccount(AmenityBooking $booking): AmenityBooking
    {
        if (! in_array($booking->status, [AmenityBooking::PENDING, AmenityBooking::CONFIRMED], true)) {
            return $booking;
        }

        return DB::connection('tenant')->transaction(function () use ($booking): AmenityBooking {
            $booking->forceFill(['status' => AmenityBooking::CANCELLED, 'cancelled_at' => Carbon::now()])->save();

            $this->moveDepositOn($booking, 'the person who booked it deleted their account');
            $this->closeGatePass($booking);

            return $booking;
        });
    }

    /**
     * THE GUESTS' PASS GOES WITH THE BOOKING (M47 §10.3). A booking paid for in the app carries a pass for its hours, so
     * its guests are let in on the QR; cancelled or declined, the event is not happening, and the pass is cancelled with
     * it — refused at every gate at once, as any cancelled pass is.
     */
    private function closeGatePass(AmenityBooking $booking): void
    {
        if ($booking->gate_pass_id === null) {
            return;
        }

        $pass = VisitorPass::query()->where('pass_id', $booking->gate_pass_id)->first();

        if ($pass !== null && $pass->status === VisitorPass::ACTIVE) {
            app(VisitorPasses::class)->cancel($pass);
        }
    }

    /**
     * Take a period out of an amenity's diary.
     *
     * The estate's own decision — resurfacing, a private function, a closure —
     * and it refuses to close over a booking that already exists. A block that
     * silently overlapped a confirmed booking would leave a household turning up
     * to a locked gate with a confirmation in their hand.
     */
    public function blockSlot(
        Amenity $amenity,
        Carbon|string $startsAt,
        Carbon|string $endsAt,
        ?string $reason = null,
        ?User $by = null,
        string $kind = AmenitySlot::BLOCKED,
    ): AmenitySlot {
        // Typed on the estate's clock, kept as the UTC moment it means (M4 §3.1).
        $from = SiteTime::parse($startsAt);
        $to = SiteTime::parse($endsAt);

        if ($to->lessThanOrEqualTo($from)) {
            throw new DomainException('A blocked period has to end after it starts.');
        }

        $clash = $amenity->bookings()
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->first();

        if ($clash !== null) {
            throw new DomainException(sprintf(
                'Booking %s already covers part of that period on the %s. Decline or cancel it first, '.
                'so the household is told rather than finding out at the gate.',
                $clash->reference,
                $amenity->name,
            ));
        }

        return AmenitySlot::create([
            'amenity_id' => $amenity->id,
            'kind' => $kind,
            'starts_at' => $from,
            'ends_at' => $to,
            'reason' => $reason,
            'created_by' => $by?->getKey(),
            'created_by_name' => $by?->name,
        ]);
    }

    /**
     * Charge the booking fee to the unit. POSTED THROUGH `Dues::charge`.
     *
     * IT IS A CHARGE ON A UNIT LIKE ANY OTHER, and it is not recorded a second
     * way. The entry is the money — Dr 1200, Cr 4100 — and the booking keeps
     * only the charge's id, so the figure on the resident's statement and the
     * figure behind their booking are the same row. Storing the amount here as
     * well would give the two permission to disagree.
     *
     * IT CAN HAPPEN ONCE. `fee_charge_id` is unique at the database and refused
     * here, because a household billed twice for one Saturday has to be refunded
     * through a credit note that nobody would have raised.
     *
     * A NIL-FEE AMENITY RAISES NOTHING AT ALL. Board 20's accounting note is
     * explicit: a Pool Deck booking must "produce a booking with zero journal
     * lines rather than a zero-amount entry". A zero-value entry is a row in the
     * ledger claiming something happened.
     */
    public function recordFee(
        AmenityBooking $booking,
        ?User $by = null,
        Carbon|string|null $dueOn = null,
    ): Charge {
        if ($booking->isCharged()) {
            throw new DomainException(sprintf(
                'Booking %s was already charged on charge %s. Billing one Saturday twice is a refund '.
                'the estate would have to notice before it could make it.',
                $booking->reference,
                (string) ($booking->feeCharge->reference ?? $booking->fee_charge_id),
            ));
        }

        if ($booking->fee_minor <= 0) {
            throw new DomainException(sprintf(
                'The %s carries no booking fee, so there is nothing to charge. An entry for nothing is '.
                'a line in the ledger saying something happened.',
                $booking->amenity->name,
            ));
        }

        if ($booking->status === AmenityBooking::DECLINED || $booking->status === AmenityBooking::CANCELLED) {
            throw new DomainException(sprintf(
                'Booking %s is %s. Charging a household for a booking the estate refused is a debt they '.
                'never agreed to.',
                $booking->reference,
                $booking->status,
            ));
        }

        /*
         * ONLY ONCE THE ESTATE HAS SAID YES (B052). The two refusals above let a
         * PENDING booking through, and a pending booking is still a question: the
         * fee was posted to the unit, the estate then declined — which moves the
         * deposit on and never touches the charge — and the household was left
         * billed for a Saturday it was turned down for. Board 19 draws the button
         * on confirmed and completed rows only; this is that rule where a request
         * that did not come from the button meets it too. Named as the statuses
         * that MAY be charged, so a status added later is refused until somebody
         * decides it can be.
         */
        if ($booking->status === AmenityBooking::PENDING) {
            throw new DomainException(sprintf(
                'Booking %s is still waiting on the estate\'s decision. Its fee is charged once the booking '.
                'is approved: charged now, a decline would leave the household billed for a booking the '.
                'estate turned down.',
                $booking->reference,
            ));
        }

        if (! in_array($booking->status, [AmenityBooking::CONFIRMED, AmenityBooking::COMPLETED], true)) {
            throw new DomainException(sprintf(
                'Booking %s is %s. A booking fee is charged only on a booking the estate has approved.',
                $booking->reference,
                $booking->status,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($booking, $by, $dueOn): Charge {
            $charge = $this->dues->charge(
                unit: $booking->unit,
                amount: Money::ofMinor($booking->fee_minor, $booking->currency),
                // What the household's ledger and statement say for the fee: the console's middot, never an em dash (Law 3).
                description: $booking->amenity->name.' booking · '.$booking->starts_at->format('M j, Y'),
                dueOn: $dueOn ?? SiteTime::date(),
                type: self::CHARGE_TYPE,
                account: self::FEE_ACCOUNT,
                by: $by,
            );

            $booking->forceFill(['fee_charge_id' => $charge->id])->save();

            return $charge;
        });
    }

    /**
     * The deposit has been paid in — Dr 1000 Bank, Cr 2200 Deposits Held.
     *
     * A POSTING, AND IT DID NOT USED TO BE. This recorded a state and raised no
     * entry, on the reasoning that moving cash belongs to whoever holds
     * `payments` and a facilities route holds neither that nor
     * `accounting_posting`. The client overruled it on Q-009: the money is in
     * the bank, so the ledger has to say so.
     *
     * WHO MAY MAKE IT IS RULED TOO (D-086). The route is on the booking detail
     * behind `estate.facilities.update`, beside the refund; the forfeit takes
     * `estate.facilities.approve`. No accounting gate sits beside either: the
     * ruling made the booking detail the Property Manager's screen, and D-010
     * locks that role out of Accounting. `EstateFacilitiesAmenitiesTest` pins
     * all three routes to exactly those gates.
     *
     * TAKEN ONCE, FROM AWAITING, AND ONLY FOR A BOOKING THAT MAY STILL HAPPEN.
     * With a route on it this is reachable by a person rather than only by the
     * seeder, so it refuses what a person could get wrong: a second hold; a
     * hold after the deposit was refunded or forfeited, either of which would
     * post money to the bank that the estate no longer has; and a hold on a
     * booking the estate declined or the household cancelled.
     */
    public function holdDeposit(
        AmenityBooking $booking,
        Carbon|string|null $on = null,
        ?User $by = null,
        bool $demonstration = false,
    ): AmenityBooking {
        if ($booking->deposit_minor <= 0) {
            throw new DomainException(sprintf(
                'Booking %s carries no deposit. Recording one held would put a liability on the estate '.
                'for money nobody was asked for.',
                $booking->reference,
            ));
        }

        if ($booking->deposit_state === AmenityBooking::DEPOSIT_HELD) {
            throw new DomainException(sprintf(
                'Booking %s is already recorded as held. Taking it twice would post the deposit to the '.
                'bank twice and leave the estate owing back money it never received.',
                $booking->reference,
            ));
        }

        if (in_array($booking->status, [AmenityBooking::DECLINED, AmenityBooking::CANCELLED, AmenityBooking::EXPIRED], true)) {
            throw new DomainException(sprintf(
                'Booking %s was %s, so there is no event for a deposit to secure. Recording one held '.
                'would leave the estate owing money on a booking that will never happen.',
                $booking->reference,
                $booking->status,
            ));
        }

        if ($booking->deposit_state !== AmenityBooking::DEPOSIT_AWAITING) {
            throw new DomainException(sprintf(
                'The deposit on booking %s has been %s. A deposit is taken once: taking it again would '.
                'post money to the bank that the estate has already returned or kept.',
                $booking->reference,
                $booking->deposit_state,
            ));
        }

        // A test card's deposit (M12 §2.6; D-179) is held like any other, against 1090 — no money came in.
        // The console's middot, never an em dash (Law 3): a memo is read on the account, the journal and the booking.
        $memo = ($demonstration ? 'DEMONSTRATION deposit held (no money) · ' : 'Deposit held · ').$booking->reference;
        $cash = $demonstration ? DemoCardPayments::ACCOUNT : self::BANK_ACCOUNT;

        return DB::connection('tenant')->transaction(function () use ($booking, $memo, $by, $on, $cash, $demonstration): AmenityBooking {
            $this->assertStillIn($booking, AmenityBooking::DEPOSIT_AWAITING, 'taken');

            /*
             * CASH MOVED, SO THE LEDGER SAYS SO. This was recorded as a state
             * and nothing else until the client overruled it on Q-009: a
             * deposit received is real money in the operating account and a
             * real liability against it, and a booking row saying "held" over a
             * ledger that had never heard of it is the estate's own books
             * disagreeing with its own diary.
             */
            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit($cash, $booking->deposit_minor, $memo),
                    Posting::credit(self::DEPOSIT_ACCOUNT, $booking->deposit_minor, $memo),
                ],
                on: $on === null ? SiteTime::date() : $this->asMoment($on),
                source: Ledger::SOURCE_DEPOSIT_HELD,
                sourceId: $booking->id,
                by: $by,
            );

            $booking->forceFill([
                'deposit_state' => AmenityBooking::DEPOSIT_HELD,
                'deposit_refunded_on' => null,
                'deposit_journal_ref' => $entry->reference,
                'deposit_demonstration' => $demonstration,
            ])->save();

            $this->auditDeposit('held', $booking, AmenityBooking::DEPOSIT_AWAITING, ['journal' => $entry->reference]);

            return $booking;
        });
    }

    /**
     * The deposit has gone back — board 19's "$5,000 refunded Aug 18".
     *
     * Dr 2200, Cr 1000: the liability is discharged and the cash leaves. The
     * mirror of taking it, and it never reaches income — a deposit returned was
     * never the estate's to earn.
     *
     * THE ONE RELEASE, WHOEVER ASKS FOR IT. The office pressing "Refund deposit",
     * a household cancelling inside its terms, the estate declining, and the
     * morning run finding a finished booking nobody claimed against all end here,
     * so there is one entry shape and one refusal. `$why` is the difference
     * between them: it goes into the memo an accountant reads and into the audit
     * log, and is null only when a person at the deposit door simply did it.
     *
     * ONCE. The state is read again under a row lock inside the transaction, so a
     * press and the morning run arriving together post one refund, not two —
     * the second is refused exactly as a second press always was.
     */
    public function refundDeposit(
        AmenityBooking $booking,
        Carbon|string|null $on = null,
        ?User $by = null,
        ?string $why = null,
    ): AmenityBooking {
        if ($booking->deposit_state !== AmenityBooking::DEPOSIT_HELD) {
            throw new DomainException(sprintf(
                'Booking %s holds no deposit to refund: it is %s. Refunding money the estate never '.
                'received would take it out of the operating account.',
                $booking->reference,
                $booking->deposit_state,
            ));
        }

        $refundedOn = $on === null ? SiteTime::date() : $this->asMoment($on);
        $memo = 'Deposit refunded · '.$booking->reference.($why === null ? '' : ' · '.$why);

        return DB::connection('tenant')->transaction(function () use ($booking, $memo, $refundedOn, $by, $why): AmenityBooking {
            $this->assertStillIn($booking, AmenityBooking::DEPOSIT_HELD, 'refunded');

            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit(self::DEPOSIT_ACCOUNT, $booking->deposit_minor, $memo),
                    // A demonstration deposit goes back to 1090, where it came from — never out of the bank.
                    Posting::credit($this->cashAccountOf($booking), $booking->deposit_minor, $memo),
                ],
                on: $refundedOn,
                source: Ledger::SOURCE_DEPOSIT_REFUND,
                sourceId: $booking->id,
                by: $by,
            );

            $booking->forceFill([
                'deposit_state' => AmenityBooking::DEPOSIT_REFUNDED,
                'deposit_refunded_on' => $refundedOn->toDateString(),
                'deposit_journal_ref' => $entry->reference,
            ])->save();

            $this->auditDeposit('refunded', $booking, AmenityBooking::DEPOSIT_HELD, [
                'journal' => $entry->reference,
                'refunded_minor' => $booking->deposit_minor,
                'why' => $why ?? 'refunded at the deposit door',
            ]);

            return $booking;
        });
    }

    /**
     * The estate is keeping it — damage, or the amenity was not handed back.
     *
     * Dr 2200, Cr 4100: THE ONLY PATH ON WHICH A DEPOSIT EVER BECOMES INCOME.
     * The liability is discharged because the estate no longer owes it back, and
     * the same figure lands in Amenity Booking Fees. No cash moves — it was
     * already in the bank from the day it was taken — which is why the bank
     * account is absent from this entry and present in the other two.
     *
     * A REASON IS REQUIRED. Keeping a resident's J$5,000 is the one deposit act
     * somebody will be asked to justify, and "forfeited" with nothing after it
     * is not an answer a committee can give them.
     *
     * IN WHOLE OR IN PART (M4 §4, defect 2). Two broken chairs do not cost a whole
     * deposit, and an estate that could only keep all of it or none of it would
     * either overcharge the household or write the damage off. `$keepMinor` is
     * what the estate keeps; null keeps it all, as before. A part is ONE balanced
     * entry, because it is one decision:
     *
     *     Dr 2200 Deposits Held   the whole deposit — the liability is over
     *     Cr 4100 Booking Fees    what the estate keeps — the only income
     *     Cr 1000 Bank            what goes back to the household
     *
     * The state is `forfeited` either way and `deposit_forfeited_minor` says how
     * much. Keeping nothing is not a forfeit — that is a refund, and is refused
     * here so it is posted as one.
     *
     * A PERSON, NEVER THE MACHINE. Nothing scheduled calls this.
     */
    public function forfeitDeposit(
        AmenityBooking $booking,
        string $reason,
        Carbon|string|null $on = null,
        ?User $by = null,
        ?int $keepMinor = null,
    ): AmenityBooking {
        if ($booking->deposit_state !== AmenityBooking::DEPOSIT_HELD) {
            throw new DomainException(sprintf(
                'Booking %s holds no deposit to forfeit: it is %s. The estate cannot keep money it is '.
                'not holding.',
                $booking->reference,
                $booking->deposit_state,
            ));
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'Say why the deposit is being kept. Forfeiting is the one deposit act a resident will '.
                'ask the estate to justify, and a blank reason is not an answer.'
            );
        }

        $kept = $keepMinor ?? $booking->deposit_minor;

        if ($kept <= 0 || $kept > $booking->deposit_minor) {
            throw new DomainException(sprintf(
                'The estate is holding %s on booking %s, so it can keep any part of that and no more. '.
                'Keeping none of it is a refund: use Refund deposit, so the books say so.',
                AmenityBooking::amount($booking->deposit_minor),
                $booking->reference,
            ));
        }

        $returned = $booking->deposit_minor - $kept;
        $forfeitedOn = $on === null ? SiteTime::date() : $this->asMoment($on);

        // `journals.memo` holds 200 characters and a reason may run longer. The
        // reason in full is on the booking; the entry carries as much as fits.
        $memo = mb_substr('Deposit forfeited · '.$booking->reference.' · '.$reason, 0, 200);

        return DB::connection('tenant')->transaction(function () use ($booking, $memo, $reason, $forfeitedOn, $by, $kept, $returned): AmenityBooking {
            $this->assertStillIn($booking, AmenityBooking::DEPOSIT_HELD, 'forfeited');

            $postings = [
                Posting::debit(self::DEPOSIT_ACCOUNT, $booking->deposit_minor, $memo),
                Posting::credit(self::FEE_ACCOUNT, $kept, $memo),
            ];

            // Only where something goes back: a whole forfeit moves no cash, and
            // a zero line is a row in the ledger claiming something happened.
            if ($returned > 0) {
                $postings[] = Posting::credit($this->cashAccountOf($booking), $returned, $memo);
            }

            $entry = $this->ledger->post(
                memo: $memo,
                postings: $postings,
                on: $forfeitedOn,
                source: Ledger::SOURCE_DEPOSIT_FORFEIT,
                sourceId: $booking->id,
                by: $by,
            );

            $booking->forceFill([
                'deposit_state' => AmenityBooking::DEPOSIT_FORFEITED,
                'deposit_forfeit_reason' => $reason,
                'deposit_forfeited_minor' => $kept,
                'deposit_refunded_on' => $returned > 0 ? $forfeitedOn->toDateString() : null,
                'deposit_journal_ref' => $entry->reference,
            ])->save();

            $this->auditDeposit('forfeited', $booking, AmenityBooking::DEPOSIT_HELD, [
                'journal' => $entry->reference,
                'kept_minor' => $kept,
                'refunded_minor' => $returned,
                'reason' => $reason,
            ]);

            return $booking;
        });
    }

    /** Where a held deposit's money is: the bank, or — for a test card's (D-179) — 1090, where no money is. */
    private function cashAccountOf(AmenityBooking $booking): string
    {
        return (bool) $booking->deposit_demonstration ? DemoCardPayments::ACCOUNT : self::BANK_ACCOUNT;
    }

    /**
     * Move every deposit on that has nothing left to wait for — the morning run.
     *
     * `php artisan amenities:settle`, scheduled daily in `routes/console.php`.
     * A deposit used to leave "held" only when somebody remembered to press a
     * button, and no booking ever completed at all, so 2200 Resident Deposits
     * Held carried money for parties long over (M4 §4, defect 2). Three things
     * happen here, and only the second posts anything:
     *
     *   COMPLETED   a confirmed booking whose time has ended is completed —
     *               status only. Its deposit stays held for the claim window.
     *   RELEASED    a held deposit is refunded (Dr 2200, Cr 1000) once there is
     *               nothing left it could secure: the booking was cancelled or
     *               declined — `cancel()` and `decline()` do this at once, so
     *               here it only catches what predates them — or it ended
     *               `CLAIM_WINDOW_DAYS` ago and nobody forfeited any of it.
     *   CLEARED     a deposit still "awaiting" on a booking that is over stops
     *               being awaited. No entry: none was ever posted for it.
     *
     * IT NEVER FORFEITS. Keeping a household's money is a person's decision with
     * a reason behind `estate.facilities.approve`; all a machine may do alone is
     * give money back.
     *
     * SAFE TO RUN AGAIN, AND ONE BOOKING FAILING DOES NOT STOP THE REST. Every
     * move re-reads its row under a lock and happens once; a deposit already
     * moved on is not selected the next time. A refusal is reported under
     * `failed` and the run carries on.
     *
     *   EXPIRED     a request still waiting on the estate when its time comes is closed (M53 §2), and its deposit
     *               moves on with the rest.
     *
     * @return array{completed: list<string>, released: list<string>, cleared: list<string>, failed: list<string>, expired: list<string>}
     */
    public function settle(?Carbon $asAt = null, bool $dryRun = false): array
    {
        // Bound to the queries as UTC, which is what the columns hold.
        $now = ($asAt?->copy() ?? Carbon::now())->utc();
        $report = ['completed' => [], 'released' => [], 'cleared' => [], 'failed' => [], 'expired' => []];

        /*
         * EXPIRED (M53 §2): a request still undecided when its time comes. It leaves the queue of requests waiting
         * and no longer holds its hours, and its deposit moves on below like a declined one's. First, so the deposit
         * step sees it closed.
         */
        $lapsed = AmenityBooking::query()
            ->with('amenity')
            ->where('status', AmenityBooking::PENDING)
            ->where('starts_at', '<=', $now)
            ->orderBy('id')
            ->get();

        foreach ($lapsed as $booking) {
            try {
                if (! $dryRun) {
                    $this->expire($booking);
                }

                $report['expired'][] = $this->settleLine($booking, 'not decided before '.SiteTime::fmt($booking->starts_at, 'M j, g:i A'));
            } catch (Throwable $failure) {
                $report['failed'][] = $this->settleLine($booking, $failure->getMessage());
            }
        }

        $finished = AmenityBooking::query()
            ->with('amenity')
            ->where('status', AmenityBooking::CONFIRMED)
            ->where('ends_at', '<=', $now)
            ->orderBy('id')
            ->get();

        foreach ($finished as $booking) {
            try {
                if (! $dryRun) {
                    $this->complete($booking);
                }

                $report['completed'][] = $this->settleLine($booking, 'ended '.SiteTime::fmt($booking->ends_at, 'M j, g:i A'));
            } catch (Throwable $failure) {
                $report['failed'][] = $this->settleLine($booking, $failure->getMessage());
            }
        }

        $open = AmenityBooking::query()
            ->with('amenity')
            ->whereIn('deposit_state', [AmenityBooking::DEPOSIT_HELD, AmenityBooking::DEPOSIT_AWAITING])
            ->where(static function ($over) use ($now): void {
                $over->whereIn('status', [AmenityBooking::CANCELLED, AmenityBooking::DECLINED, AmenityBooking::EXPIRED])
                    ->orWhere('ends_at', '<=', $now);
            })
            ->orderBy('id')
            ->get();

        foreach ($open as $booking) {
            $why = $this->whyItMovesOn($booking, $now);

            if ($why === null) {
                continue;
            }

            $kind = $booking->deposit_state === AmenityBooking::DEPOSIT_HELD ? 'released' : 'cleared';

            try {
                if (! $dryRun) {
                    $this->moveDepositOn($booking, $why);
                }

                $report[$kind][] = $this->settleLine($booking, AmenityBooking::amount($booking->deposit_minor).' · '.$why);
            } catch (Throwable $failure) {
                $report['failed'][] = $this->settleLine($booking, $failure->getMessage());
            }
        }

        return $report;
    }

    /**
     * Why this deposit has nothing left to wait for — or null while it still has.
     *
     * THE RULE, IN ONE PLACE, and it reads the booking and the clock and nothing
     * else. A booking cancelled or declined will never happen, so there is
     * nothing for a deposit to secure. A booking that ran keeps its deposit held
     * for the claim window and not a day longer.
     */
    private function whyItMovesOn(AmenityBooking $booking, Carbon $now): ?string
    {
        if (in_array($booking->deposit_state, AmenityBooking::DEPOSIT_TERMINAL, true)) {
            return null;
        }

        if ($booking->status === AmenityBooking::CANCELLED) {
            return 'the household cancelled the booking inside its terms';
        }

        if ($booking->status === AmenityBooking::DECLINED) {
            return 'the estate declined the booking';
        }

        if ($booking->status === AmenityBooking::EXPIRED) {
            return 'the request expired before the estate decided on it';
        }

        if ($booking->ends_at->greaterThan($now)) {
            return null;
        }

        if ($booking->deposit_state === AmenityBooking::DEPOSIT_AWAITING) {
            return 'the booking is over and the deposit was never taken';
        }

        if ($booking->ends_at->copy()->addDays(self::CLAIM_WINDOW_DAYS)->greaterThan($now)) {
            return null;
        }

        return sprintf('the booking ended and no claim was made within %d days', self::CLAIM_WINDOW_DAYS);
    }

    /**
     * Send a deposit to where it ends up now that its booking is over.
     *
     * Held money goes back through `refundDeposit` — the one release. A deposit
     * that was only ever quoted becomes `none`: the ledger never heard of it, so
     * there is nothing to post and nothing to reverse, and leaving it "awaiting"
     * told a household under a cancelled booking that it still owed the estate
     * money. A deposit already refunded, kept or cleared is left exactly as it
     * is, which is what makes every caller safe to repeat.
     */
    private function moveDepositOn(AmenityBooking $booking, string $why, ?User $by = null): void
    {
        if ($booking->deposit_state === AmenityBooking::DEPOSIT_HELD) {
            $this->refundDeposit($booking, null, $by, $why);

            return;
        }

        if ($booking->deposit_state !== AmenityBooking::DEPOSIT_AWAITING) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($booking, $why): void {
            $this->assertStillIn($booking, AmenityBooking::DEPOSIT_AWAITING, 'cleared');

            $booking->forceFill(['deposit_state' => AmenityBooking::DEPOSIT_NONE])->save();

            $this->auditDeposit('cleared', $booking, AmenityBooking::DEPOSIT_AWAITING, ['why' => $why]);
        });
    }

    /**
     * Refuse a move the database says has already been made.
     *
     * The caller checked the booking it was handed; this checks the ROW, locked
     * until the transaction ends, so two requests that both read "held" cannot
     * both post. The second one finds the first one's state and is refused in the
     * words a second press always was.
     */
    private function assertStillIn(AmenityBooking $booking, string $state, string $act): void
    {
        $current = AmenityBooking::query()->whereKey($booking->id)->lockForUpdate()->value('deposit_state');

        if ($current !== $state) {
            throw new DomainException(sprintf(
                'The deposit on booking %s is %s, so it cannot be %s. A deposit moves on once.',
                $booking->reference,
                (string) $current,
                $act,
            ));
        }
    }

    /**
     * Every deposit move, in the audit log — what it was, what it became, the
     * entry behind it and why. The actor is whoever is signed in; the morning
     * run and a household's own cancellation have none, and read as "System".
     *
     * @param  array<string, mixed>  $after
     */
    private function auditDeposit(string $move, AmenityBooking $booking, string $from, array $after = []): void
    {
        $this->audit->record(
            action: 'estate.amenity_deposit_'.$move,
            entityType: 'AmenityBooking',
            entityId: (string) $booking->id,
            before: ['deposit_state' => $from],
            after: [
                'reference' => $booking->reference,
                'deposit_state' => $booking->deposit_state,
                'deposit_minor' => $booking->deposit_minor,
                ...$after,
            ],
        );
    }

    /** "BKG-2026-09-0004 · Gazebo — …" for the morning run's report. */
    private function settleLine(AmenityBooking $booking, string $what): string
    {
        return $booking->reference.' · '.($booking->amenity->name ?? 'Amenity').' · '.$what;
    }

    /** The event has happened and the amenity was handed back. */
    public function complete(
        AmenityBooking $booking,
        Carbon|string|null $refundedOn = null,
        ?User $by = null,
    ): AmenityBooking {
        if ($booking->status !== AmenityBooking::CONFIRMED) {
            throw new DomainException(sprintf(
                'Booking %s is %s. Only a confirmed booking can complete: the others describe events '.
                'that never took place.',
                $booking->reference,
                $booking->status,
            ));
        }

        $booking->forceFill(['status' => AmenityBooking::COMPLETED])->save();

        if ($refundedOn !== null && $booking->deposit_state === AmenityBooking::DEPOSIT_HELD) {
            $this->refundDeposit($booking, $refundedOn, $by);
        }

        return $booking;
    }

    /* ------------------------------------------------------------------ */
    /* what the screens read */
    /* ------------------------------------------------------------------ */

    /**
     * Board 19 — the chips and the booking list.
     *
     * NOT ONE FIGURE HERE IS A RESIDENT'S BALANCE. The Deposit column is the
     * amount snapshotted onto the booking, which is a fact about the booking and
     * not about the household's account.
     *
     * THE WHOLE DIARY, A PAGE AT A TIME (M32 F5), ordered and paged in SQL; the chip filters it there too, and the
     * pending count is still the whole diary's.
     *
     * THE BOOKINGS WAITING ON THE OFFICE, ONE PRESS AWAY (AME-2). `$waiting` keeps only those waiting on a decision,
     * in SQL, beside the amenity chip if one is chosen; the amber count chip is the control that turns it on.
     *
     * @param  string  $amenity  an amenity name, or '' for every one of them
     * @return array<string, mixed>
     */
    public function bookingsBoard(string $amenity = '', ?Carbon $asAt = null, ?TableQuery $table = null, bool $waiting = false): array
    {
        // The ESTATE'S today began at its own midnight (M4 §3.1) — carried on the estate's clock, so
        // its date is the date a person would say, and bound to the query as the UTC moment it is.
        $today = SiteTime::local(SiteTime::dayBounds($asAt)[0]);
        $table ??= self::bookingsTable(asAt: $asAt);

        // `feeCharge` for the day a charged row says it was charged (M53 Law 1), read with the page, not per row.
        $query = AmenityBooking::query()->with(['amenity', 'unit', 'feeCharge']);

        if ($amenity !== '') {
            $query->whereHas('amenity', static fn ($named) => $named->where('name', $amenity));
        }

        if ($waiting) {
            $query->where('amenity_bookings.status', AmenityBooking::PENDING);
        }

        $table->order($query, 'amenity_bookings.id');
        $page = $table->paginate($query);

        $rows = [];
        $pending = AmenityBooking::query()->where('status', AmenityBooking::PENDING)->count();

        foreach ($page->getCollection() as $booking) {
            $rows[] = [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'amenity' => $booking->amenity->name,
                'icon' => $booking->amenity->icon_key,
                'booked_by' => $booking->bookedByLabel(),
                'unit' => $booking->unit->reference,
                'when' => $booking->whenLabel($today),
                'is_past' => $booking->starts_at->lessThan($today),
                'deposit' => $booking->depositLabel(),
                'deposit_minor' => $booking->deposit_minor,
                'deposit_state' => $booking->deposit_state,
                'fee_minor' => $booking->fee_minor,
                // The fee approving it will charge, written as money is written everywhere (M65 B10), for Approve's
                // question (DR-057); null when approving charges nothing.
                'fee_label' => $booking->fee_minor > 0 && ! $booking->isCharged()
                    ? MoneyFormatter::fromMinor((int) $booking->fee_minor, (string) $booking->currency)
                    : null,
                'is_charged' => $booking->isCharged(),
                // "Charged 28 Aug" where the Charge fee button would be: charging twice is wrong, so the row says it is done.
                'charged_label' => $booking->feeCharge?->created_at === null
                    ? null
                    : 'Charged '.SiteTime::local($booking->feeCharge->created_at)->format('j M'),
                'status' => $booking->status,
                'status_label' => AmenityBooking::STATUS_LABELS[$booking->status],

                // Board 19 derives the row's controls from the status: a pending
                // booking gets the approve/reject pair and every other status
                // gets a single link.
                'decidable' => $booking->status === AmenityBooking::PENDING,
            ];
        }

        return [
            // Generated from the bookable amenities, which is what board 19's
            // chip row is: the estate's own list rather than a fixed three.
            'amenities' => Amenity::query()
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->orderBy('sort_order')
                ->pluck('name')
                ->all(),
            'filter' => $amenity,
            'waiting' => $waiting,
            'pending' => $pending,
            'rows' => $rows,
            'table' => $table->meta($page),
        ];
    }

    /**
     * What board 19's diary orders by (M32 F5).
     *
     * WHEN IS THE DIARY'S OWN ORDER: upcoming first, soonest first, then the past — board 19's order and not a plain
     * date sort, because a diary is read forwards and what is coming is what somebody has to do something about.
     * Turned round, it is the same list read from the other end.
     *
     * ORDERED BY WHEN EACH BOOKING STARTS, and the reference is only the tiebreak. A reference is sequential within
     * the MONTH the booking falls in, so ordering by it orders same-month bookings by the order they were TAKEN in —
     * and a Saturday booked this morning would be drawn above the Wednesday before it. The tiebreak is still there so
     * that two bookings beginning at the same moment have one order rather than whichever the database returned.
     *
     * @return array<string, string|\Closure>
     */
    public static function bookingSorts(?Carbon $asAt = null): array
    {
        $today = SiteTime::local(SiteTime::dayBounds($asAt)[0])->copy()->utc()->toDateTimeString();

        return [
            'amenity' => static fn ($query, string $dir) => $query->orderBy(
                Amenity::query()->select('name')->whereColumn('amenities.id', 'amenity_bookings.amenity_id'),
                $dir,
            ),
            'booked_by' => 'amenity_bookings.resident_name',
            'when' => static fn ($query, string $dir) => $query
                ->orderByRaw('CASE WHEN amenity_bookings.starts_at < ? THEN 1 ELSE 0 END '.$dir, [$today])
                ->orderBy('amenity_bookings.starts_at', $dir)
                ->orderBy('amenity_bookings.reference', $dir),
            'deposit' => 'amenity_bookings.deposit_minor',
            // Waiting on a decision first, then what stands, then what did not happen.
            'status' => static fn ($query, string $dir) => $query->orderByRaw(
                "FIELD(amenity_bookings.status, 'pending', 'confirmed', 'completed', 'declined', 'cancelled') ".$dir,
            ),
        ];
    }

    /** Board 19's diary as the address asks for it (M32 F5). */
    public static function bookingsTable(?Request $request = null, ?Carbon $asAt = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::bookingSorts($asAt), 'when', 'asc');
    }

    /**
     * One booking and the deposit behind it — the booking detail.
     *
     * NOT ON AN APPROVED BOARD (D-086). The client ruled that the deposit door
     * goes here: board 19 draws the state and no control, and a service that
     * moved money with no route was "a worse gap than the caution it replaced".
     *
     * THE DEPOSIT'S STORY IS THREE STEPS AND THE LEDGER TELLS IT. Quoted when
     * the booking was made; received and held; then refunded or forfeited. The
     * second and third are read off the JOURNALS this class posted rather than
     * off the booking's state column, so each step carries the entry reference
     * and the day it was posted — the same entries `gate:ledger` proves balance
     * — and a state that disagreed with the ledger would show as a step with no
     * entry behind it rather than as a tidy story.
     *
     * NOT ONE FIGURE HERE IS A HOUSEHOLD'S FINANCIAL POSITION, exactly as on
     * board 19: the fee and the deposit are the terms snapshotted onto the
     * booking, which are facts about the booking and not about the account.
     *
     * @return array{booking: array<string, mixed>, deposit: array<string, mixed>}
     */
    public function bookingBoard(AmenityBooking $booking): array
    {
        $booking->loadMissing(['amenity', 'unit']);

        // The first entry of each kind. There is only ever one — the state
        // machine refuses a second hold, refund or forfeit — so `unique` is a
        // guard on the rail rather than a choice between entries.
        $entries = Journal::query()
            ->whereIn('source', [
                Ledger::SOURCE_DEPOSIT_HELD,
                Ledger::SOURCE_DEPOSIT_REFUND,
                Ledger::SOURCE_DEPOSIT_FORFEIT,
            ])
            ->where('source_id', $booking->id)
            ->orderBy('posted_on')
            ->orderBy('id')
            ->get()
            ->unique('source')
            ->keyBy('source');

        $held = $entries->get(Ledger::SOURCE_DEPOSIT_HELD);
        $refund = $entries->get(Ledger::SOURCE_DEPOSIT_REFUND);
        $forfeit = $entries->get(Ledger::SOURCE_DEPOSIT_FORFEIT);

        $hasDeposit = $booking->deposit_minor > 0 && $booking->deposit_state !== AmenityBooking::DEPOSIT_NONE;

        $timeline = [];

        if ($hasDeposit) {
            $timeline[] = [
                'key' => 'quoted',
                'label' => 'Quoted · '.AmenityBooking::amount($booking->deposit_minor),
                'line' => sprintf(
                    'Copied off the %s rate card when the booking was made%s',
                    $booking->amenity->name,
                    $booking->created_at === null ? '' : ', '.$booking->created_at->format('M j'),
                ),
                'state' => 'done',
            ];

            $timeline[] = [
                'key' => 'held',
                'label' => 'Received and held',
                'line' => $held === null
                    ? 'Awaiting payment. It reaches the estate’s books only when the money arrives.'
                    : $this->entryLine($held),
                'state' => match (true) {
                    $held !== null => 'done',
                    $booking->deposit_state === AmenityBooking::DEPOSIT_AWAITING => 'active',
                    default => 'pending',
                },
            ];

            $timeline[] = [
                'key' => 'closed',
                'label' => match (true) {
                    $refund !== null => 'Refunded',

                    // A part kept says both halves: what the estate kept, and
                    // what went back in the same entry.
                    $forfeit !== null => ($booking->depositKeptMinor() ?? $booking->deposit_minor) < $booking->deposit_minor
                        ? sprintf(
                            'Forfeited · %s kept, %s returned',
                            AmenityBooking::amount((int) $booking->depositKeptMinor()),
                            AmenityBooking::amount($booking->deposit_minor - (int) $booking->depositKeptMinor()),
                        )
                        : 'Forfeited',
                    default => 'Refunded or forfeited',
                },
                'line' => match (true) {
                    $refund !== null => $this->entryLine($refund),
                    $forfeit !== null => $this->entryLine($forfeit).' · '.(string) $booking->deposit_forfeit_reason,
                    default => null,
                },
                'state' => match (true) {
                    $refund !== null, $forfeit !== null => 'done',
                    $booking->deposit_state === AmenityBooking::DEPOSIT_HELD => 'active',
                    default => 'pending',
                },
            ];
        }

        return [
            'booking' => [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'amenity' => $booking->amenity->name,
                'resident' => $booking->resident_name,
                'unit' => $booking->unit->reference,

                // The full date, always. The diary collapses a past booking to
                // "Aug 15 (past)" because nobody scans a list for which two hours
                // of a finished Saturday it ran; a detail screen is where somebody
                // asking about a forfeited deposit needs exactly that.
                'when' => $booking->starts_at->format('D, M j, Y').' · '
                    .$booking->starts_at->format('g:i A').'–'.SiteTime::fmt($booking->ends_at, 'g:i A'),
                'guests' => $booking->guests,
                'status' => $booking->status,
                'status_label' => AmenityBooking::STATUS_LABELS[$booking->status],
                'fee' => $booking->fee_minor > 0 ? AmenityBooking::amount($booking->fee_minor) : 'No fee',
                'fee_charged' => $booking->isCharged(),
                'cancellation' => $booking->cancellation_hours === null ? null : $booking->cancellation_hours.' hours',
                'decision' => $this->decisionLine($booking),
                'notes' => $booking->notes,
            ],
            'deposit' => [
                'amount' => $hasDeposit ? AmenityBooking::amount($booking->deposit_minor) : null,
                'state' => $booking->deposit_state,
                'label' => $booking->depositLabel(),
                'timeline' => $timeline,

                /*
                 * THE DEPOSIT'S STATE, AS A BADGE, AND WHEN (M94 §1.2, M96 §1). A refunded or forfeited deposit, or one a
                 * cancelled booking will never need, left three dead buttons that said nothing even on hover: it is
                 * `settled`, and the page shows this badge and no buttons. A held one says since when, so its "Record
                 * deposit received" can go: it is done.
                 */
                'badge' => ! $hasDeposit ? null : match (true) {
                    // Month first, as every other date on this page is written ("Confirmed by …, Oct 12", the timeline's "Oct 14").
                    $refund !== null => ['label' => 'Deposit refunded · '.$refund->posted_on->format('M j'), 'tone' => 'completed'],
                    $forfeit !== null => ['label' => 'Deposit forfeited · '.$forfeit->posted_on->format('M j'), 'tone' => 'closed'],
                    $held !== null => ['label' => 'Deposit held · '.$held->posted_on->format('M j'), 'tone' => 'in_progress'],
                    in_array($booking->status, [AmenityBooking::DECLINED, AmenityBooking::CANCELLED, AmenityBooking::EXPIRED], true) => ['label' => 'No deposit due', 'tone' => 'closed'],
                    default => ['label' => 'Deposit pending', 'tone' => 'pending'],
                },
                'settled' => $hasDeposit && ($refund !== null || $forfeit !== null || ($held === null
                    && in_array($booking->status, [AmenityBooking::DECLINED, AmenityBooking::CANCELLED, AmenityBooking::EXPIRED], true))),
            ],
        ];
    }

    /** "Sep 1 · JV-2026-09-0001 · Tracey Reid" — when, which entry, and who. */
    private function entryLine(Journal $entry): string
    {
        return implode(' · ', array_filter([
            $entry->posted_on->format('M j'),
            $entry->reference,
            $entry->posted_by_name,
        ]));
    }

    /**
     * WHEN EACH AMENITY'S DIARY IS TAKEN, for the Resident App's hour picker (M21 §1.5) — the periods a booking would be
     * refused for by `guardAvailability`: every pending or confirmed booking and every period the estate closed, from
     * now to `$until`. Times only: no household, no name, no reference. A resident choosing hours sees which are
     * booked instead of learning it one refusal at a time.
     *
     * @param  list<int>  $amenityIds
     * @return array<int, list<array{starts_at: Carbon, ends_at: Carbon}>> by amenity id, earliest first
     */
    public function takenPeriods(array $amenityIds, Carbon $until): array
    {
        $now = Carbon::now();
        $taken = array_fill_keys($amenityIds, []);

        $bookings = AmenityBooking::query()->whereIn('amenity_id', $amenityIds)
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->where('ends_at', '>', $now)->where('starts_at', '<', $until)
            ->get(['amenity_id', 'starts_at', 'ends_at']);
        $closed = AmenitySlot::query()->whereIn('amenity_id', $amenityIds)
            ->where('ends_at', '>', $now)->where('starts_at', '<', $until)
            ->get(['amenity_id', 'starts_at', 'ends_at']);

        foreach ([...$bookings, ...$closed] as $row) {
            $taken[(int) $row->amenity_id][] = ['starts_at' => $row->starts_at, 'ends_at' => $row->ends_at];
        }

        return array_map(static function (array $periods): array {
            usort($periods, static fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

            return $periods;
        }, $taken);
    }

    /** Who decided the booking and when, in the words the detail prints. */
    private function decisionLine(AmenityBooking $booking): ?string
    {
        return match ($booking->status) {
            AmenityBooking::CONFIRMED, AmenityBooking::COMPLETED => $booking->approved_at === null
                ? null
                : sprintf(
                    'Confirmed by %s, %s',
                    $booking->approved_by_name ?? 'the estate',
                    SiteTime::fmt($booking->approved_at, 'M j, g:i A'),
                ),
            AmenityBooking::DECLINED => 'Declined'
                .($booking->declined_at === null ? '' : ' '.$booking->declined_at->format('M j'))
                .': '.($booking->declined_reason ?? 'no reason was recorded'),
            AmenityBooking::CANCELLED => 'Cancelled'
                .($booking->cancelled_at === null ? '' : ' '.$booking->cancelled_at->format('M j')),
            default => null,
        };
    }

    /**
     * Add an amenity to the rate card — board 20's "Add amenity" (12 §2, Wave 2).
     *
     * Capacity, hours, fee and deposit captured together, as the old inert
     * reason said they had to be: an amenity brought into existence with none
     * of them is a card every future booking would copy nothing from. A nil
     * fee or deposit is stored as NULL, not zero — "Free for residents" and
     * "None" are statements, and a zero-amount entry is what the board's own
     * accounting note forbids.
     *
     * @param  array<string, mixed>  $terms
     */
    public function addAmenity(array $terms, ?User $by = null): Amenity
    {
        $this->guardTerms($terms);

        if (Amenity::query()->whereRaw('LOWER(name) = ?', [strtolower(trim((string) $terms['name']))])->exists()) {
            throw new DomainException(trim((string) $terms['name']).' is already on the rate card. Two amenities with one name would fill one diary with two sets of bookings.');
        }

        $amenity = Amenity::create([
            'name' => trim((string) $terms['name']),
            'icon_key' => $terms['icon_key'],
            'capacity' => (int) $terms['capacity'],
            'booking_fee_minor' => $this->minorOrNull($terms['fee'] ?? null),
            'deposit_minor' => $this->minorOrNull($terms['deposit'] ?? null),
            'currency' => 'JMD',
            'opens_at' => $terms['opens_at'],
            'closes_at' => $terms['closes_at'],
            'booking_window_days' => (int) ($terms['booking_window_days'] ?? 30),
            'cancellation_hours' => (int) ($terms['cancellation_hours'] ?? 24),
            'sort_order' => ((int) Amenity::query()->max('sort_order')) + 1,
            'is_active' => true,
            'is_bookable' => (bool) ($terms['is_bookable'] ?? true),
            // On unless the card says otherwise (M53 §2): a booking of it waits for the office.
            'requires_approval' => (bool) ($terms['requires_approval'] ?? true),
        ]);

        /*
         * AUDITED (M10 §3: "every change is audited"). A new card is a new price
         * the estate charges its households, and until now nothing recorded who
         * set it or when. The actor is whoever is signed in; `AuditLogger` reads
         * them itself.
         */
        $this->audit->record(
            action: 'estate.amenity_added',
            entityType: 'Amenity',
            entityId: (string) $amenity->id,
            after: $this->auditedTerms($amenity),
        );

        return $amenity;
    }

    /**
     * Edit the rate card — board 20's pencil (12 §2, Wave 2).
     *
     * AN EDIT NEVER ALTERS A CONFIRMED BOOKING, and that is not something this
     * method has to do anything about: every booking carries its own copy of
     * the fee, the deposit and the cancellation rule it was made under, and
     * nothing reads them back through the relation. Changing the card changes
     * what the NEXT booking copies. `EstateFacilitiesAmenitiesTest` proves a
     * held deposit does not move by a cent when the card it came from triples.
     *
     * Closing an amenity to new bookings is an edit; retiring it is too
     * (`is_active` false) — never a delete, because what was booked while it
     * was open still has to read correctly.
     *
     * EVERY CHANGE IS AUDITED (M10 §3), before and after, which it was not: an
     * estate's booking fee and deposit are money a household is charged, and
     * the card could be edited with no record of who changed it or what it had
     * said. The class docblock's snapshot is what protects the bookings already
     * made; the audit entry is the separate thing the client asked for — who
     * moved the price, from what, to what. An edit that changes nothing writes
     * no entry, because an entry is a claim that something changed.
     *
     * @param  array<string, mixed>  $terms
     */
    public function editAmenity(Amenity $amenity, array $terms, ?User $by = null): Amenity
    {
        $this->guardTerms($terms);

        $clash = Amenity::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(trim((string) $terms['name']))])
            ->where('id', '!=', $amenity->id)
            ->exists();

        if ($clash) {
            throw new DomainException(trim((string) $terms['name']).' is already the name of another amenity.');
        }

        // Taken BEFORE the fill, or "before" would read what the edit wrote.
        $before = $this->auditedTerms($amenity);

        $amenity->fill([
            'name' => trim((string) $terms['name']),
            'icon_key' => $terms['icon_key'],
            'capacity' => (int) $terms['capacity'],
            'booking_fee_minor' => $this->minorOrNull($terms['fee'] ?? null),
            'deposit_minor' => $this->minorOrNull($terms['deposit'] ?? null),
            'opens_at' => $terms['opens_at'],
            'closes_at' => $terms['closes_at'],
            /*
             * AN UNSET RULE STAYS UNSET. Blank keeps what the card had, as it
             * always meant to — but what it had may be NOTHING (the seeded cards
             * set neither), and `(int) null` is 0: an edit that only corrected a
             * fee used to save a booking window of zero days, which closes the
             * diary, and a cancellation notice of zero hours nobody chose. A
             * figure that was typed, zero included, is written as typed.
             */
            'booking_window_days' => $this->typedOrKept($terms['booking_window_days'] ?? null, $amenity->booking_window_days),
            'cancellation_hours' => $this->typedOrKept($terms['cancellation_hours'] ?? null, $amenity->cancellation_hours),
            'is_bookable' => (bool) ($terms['is_bookable'] ?? true),
            'is_active' => (bool) ($terms['is_active'] ?? true),
            // Kept as it was when the form sends nothing for it (an older page): the switch moves only when pressed.
            'requires_approval' => array_key_exists('requires_approval', $terms) ? (bool) $terms['requires_approval'] : (bool) $amenity->requires_approval,
        ])->save();

        $after = $this->auditedTerms($amenity);

        if ($after !== $before) {
            $this->audit->record(
                action: $before['is_active'] && ! $after['is_active'] ? 'estate.amenity_retired' : 'estate.amenity_terms_changed',
                entityType: 'Amenity',
                entityId: (string) $amenity->id,
                before: $before,
                after: [
                    ...$after,

                    // What the change reached, and what it did not: the bookings
                    // already made keep the terms they were made under.
                    'upcoming_bookings_keeping_terms' => $this->upcomingBookings($amenity),
                ],
            );
        }

        return $amenity;
    }

    /**
     * What an edit to this card reaches, in the words the edit panel prints
     * BEFORE the press (M10 §3: "both screens show clearly what a change will
     * affect and from when").
     *
     * FROM THE NEXT BOOKING MADE, AND NOT ONE ALREADY MADE. A booking copies the
     * fee, the deposit and the cancellation rule when it is taken (the class
     * docblock), so the honest sentence is a count of what the change will NOT
     * touch — the bookings still to happen, pending or confirmed — and when it
     * starts to bite.
     *
     * Given the labels the card carried before an edit, it leads with what moved:
     * "Booking fee $3,000 → $3,500."
     *
     * @param  array{fee: string, deposit: string, cancellation_hours: int|null}|null  $before  from `priceLabels()`
     */
    public function termsEffect(Amenity $amenity, ?array $before = null, ?int $upcoming = null): string
    {
        $parts = [];

        if ($before !== null) {
            $now = $this->priceLabels($amenity);
            $moved = [];

            if ($before['fee'] !== $now['fee']) {
                $moved[] = 'booking fee '.$before['fee'].' → '.$now['fee'];
            }

            if ($before['deposit'] !== $now['deposit']) {
                $moved[] = 'deposit '.$before['deposit'].' → '.$now['deposit'];
            }

            if ($before['cancellation_hours'] !== $now['cancellation_hours']) {
                // A blank notice is "any time", in words: it printed "cancellation notice 24h → h" (batch 1 item 2).
                $notice = static fn (?int $hours): string => $hours === null || $hours === 0 ? 'any time' : $hours.'h';
                $moved[] = 'cancellation notice '.$notice($before['cancellation_hours']).' → '.$notice($now['cancellation_hours']);
            }

            if ($moved !== []) {
                $parts[] = ucfirst(implode('; ', $moved)).'.';
            }
        }

        $parts[] = 'Applies to bookings made from now on.';

        // Counted for every amenity at once by the rate card (M11 A2), or here for one.
        $upcoming ??= $this->upcomingBookings($amenity);

        $parts[] = match ($upcoming) {
            0 => 'No upcoming booking has been made, so none is affected.',
            1 => 'The 1 upcoming booking already made keeps the fee, deposit and cancellation rule it was made under.',
            default => 'The '.$upcoming.' upcoming bookings already made keep the fee, deposit and cancellation rule they were made under.',
        };

        return implode(' ', $parts);
    }

    /**
     * The three terms a booking copies, as the card prints them — what
     * `termsEffect()` compares an edit against.
     *
     * @return array{fee: string, deposit: string, cancellation_hours: int|null}
     */
    public function priceLabels(Amenity $amenity): array
    {
        return [
            'fee' => $amenity->feeLabel(),
            'deposit' => $amenity->depositLabel(),
            'cancellation_hours' => $amenity->cancellation_hours === null ? null : (int) $amenity->cancellation_hours,
        ];
    }

    /**
     * Bookings still to happen — waiting on a decision or confirmed — each
     * holding its own copy of the terms.
     */
    private function upcomingBookings(Amenity $amenity): int
    {
        return $amenity->bookings()
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->where('ends_at', '>', Carbon::now())
            ->count();
    }

    /**
     * The card as the audit log records it.
     *
     * Times cut to HH:MM, because the column hands back "08:00:00" and the form
     * sends "08:00": compared raw, every save would be recorded as moving the
     * opening hours when nobody touched them.
     *
     * @return array<string, mixed>
     */
    private function auditedTerms(Amenity $amenity): array
    {
        return [
            'name' => (string) $amenity->name,
            'icon_key' => (string) $amenity->icon_key,
            'capacity' => (int) $amenity->capacity,
            'booking_fee_minor' => $amenity->booking_fee_minor === null ? null : (int) $amenity->booking_fee_minor,
            'deposit_minor' => $amenity->deposit_minor === null ? null : (int) $amenity->deposit_minor,
            'opens_at' => substr((string) $amenity->opens_at, 0, 5),
            'closes_at' => substr((string) $amenity->closes_at, 0, 5),
            'booking_window_days' => $amenity->booking_window_days === null ? null : (int) $amenity->booking_window_days,
            'cancellation_hours' => $amenity->cancellation_hours === null ? null : (int) $amenity->cancellation_hours,
            'is_bookable' => (bool) $amenity->is_bookable,
            'is_active' => (bool) $amenity->is_active,
            'requires_approval' => (bool) $amenity->requires_approval,
        ];
    }

    /**
     * What every amenity has to say for itself before it goes on the card.
     *
     * @param  array<string, mixed>  $terms
     */
    private function guardTerms(array $terms): void
    {
        if (trim((string) ($terms['name'] ?? '')) === '') {
            throw new DomainException('An amenity has a name.');
        }

        if (! in_array($terms['icon_key'] ?? '', Amenity::ICONS, true)) {
            throw new DomainException('Choose one of the four glyphs the boards draw: gazebo, clubhouse, pavilion or pool.');
        }

        if ((int) ($terms['capacity'] ?? 0) < 1 || (int) $terms['capacity'] > 5000) {
            throw new DomainException('Capacity is a count of guests, from 1 to 5,000.');
        }

        foreach (['opens_at', 'closes_at'] as $key) {
            if (preg_match('/^(?:[01]\d|2[0-4]):[0-5]\d$/', (string) ($terms[$key] ?? '')) !== 1) {
                throw new DomainException('Opening hours are HH:MM on a 24-hour clock; 24:00 is midnight.');
            }
        }

        if (strcmp((string) $terms['closes_at'], (string) $terms['opens_at']) <= 0) {
            throw new DomainException('An amenity closes after it opens. Write midnight as 24:00, not 00:00.');
        }

        foreach (['fee', 'deposit'] as $key) {
            // Converted here, before anything is written, so a figure that is not
            // money is refused in words rather than half-way through a save.
            $this->minorOrNull($terms[$key] ?? null);
        }
    }

    /** A whole number that was typed, or — when the field came back blank — what the card already said, unset included. */
    private function typedOrKept(mixed $typed, ?int $kept): ?int
    {
        return $typed === null || $typed === '' ? $kept : (int) $typed;
    }

    /**
     * "3000.00" to 300000; blank or zero to NULL — "Free for residents", not J$0.00.
     *
     * DOLLARS AND CENTS, OR A REFUSAL IN WORDS (B045). "4500.505" is numeric and
     * is not an amount of Jamaican dollars, so Brick refuses to round it — and it
     * refused with a MathException, which no caller catches, so a Property
     * Manager who mistyped a fee lost the form to a 500 page. "1e30" is numeric
     * too and overflowed. Nothing is rounded to make it fit: a fee the estate
     * never typed would be a price every future booking copies.
     */
    private function minorOrNull(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $refusal = sprintf(
            'A fee or a deposit is an amount of dollars and cents, at most two decimal places and no more than $%s, or blank for none.',
            number_format(self::MAX_TERM_MINOR / 100, 2),
        );

        if (! is_numeric($amount)) {
            throw new DomainException($refusal);
        }

        try {
            $minor = Money::of((string) $amount, 'JMD')->getMinorAmount()->toInt();
        } catch (MathException) {
            throw new DomainException($refusal);
        }

        if ($minor < 0 || $minor > self::MAX_TERM_MINOR) {
            throw new DomainException($refusal);
        }

        return $minor > 0 ? $minor : null;
    }

    /**
     * Board 20 — the rate card.
     *
     * @return array<string, mixed>
     */
    public function settingsBoard(): array
    {
        $amenities = Amenity::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /*
         * Every card's upcoming bookings in one count (M11 A2) — the same question
         * `upcomingBookings()` asks of one amenity, asked of them all together.
         */
        $upcoming = AmenityBooking::query()
            ->whereIn('amenity_id', $amenities->modelKeys())
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->where('ends_at', '>', Carbon::now())
            ->selectRaw('amenity_id, COUNT(*) AS upcoming')
            ->groupBy('amenity_id')
            ->pluck('upcoming', 'amenity_id')
            ->map(static fn ($n): int => (int) $n);

        return [
            'rows' => $amenities->map(fn (Amenity $amenity): array => [
                'id' => $amenity->id,
                'name' => $amenity->name,

                // What an edit to this card reaches and from when, for the edit
                // panel to print before the press. See `termsEffect()`.
                'terms_effect' => $this->termsEffect($amenity, upcoming: $upcoming[$amenity->id] ?? 0),
                'icon' => $amenity->icon_key,
                'capacity' => $amenity->capacity,
                'capacity_label' => $amenity->capacity.' guests',

                /*
                 * The amounts AND the labels. The minor units are the fact and
                 * the label is what the board draws — "Free for residents",
                 * "None" — which are statements about the amenity rather than
                 * numbers, and which a page formatting a null would have to
                 * invent.
                 */
                'fee_minor' => $amenity->booking_fee_minor,
                'fee_label' => $amenity->feeLabel(),
                'deposit_minor' => $amenity->deposit_minor,
                'deposit_label' => $amenity->depositLabel(),
                'hours_label' => $amenity->hoursLabel().' '.SiteTime::clockTag(SiteTime::zone()),
                'cancellation_hours' => $amenity->cancellation_hours,
                'booking_window_days' => $amenity->booking_window_days,
                'is_bookable' => $amenity->is_bookable,
                'requires_approval' => (bool) $amenity->requires_approval,

                // The stored terms in the form the edit panel edits — HH:MM
                // and decimal strings, so the panel never has to divide.
                'terms' => [
                    'name' => $amenity->name,
                    'icon_key' => $amenity->icon_key,
                    'capacity' => $amenity->capacity,
                    'fee' => $amenity->booking_fee_minor === null ? '' : number_format($amenity->booking_fee_minor / 100, 2, '.', ''),
                    'deposit' => $amenity->deposit_minor === null ? '' : number_format($amenity->deposit_minor / 100, 2, '.', ''),
                    'opens_at' => substr((string) $amenity->opens_at, 0, 5),
                    'closes_at' => substr((string) $amenity->closes_at, 0, 5),
                    'booking_window_days' => $amenity->booking_window_days,
                    'cancellation_hours' => $amenity->cancellation_hours,
                    'is_bookable' => $amenity->is_bookable,
                    'requires_approval' => (bool) $amenity->requires_approval,
                ],
            ])->all(),
            'icons' => Amenity::ICONS,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Refuse a period something else already has.
     *
     * Two separate refusals with two different sentences, because "the Gazebo is
     * closed that afternoon" and "somebody else has it" send a household to
     * different places.
     */
    private function guardAvailability(Amenity $amenity, Carbon $from, Carbon $to): void
    {
        $blocked = $amenity->slots()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->first();

        if ($blocked !== null) {
            throw new DomainException(sprintf(
                'The %s is unavailable then: %s. The estate closed that period; it is not a slot '.
                'somebody else has taken.',
                $amenity->name,
                $blocked->label(),
            ));
        }

        $taken = $amenity->bookings()
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->first();

        if ($taken !== null) {
            throw new SlotTaken(sprintf(
                'The %s is already booked for part of that period (%s). Two households told the same '.
                'Saturday is theirs is the one failure a booking diary exists to prevent.',
                $amenity->name,
                $taken->reference,
            ), $taken->starts_at, $taken->ends_at);
        }
    }

    /**
     * "BKG-2026-09-0001" — sequential within the month the booking falls in.
     *
     * The same shape as a charge, a receipt and a payment plan, because a
     * resident reads it back over the phone and a manager looks it up beside the
     * others.
     */
    private function nextReference(Carbon $on): string
    {
        $stem = 'BKG-'.$on->format('Y-m').'-';

        // Past every reference the unique index holds, a retired sample booking's too (M33 §5).
        $last = Retirements::seeingEverything(static fn (): mixed => AmenityBooking::query()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference'));

        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($stem))) + 1;

        return $stem.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function asMoment(Carbon|string $moment): Carbon
    {
        return $moment instanceof Carbon ? $moment->copy() : Carbon::parse($moment);
    }
}
