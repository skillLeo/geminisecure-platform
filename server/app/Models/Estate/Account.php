<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One account in the estate's chart of accounts.
 *
 * Every journal line posts against one of these. The chart is a tree ordered by
 * code, and the code is what an accountant navigates by.
 *
 * NO BALANCE IS STORED HERE. An account's balance is the sum of its posted
 * lines, computed on read by `Ledger`. A stored balance is a second copy of the
 * ledger that is free to drift from it — and the copy is the one a committee
 * reads, so the drift is invisible until an auditor arrives.
 *
 * ARCHIVED, NEVER DELETED once anything is posted to it. The Build Spec is
 * explicit, and the database enforces it with a restricting foreign key on
 * `journal_lines.account_id` rather than leaving it to a check somebody has to
 * remember.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property int|null $parent_id
 * @property bool $is_control
 * @property string|null $subsidiary
 * @property bool $is_bank_account
 * @property bool $is_reserve_fund the account the chart's "Reserve fund" tile reads — marked, never inferred (bug hunt B035)
 * @property bool $is_active
 * @property Carbon|null $archived_at
 * @property-read Account|null $parent
 * @property-read Collection<int, Account> $children
 * @property-read Collection<int, JournalLine> $lines
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account query()
 *
 * @mixin \Eloquent
 */
class Account extends Model
{
    public const ASSET = 'asset';

    public const LIABILITY = 'liability';

    public const EQUITY = 'equity';

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    /**
     * The sub-ledgers a control account can tie to.
     *
     * Named here rather than as a list of account codes, because every estate
     * numbers its chart differently and a hardcoded "1100 is receivables" would
     * be wrong the first time one of them renumbers.
     */
    /**
     * The UNIT, not the household. Dues attach to the property: a vacant unit
     * still owes its maintenance, and there are nine of them at Phoenix Park.
     */
    public const SUBSIDIARY_UNITS = 'units';

    public const SUBSIDIARY_VENDORS = 'vendors';

    protected $fillable = [
        'code',
        'name',
        'type',
        'parent_id',
        'is_control',
        'subsidiary',
        'is_bank_account',
        'is_reserve_fund',
        'is_active',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_control' => 'boolean',
            'is_bank_account' => 'boolean',
            'is_reserve_fund' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Which side of the account increases it.
     *
     * DERIVED FROM `type`, never stored. Assets and expenses increase on the
     * debit side; liabilities, equity and income on the credit side. That is
     * arithmetic, not configuration, and a stored column could contradict the
     * type sitting next to it.
     */
    public function normalBalance(): string
    {
        return in_array($this->type, [self::ASSET, self::EXPENSE], true) ? 'debit' : 'credit';
    }

    /**
     * The signed balance in minor units, given raw debit and credit totals.
     *
     * A receivables account with 100 debited and 40 credited holds 60, and a
     * liability with 40 debited and 100 credited also holds 60 — positive in
     * both cases, because "the balance" means the amount on the side the
     * account normally sits on. Printing a liability as negative because the
     * arithmetic ran the other way is how a balance sheet ends up unreadable.
     */
    public function signedMinor(int $debitMinor, int $creditMinor): int
    {
        return $this->normalBalance() === 'debit'
            ? $debitMinor - $creditMinor
            : $creditMinor - $debitMinor;
    }

    public function balanceFrom(int $debitMinor, int $creditMinor, string $currency = 'JMD'): Money
    {
        return Money::ofMinor($this->signedMinor($debitMinor, $creditMinor), $currency);
    }

    /**
     * How an account reads in a list to pick from: its code, a middot and its name, "1200 · Accounts Receivable"
     * (M62 B4, Law 3). The one place every screen's list of accounts takes it from.
     */
    public function optionLabel(): string
    {
        return $this->code.' · '.$this->name;
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Whether anything has ever been posted against this account. */
    public function hasHistory(): bool
    {
        return $this->lines()->exists();
    }

    /**
     * Take the account out of use without destroying what was posted to it.
     *
     * The only way an account leaves the chart. `delete()` is refused below
     * once there is history, and the database refuses it too.
     */
    public function archive(): void
    {
        $this->forceFill(['is_active' => false, 'archived_at' => now()])->save();
    }

    protected static function booted(): void
    {
        static::deleting(function (self $account) {
            if ($account->hasHistory()) {
                throw new LogicException(
                    "Account [{$account->code} {$account->name}] has posted journal lines and cannot be deleted. ".
                    'Archive it instead — deleting it would orphan every entry ever posted to it. '.
                    'A restricting foreign key enforces this at the database too; you are seeing the earlier layer.'
                );
            }
        });
    }
}
