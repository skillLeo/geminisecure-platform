<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A user's right to reach one estate, in one role.
 *
 * Central, like everything about identity. An estate database holds residents
 * and money; it never holds the answer to "may this person be here".
 *
 * @property int $id
 * @property int $user_id
 * @property string $tenant_id
 * @property int $role_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Role $role
 * @property-read Tenant|null $tenant
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereUserId($value)
 *
 * @mixin \Eloquent
 */
class EstateAssignment extends Model
{
    use CentralConnection;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'role_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
