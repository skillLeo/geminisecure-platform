<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An agreement to clear an existing balance in instalments — board 7.
 *
 * A PLAN IS A LIVE THING A GATE DECISION DEPENDS ON, not a note on a file.
 * While an agreed plan is active and being met, the arrears restriction is
 * lifted for the household; missing an instalment defaults the plan and the
 * restriction comes back. `Collections::isProtected()` is what reads that, and
 * `RestrictionPolicy` is what asks.
 *
 * IT POSTS NOTHING. The receivable was recognised when the charges were raised,
 * and scheduling a debt does not change what is owed — so creating, activating
 * or defaulting a plan raises no journal entry at all. Only the instalment
 * payments do, and they post as ordinary receipts through `Dues::receive`.
 *
 * `total_minor` IS SNAPSHOTTED, and deliberately not recomputed. It is what the
 * household agreed to pay; the unit's balance moves on the moment anything else
 * is charged or received, and an agreement that silently restated itself would
 * be an agreement to nothing.
 *
 * @property int $id
 * @property int $unit_id
 * @property string $reference
 * @property int $total_minor
 * @property string $currency
 * @property int $instalments
 * @property Carbon $starts_on
 * @property string $status
 * @property Carbon|null $agreed_at
 * @property string|null $agreed_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property string|null $terms
 * @property Money $total
 * @property-read Unit $unit
 * @property-read Collection<int, PaymentPlanInstalment> $schedule
 * @property-read int|null $schedule_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlan query()
 *
 * @mixin \Eloquent
 */
class PaymentPlan extends Model
{
    /** Drawn up, not yet agreed to. Shields nothing. */
    public const DRAFT = 'draft';

    /** Agreed and approved. THIS is the state that lifts the restriction. */
    public const ACTIVE = 'active';

    /** Every instalment met. The debt it scheduled is gone. */
    public const COMPLETED = 'completed';

    /** An instalment was missed. The shield is off and does not come back. */
    public const DEFAULTED = 'defaulted';

    /** Withdrawn before it ran. */
    public const CANCELLED = 'cancelled';

    /**
     * Instalments fall monthly, and there is no other option.
     *
     * Board 7 draws a "Frequency" field offering more, but the schema records
     * only `starts_on` and a count — there is nowhere for a frequency to live,
     * and inventing one in application code would make the due dates on a
     * resident's statement unreproducible from the row. Monthly is also what
     * the estate bills, so a monthly instalment lands beside a monthly charge.
     */
    public const FREQUENCY = 'Monthly';

    protected $fillable = [
        'unit_id',
        'reference',
        'total_minor',
        'currency',
        'instalments',
        'starts_on',
        'status',
        'agreed_at',
        'agreed_by_name',
        'approved_by',
        'approved_by_name',
        'terms',
    ];

    protected function casts(): array
    {
        return [
            'total' => MoneyCast::class.':total_minor,currency',
            'starts_on' => 'date',
            'agreed_at' => 'datetime',
            'total_minor' => 'integer',
            'instalments' => 'integer',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The instalments, in the order they fall due.
     *
     * NAMED `schedule`, NOT `instalments`, because `instalments` is already a
     * column on this table — the agreed count. Eloquent resolves an attribute
     * before a relation, so `$plan->instalments` would keep returning the
     * integer while `$plan->instalments()` returned a query builder, and the
     * two would be silently different things with the same name.
     *
     * @return HasMany<PaymentPlanInstalment, $this>
     */
    public function schedule(): HasMany
    {
        return $this->hasMany(PaymentPlanInstalment::class)->orderBy('sequence');
    }

    /**
     * Has the household actually agreed to this?
     *
     * A plan nobody agreed to is a demand, and lifting a gate restriction on
     * the strength of one would be the estate deciding on the household's
     * behalf. `Collections::activate` refuses without this.
     */
    public function isAgreed(): bool
    {
        return $this->agreed_at !== null && trim((string) $this->agreed_by_name) !== '';
    }

    /** Is this the state that shields the household from restriction? */
    public function isCurrent(): bool
    {
        return $this->status === self::ACTIVE && $this->isAgreed();
    }

    /** The next instalment still owing, if the plan has one. */
    public function nextDue(): ?PaymentPlanInstalment
    {
        return $this->schedule()
            ->where('status', PaymentPlanInstalment::DUE)
            ->orderBy('sequence')
            ->first();
    }
}
