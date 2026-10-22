<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dated step of a payment plan.
 *
 * THE STATE IS RECORDED, NOT DERIVED, and the migration argues why: an
 * instalment is met by a DECISION as well as by an amount. A treasurer can
 * accept a short payment as meeting one — a household that pays J$3,050 of a
 * J$3,100 instalment has not walked away from the agreement — and no rule over
 * the payments table could express that without the treasurer being asked.
 *
 * WHICH MAKES `missed` THE MOST CONSEQUENTIAL FIELD IN THE MODULE. It is the
 * fact that defaults the plan, and defaulting the plan is what puts the arrears
 * restriction back on a household's guest passes. Nothing sets it by elapsed
 * time; `Collections::markMissed` is a deliberate act with a person behind it,
 * for the same reason restriction never follows a failed card.
 *
 * @property int $id
 * @property int $payment_plan_id
 * @property int $sequence
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $due_on
 * @property string $status
 * @property Carbon|null $settled_at
 * @property Money $amount
 * @property-read PaymentPlan $plan
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstalment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstalment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstalment query()
 *
 * @mixin \Eloquent
 */
class PaymentPlanInstalment extends Model
{
    /** Owing, and not yet late enough for anyone to have ruled on it. */
    public const DUE = 'due';

    /** Accepted as settled by a treasurer. */
    public const MET = 'met';

    /** Ruled missed. This is what defaults the plan. */
    public const MISSED = 'missed';

    protected $fillable = [
        'payment_plan_id',
        'sequence',
        'amount_minor',
        'currency',
        'due_on',
        'status',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'due_on' => 'date',
            'settled_at' => 'datetime',
            'amount_minor' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class, 'payment_plan_id');
    }

    /**
     * Past its date and still owing.
     *
     * NOT the same as missed, and the gap between them is the point. This is an
     * observation about the calendar; `missed` is a ruling, and only the ruling
     * reaches a gate. A plan does not default because a bank held a transfer
     * over a weekend.
     *
     * "Past" is on the estate's calendar (bug hunt B023): after 7 PM in Kingston
     * UTC's date is tomorrow, and an instalment due today there read as passed.
     */
    public function isOverdue(?Carbon $asAt = null): bool
    {
        return $this->status === self::DUE
            && $this->due_on->lessThan($asAt ?? Carbon::parse(SiteTime::today()->toDateString()));
    }
}
