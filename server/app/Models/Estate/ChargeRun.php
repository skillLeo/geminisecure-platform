<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One month of a charge schedule, posted — and, if it was wrong, reversed.
 *
 * NEVER DELETED. A reversal posts the mirror entry and is recorded here, so the
 * month reads as what happened: posted, then reversed, by whom and why.
 *
 * @property int $id
 * @property int $charge_schedule_id
 * @property string $period
 * @property Carbon $due_on
 * @property int $unit_count
 * @property int $total_minor
 * @property string $currency
 * @property string $journal_ref
 * @property string|null $reversal_journal_ref
 * @property int|null $posted_by
 * @property string|null $posted_by_name
 * @property Carbon $posted_at
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversed_by_name
 * @property string|null $reversal_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChargeSchedule $schedule
 */
class ChargeRun extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'charge_schedule_id',
        'period',
        'due_on',
        'unit_count',
        'total_minor',
        'currency',
        'journal_ref',
        'reversal_journal_ref',
        'posted_by',
        'posted_by_name',
        'posted_at',
        'reversed_at',
        'reversed_by',
        'reversed_by_name',
        'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
            'unit_count' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<ChargeSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ChargeSchedule::class, 'charge_schedule_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
