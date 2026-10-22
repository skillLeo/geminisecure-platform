<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A GUARD THIS ALERT HAS RUNG — once each, from the round they were first rung.
 *
 * THIS IS WHO MAY ANSWER. `PanicFanOut::guardsFor()` unions these with the officers on
 * duty and rostered today, so an off-duty officer rung at the escalation can open the
 * alert and say "on my way" (§3.3). A guard who said "can't respond" for the whole alert
 * keeps their row — they were rung — with `answer = cannot`, and is left out of later
 * rounds. Central, and the only way the table is touched (G5).
 *
 * @property int $id
 * @property int $duress_alert_id
 * @property string $tenant_id
 * @property int $guard_id
 * @property string $audience on_shift or estate
 * @property int $first_round
 * @property string|null $answer cannot
 * @property Carbon|null $answered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guard|null $officer
 *
 * @mixin \Eloquent
 */
class DuressAlertRingTarget extends Model
{
    use CentralConnection;

    /** Rung because they were on duty or rostered here today. */
    public const ON_SHIFT = 'on_shift';

    /** Rung at the escalation: an active officer of the estate, off duty too. */
    public const ESTATE = 'estate';

    public const CANNOT = 'cannot';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'guard_id' => 'integer',
            'first_round' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * Not named `guard()`: `Model::guard(array)` is mass-assignment's, and a relation of that
     * name is a fatal signature clash (the reason `DuressAlert::raisedByGuard` has its name).
     *
     * @return BelongsTo<Guard, $this>
     */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }
}
