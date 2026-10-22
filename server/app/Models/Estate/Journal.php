<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use App\Models\User;
use App\Services\Estate\Ledger;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A posted journal entry. APPEND-ONLY.
 *
 * Enforced at the database in two independent layers — a withheld grant and a
 * BEFORE UPDATE/DELETE trigger — so this class does not carry the guarantee.
 * The overrides below exist to fail early and legibly, with an explanation, in
 * place of a raw SQLSTATE 45000 surfacing from three layers down.
 *
 * A correction is a new entry referencing the original, via reverse().
 *
 * @property int $id
 * @property string $reference
 * @property string $memo
 * @property string $source
 * @property int|null $source_id
 * @property int $amount_minor
 * @property string $currency
 * @property Money $amount
 * @property Carbon $posted_on
 * @property int|null $posted_by
 * @property string|null $posted_by_name
 * @property int|null $reverses_journal_id
 * @property Carbon|null $created_at
 * @property-read Journal|null $reverses
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Journal newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Journal newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Journal query()
 *
 * @mixin \Eloquent
 */
class Journal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'reference',
        'memo',
        'source',
        'source_id',
        'amount_minor',
        'currency',
        'posted_on',
        'posted_by',
        'posted_by_name',
        'reverses_journal_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'posted_on' => 'date',
        ];
    }

    /**
     * The two or more sides of this entry.
     *
     * Joined on the reference rather than an id. The lines are written BEFORE
     * this header, so that the header's insert can count them and refuse an
     * entry whose debits and credits differ — a foreign key would demand the
     * opposite order and make that check impossible. The ledger migration
     * carries the full argument.
     *
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_ref', 'reference')->orderBy('line_no');
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    /** @return HasMany<self, $this> */
    public function reversedBy(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_journal_id');
    }

    /** Whether a reversing entry has already been posted against this one. */
    public function isReversed(): bool
    {
        return $this->reversedBy()->exists();
    }

    /**
     * Post the reversing entry that corrects this one.
     *
     * The only way to undo a posted journal. Both entries remain, and the pair
     * is the audit trail.
     *
     * DELEGATED TO THE LEDGER, and it has to be: a reversal is not a negative
     * amount on a header, it is every line of the original with its debit and
     * credit swapped. The old implementation negated `amount_minor` alone,
     * which was the only thing a single-sided journal could mean and is not
     * bookkeeping.
     */
    public function reverse(string $memo, ?User $by = null): self
    {
        return app(Ledger::class)->reverse($this, $memo, $by);
    }

    protected static function booted(): void
    {
        static::updating(function (self $journal) {
            throw new LogicException(
                "Journal [{$journal->reference}] is posted and cannot be edited. ".
                'Post a reversing entry with reverse() instead. This is also enforced by '.
                'a revoked grant and a database trigger; you are seeing the earliest of three layers.'
            );
        });

        static::deleting(function (self $journal) {
            throw new LogicException(
                "Journal [{$journal->reference}] is posted and cannot be deleted."
            );
        });
    }
}
