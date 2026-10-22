<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Support\SiteTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shared facility residents may book — board 20's rate card.
 *
 * IT SOURCES POSTINGS AND MAKES NONE. Every figure on board 19 and every amenity
 * charge on a unit's ledger derives from the two amounts here, and the board's
 * own note says why they cannot be one column: a BOOKING FEE is revenue, earned
 * and non-refundable, and a DEPOSIT is a refundable liability that is never
 * recognised as income. Conflating them would credit 4100 with money the estate
 * has to give back.
 *
 * NULL IS NOT ZERO ON EITHER OF THEM. Board 20 draws "Free for residents"
 * against the Pool Deck's fee and "None" against its deposit — statements about
 * the amenity rather than amounts — and the board's accounting note is explicit
 * that a nil amenity must "produce a booking with zero journal lines rather than
 * a zero-amount entry". A zero-value journal entry is a row in the ledger
 * claiming something happened.
 *
 * EDITING THIS RECORD CHANGES NOTHING ALREADY BOOKED. A booking copies the fee,
 * the deposit and the cancellation rule at creation; see `AmenityBooking`. That
 * is what lets board 19's Deposit column keep agreeing with the deposits
 * actually held after a manager edits the rate card.
 *
 * `closes_at` MAY HOLD 24:00:00. Board 20 draws the Community Centre closing at
 * "Midnight", and 00:00:00 would sort before every opening time and make the
 * amenity closed all day. MySQL's TIME type carries it; nothing here casts these
 * two columns to Carbon, because Carbon cannot parse the twenty-fourth hour.
 *
 * @property int $id
 * @property string $name
 * @property string $icon_key
 * @property int $capacity
 * @property int|null $booking_fee_minor
 * @property int|null $deposit_minor
 * @property string $currency
 * @property string $opens_at
 * @property string $closes_at
 * @property int|null $booking_window_days
 * @property int|null $cancellation_hours
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_bookable
 * @property bool $requires_approval a booking of it is a request the office approves, its fee charged then (M53 §2); off, it is paid for in the app and confirmed at once
 * @property string|null $description "garden setting" — what the place is, under its name (M47 §8.2)
 * @property string|null $place "Phase 2" — where it is on the estate (M47 §9.4)
 * @property int|null $hourly_rate_minor the fee is this times the hours booked; null keeps the flat `booking_fee_minor`
 * @property int|null $slot_minutes the blocks a day is booked in (120: "9–11 AM"); null is one hour
 * @property string|null $rules_summary "noise cut-off at 9:00 PM and no glassware on the lawn"
 * @property string|null $rules the house rules themselves
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AmenityBooking> $bookings
 * @property-read Collection<int, AmenitySlot> $slots
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity query()
 *
 * @mixin \Eloquent
 */
class Amenity extends Model
{
    /**
     * The name of the glyph each card and each booking row draws.
     *
     * Stored on the amenity rather than matched from its name: board 20 draws
     * four distinct SVGs and the same key has to resolve to the smaller glyph on
     * board 19, so an estate adding "Tennis Court" picks an icon rather than
     * inheriting whichever one a string match landed on.
     */
    public const ICONS = ['gazebo', 'clubhouse', 'pavilion', 'pool'];

    /** What board 20 prints where an amenity costs nothing. */
    public const FREE_LABEL = 'Free for residents';

    /** What it prints where no deposit is taken. */
    public const NO_DEPOSIT_LABEL = 'None';

