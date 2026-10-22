<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\SiteDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Something that went wrong on a Gemini-staffed post.
 *
 * The record a security company is judged on: by an insurer after a claim, by a
 * client deciding whether to renew, and by the PSRA. Central, because the
 * incidents are on posts Gemini staffs and involve guards it employs.
 *
 * A DURESS ALERT IS NOT AN INCIDENT and never becomes one of these. It is a
 * life-safety path with its own table, its own broadcast channel and its own
 * response screen; folding the two together would put a panic button behind a
 * case-management workflow.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $guard_id
 * @property string|null $guard_name
 * @property string $kind
 * @property string|null $detail
 * @property string $severity
 * @property string $status
 * @property Carbon $occurred_at
 * @property string|null $resolution
 * @property Carbon|null $closed_at
 * @property int|null $logged_by
 * @property string|null $logged_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guard|null $officer
 * @property-read Tenant|null $estate
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SecurityIncident newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SecurityIncident newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SecurityIncident query()
 *
 * @mixin \Eloquent
 */
class SecurityIncident extends Model
{
    use CentralConnection;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $fillable = [
        'tenant_id',
        'guard_id',
        'guard_name',
        'kind',
        'detail',
        'severity',
        'status',
        'occurred_at',
        'resolution',
        'closed_at',
        'logged_by',
        'logged_by_name',
    ];

    /**
     * ONE TIME RULE (M4 §3.1). The console types "when it happened" on the estate's clock and a
     * guard's phone sends a moment; both are stored true UTC and read back on the clock of the
     * estate the incident is at. Send them to a phone through `SiteTime::iso()`.
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => SiteDateTime::class.':tenant_id',
            'closed_at' => SiteDateTime::class.':tenant_id',
        ];
    }

    /**
     * The estate first — a wall-clock string is read in the zone of `tenant_id` (see `Shift::fill`).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes)
    {
        if (array_key_exists('tenant_id', $attributes)) {
            $attributes = ['tenant_id' => $attributes['tenant_id']] + $attributes;
        }

        return parent::fill($attributes);
    }

    /**
     * Whether this is closed out.
     *
     * Derived from the resolution rather than trusted from the status column.
     * An incident marked resolved with no account of what was done reads the
     * same as an open one to anybody who comes to it later — an insurer, a
     * client, the PSRA — so it is not closed.
     */
    public function isSettled(): bool
    {
        return $this->status === self::RESOLVED
            && $this->resolution !== null
            && trim($this->resolution) !== '';
    }

    /**
     * The guard on post when it happened.
     *
     * `officer`, not `guard`: Eloquent's own `Model::guard(array $guarded)`
     * already occupies that name.
     *
     * @return BelongsTo<Guard, $this>
     */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
