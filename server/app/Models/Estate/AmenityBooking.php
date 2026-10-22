<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\SiteDateTime;
use App\Support\SiteTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reservation of a shared facility — board 19.
 *
 * A BOOKING CARRIES THE TERMS IT WAS MADE UNDER. `fee_minor`, `deposit_minor`
 * and `cancellation_hours` are copied off the amenity when the booking is
 * created and are never read back through the relation. The Build Spec states
 * the rule for board 20 — "Changing a rule does not alter bookings already
 * confirmed under the previous rule" — and board 19's accounting note states the
 * consequence: "bookings copy amenity.deposit_amount verbatim, so a change to
 * this rate card must not retro-alter deposits already held or the deposit
 * control account will no longer agree to the sum of open bookings."
 *
 * A booking that read the amenity live would restate every held deposit in the
 * estate the moment a manager typed a new figure into board 20, and the estate
 * would owe residents an amount its own books no longer showed.
 *
 * THE DEPOSIT STATE FOLLOWS THE LEDGER, NEVER THE OTHER WAY ROUND. "held" means
 * the estate has the money and carries it as a refundable liability on 2200;
 * "awaiting" means it has nothing at all and must not touch that account, or
 * the control balance overstates by the quoted amount; "refunded" means the
 * liability was reversed; "forfeited" means it became income. Every move is a
 * posting made by `Amenities` (QUESTIONS.md Q-009), and each has its gate on the
 * booking detail (D-086).
 *
 * `fee_charge_id` IS A POINTER AND THE AMOUNT IS NOT BESIDE IT. An amenity fee
 * that is charged is a charge on a unit like any other: it posts through
 * `Dues::charge`, which raises the entry, and the charge is the money. The column
 * is unique, so the same charge cannot back two bookings and — with the service's
 * own refusal — the same booking cannot be charged twice.
 *
 * @property bool $is_simulated whether the Resident App simulator wrote this row (13 C3)
 * @property int $id
 * @property string $reference
 * @property int $amenity_id
 * @property int $unit_id
 * @property string $resident_name
 * @property int|null $resident_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int|null $guests
 * @property string $status
 * @property int $fee_minor
 * @property int $deposit_minor
 * @property string $currency
 * @property int|null $cancellation_hours
 * @property string $deposit_state
 * @property Carbon|null $deposit_refunded_on
 * @property string|null $deposit_journal_ref
 * @property string|null $deposit_forfeit_reason
 * @property int|null $deposit_forfeited_minor what the estate KEPT of a forfeited deposit; null on a row forfeited before part-forfeits existed, which was always the whole
 * @property int|null $fee_charge_id
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property Carbon|null $approved_at
 * @property string|null $declined_reason
 * @property Carbon|null $declined_at
 * @property Carbon|null $cancelled_at
 * @property string|null $notes
 * @property string|null $confirmation_code "GZ-2609-A4" — a booking paid for in the app, confirmed at once (M47 §10)
 * @property string|null $gate_pass_id the guests' pass for the event's hours — its QR is on the confirmation (M47 §10.3)
 * @property string|null $paid_card "Visa •••• 4417" — the test card it was paid with; never more than brand and last four
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Amenity $amenity
 * @property-read Unit $unit
 * @property-read Charge|null $feeCharge
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AmenityBooking newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AmenityBooking newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AmenityBooking query()
 *
 * @mixin \Eloquent
 */
