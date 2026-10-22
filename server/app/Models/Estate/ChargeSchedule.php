<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring charge the estate raises — board 5's "Charge schedule" (12 §2,
 * item 17). The standing decision, not a month of it; see `ChargeRun`.
 *
 * @property int $id
 * @property string $description
 * @property int $amount_minor
 * @property string $currency
 * @property string $account_code
 * @property string $scope
 * @property string|null $phase
 * @property int $due_day
 * @property bool $is_active
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ChargeRun> $runs
 */
class ChargeSchedule extends Model
{
    public const ESTATE = 'estate';

    public const PHASE = 'phase';

    protected $connection = 'tenant';

    protected $fillable = [
        'description',
        'amount_minor',
        'currency',
        'account_code',
        'scope',
        'phase',
        'due_day',
        'is_active',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'due_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ChargeRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ChargeRun::class);
    }

    /** "Whole estate", "Phase 2". */
    public function scopeLabel(): string
    {
        return $this->scope === self::PHASE ? (string) $this->phase : 'Whole estate';
    }
}
