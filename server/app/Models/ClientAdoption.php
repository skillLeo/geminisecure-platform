<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One client's take-up of one capability.
 *
 * The roll-up the client health report reads instead of opening an estate
 * database. See the table's migration for who writes what and why.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $module_key
 * @property string $label
 * @property int $adopted
 * @property int $eligible
 * @property string|null $detail
 * @property string $reported_by
 * @property Carbon $measured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientAdoption newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientAdoption newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientAdoption query()
 *
 * @mixin \Eloquent
 */
class ClientAdoption extends Model
{
    use CentralConnection;

    /** Gemini operates this capability at the estate and counts it centrally. */
    public const BY_GEMINI = 'gemini';

    /** The estate operates it and rolls its own count up to here. */
    public const BY_ESTATE = 'estate';

    protected $table = 'client_adoption';

    protected $fillable = [
        'tenant_id',
        'module_key',
        'label',
        'adopted',
        'eligible',
        'detail',
        'reported_by',
        'measured_at',
    ];

    protected function casts(): array
    {
        return [
            'adopted' => 'integer',
            'eligible' => 'integer',
            'measured_at' => 'datetime',
        ];
    }

    /**
     * Whether there is anything here to draw a bar from.
     *
     * A client with no posts yet has nothing to adopt, and nought out of
     * nought is not nought per cent — it is a question that does not apply.
     * Reporting it as 0% would put a client at the bottom of a health list for
     * having done nothing wrong.
     */
    public function isMeasurable(): bool
    {
        return $this->eligible > 0;
    }

    /** Whole per cent, rounded, never above 100. */
    public function percentage(): int
    {
        if (! $this->isMeasurable()) {
            return 0;
        }

        return (int) min(100, round($this->adopted / $this->eligible * 100));
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
