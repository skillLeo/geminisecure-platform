<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of one bank statement, as the bank wrote it.
 *
 * A TRANSCRIPTION, NOT A POSTING. `amount_minor` is signed — money in positive,
 * money out negative — and deliberately unlike a journal line, because a
 * statement shows one column with a sign and re-expressing it as a debit or a
 * credit would be deciding what the line means before anybody has matched it.
 *
 * THE MATCH LIVES HERE AND NOTHING ON THE LEDGER SIDE IS TOUCHED. A posted entry
 * is immutable, so a reconciliation that wrote to one could not exist; and
 * unmatching has to be possible, because a treasurer will pair the wrong two
 * items and needs to be able to undo it without a reversing journal for a
 * bookkeeping event that never happened.
 *
 * `matched_entry_ref` HOLDS A REFERENCE, NOT A FOREIGN KEY, for the same reason
 * `journal_lines.entry_ref` does: `journals.reference` is what identifies an
 * entry everywhere else in this system, and the index on it is what makes "is
 * this entry already claimed by another line" a question the database can answer.
 *
 * @property int $id
 * @property int $bank_reconciliation_id
 * @property Carbon $value_date
 * @property string $description
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $bank_reference
 * @property string|null $matched_entry_ref
 * @property Carbon|null $matched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Money $amount
 * @property-read BankReconciliation $reconciliation
 * @property-read Journal|null $entry
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BankStatementLine newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BankStatementLine newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BankStatementLine query()
 *
 * @mixin \Eloquent
 */
class BankStatementLine extends Model
{
    protected $fillable = [
        'bank_reconciliation_id',
        'value_date',
        'description',
        'amount_minor',
        'currency',
        'bank_reference',
        'matched_entry_ref',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'amount_minor' => 'integer',
            'value_date' => 'date',
            'matched_at' => 'datetime',
        ];
    }

    public function isMatched(): bool
    {
        return $this->matched_entry_ref !== null;
    }

    /**
     * Whether the bank took money out on this line.
     *
     * Read off the sign, which is the only thing the statement actually said. A
     * withdrawal must pair with an entry that CREDITS the bank account, and a
     * deposit with one that debits it — so this is what stops a $6,500 payment
     * out being matched to a $6,500 receipt in.
     */
    public function isWithdrawal(): bool
    {
        return $this->amount_minor < 0;
    }

    /** @return BelongsTo<BankReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    /**
     * The entry this line was matched to, if any.
     *
     * @return BelongsTo<Journal, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'matched_entry_ref', 'reference');
    }
}
