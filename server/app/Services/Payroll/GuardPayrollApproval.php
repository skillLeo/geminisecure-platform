<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\User;
use DomainException;

/**
 * Approving one of Gemini Security's own guard pay runs — board super-admin-29.
 *
 * THERE WAS NO APPROVE ROUTE HERE BEFORE, DELIBERATELY. The statutory rates were
 * a draft (D-021), and a route that exists but is guarded is one refactor away
 * from being reachable. The client has ruled on Q-002 (D-082): the TAJ cards are
 * seeded with their published periodic figures and verified, and payroll is to
 * be unblocked. So the route now exists and this is the one door behind it.
 *
 * APPROVE, NOT DISBURSE. The board labels its control "Approve & disburse", and
 * this platform holds no ledger for Gemini's own books — moving the money is the
 * bank transfer Gemini makes. What this records is the decision, who took it,
 * and when; the run then reads "Approved" on board 28, and the statutory register
 * can prepare the returns owed on it.
 *
 * THE FIRST LIVE RUN CARRIES AN ACKNOWLEDGEMENT, Part F of the ruling. The rates
 * reconcile to the cent against TAJ's tables and nobody at the client has
 * countersigned them, so the first approval asks the approver to confirm the run
 * was reconciled against current TAJ tables, naming the card. Not a block — a
 * tick, recorded with who gave it — and never asked again once a run carries it.
 * The estates' payrolls ask the same question of their own first run
 * (`App\Services\Estate\Payroll`), because each is a different employer.
 *
 * NO SECOND-PERSON RULE HERE, and that is a difference from the estate payroll
 * worth stating. An estate run is PREPARED by a person — board 15 names Tracey
 * Reid — and the preparer may not approve it. A guard run is calculated by the
 * engine from the roster; nobody prepares it by hand, so there is no preparer to
 * exclude. The permission is the control.
 */
final class GuardPayrollApproval
{
    public function __construct(private readonly StatutoryRates $rates) {}

    /** Why this viewer may not approve this run, or null when they may. */
    public function refusal(PayrollRun $run, ?User $viewer): ?string
    {
        if (in_array($run->status, ['approved', 'paid'], true)) {
            return 'This run has already been approved. A pay run is approved once; a correction is a later run.';
        }

        if ($run->status !== 'calculated') {
            return 'This run has not been calculated yet, so there is nothing to approve.';
        }

        if ($viewer === null || ! $viewer->can('gemini.payroll_accounting.approve')) {
            return "Approving a guard pay run releases every guard's pay, so it needs Payroll & Accounting "
                .'approval, which this role does not hold.';
        }

        return $this->rates->approvalBlockedReason($run->rateVersion);
    }

    /**
     * Whether the next approval is Gemini's first live guard pay run.
     *
     * "Live" is a run approved on this platform with the acknowledgement on it.
     * Nothing else counts, so no history quietly spends the question.
     */
    public function needsReconciliationAcknowledgement(): bool
    {
        return ! PayrollRun::query()
            ->whereIn('status', ['approved', 'paid'])
            ->whereNotNull('reconciliation_acknowledged_at')
            ->exists();
    }

    /** The sentence the approver ticks, naming the card they reconciled against. */
    public function reconciliationStatement(PayrollRun $run): string
    {
        $version = $run->rateVersion;

        return sprintf(
            // A comma, never an em dash (Law 3; the payroll pass): it is the sentence the approver ticks.
            'I confirm this run has been reconciled against the current TAJ tables, rate card %s (%s).',
            $version->label,
            $version->effective_from->format('Y-m'),
        );
    }

    public function approve(PayrollRun $run, User $by, bool $reconciled = false): PayrollRun
    {
        $refusal = $this->refusal($run, $by);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        $acknowledging = $this->needsReconciliationAcknowledgement();

        if ($acknowledging && ! $reconciled) {
            throw new DomainException(
                "This is Gemini's first live guard pay run, so it needs the approver to confirm it has been "
                .'reconciled against the current TAJ tables. '.$this->reconciliationStatement($run)
            );
        }

        $version = $run->rateVersion;

        $run->forceFill([
            'status' => 'approved',
            'approved_by' => $by->getKey(),
            'approved_at' => now(),
            ...($acknowledging ? [
                'reconciliation_acknowledged_at' => now(),
                'reconciliation_acknowledged_by' => $by->getKey(),
                'reconciliation_rate_version' => $version->label.' ('.$version->effective_from->format('Y-m').')',
            ] : []),
        ])->save();

        return $run->refresh();
    }
}
