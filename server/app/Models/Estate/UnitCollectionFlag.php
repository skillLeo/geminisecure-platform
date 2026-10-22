<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hardship or dispute the committee has agreed about a household (12 §1).
 *
 * WHAT IT DOES: stops the estate's automated chasing. WHAT IT DOES NOT DO:
 * change what the household owes. The balance, the ageing and the arrears
 * board are exactly what they were — see the migration.
 *
 * @property int $id
 * @property int $unit_id
 * @property string $kind
 * @property string $reason
 * @property string $minute_reference
 * @property int|null $raised_by_id
 * @property string $raised_by_name
 * @property Carbon $raised_at
 * @property Carbon|null $lifted_at
 * @property string|null $lifted_by_name
 * @property string|null $lifted_reason
 * @property string|null $lifted_minute_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit $unit
 */
class UnitCollectionFlag extends Model
{
    public const HARDSHIP = 'hardship';

    public const DISPUTE = 'dispute';

    /**
     * The two, and what each one means to a treasurer.
     *
     * They are kept apart because they end differently: a hardship is lifted
     * when the household can pay again, and a dispute when somebody decides
     * who was right. Collapsing them into one "on hold" would lose that.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        self::HARDSHIP => 'Hardship · the household cannot pay at present',
        self::DISPUTE => 'Dispute · the household says the charge is wrong',
    ];

    protected $connection = 'tenant';

    protected $fillable = [
        'unit_id',
        'kind',
        'reason',
        'minute_reference',
        'raised_by_id',
        'raised_by_name',
        'raised_at',
        'lifted_at',
        'lifted_by_name',
        'lifted_reason',
        'lifted_minute_reference',
    ];

    protected function casts(): array
    {
        return [
            'raised_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function isInForce(): bool
    {
        return $this->lifted_at === null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** "Dispute, under Min. 2026-09-03 §4" — the head of the banner. */
    public function headline(): string
    {
        return ucfirst($this->kind).', under '.$this->minute_reference;
    }

    /** "Hardship" or "Dispute" — the arrears row's pill (13 A4). */
    public function shortLabel(): string
    {
        return ucfirst($this->kind);
    }
}
