<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One entry in the append-only audit log.
 *
 * The database enforces immutability with a withheld grant and a trigger; the
 * overrides below only fail earlier and more legibly than a raw SQLSTATE 45000
 * arriving from three layers down.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string|null $actor_role
 * @property string $action
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property array<array-key, mixed>|null $before
 * @property array<array-key, mixed>|null $after
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property-read User|null $actor
 * @property-read Tenant|null $estate
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereActorName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereActorRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereBefore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereUserAgent($value)
 *
 * @mixin \Eloquent
 */
class AuditEntry extends Model
{
    use CentralConnection;

    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'actor_name',
        'actor_role',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException(
                'audit_log is append-only and is not editable by anyone, including platform staff. '.
                'This is also enforced by a withheld grant and a database trigger.'
            );
        });

        static::deleting(function () {
            throw new LogicException('audit_log is append-only; entries are never deleted.');
        });
    }
}
