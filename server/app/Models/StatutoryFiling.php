<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One statutory return owed to a Jamaican authority.
 *
 * APPEND-ONLY ONCE FILED (invariant 4). A filed return is the record of a
 * submission the authority also holds a copy of; correcting it here would put
 * the two copies out of step without leaving a trace. The correction is an
 * AMENDED RETURN, a new row for the same period, and never an edit.
 *
 * That rule is enforced three times over, deliberately: there is no update or
 * delete route in this module, the model refuses the save below, and the
 * database refuses it in a trigger. The first protects the screens, the second
 * protects the code, and the third protects against everything else.
 *
 * @property int $id
 * @property string $form_code
 * @property string $form_title
 * @property string $period_label
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $due_on
 * @property string $status
 * @property Carbon|null $filed_on
 * @property string|null $confirmation_reference
 * @property int|null $payroll_run_id
 * @property int|null $employees_covered
 * @property int|null $nis_minor
 * @property int|null $nht_minor
 * @property int|null $education_tax_minor
 * @property int|null $paye_minor
 * @property int|null $employer_nis_minor
 * @property int|null $employer_nht_minor
 * @property int|null $employer_education_tax_minor
 * @property int|null $heart_minor
 * @property int|null $total_minor
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PayrollRun|null $payrollRun
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryFiling newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryFiling newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryFiling query()
 *
 * @mixin \Eloquent
 */
class StatutoryFiling extends Model
{
    use CentralConnection;

    /** The return has not been prepared. Its period may not even have closed. */
    public const NOT_STARTED = 'not_started';

    /** The period has closed and the return is owed. */
    public const DUE = 'due';

    /** Submitted. From here the row never changes again. */
    public const FILED = 'filed';

    protected $fillable = [
        'form_code', 'form_title', 'period_label',
        'period_start', 'period_end', 'due_on',
        'status', 'filed_on', 'confirmation_reference',
        'payroll_run_id', 'employees_covered',
        'nis_minor', 'nht_minor', 'education_tax_minor', 'paye_minor',
        'employer_nis_minor', 'employer_nht_minor', 'employer_education_tax_minor', 'heart_minor',
        'total_minor',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_on' => 'date',
            'filed_on' => 'date',
        ];
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function isFiled(): bool
    {
        return $this->status === self::FILED;
    }

    /**
     * Whether the period this return covers has already closed.
     *
     * A return is not owed before its period ends, which is what separates the
     * annual employer return sitting quietly in the list from the monthly
     * remittance that is now overdue.
     */
    public function periodHasClosed(Carbon $today): bool
    {
        return $this->period_end->lte($today);
    }

    public function isOverdue(Carbon $today): bool
    {
        return ! $this->isFiled() && $this->due_on->lt($today);
    }

    protected static function booted(): void
    {
        static::updating(function (self $filing): void {
            if ($filing->getOriginal('status') === self::FILED) {
                throw new LogicException(
                    "Statutory return [{$filing->form_code} {$filing->period_label}] has been filed and cannot be "
                    .'edited. File an amended return for the same period instead.'
                );
            }
        });

        static::deleting(function (self $filing): void {
            if ($filing->getOriginal('status') === self::FILED) {
                throw new LogicException(
                    "Statutory return [{$filing->form_code} {$filing->period_label}] has been filed and cannot be "
                    .'deleted. The authority holds a copy of it.'
                );
            }
        });
    }
}
