<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One employee's payslip within one run — a row of board 15's table.
 *
 * EVERY FIGURE HERE IS COMPUTED, NOT TYPED. `PayrollCalculator` produces the
 * five deductions from gross and a rate version, in an order that is itself
 * load-bearing: NIS first because Education Tax and PAYE are both charged on
 * income after it. This model stores that result; it does not reproduce the
 * arithmetic, and nothing else in the application may either.
 *
 * `net_minor` IS STORED RATHER THAN DERIVED ON READ, and that is the one place
 * this module departs from the ledger's own rule that a balance is never kept
 * beside the entries it is made of. The reason is that a payslip is a statement
 * issued to a person on a date: recomputing it later against whatever rate
 * version is current would silently restate what somebody was told they earned.
 * The row is the record of what was actually paid. `EstatePayrollTest` asserts
 * gross less the four deductions equals it, so a stored figure that drifted
 * from its own components fails rather than standing.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property int $gross_minor
 * @property int $nis_minor
 * @property int $nht_minor
 * @property int $education_tax_minor
 * @property int $paye_minor
 * @property int $pension_minor
 * @property int $net_minor
 * @property int $employer_nis_minor
 * @property int $employer_nht_minor
 * @property int $employer_education_tax_minor
 * @property int $employer_heart_minor
 * @property string $currency
 * @property string|null $paye_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read PayrollRun $run
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 *
 * @mixin \Eloquent
 */
class PayrollRunLine extends Model
{
    protected $table = 'payroll_run_lines';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'gross_minor',
        'nis_minor',
        'nht_minor',
        'education_tax_minor',
        'paye_minor',
        'pension_minor',
        'net_minor',
        'employer_nis_minor',
        'employer_nht_minor',
        'employer_education_tax_minor',
        'employer_heart_minor',
        'currency',
        'paye_note',
    ];

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * What this payslip withheld in total.
     *
     * The four statutory deductions and nothing else. Board 15's "Total
     * deductions" is the sum of this across the run, and board 16's S01
     * remittance clears exactly the same figure out of the four payable control
     * accounts — so a fifth deduction added here without a matching payable
     * account would leave the remittance unable to reconcile.
     */
    public function deductionsMinor(): int
    {
        return $this->nis_minor + $this->nht_minor + $this->education_tax_minor + $this->paye_minor;
    }

    /**
     * What the estate owes on top of this person's gross — its own NIS, NHT,
     * Education Tax and HEART (Q-002, ruled).
     *
     * Kept apart from `deductionsMinor()` on purpose. Both are credited to 2100
     * and both are remitted on the S01, but only one of them came out of this
     * person's pay, and a payslip that summed the two would show them a
     * deduction they never suffered.
     */
    public function employerContributionsMinor(): int
    {
        return $this->employer_nis_minor + $this->employer_nht_minor
            + $this->employer_education_tax_minor + $this->employer_heart_minor;
    }
}
