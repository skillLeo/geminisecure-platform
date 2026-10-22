<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PayrollRun;
use App\Models\StatutoryFiling;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The statutory register as it stands today: one return owed, two filed, one
 * not yet owed.
 *
 * WHERE THE FIGURES COME FROM. The two filed returns are HISTORY - the runs
 * they were paid from predate this system, so there is no run row to point at.
 * Their amounts are therefore recorded as the engine's own output for the same
 * roster at the same rates, rather than typed in: a seeded remittance that does
 * not reconcile to the calculator would teach the reader that the two are
 * allowed to disagree.
 *
 * The owed return has NO amounts, and that is deliberate. Its period has closed
 * but no approved run covers it - D-021 holds every run short of approval - and
 * the Build Spec's rule for this screen is that amounts derive from approved
 * runs only. A provisional figure here would be a number somebody could remit.
 *
 * Everything is anchored to the current month rather than to fixed dates, so
 * the register still reads correctly whenever the demo data is rebuilt.
 */
class StatutoryFilingsSeeder extends Seeder
{
    public function run(): void
    {
        $thisMonth = Carbon::now()->startOfMonth();

        // The month that has closed and is now owed.
        $owed = $thisMonth->copy()->subMonth();

        // The month before that, settled.
        $settled = $thisMonth->copy()->subMonths(2);

        $totals = $this->remittanceTotals();

        /*
         * S01 for the closed month. Owed, and unpreparable: no approved run
         * covers it. Amounts stay null rather than being borrowed from the
         * calculated run, because a remittance is a payment.
         */
        $this->record([
            'form_code' => 'S01',
            'form_title' => 'Statutory Deduction Remittance',
            'period_label' => $owed->format('F Y'),
            'period_start' => $owed->toDateString(),
            'period_end' => $owed->copy()->endOfMonth()->toDateString(),
            // Remittance is due by the 14th of the month after the period.
            'due_on' => $owed->copy()->addMonth()->day(14)->toDateString(),
            'status' => StatutoryFiling::DUE,
        ]);

        // The settled month: the remittance and the reconciliation that follows
        // it, both filed five days before the deadline.
        foreach ([
            ['S01', 'Statutory Deduction Remittance'],
            ['S02', 'Monthly Reconciliation'],
        ] as [$code, $title]) {
            $this->record([
                'form_code' => $code,
                'form_title' => $title,
                'period_label' => $settled->format('F Y'),
                'period_start' => $settled->toDateString(),
                'period_end' => $settled->copy()->endOfMonth()->toDateString(),
                'due_on' => $settled->copy()->addMonth()->day(14)->toDateString(),
                'status' => StatutoryFiling::FILED,
                'filed_on' => $settled->copy()->addMonth()->day(11)->toDateString(),
                'confirmation_reference' => sprintf('TAJ-%s-%s', $settled->format('Ym'), $code),
                'employees_covered' => $totals['employees'],
                'nis_minor' => $totals['nis'],
                'nht_minor' => $totals['nht'],
                'education_tax_minor' => $totals['education_tax'],
                'paye_minor' => $totals['paye'],

                // The employer's own share, on the same return (Q-002, ruled):
                // an S01 covers PAYE, NIS, NHT, Education Tax AND HEART.
                'employer_nis_minor' => $totals['employer_nis'],
                'employer_nht_minor' => $totals['employer_nht'],
                'employer_education_tax_minor' => $totals['employer_education_tax'],
                'heart_minor' => $totals['heart'],
                'total_minor' => $totals['total'],
            ]);
        }

        /*
         * The annual employer return for the current tax year. Its period has
         * not closed, so nothing is owed and nothing is late - it sits in the
         * register so that it cannot be forgotten in March.
         */
        $this->record([
            'form_code' => 'P24',
            'form_title' => 'Annual Employer Return',
            'period_label' => 'Tax year '.$thisMonth->year,
            'period_start' => $thisMonth->copy()->startOfYear()->toDateString(),
            'period_end' => $thisMonth->copy()->endOfYear()->toDateString(),
            // Due by 31 March of the year following the tax year.
            'due_on' => $thisMonth->copy()->addYear()->month(3)->day(31)->toDateString(),
            'status' => StatutoryFiling::NOT_STARTED,
        ]);
    }

    /**
     * Create the return if it is not already there, and never touch it if it is.
     *
     * `firstOrCreate` rather than `updateOrCreate` on purpose: a filed return is
     * append-only, the model refuses the save and the database refuses it in a
     * trigger, so a seeder that reached for `updateOrCreate` would fail the
     * second time it ran. Refusing to rewrite history is the correct behaviour
     * and this seeder is written to live with it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function record(array $attributes): void
    {
        StatutoryFiling::firstOrCreate(
            [
                'form_code' => $attributes['form_code'],
                'period_label' => $attributes['period_label'],
            ],
            $attributes,
        );
    }

    /**
     * What a month's remittance comes to, from the engine rather than by hand.
     *
     * Summed in minor units across the payslips of the most recent run. Never
     * from the formatted strings, and never through a float: the component lines
     * of a return have to add up to its total exactly.
     *
     * THE EMPLOYER'S SHARE IS ON IT. The ruling on Q-002 put employer
     * contributions on the monthly S01 beside what was withheld from the guards,
     * so the total is both halves — the four employee deductions and the four
     * employer contributions, HEART among them.
     *
     * @return array{employees: int, nis: int, nht: int, education_tax: int, paye: int, employer_nis: int, employer_nht: int, employer_education_tax: int, heart: int, total: int}
     */
    private function remittanceTotals(): array
    {
        $run = PayrollRun::query()->orderByDesc('period_start')->first();

        if ($run === null) {
            return [
                'employees' => 0, 'nis' => 0, 'nht' => 0, 'education_tax' => 0, 'paye' => 0,
                'employer_nis' => 0, 'employer_nht' => 0, 'employer_education_tax' => 0, 'heart' => 0,
                'total' => 0,
            ];
        }

        $payslips = $run->payslips()->get();

        $nis = (int) $payslips->sum('nis_minor');
        $nht = (int) $payslips->sum('nht_minor');
        $educationTax = (int) $payslips->sum('education_tax_minor');
        $paye = (int) $payslips->sum('paye_minor');

        $employerNis = (int) $payslips->sum('employer_nis_minor');
        $employerNht = (int) $payslips->sum('employer_nht_minor');
        $employerEducationTax = (int) $payslips->sum('employer_education_tax_minor');
        $heart = (int) $payslips->sum('employer_heart_minor');

        return [
            'employees' => $payslips->count(),
            'nis' => $nis,
            'nht' => $nht,
            'education_tax' => $educationTax,
            'paye' => $paye,
            'employer_nis' => $employerNis,
            'employer_nht' => $employerNht,
            'employer_education_tax' => $employerEducationTax,
            'heart' => $heart,
            'total' => $nis + $nht + $educationTax + $paye
                + $employerNis + $employerNht + $employerEducationTax + $heart,
        ];
    }
}
