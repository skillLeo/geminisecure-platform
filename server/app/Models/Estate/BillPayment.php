<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money paid out against a bill.
 *
 * The counterpart of `Payment`, and the same division of labour: the entry it
 * raised — Dr 2000 Accounts Payable, Cr the bank — is the money, and this row
 * carries the cheque number, the method and the date the estate's own paperwork
 * puts on it.
 *
 * PARTIAL PAYMENT IS ORDINARY, which is why a bill has many of these rather than
 * one. A large invoice settled in two instalments is two entries against the same
 * liability, and what remains outstanding is the balance of the bill's lines on
 * 2000 — never `amount_minor` less the sum of this table.
 *
 * `overpayment_reason` IS THE ONLY WAY PAST THE OUTSTANDING BALANCE. The Build
 * Spec: "A payment cannot exceed the bill without an explicit over-payment
 * reason." It is nullable here and `Payables::pay()` refuses the payment without
 * it, so an overpayment is always a sentence somebody wrote rather than a
 * silently larger number.
 *
 * @property int $id
 * @property int $bill_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $method
 * @property string|null $reference
 * @property Carbon $paid_on
 * @property int|null $paid_by
 * @property string|null $paid_by_name
 * @property string|null $overpayment_reason
 * @property string|null $journal_ref
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Money $amount
 * @property-read Bill $bill
 * @property-read Journal|null $entry
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillPayment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillPayment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillPayment query()
 *
 * @mixin \Eloquent
 */
class BillPayment extends Model
{
    public const CHEQUE = 'cheque';

    public const BANK = 'bank';

    public const CARD = 'card';

    public const CASH = 'cash';

    protected $fillable = [
        'bill_id',
        'amount_minor',
        'currency',
        'method',
        'reference',
        'paid_on',
        'paid_by',
        'paid_by_name',
        'overpayment_reason',
        'journal_ref',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'amount_minor' => 'integer',
            'paid_on' => 'date',
        ];
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * The entry raised: Dr 2000, Cr the bank.
     *
     * @return BelongsTo<Journal, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_ref', 'reference');
    }
}
