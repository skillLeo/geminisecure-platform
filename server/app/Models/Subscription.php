<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An estate's subscription to GeminiSecure.
 *
 * `dunning` and `suspended` gate BILLING features only. Neither ever restricts
 * entry, a safety function, or a resident. Access is never withheld over a
 * billing dispute.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $plan_id
 * @property int $unit_count
 * @property string $status
 * @property Carbon|null $started_on
 * @property Carbon|null $renews_on
 * @property int|null $term_months
 * @property Carbon|null $contract_renewal_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 * @property-read Plan $plan
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription wherePlanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereRenewsOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStartedOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereUnitCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Subscription extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id', 'plan_id', 'unit_count', 'contracted_guards',
        'status', 'started_on', 'renews_on', 'term_months', 'contract_renewal_on',
    ];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'renews_on' => 'date', 'contract_renewal_on' => 'date'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Monthly recurring revenue in minor units. */
    public function mrrMinor(): int
    {
        return $this->unit_count * $this->plan->price_per_unit_minor;
    }
}
