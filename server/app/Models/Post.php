<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A guarded position at an estate: a gate, a patrol route, a relief slot.
 *
 * Central alongside guards, because rostering is a Gemini operation spanning
 * every client rather than something each estate manages for itself.
 *
 * A PATROL IS A DUTY, NOT A POST (M27, D-225). Phoenix Park's patrol is a vehicle across five phases that starts at
 * the Main Gate: its shift is posted at the Main Gate with `shifts.duty = patrol`, clocked in inside the Main Gate's
 * own geofence, and evidenced by checkpoint scans and the on duty position trail. A post of type `patrol` is the older
 * shape, kept for the estates that have one; no new one is offered.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $also_called the names the estate also uses for it, "Gate 5, Phase 5 Gate" (M26)
 * @property string $type
 * @property bool $is_active
 * @property float|null $latitude surveyed position (13 D2)
 * @property float|null $longitude
 * @property int $geofence_radius_m how close counts as on post
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 * @property-read Collection<int, Guard> $guards
 * @property-read int|null $guards_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Post extends Model
{
    use CentralConnection;

    public const GATE = 'gate';

    public const PATROL = 'patrol';

    /** The column's width: a post's other names are a few words, not a description. */
    public const ALSO_CALLED_MAX = 60;

    /** What a snapshot of the label may hold (`gate_events.post_name`, `gate_approval_requests.post_name`). */
    public const LABEL_MAX = 120;

    protected $fillable = [
        'tenant_id',
        'name',
        'also_called',
        'type',
        'is_active',
        'latitude',
        'longitude',
        'geofence_radius_m',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'geofence_radius_m' => 'integer',
        ];
    }

    /**
     * THE POST AS THE PEOPLE AT THE ESTATE KNOW IT (M26): "Back Gate (Gate 5, Phase 5 Gate)". A guard rostered to the
     * Back Gate who has only ever heard "Gate 5" must recognise the post, so every screen that names a post names it
     * this way: the roster, the Guard App's shift and clock-in screens, the clock-in's refusal, the gate log. A label
     * too long for the snapshot columns drops its other names rather than cutting a word.
     */
    public function label(): string
    {
        return self::labelFor((string) $this->name, $this->also_called);
    }

    /** `label()` for a row read without the model: a join's `posts.name` and `posts.also_called`. */
    public static function labelFor(?string $name, ?string $alsoCalled): string
    {
        $name = trim((string) $name);
        $also = trim((string) $alsoCalled);

        if ($also === '' || $name === '') {
            return $name;
        }

        $label = $name.' ('.$also.')';

        return mb_strlen($label) > self::LABEL_MAX ? $name : $label;
    }

    /**
     * `label()` in SQL, for a query that reads posts through a join: "name (also called)", or the name alone. The one
     * difference is the length cap, which only the snapshot columns need and which `labelFor()` keeps.
     */
    public static function labelSql(string $table = 'posts'): string
    {
        return "CASE WHEN {$table}.also_called IS NULL OR {$table}.also_called = '' THEN {$table}.name ELSE CONCAT({$table}.name, ' (', {$table}.also_called, ')') END";
    }

    /** The older shape of a patrol: a post of its own, with its own checkpoints (see the class note). */
    public function isPatrolRoute(): bool
    {
        return $this->type === self::PATROL;
    }

    /** Placed on the map: what a clock-in at it is judged against. */
    public function isPlaced(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return HasMany<Guard, $this> */
    public function guards(): HasMany
    {
        return $this->hasMany(Guard::class);
    }
}
