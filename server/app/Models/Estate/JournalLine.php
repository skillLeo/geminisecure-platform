<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One side of one journal entry. APPEND-ONLY.
 *
 * Exactly one of `debit_minor` and `credit_minor` is non-zero, and the database
 * refuses anything else with a CHECK constraint. A single signed column would
 * let a credit be written as a negative debit — the same arithmetic, a
 * different statement — and a trial balance printed from it would have no two
 * columns to compare.
 *
 * IT IS LINKED TO ITS ENTRY BY `entry_ref`, NOT BY A FOREIGN KEY, and the
 * migration explains at length why: the lines are written before the header, so
 * that the header's insert can count them and refuse an entry that does not
 * balance. A foreign key would demand the opposite order and make that check
 * impossible.
 *
 * The overrides below fail early and legibly in place of a raw SQLSTATE 45000
 * arriving from three layers down. They are not the guarantee — the triggers
 * are.
 *
 * @property int $id
 * @property string $entry_ref
 * @property int $account_id
 * @property int $line_no
 * @property int $debit_minor
 * @property int $credit_minor
 * @property string $currency
 * @property string|null $memo
 * @property int|null $unit_id
 * @property int|null $vendor_id
 * @property Carbon|null $created_at
 * @property-read Account $account
 * @property-read Journal|null $entry
 * @property-read Unit|null $unit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JournalLine newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JournalLine newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JournalLine query()
 *
 * @mixin \Eloquent
 */
class JournalLine extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'entry_ref',
        'account_id',
        'line_no',
        'debit_minor',
        'credit_minor',
        'currency',
        'memo',
        'unit_id',
        'vendor_id',
    ];

    protected function casts(): array
    {
        return [
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
        ];
    }

    public function isDebit(): bool
    {
        return $this->debit_minor > 0;
    }

    /** The amount, whichever side it is on. Always positive. */
    public function amount(): Money
    {
        return Money::ofMinor(max($this->debit_minor, $this->credit_minor), $this->currency);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The entry this line belongs to.
     *
     * Joined on the reference rather than an id, for the reason in the class
     * docblock. Nullable in principle — a line whose header failed to post is
     * rolled back with it, and `gate:ledger` proves none survives.
     *
     * @return BelongsTo<Journal, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'entry_ref', 'reference');
    }

    /**
     * The unit this line is owed by, where it is owed by one.
     *
     * The UNIT, not the household. Dues attach to the property: a vacant unit
     * still owes its maintenance, and a household that moves out does not take
     * the arrears with it.
     *
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $line) {
            throw new LogicException(
                "Journal line [{$line->entry_ref} #{$line->line_no}] is posted and cannot be edited. ".
                'Post a reversing entry instead. A database trigger enforces this too; '.
                'you are seeing the earlier of two layers.'
            );
        });

        static::deleting(function (self $line) {
            throw new LogicException(
                "Journal line [{$line->entry_ref} #{$line->line_no}] is posted and cannot be deleted."
            );
        });
    }
}
