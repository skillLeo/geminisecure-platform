<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Something a guard has asked dispatch for: leave, equipment, a swap.
 *
 * Raised from the Guard App and decided in the Gemini Console's requests inbox.
 * The decision is part of the record — who made it, when, and on what note —
 * because the requester is shown the outcome and has to be able to see who
 * refused them.
 *
 * NO AMOUNT APPEARS HERE. A raincoat is a quantity and a reason; what it costs
 * is procurement's problem, on a screen a dispatcher never opens.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $guard_id
 * @property string $kind
 * @property string $subject
 * @property int|null $quantity
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property string|null $reason
 * @property bool $certificate_attached
 * @property string $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guard|null $officer
 * @property-read Tenant|null $estate
 * @property-read User|null $decidedBy
 *
 * @method static Builder<static>|GuardRequest pending()
 * @method static Builder<static>|GuardRequest newModelQuery()
 * @method static Builder<static>|GuardRequest newQuery()
 * @method static Builder<static>|GuardRequest query()
 *
 * @mixin \Eloquent
 */
class GuardRequest extends Model
{
    use CentralConnection;

    /** The groups the inbox draws, in the order it draws them. */
    public const LEAVE = 'leave';

    public const EQUIPMENT = 'equipment';

    protected $fillable = [
        'tenant_id',
        'guard_id',
        'kind',
        'subject',
        'quantity',
        'starts_on',
        'ends_on',
        'reason',
        'certificate_attached',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'certificate_attached' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * The guard who asked.
     *
     * `officer`, not `guard`: Eloquent's own `Model::guard(array $guarded)` is
     * already on this class and a relation of the same name is a fatal
     * signature clash. The foreign key is still guard_id.
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
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Calendar days requested, inclusive of both ends.
     *
     * Inclusive because a guard asking for the 15th to the 22nd is asking for
     * eight days off, not seven. Null for a request that is not about dates.
     */
    public function days(): ?int
    {
        if ($this->starts_on === null || $this->ends_on === null) {
            return null;
        }

        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /** The pill label and the board class that colours it. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'approved' => 'Approved',
            'denied' => 'Denied',
            'info_requested' => 'Info requested',
            default => 'Pending',
        };
    }
}
