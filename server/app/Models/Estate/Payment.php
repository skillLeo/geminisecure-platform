<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use App\Support\SiteTime;
use Brick\Money\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money received against a unit.
 *
 * The RECEIPT, not the accounting. The entry this raised is the money; this row
 * carries what bookkeeping has no place for — the number on the paper the
 * resident holds, how it arrived, and the two timestamps.
 *
 * TWO TIMESTAMPS, AND NEITHER DEFAULTS TO THE OTHER. A guard takes cash at the
 * gate on Friday night and the treasurer keys it on Monday. The resident's
 * receipt is dated Friday; the bank sees it Monday. An arrears report that used
 * one for the other would show them in default over a weekend they had already
 * paid for.
 *
 * @property int $id
 * @property int $unit_id
 * @property string $receipt_no
 * @property int $amount_minor
 * @property string $currency
 * @property string $method
 * @property string|null $reference
 * @property Carbon $received_at
 * @property Carbon $entered_at
 * @property int|null $received_by
 * @property string|null $received_by_name
 * @property string|null $gateway_ref
 * @property int|null $gateway_fee_minor
 * @property string $status
 * @property string|null $journal_ref
 * @property bool $is_demonstration a test card paid it: posted to 1090, no money moved (D-179)
 * @property Money $amount
 * @property-read Unit $unit
 * @property-read Journal|null $entry
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment query()
 *
 * @mixin \Eloquent
 */
class Payment extends Model
{
    public const CARD = 'card';

    public const BANK = 'bank';

    public const CASH = 'cash';

    public const CHEQUE = 'cheque';

    protected $fillable = [
        'unit_id',
        'receipt_no',
        'amount_minor',
        'currency',
        'method',
        'reference',
        'received_at',
        'entered_at',
        'received_by',
        'received_by_name',
        'gateway_ref',
        'gateway_fee_minor',
        'status',
        'journal_ref',
        'is_demonstration',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'received_at' => 'datetime',
            'entered_at' => 'datetime',
            'amount_minor' => 'integer',
            'gateway_fee_minor' => 'integer',
            // A test card paid it (M12 §2.6; D-179): a real receipt, posted to 1090, and no money moved.
            'is_demonstration' => 'boolean',
        ];
    }

    /**
     * The calendar day a payment arrived, as `received_at` carries it: midnight
     * UTC with that day's date on it (bug hunt B023).
     *
     * `received_at` IS A DATE, NOT AN INSTANT — the day the office says the money
     * came in, which the API catalogue tells the phones too. So:
     *
     *   a string                     the date typed, "2026-09-30", read for its digits;
     *                                one carrying its own offset is a moment (below)
     *   a date-time at the very      that day, read for its digits — a seeder's
     *   start of its own day         `Carbon::parse('2026-09-07')`, or a stored row
     *   any other date-time          a MOMENT, and it names the day it was AT THE
     *                                ESTATE — at 8:30 PM in Kingston on the 30th that
     *                                is still the 30th, though UTC is on the 1st
     */
    public static function calendarDay(DateTimeInterface|string $day): Carbon
    {
        if (is_string($day)) {
            $text = trim($day);

            if (! SiteTime::carriesOffset($text)) {
                return Carbon::parse(substr($text, 0, 10))->startOfDay();
            }

            $day = Carbon::parse($text);
        }

        $moment = Carbon::instance($day);

        $local = $moment->format('H:i:s.u') === '00:00:00.000000' ? $moment : SiteTime::local($moment);

        return Carbon::parse($local->format('Y-m-d'))->startOfDay();
    }

    /** The day the money arrived — see `calendarDay()`. */
    public function receivedOn(): Carbon
    {
        return self::calendarDay($this->received_at);
    }

    /**
     * Whether the money was recorded on a different day from the one it
     * arrived.
     *
     * Not a defect — cash genuinely works this way — but a fact a treasurer
     * reconciling a week's takings needs to be able to see.
     *
     * THE KEYING DAY IS THE ESTATE'S (bug hunt B023). `entered_at` is the instant
     * the office pressed the button, and it used to be compared on the UTC
     * calendar: a payment received and keyed the same evening in Kingston was
     * marked "late entry", because by 7 PM there UTC had moved on a day.
     */
    public function enteredLate(): bool
    {
        return $this->receivedOn()->toDateString() !== SiteTime::local($this->entered_at)->toDateString();
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Journal, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_ref', 'reference');
    }
}
