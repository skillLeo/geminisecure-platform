<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Guard;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\StatutoryRateVersion;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Database\Seeder;

/**
 * Gemini Security's own guard payroll: the rate card, one calculated run, and
 * the returns owed on it.
 *
 * THE RATE CARD COMES FROM ITS OWN SEEDER NOW. The Jamaica-wide TAJ cards are
 * not a fact about Gemini's guards — the estates' own payrolls read the same
 * table — and the client's ruling on Q-002 replaced the single provisional card
 * this file used to write with dated versions either side of each 1 April. See
 * `StatutoryRatesSeeder`.
 *
 * THE RUN TAKES THE CARD IN FORCE ON ITS PAY DATE. Never "the latest": a run
 * paid in March is taxed on March's threshold, which is the 2025-04 card, and
 * one paid in April on the 2026-04 card. `StatutoryRateVersion::forPayDate()` is
 * the selector, and the version is stored on the run so it reproduces exactly.
 *
 * The run stops at `calculated`. Approving it is a person's act, taken on the
 * payroll screen with the first-live-run acknowledgement the ruling asks for,
 * not something a seeder does on anybody's behalf.
 */
class PayrollSeeder extends Seeder
{
    /** Fortnightly — guards are paid every two weeks. */
    private const PERIODS_PER_YEAR = 26;

    public function run(): void
    {
        $this->call(StatutoryRatesSeeder::class);

        $guards = Guard::whereIn('status', ['active', 'on_leave'])->get();

        if ($guards->isEmpty()) {
            $this->command->warn('No guards on the roster; skipping payroll seed.');

            return;
        }

        $start = now()->startOfMonth();
        $periodStart = $start->copy()->addDays(14);
        $periodEnd = $start->copy()->endOfMonth();

        $rates = StatutoryRateVersion::forPayDate($periodEnd);

        $payrollRun = PayrollRun::firstOrNew(['reference' => 'GS-PR-'.$start->format('Ym').'-2']);

        // Never rewrite an approved run: that is invariant 4, and the trigger
        // would refuse it anyway.
        if ($payrollRun->exists && in_array($payrollRun->status, ['approved', 'paid'], true)) {
            // The run is settled and must not be rewritten, but the returns
            // owed on it still have to exist.
            $this->call(StatutoryFilingsSeeder::class);

            return;
        }

        $payrollRun->fill([
            'period_label' => $start->format('M Y').' - fortnight 2',
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'periods_per_year' => self::PERIODS_PER_YEAR,
            'statutory_rate_version_id' => $rates->id,
            'status' => 'calculated',
            'currency' => 'JMD',
        ])->save();

        $calculator = new PayrollCalculator;

        /*
         * A spread that straddles the threshold, so the payslip screen shows
         * both the zero-PAYE explanation and a real PAYE figure. Worked out
         * first, because HEART is decided on the payroll as a whole rather than
         * per person (Q-016).
         */
        $grosses = [];

        foreach ($guards->values() as $i => $guard) {
            $grosses[$guard->id] = [52_000_00, 61_500_00, 74_800_00, 95_200_00][$i % 4];
        }

        $heartApplies = $rates->heartAppliesTo(array_sum($grosses), self::PERIODS_PER_YEAR);

        $gross = 0;
        $net = 0;

        foreach ($guards as $guard) {
            // Q-017: every guard's approved pension is zero; read, not assumed.
            $pension = (int) $guard->approved_pension_minor;
            $figures = $calculator->payslip($grosses[$guard->id], $rates, self::PERIODS_PER_YEAR, $pension);
            $employer = $calculator->employerCost($grosses[$guard->id], $rates, self::PERIODS_PER_YEAR, $heartApplies, $pension);

            Payslip::updateOrCreate(
                ['payroll_run_id' => $payrollRun->id, 'guard_id' => $guard->id],
                [
                    'tenant_id' => $guard->tenant_id,
                    'currency' => 'JMD',
                    ...$figures,

                    // Gemini's own share as the employer. On the S01, never on
                    // the guard's slip as a deduction — it is not one.
                    'employer_nis_minor' => $employer['nis_minor'],
                    'employer_nht_minor' => $employer['nht_minor'],
                    'employer_education_tax_minor' => $employer['education_tax_minor'],
                    'employer_heart_minor' => $employer['heart_minor'],
                ],
            );

            $gross += $figures['gross_minor'];
            $net += $figures['net_minor'];
        }

        $payrollRun->forceFill(['gross_minor' => $gross, 'net_minor' => $net])->save();

        /*
         * The statutory register, seeded last because two of its returns are
         * recorded at the totals this run's payslips come to.
         *
         * Called from here rather than added to DatabaseSeeder so that the
         * payroll module's seed data stays in one ordered piece: the rates, the
         * run they produced, and the returns owed on it.
         */
        $this->call(StatutoryFilingsSeeder::class);
    }
}
