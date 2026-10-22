<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A return the estate owes the revenue authority — board 16's five rows.
 *
 * NOT `App\Models\StatutoryFiling`. That class is central and is Gemini's own
 * filings for its guards; this one is the estate filing for its own staff, in
 * the estate's own database, owed by a different employer under a different
 * TRN. Two employers, two obligations, two rows — and a screen that merged them
 * would show a treasurer a remittance somebody else has to pay.
 *
 * EVERY ROW IS A CLEARING EVENT AGAINST A CONTROL ACCOUNT, which is why the
 * four deduction columns are on it. Board 16 prints no figures at all, but the
 * S01 for August 2026 is exactly what returns the four statutory payables to
 * nil for that month: NIS 13,200 + NHT 8,800 + Education Tax 9,603 + PAYE
 * 85,392 = 116,995, which is the same "Total deductions" board 15 prints for
 * the run this row points at. Storing the four separately rather than only the
 * total is what lets that tie be asserted per account instead of in aggregate —
 * four payables that sum correctly while two of them are individually wrong is
 * a reconciliation that passes and a filing that is false.
 *
 * `due_on` IS STORED, NOT DERIVED. The S01 rule today is the 14th of the
 * following month, and it is the kind of rule that changes by statute; a filed
 * return must keep saying when it was actually due, not when a rule written
 * later would have made it due.
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
 * @property string|null $journal_ref
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PayrollRun|null $run
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 *
 * @mixin \Eloquent
 */
class StatutoryFiling extends Model
{
    protected $table = 'statutory_filings';

    public const NOT_STARTED = 'not_started';

    public const DUE_SOON = 'due_soon';

    public const FILED = 'filed';

    /**
     * The forms board 16 names, and what each one is for.
     *
     * S02 is filed alongside its S01 rather than on its own schedule — board 16
     * dates both July returns to Aug 12 — because the reconciliation is what
     * agrees the remittance to the payroll register it came from.
     */
    public const S01 = 'S01';

    public const S02 = 'S02';

    public const GCT = 'GCT';

    public const P24 = 'P24';

    protected $fillable = [
        'form_code',
        'form_title',
        'period_label',
        'period_start',
        'period_end',
        'due_on',
        'status',
        'filed_on',
        'confirmation_reference',
        'payroll_run_id',
        'employees_covered',
        'nis_minor',
        'nht_minor',
        'education_tax_minor',
        'paye_minor',
        'employer_nis_minor',
        'employer_nht_minor',
        'employer_education_tax_minor',
        'heart_minor',
        'total_minor',
        'currency',
        'journal_ref',
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
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * The four deductions this return remits, added up.
     *
     * Computed from its parts rather than read from `total_minor`, so a stored
     * total that disagrees with the four columns beside it cannot be what a
     * screen prints. `total_minor` exists for the forms that carry a figure no
     * deduction breakdown explains — a GCT return has none of these four.
     */
    public function deductionsMinor(): int
    {
        return (int) $this->nis_minor
            + (int) $this->nht_minor
            + (int) $this->education_tax_minor
            + (int) $this->paye_minor;
    }

    /**
     * The employer's own share this return remits — NIS, NHT, Education Tax
     * and HEART (Q-002, ruled: "Employer contributions go on the monthly S01,
     * alongside employee deductions").
     */
    public function employerContributionsMinor(): int
    {
        return (int) $this->employer_nis_minor
            + (int) $this->employer_nht_minor
            + (int) $this->employer_education_tax_minor
            + (int) $this->heart_minor;
    }

    /**
     * What filing this return actually sends to the revenue authority.
     *
     * Both halves, because 2100 holds both: a pay run credits it with what it
     * withheld AND with what the employer owes on top, and an S01 that remitted
     * only the first would leave the second sitting in the payable forever —
     * a liability that looks paid on the register and is not.
     */
    public function remittanceMinor(): int
    {
        return $this->deductionsMinor() + $this->employerContributionsMinor();
    }

    /** Still owed, and the date has not passed. Board 16's amber row. */
    public function isOutstanding(): bool
    {
        return $this->status !== self::FILED;
    }
}
