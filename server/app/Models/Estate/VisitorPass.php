<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A signed visitor pass (13 D1). See `App\Services\Passes\VisitorPasses`.
 *
 * @property int $id
 * @property string $pass_id
 * @property int $unit_id
 * @property int|null $household_id
 * @property int|null $issued_by_resident_id
 * @property string $issued_by_name
 * @property string $category
 * @property string $visitor_name
 * @property string|null $visitor_phone
 * @property string|null $purpose
 * @property string|null $vehicle_plate
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property bool $single_use
 * @property string $nonce
 * @property int $key_version
 * @property string $token
 * @property string $code
 * @property string $status
 * @property Carbon|null $used_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $last_admitted_at the latest time a visitor was let in on this pass, any kind (M11 §2, D-169)
 * @property string|null $last_admitted_post the gate it happened at, by the post's own name (M47 §5.4)
 * @property string|null $last_admitted_by the guard who let them in, by name (M47 §5.4)
 * @property int $share_count
 * @property string|null $idempotency_key
 * @property bool $is_simulated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit $unit
 */
class VisitorPass extends Model
{
    public const ACTIVE = 'active';

    public const USED = 'used';

    public const CANCELLED = 'cancelled';

    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'single_use' => 'boolean',
            'key_version' => 'integer',
            'used_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_admitted_at' => 'datetime',
            'share_count' => 'integer',
            'is_simulated' => 'boolean',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
