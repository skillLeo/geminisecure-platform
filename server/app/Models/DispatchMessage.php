<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A message between dispatch and the guards on the ground.
 *
 * THREE directions: a broadcast to every guard at an estate, one guard writing
 * back to dispatch, and dispatch writing to ONE named guard. Guard-to-guard is
 * deliberately absent — the console is not a chat product, and a channel
 * dispatch cannot see is a channel that cannot be audited after an incident.
 *
 * `outbound` was added to the column by the Guard App migration (13 D2) and the
 * handset has served it since: `MessagesController::index()` returns every
 * broadcast plus anything carrying this guard's id, and counts it unread. What
 * was missing was any way to WRITE one — so the alert screen's message box sat
 * disabled saying "messaging opens when the Guard App ships", which had stopped
 * being true twice over: the app shipped, and the model it said did not exist
 * was already here.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $direction
 * @property int|null $guard_id
 * @property int|null $sent_by
 * @property string $body
 * @property Carbon $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guard|null $officer
 * @property-read Tenant|null $estate
 * @property-read User|null $sender
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DispatchMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DispatchMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DispatchMessage query()
 *
 * @mixin \Eloquent
 */
class DispatchMessage extends Model
{
    use CentralConnection;

    public const BROADCAST = 'broadcast';

    public const INBOUND = 'inbound';

    /**
     * Dispatch to one named guard.
     *
     * Carries `guard_id`, which is what makes it reach that handset and nobody
     * else's: the guard's own read is `direction = broadcast OR guard_id = me`.
     */
    public const OUTBOUND = 'outbound';

    protected $fillable = [
        'tenant_id',
        'direction',
        'guard_id',
        'sent_by',
        'body',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /**
     * The guard on the other end, on an inbound message.
     *
     * `officer`, not `guard`: Eloquent's `Model::guard(array $guarded)` already
     * occupies that name and a relation would clash with its signature.
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

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
