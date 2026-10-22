<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One bank statement, set against one account — board 28.
 *
 * ONE ACCOUNT AND ONE STATEMENT, which is why `account_id` is here rather than
 * implied. An estate with an operating account and a reserve account reconciles
 * them separately against separate statements, and a reconciliation that did not
 * say which account it was for could not be checked at all. The unique key on
 * (account, statement date) says the same thing: a month is reconciled once.
 *
 * IT CANNOT BE COMPLETED WHILE A DIFFERENCE REMAINS. The difference is the
 * movement the bank reports — closing less opening — less every statement line
 * the estate has claimed as one of its own entries. Zero means the estate has
 * accounted for the whole month; anything else is money one side knows about and
 * the other does not, and signing that off is precisely the act a reconciliation
 * exists to prevent. `Payables::complete()` refuses it and names the figure.
 *
 * THE OPENING AND CLOSING BALANCES ARE THE BANK'S, NOT THE LEDGER'S, and that is
 * not a duplicate of anything: the whole exercise is comparing two independent
 * records of the same account, so transcribing what the bank said is the input,
 * not a cached copy of what the estate already knows.
 *
 * @property int $id
 * @property int $account_id
 * @property Carbon $statement_date
 * @property int $opening_minor
 * @property int $closing_minor
 * @property string $currency
 * @property string $status
 * @property Carbon|null $completed_at
 * @property int|null $reconciled_by
 * @property string|null $reconciled_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Money $opening
 * @property-read Account $account
 * @property-read Collection<int, BankStatementLine> $lines
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BankReconciliation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BankReconciliation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BankReconciliation query()
 *
 * @mixin \Eloquent
 */
class BankReconciliation extends Model
{
    public const OPEN = 'open';

    public const COMPLETED = 'completed';

    protected $fillable = [
        'account_id',
        'statement_date',
        'opening_minor',
        'closing_minor',
        'currency',
        'status',
        'completed_at',
        'reconciled_by',
        'reconciled_by_name',
    ];

    protected function casts(): array
    {
        return [
            'opening' => MoneyCast::class.':opening_minor,currency',
            'opening_minor' => 'integer',
            'closing_minor' => 'integer',
            'statement_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /**
     * What the bank says happened over the period, signed.
     *
     * Money in is positive and money out is negative, because that is how a
     * statement reads. Re-expressing it as debits and credits would be
     * interpreting it before anything has been matched.
     */
    public function movementMinor(): int
    {
        return $this->closing_minor - $this->opening_minor;
    }

    /**
     * The statement's own period, as the board heads its column — "August".
     *
     * Derived from the statement date rather than stored: a period label kept
     * beside a date is a second thing that can disagree with it.
     */
    public function periodLabel(): string
    {
        return $this->statement_date->format('F');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The statement's lines, in the order board 28 draws them: everything
     * matched first, then what is still outstanding, oldest first within each.
     * A treasurer opens this screen to find what is left to do.
     *
     * @return HasMany<BankStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)
            ->orderByRaw('matched_entry_ref IS NULL')
            ->orderBy('value_date')
            ->orderBy('id');
    }
}
