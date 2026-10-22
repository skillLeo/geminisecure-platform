<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A decision a Gemini guard made at a gate.
 *
 * Central, and the table's own migration carries the whole argument for why
 * that is not an isolation breach: this is Gemini's account of work its own
 * employee did on a post it staffs, not the estate's record of who came to
 * whose house. It holds no household, no resident id, no money and no position.
 *
 * Only DECISIONS live here — an admit, a denial, an override. Checkpoint scans
 * and shift starts are already recorded centrally by the screens that own them,
 * and a second copy of an event is a second version of it.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $guard_id
 * @property string|null $guard_name
 * @property int|null $post_id
 * @property string|null $post_name
 * @property string $verdict
 * @property string|null $category
 * @property string $subject
 * @property string|null $basis
 * @property Carbon $occurred_at
 * @property Carbon|null $device_time
 * @property string|null $idempotency_key
 * @property bool $is_simulated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guard|null $officer
 * @property-read Post|null $post
 * @property-read Tenant|null $estate
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GateEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GateEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GateEvent query()
 *
 * @mixin \Eloquent
 */
class GateEvent extends Model
{
    use CentralConnection;

    public const ADMIT = 'admit';

    public const DENY = 'deny';

    /** A guard let somebody in against standing orders, and said why. */
    public const OVERRIDE = 'override';

    /** Somebody left through the gate (`POST /gate/exit`) — neither an admission nor a refusal. */
    public const EXIT = 'exit';

    protected $fillable = [
        'tenant_id',
        'guard_id',
        'guard_name',
        'post_id',
        'post_name',
        'verdict',
        'category',
        'subject',
        'basis',
        'occurred_at',
        'device_time',
        'idempotency_key',
        'is_simulated',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'device_time' => 'datetime',
            'is_simulated' => 'boolean',
        ];
    }

    /**
     * The guard who decided.
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

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
