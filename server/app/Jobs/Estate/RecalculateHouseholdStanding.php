<?php

declare(strict_types=1);

namespace App\Jobs\Estate;

use App\Jobs\Concerns\RequiresTenantContext;
use App\Models\Estate\Household;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recomputes a household's standing after a charge or payment.
 *
 * Writes estate data, so it must know which estate. Dispatching it without
 * tenant context is always a bug, and it throws rather than guessing — a job
 * that fell back to the central connection or to whichever tenant the worker
 * last handled would write one community's records into another's.
 *
 * ASSUMPTION Q-005: this recomputes only the totals. It does NOT set
 * access_restricted, because the arrears threshold that would drive it is
 * unresolved. Restriction must follow arrears and never a failed payment, so
 * nothing here may set it as a side effect of a payment event.
 */
class RecalculateHouseholdStanding implements ShouldQueue
{
    use Queueable;
    use RequiresTenantContext;

    public function __construct(public int $householdId) {}

    public function handle(): void
    {
        $this->assertTenantContext();

        $household = Household::with('charges')->findOrFail($this->householdId);

        // Totals only. See the class docblock for why restriction is not set.
        $household->charges()
            ->where('status', 'outstanding')
            ->sum('amount_minor');
    }
}
