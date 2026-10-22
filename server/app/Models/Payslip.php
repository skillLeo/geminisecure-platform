<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $payroll_run_id
 * @property int $guard_id
 * @property string|null $tenant_id
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
 * @property-read Guard $employee
 * @property-read Tenant|null $estate
 * @property-read PayrollRun $run
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereEducationTaxMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereGrossMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereGuardId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereNetMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereNhtMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereNisMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip wherePayeMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip wherePayeNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip wherePayrollRunId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Payslip extends Model
{
    use CentralConnection;

    protected $fillable = [
        'payroll_run_id', 'guard_id', 'tenant_id',

        // The hours, the rates and the earnings the gross is made of, and what each statutory
        // deduction was taken ON (M8 §5.3). Every one is nullable: a slip written before this
        // existed cannot have its hours invented afterwards, and the screen says so instead.
        'regular_hours', 'overtime_hours', 'regular_rate_minor', 'overtime_rate_minor',
        'basic_minor', 'overtime_minor', 'allowances_minor',
        'nis_base_minor', 'nht_base_minor', 'education_tax_base_minor', 'paye_base_minor', 'paye_threshold_minor',

        'gross_minor', 'nis_minor', 'nht_minor',
        'education_tax_minor', 'paye_minor', 'pension_minor', 'net_minor',

        // The employer's own contributions (Q-002, ruled). On the S01; never on
        // the guard's slip as a deduction, because none of it came out of their pay.
        'employer_nis_minor', 'employer_nht_minor', 'employer_education_tax_minor', 'employer_heart_minor',

        'currency', 'paye_note',
    ];

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** Not named guard(): Model::guard(array) already exists in Eloquent. */
    /** @return BelongsTo<Guard, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
