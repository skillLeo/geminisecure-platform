<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $price_per_unit_minor
 * @property string $currency
 * @property int $min_units
 * @property bool $is_active
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Money $price_per_unit
 * @property-read Collection<int, Subscription> $subscriptions
 * @property-read int|null $subscriptions_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereMinUnits($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan wherePricePerUnitMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereSort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Plan extends Model
{
    use CentralConnection;

    protected $fillable = [
        'key', 'name', 'description', 'highlights', 'price_per_unit_minor',
        'currency', 'min_units', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price_per_unit' => MoneyCast::class.':price_per_unit_minor,currency',
            'is_active' => 'boolean',

            // An ordered list of short lines. The order is the argument — a
            // higher tier opens with "Everything in <the tier below>".
            'highlights' => 'array',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
