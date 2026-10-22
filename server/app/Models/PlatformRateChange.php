<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One dated change to a platform rate — the per-guard add-on (M10 §3).
 *
 * The twin of a `plan_price_changes` row: what the rate was, what it becomes,
 * from when, why, and who said so. `platform_rates.amount_minor` stays as the
 * rate in force TODAY, because every projection reads it; what an invoice is
 * raised at is the rate in force on its period's first day, which
 * `PlatformPricing::rateMinorOn()` resolves from these rows.
 *
 * CENTRAL, like every pricing table. Under `DatabaseTenancyBootstrapper` the
 * default connection is the tenant's for the length of a tenant request, and
 * without the trait a change recorded there would land in an estate database
 * where no invoice run would ever read it.
 *
 * NEVER EDITED BY HAND. A pending change that turns out wrong is superseded by
 * another change for the same date, which the resolver and the sweep both take
 * as the later word — so the history keeps the mistake and its correction, and
 * `audit_log` holds both.
 *
 * @property int $id
 * @property int $platform_rate_id
 * @property int $from_minor
 * @property int $to_minor
 * @property string $currency
 * @property Carbon $effective_from
 * @property string $reason
 * @property int|null $changed_by_id
 * @property string $changed_by_name
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformRateChange newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformRateChange newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformRateChange query()
 *
 * @mixin \Eloquent
 */
class PlatformRateChange extends Model
{
    use CentralConnection;

    protected $fillable = [
        'platform_rate_id', 'from_minor', 'to_minor', 'currency', 'effective_from',
        'reason', 'changed_by_id', 'changed_by_name', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'from_minor' => 'integer',
            'to_minor' => 'integer',
            'effective_from' => 'date',
            'applied_at' => 'datetime',
        ];
    }
}
