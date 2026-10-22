<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A manual journal while it is a draft (M84 §3.4). Edited until it posts; then it carries the journal's reference and is
 * never edited again, and the journal itself is append-only.
 *
 * @property int $id
 * @property Carbon $posted_on
 * @property string|null $reference
 * @property string $memo
 * @property list<array{account: string, debit_minor: int, credit_minor: int, unit: ?string}> $lines
 * @property int|null $bank_statement_line_id
 * @property string $status
 * @property string|null $journal_reference
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property int|null $posted_by
 * @property string|null $posted_by_name
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JournalDraft extends Model
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    protected $table = 'journal_drafts';

    protected $fillable = [
        'posted_on',
        'reference',
        'memo',
        'lines',
        'bank_statement_line_id',
        'status',
        'journal_reference',
        'created_by',
        'created_by_name',
        'posted_by',
        'posted_by_name',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_on' => 'date',
            'lines' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    /** Debits less credits: zero, or the journal cannot post. */
    public function differenceMinor(): int
    {
        return array_sum(array_map(static fn (array $l): int => (int) $l['debit_minor'] - (int) $l['credit_minor'], $this->lines ?? []));
    }

    /** Total debits, the figure the confirm names. */
    public function totalMinor(): int
    {
        return array_sum(array_map(static fn (array $l): int => (int) $l['debit_minor'], $this->lines ?? []));
    }
}