    protected $fillable = [
        'name',
        'icon_key',
        'capacity',
        'booking_fee_minor',
        'deposit_minor',
        'currency',
        'opens_at',
        'closes_at',
        'booking_window_days',
        'cancellation_hours',
        'sort_order',
        'is_active',
        'is_bookable',
        'requires_approval',
        'description',
        'place',
        'hourly_rate_minor',
        'slot_minutes',
        'rules_summary',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'booking_fee_minor' => 'integer',
            'hourly_rate_minor' => 'integer',
            'slot_minutes' => 'integer',
            'deposit_minor' => 'integer',
            'booking_window_days' => 'integer',
            'cancellation_hours' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_bookable' => 'boolean',
            'requires_approval' => 'boolean',
        ];
    }

    /** Whether booking this amenity raises a charge at all. */
    public function chargesAFee(): bool
    {
        return $this->isHourly() || ($this->booking_fee_minor ?? 0) > 0;
    }

    /** Priced by the hour (M47 §8): "$2,000/hr", the fee the rate times the hours booked. */
    public function isHourly(): bool
    {
        return ($this->hourly_rate_minor ?? 0) > 0;
    }

    /**
     * THE FEE FOR A PERIOD, in minor units: the hourly rate times its length — "2 hrs × $2,000" is $4,000 — counted to
     * the minute and rounded to the cent; or, for an amenity with no rate, the one flat fee per booking it has always
     * charged, whatever the length. `Amenities::book` copies it onto the booking, where it never moves again.
     */
    public function feeMinorFor(Carbon $startsAt, Carbon $endsAt): int
    {
        if (! $this->isHourly()) {
            return (int) ($this->booking_fee_minor ?? 0);
        }

        $minutes = intdiv(max(0, $endsAt->getTimestamp() - $startsAt->getTimestamp()), 60);

        return intdiv((int) $this->hourly_rate_minor * $minutes + 30, 60);
    }

    /** Whether it takes a refundable deposit. */
    public function takesADeposit(): bool
    {
        return ($this->deposit_minor ?? 0) > 0;
    }

    /** "$3,000", "$2,000/hr" for one priced by the hour, or "Free for residents" where there is no fee. */
    public function feeLabel(): string
    {
        if ($this->isHourly()) {
            return $this->money((int) $this->hourly_rate_minor).'/hr';
        }

        return $this->chargesAFee() ? $this->money((int) $this->booking_fee_minor) : self::FREE_LABEL;
    }

    /** "$5,000", or "None". */
    public function depositLabel(): string
    {
        return $this->takesADeposit() ? $this->money((int) $this->deposit_minor) : self::NO_DEPOSIT_LABEL;
    }

    /** "8:00 AM – 10:00 PM" — an en-dash WITH spaces, unlike a booking's range. */
    public function hoursLabel(): string
    {
        return $this->timeLabel($this->opens_at).' – '.$this->timeLabel($this->closes_at);
    }

    /**
     * Whether a period falls inside this amenity's opening hours.
     *
     * Compared as seconds since midnight so the twenty-fourth hour works: a
     * booking ending at 23:30 on a Community Centre that closes at "Midnight"
     * is inside, and the same comparison against a parsed 00:00:00 would put it
     * outside by twenty-three and a half hours.
     */
    public function isWithinHours(Carbon $startsAt, Carbon $endsAt): bool
    {
        $open = $this->seconds($this->opens_at);
        $close = $this->seconds($this->closes_at);

        // OPENING HOURS ARE ON THE ESTATE'S CLOCK (M4 §3.1). A booking is a UTC moment; "is it
        // inside 8 AM–10 PM" is asked of that moment as the estate reads it, by the zone's name.
        $startsAt = SiteTime::local($startsAt);
        $endsAt = SiteTime::local($endsAt);

        $from = $startsAt->secondsSinceMidnight();

        /*
         * A booking that ends exactly at midnight has run to the end of the day,
         * not to the start of it. Its own seconds-since-midnight is zero, which
         * would read as ending before it began.
         */
        $to = $endsAt->isSameDay($startsAt) ? $endsAt->secondsSinceMidnight() : 24 * 3600;

        return $from >= $open && $to <= $close && $to > $from;
    }

    /** @return HasMany<AmenityBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(AmenityBooking::class);
    }

    /** @return HasMany<AmenitySlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(AmenitySlot::class);
    }

    /**
     * The bare-dollar form boards 19 and 20 use.
     *
     * NOT THE LEDGER'S FORMAT, and the difference is the boards': the ledger
     * screens write "J$12,400.00" and these two write "$5,000" with no decimals
     * and no country prefix. The conversion happens here, at the boundary,
     * rather than in a page — the amount is stored in minor units like every
     * other amount in this system, and a template dividing by a hundred is how a
     * rounding artefact reaches a rate card.
     */
    private function money(int $minor): string
    {
        // The diary's own formatter, so the rate card and every booking copied from it print one amount one way, and a
        // card with cents in it says them ("$3,000.50") where `intdiv` dropped them.
        return AmenityBooking::amount($minor);
    }

    /** "8:00 AM", or the word board 20 prints for the twenty-fourth hour. */
    private function timeLabel(string $time): string
    {
        $seconds = $this->seconds($time);

        if ($seconds === 0 || $seconds >= 24 * 3600) {
            return 'Midnight';
        }

        return Carbon::today()->addSeconds($seconds)->format('g:i A');
    }

    /** A stored TIME as seconds since midnight, 24:00:00 included. */
    private function seconds(string $time): int
    {
        // Padded rather than defended read by read. MySQL hands back a full
        // "HH:MM:SS", but a column read through a cast or a fixture written by
        // hand can be "22:00" — and an hour silently dropped is a card that
        // says the pool closes at ten and a booking form that says midnight.
        $parts = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
    }
}
