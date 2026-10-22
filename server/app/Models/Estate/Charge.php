<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge raised against a household.
 *
 * Lives only in gs_estate_<subdomain>. There is no tenant_id column: the
 * database boundary is the tenant boundary, and a second source of truth
 * could only ever disagree with the first.
 *
 * No model in this namespace declares a connection. Under
 * DatabaseTenancyBootstrapper the default connection IS the current estate,
 * so a query with no tenant context fails to resolve rather than silently
 * reading central data.
 *
 * @property Money $amount
 * @property \Illuminate\Support\Carbon $due_on
 * @property-read Household|null $household
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Charge newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Charge newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Charge query()
 *
 * @mixin \Eloquent
 */
class Charge extends Model
{
    protected $fillable = [
        'unit_id',
        'type',
        'period',
        'account_id',
        'journal_ref',
        'posted_by',
        'posted_by_name',
        'reference',
        'description',
        'amount_minor',
        'currency',
        'due_on',
        'status',

        // The whole-phase or whole-estate preview this charge was posted from,
        // unique per unit, so one preview bills a unit once (bug hunt B020).
        'bulk_token',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'due_on' => 'date',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
