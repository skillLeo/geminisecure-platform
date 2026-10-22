<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something that stops a run being calculated — board 14's two rows.
 *
 * AN EXCEPTION IS RESOLVED, NEVER DELETED. Board 14 offers "Exclude" beside
 * "Enter manually" and "Approve as-is" beside "Review hours", and all four are
 * resolutions rather than dismissals: each one is a decision somebody made
 * about a person's pay, and the reason a technician's 22 overtime hours went
 * through unchallenged is exactly what an auditor asks about six months later.
 * So `status` moves and `resolution_note`, `resolved_by` and `resolved_at`
 * record who decided what — the row stays.
 *
 * `EXCLUDED` IS NOT `RESOLVED`, and the difference is a person's wages. An
 * excluded employee is left OUT of the run entirely: no payslip, no gross, no
 * net. That is why board 13's May 2026 run shows three employees against the
 * other months' four, and why its totals differ by exactly one person's figures
 * rather than by a rounding. A run that treated exclusion as resolution would
 * pay somebody nothing and report itself complete.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property string $type
 * @property string $detail
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property int|null $overtime_hours
 * @property int|null $overtime_baseline_hours
 * @property string $status
 * @property string|null $resolution_note
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolved_by_name
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
class PayrollException extends Model
{
    protected $table = 'payroll_run_exceptions';

    /** Board 14's two kinds, and the only two anything raises today. */
    public const MISSING_TIMESHEET = 'missing_timesheet';

    public const OVERTIME_ANOMALY = 'overtime_anomaly';

    public const UNRESOLVED = 'unresolved';

    /** Dealt with, and the employee stays in the run. */
    public const RESOLVED = 'resolved';

    /** Dealt with by leaving the employee OUT of the run. Not the same thing. */
    public const EXCLUDED = 'excluded';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'type',
        'detail',
        'period_start',
        'period_end',
        // For an overtime anomaly: the hours logged, and the usual hours they are measured against (EST-B-07).
        'overtime_hours',
        'overtime_baseline_hours',
        'status',
        'resolution_note',
        'resolved_at',
        'resolved_by',
        'resolved_by_name',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'overtime_hours' => 'integer',
            'overtime_baseline_hours' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

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

    /** Does this row still stop the run proceeding to calculation? */
    public function blocksCalculation(): bool
    {
        return $this->status === self::UNRESOLVED;
    }
}