class AmenityBooking extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const COMPLETED = 'completed';

    /**
     * A request the office never decided before its time came (M53 §2): `Amenities::settle()` closes it, so it leaves
     * the queue of requests waiting and no longer holds the hours. Nobody declined it, so it is never called declined.
     */
    public const EXPIRED = 'expired';

    /** No deposit was ever due — a nil-deposit amenity. */
    public const DEPOSIT_NONE = 'none';

    /** Quoted and not received. It must never reach the deposits-held account. */
    public const DEPOSIT_AWAITING = 'awaiting';

    /** Received and carried as a refundable liability. */
    public const DEPOSIT_HELD = 'held';

    /** Returned, and no longer part of the held balance. */
    public const DEPOSIT_REFUNDED = 'refunded';

    /**
     * Kept by the estate — damage, or the amenity was not handed back.
     *
     * The only deposit state that ever becomes income (Dr 2200, Cr 4100). It
     * carries a reason for the same argument the state itself makes: keeping a
     * resident's money is the one deposit act somebody will be asked to justify.
     */
    public const DEPOSIT_FORFEITED = 'forfeited';

    /**
     * The states a deposit never leaves (M4 §4, defect 2).
     *
     * `awaiting` and `held` are the two that MUST move on: the first is a promise
     * nobody may still be waiting on once the booking is over, the second is
     * somebody else's money on 2200 Resident Deposits Held. `Amenities` moves
     * both; nothing moves one of these three.
     */
    public const DEPOSIT_TERMINAL = [self::DEPOSIT_NONE, self::DEPOSIT_REFUNDED, self::DEPOSIT_FORFEITED];

    /**
     * What board 19's status badge prints. A declined booking reads Rejected, the one vocabulary's word (M96 §2);
     * Confirmed, Cancelled and Expired have no word in it and wait on Hassam's decision (docs/qa/M97_PASS_QA.md).
     */
    public const STATUS_LABELS = [
        self::PENDING => 'Pending',
        self::CONFIRMED => 'Confirmed',
        self::DECLINED => 'Rejected',
        self::CANCELLED => 'Cancelled',
        self::COMPLETED => 'Completed',
        self::EXPIRED => 'Expired',
    ];

    protected $fillable = [
        'reference',
        'amenity_id',
        'unit_id',
        'resident_name',
        'resident_id',
        'starts_at',
        'ends_at',
        'guests',
        'status',
        'fee_minor',
        'deposit_minor',
        'currency',
        'cancellation_hours',
        'deposit_state',
        'deposit_refunded_on',
        'deposit_journal_ref',
        'deposit_forfeit_reason',
        'deposit_forfeited_minor',
        'fee_charge_id',
        'approved_by',
        'approved_by_name',
        'approved_at',
        'declined_reason',
        'declined_at',
        'cancelled_at',
        'notes',
        'confirmation_code',
        'gate_pass_id',
        'paid_card',
    ];

    protected function casts(): array
    {
        // ONE TIME RULE (M4 §3.1): stored true UTC, read on the estate's clock — see `SiteDateTime`.
        return [
            'starts_at' => SiteDateTime::class,
            'is_simulated' => 'boolean',
            'ends_at' => SiteDateTime::class,
            'guests' => 'integer',
            'fee_minor' => 'integer',
            'deposit_minor' => 'integer',
            'cancellation_hours' => 'integer',
            'deposit_refunded_on' => 'date',
            'deposit_forfeited_minor' => 'integer',
            // A test card paid the deposit (M12 §2.6; D-179): it is held, refunded and kept against 1090, not the bank.
            'deposit_demonstration' => 'boolean',
            'approved_at' => SiteDateTime::class,
            'declined_at' => SiteDateTime::class,
            'cancelled_at' => SiteDateTime::class,
        ];
    }

    /** Whether this booking still occupies the diary. */
    public function holdsTheDiary(): bool
    {
        return in_array($this->status, [self::PENDING, self::CONFIRMED], true);
    }

    /** Whether its fee has already been charged to the unit. */
    public function isCharged(): bool
    {
        return $this->fee_charge_id !== null;
    }

    /**
     * Half-open on the right, so back-to-back bookings are possible.
     *
     * A Club House booked until 7:00 PM and another from 7:00 PM are two
     * bookings, not a clash — which is how a Saturday at a club house actually
     * runs.
     */
    public function overlaps(Carbon $startsAt, Carbon $endsAt): bool
    {
        return $this->starts_at->lessThan($endsAt) && $this->ends_at->greaterThan($startsAt);
    }

    /** "Andrea Fletcher · Lot 47": board 19's Booked by cell, with the console's middot (Law 3), never an em dash. */
    public function bookedByLabel(): string
    {
        return $this->resident_name.' · '.$this->unit->reference;
    }

    /**
     * Board 19's Date & time cell.
     *
     * A PAST BOOKING COLLAPSES TO THE DATE. The board draws "Aug 15 (past)" with
     * no weekday and no time range: once an event has happened, which two hours
     * of that Saturday it ran for is not what anybody is scanning the list for.
     */
    public function whenLabel(?Carbon $asAt = null): string
    {
        // "Past" is before the ESTATE'S today began, not before midnight UTC.
        $today = SiteTime::dayBounds($asAt)[0];

        if ($this->starts_at->lessThan($today)) {
            return $this->starts_at->format('M j').' (past)';
        }

        // An en-dash with NO spaces, unlike the amenity's opening hours. Both
        // forms are the boards' own and they are deliberately different.
        // …and the clock it is on, once, at the end (M19A §4.2): "Sat, Sep 26 · 2:00 PM–4:00 PM JA".
        return $this->starts_at->format('D, M j').' · '
            .$this->starts_at->format('g:i A').'–'.SiteTime::fmt($this->ends_at, 'g:i A');
    }

    /**
     * Board 19's Deposit cell, in each of its printed forms.
     *
     * An empty string where no deposit was ever due — a Pool Deck booking has
     * nothing to say in this column, and printing "$0" would suggest a deposit
     * of nothing was taken.
     *
     * "Forfeited" is a fourth form the board does not draw, because the state
     * did not exist when it was drawn. It reads plainly rather than softly: the
     * estate kept a resident's money and the row should say so.
     */
    public function depositLabel(): string
    {
        if ($this->deposit_state === self::DEPOSIT_NONE || $this->deposit_minor <= 0) {
            return '';
        }

        $amount = self::amount($this->deposit_minor);

        return match ($this->deposit_state) {
            self::DEPOSIT_HELD => $amount.' held',

            // A booking that will not happen owes no deposit, as the booking page's badge says: "awaiting payment" on a
            // cancelled booking told the office to chase money nobody owes (routed to the hub, 12 October).
            self::DEPOSIT_AWAITING => in_array($this->status, [self::DECLINED, self::CANCELLED, self::EXPIRED], true)
                ? 'No deposit due'
                : $amount.' · awaiting payment',
            self::DEPOSIT_REFUNDED => $amount.' refunded '.($this->deposit_refunded_on?->format('M j') ?? ''),

            // A part kept says how much: "$5,000 forfeited" over a household that
            // got $3,000 of it back would be the row overstating what was taken.
            self::DEPOSIT_FORFEITED => $this->depositKeptMinor() < $this->deposit_minor
                ? self::amount((int) $this->depositKeptMinor()).' of '.$amount.' forfeited'
                : $amount.' forfeited',
            default => $amount,
        };
    }

    /**
     * What the estate kept of this deposit, in minor units — null unless it was
     * forfeited.
     *
     * A row forfeited before a part could be kept has no figure beside it, and
     * that was always the whole deposit: there was no other kind.
     */
    public function depositKeptMinor(): ?int
    {
        if ($this->deposit_state !== self::DEPOSIT_FORFEITED) {
            return null;
        }

        return $this->deposit_forfeited_minor ?? $this->deposit_minor;
    }

    /**
     * "$5,000" — board 19's own money form: a bare dollar sign, whole dollars.
     *
     * Public and static so the booking detail and its flash messages print a
     * booking's fee and deposit exactly as the diary does, rather than growing
     * a second formatter that disagrees with it by a "J".
     *
     * AND NEVER A CENT DROPPED (Hassam's money proof, 12 October). The board's whole dollars are kept for a whole amount,
     * "$5,000"; an amount with cents in it says them, "$5,000.50". It was `intdiv`, which printed a $5,000.50 deposit as
     * "$5,000": fifty cents the estate is holding, missing from the diary, the rate card and the booking page. Integer
     * arithmetic only, never a float.
     */
    public static function amount(int $minor): string
    {
        $cents = abs($minor) % 100;

        return ($minor < 0 ? '-' : '').'$'.number_format(intdiv(abs($minor), 100))
            .($cents === 0 ? '' : '.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT));
    }

    /** @return BelongsTo<Amenity, $this> */
    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The charge this booking's fee raised, where it raised one.
     *
     * @return BelongsTo<Charge, $this>
     */
    public function feeCharge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'fee_charge_id');
    }
}
