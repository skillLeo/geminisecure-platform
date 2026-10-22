<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\StatutoryRateVersion;
use App\Support\MoneyFormatter;
use RuntimeException;

/**
 * Computes one payslip from gross pay and a statutory rate version.
 *
 * THE ORDER IS FIXED BY THE CLIENT'S RULING ON Q-002, AND IT IS LOAD-BEARING:
 *
 *   gross
 *   → NIS               3% of gross, capped at the per-period ceiling
 *   → statutory income  gross less NIS less an approved pension (Q-017: the
 *                       field exists per employee and is zero for everybody)
 *   → Education Tax     2.25% of statutory income, from the FIRST dollar
 *   → PAYE              25% of statutory income above TAJ's threshold for the
 *                       period; 30% of statutory income above the rate card's
 *                       higher band — 6,000,000 a year, 500,000 a month (Q-018)
 *   → NHT               2% of gross, no ceiling
 *   → net
 *
 * PAYE IS A BAND ON THE EXCESS, NEVER A FLAT RATE ON THE WHOLE. That is the whole
 * of Q-002. Board 15's sample payslips charged 25% of the entire amount once a
 * threshold was passed — and the threshold they passed was TAJ's FORTNIGHTLY
 * figure, applied to monthly pay — and the client ruled that this engine had it
 * right and the samples had it wrong (D-082).
 *
 * EDUCATION TAX IGNORES THE THRESHOLD. Somebody who pays no PAYE still pays it,
 * which is why Simone Clarke's golden payslip carries 1,571.40 of Education Tax
 * beside a nil PAYE — the single payslip that proves the threshold is applied as
 * a threshold, that PAYE is a band rather than a cliff, and that Education Tax
 * never looks at the threshold at all.
 *
 * THE THRESHOLD IS TAJ'S PUBLISHED PERIODIC FIGURE, not the annual one divided.
 * See `StatutoryRateVersion::thresholdPerPeriod()`.
 *
 * Everything is integer minor units throughout. No float touches a wage.
 *
 * `payslip()` is the employee's half. `employerCost()` is the employer's own, and
 * they are separate methods because they are separate documents — an employer's
 * contribution on somebody's slip would read as money taken off them that they
 * never had.
 */
class PayrollCalculator
{
    /** @var array<int, string> */
    private const PERIOD_NAMES = [12 => 'monthly', 26 => 'fortnightly', 52 => 'weekly'];

    /**
     * @param  int  $approvedPensionMinor  the employee's approved pension contribution this period (Q-017) — zero for everybody today
     * @return array{
     *     gross_minor:int, nis_minor:int, nht_minor:int,
     *     education_tax_minor:int, paye_minor:int, pension_minor:int, net_minor:int,
     *     paye_note:?string,
     *     nis_base_minor:int, nht_base_minor:int, education_tax_base_minor:int,
     *     paye_base_minor:int, paye_threshold_minor:int
     * }
     */
    public function payslip(int $grossMinor, StatutoryRateVersion $rates, int $periodsPerYear, int $approvedPensionMinor = 0): array
    {
        if ($periodsPerYear < 1) {
            throw new RuntimeException('periodsPerYear must be at least 1.');
        }

        // 1. NIS, on gross, capped at the per-period share of the annual ceiling.
        $nisMinor = $this->applyBasisPoints(
            min($grossMinor, $rates->nisCeilingPerPeriod($periodsPerYear)),
            $rates->nis_employee_bp,
        );

        // 2. Statutory income: gross less NIS less an approved pension.
        // ASSUMPTION Q-017 — ruled (13 §0): nobody has an approved scheme, so
        // every employee's `approved_pension_minor` is zero. The field is per
        // employee so the day somebody joins one, their payslip changes and
        // nobody else's does. Kept marked so the accountant's note stays tied
        // to the line it would change.
        $statutoryIncomeMinor = $grossMinor - $nisMinor - $this->pension($approvedPensionMinor, $grossMinor - $nisMinor);

        // 3. Education Tax, on statutory income — and NOT subject to the threshold.
        $educationTaxMinor = $this->applyBasisPoints($statutoryIncomeMinor, $rates->education_tax_employee_bp);

        // 4. PAYE, a band on the excess above TAJ's threshold for the period.
        $thresholdMinor = $rates->thresholdPerPeriod($periodsPerYear);
        $payeMinor = $this->paye($statutoryIncomeMinor, $thresholdMinor, $rates, $periodsPerYear);

        // 5. NHT, on gross, with no ceiling.
        $nhtMinor = $this->applyBasisPoints($grossMinor, $rates->nht_employee_bp);

        return [
            'gross_minor' => $grossMinor,
            'nis_minor' => $nisMinor,
            'nht_minor' => $nhtMinor,
            'education_tax_minor' => $educationTaxMinor,
            'paye_minor' => $payeMinor,

            // Withheld from pay like a statutory deduction, and owed to the
            // pension scheme rather than to TAJ — so never on the S01.
            'pension_minor' => $approvedPensionMinor,
            'net_minor' => $grossMinor - $nisMinor - $approvedPensionMinor - $educationTaxMinor - $payeMinor - $nhtMinor,
            // Only an income at or below the threshold is "below" it: a cent above it is taxed at 25% of 0.01, which rounds
            // to nil, and the note would say something untrue on that slip (the test campaign, 12 Oct 2026).
            'paye_note' => $statutoryIncomeMinor <= $thresholdMinor
                ? $this->belowThresholdNote($thresholdMinor, $periodsPerYear, $rates)
                : null,

            /*
             * WHAT EACH DEDUCTION WAS TAKEN ON (M8 §5.3). Four different figures, and that is the
             * whole reason they are reported: an officer checking "2.5% of my pay" against NIS
             * would get a different answer from the platform whenever gross is above the period's
             * insurable ceiling — correctly, and invisibly, until the base is shown beside the rate.
             * Computed nowhere else: these are the exact values the four lines above used.
             */
            'nis_base_minor' => min($grossMinor, $rates->nisCeilingPerPeriod($periodsPerYear)),
            'nht_base_minor' => $grossMinor,
            'education_tax_base_minor' => $statutoryIncomeMinor,
            'paye_base_minor' => $statutoryIncomeMinor,
            'paye_threshold_minor' => $thresholdMinor,
        ];
    }

    /**
     * THE EARNINGS SIDE: hours and rates in, a gross out (M8 §5.3).
     *
     * The deduction half below has always started from a gross somebody else worked out. This is
     * where that gross comes from, so the payslip screen, the payroll run and the seeder cannot
     * each arrive at it their own way.
     *
     * OVERTIME IS A MULTIPLE OF THE STANDARD RATE, not a rate of its own: Jamaica's Minimum Wage
     * Act sets overtime at time-and-a-half, and an estate that pays double time changes the
     * multiplier rather than keying a second rate that could drift from the first. The multiplier
     * is in basis points for the same reason every rate on this platform is — 15_000 is 1.5.
     *
     * Rounding is per LINE, not on the total: an hour rate of 1,050.00 over 6.25 hours is
     * 6,562.50 exactly, and half a cent is only created by rounding a sum of already-rounded
     * lines. Each line is rounded once, then added.
     *
     * @param  float  $regularHours  hours at the standard rate
     * @param  float  $overtimeHours  hours at the overtime multiple
     * @param  int  $rateMinor  the standard hourly rate, minor units
     * @param  int  $overtimeBp  the overtime multiplier in basis points (15_000 = time and a half)
     * @param  int  $allowancesMinor  allowances for the period (uniform, duty, travel), taxable
     * @return array{
     *     regular_hours:float, overtime_hours:float,
     *     regular_rate_minor:int, overtime_rate_minor:int,
     *     basic_minor:int, overtime_minor:int, allowances_minor:int, gross_minor:int
     * }
     */
    public function earnings(float $regularHours, float $overtimeHours, int $rateMinor, int $overtimeBp = 15_000, int $allowancesMinor = 0): array
    {
        if ($regularHours < 0 || $overtimeHours < 0 || $rateMinor < 0 || $allowancesMinor < 0) {
            throw new RuntimeException('Hours, rates and allowances are never negative.');
        }

        $overtimeRateMinor = (int) round($rateMinor * $overtimeBp / 10_000);
        $basicMinor = (int) round($rateMinor * $regularHours);
        $overtimeMinor = (int) round($overtimeRateMinor * $overtimeHours);

        return [
            'regular_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'regular_rate_minor' => $rateMinor,
            'overtime_rate_minor' => $overtimeRateMinor,
            'basic_minor' => $basicMinor,
            'overtime_minor' => $overtimeMinor,
            'allowances_minor' => $allowancesMinor,
            'gross_minor' => $basicMinor + $overtimeMinor + $allowancesMinor,
        ];
    }

    /**
     * What employing somebody costs on top of their gross.
     *
     * RULED ON Q-002 (D-083): employer contributions go on the monthly S01
     * beside the employee deductions, and they are posted — Dr 5010 Employer
     * Statutory Contributions, Cr 2100 Statutory Payables. Until this ruling the
     * rate card carried the three employer rates and nothing read them (D-072).
     *
     * EDUCATION TAX IS CHARGED ON STATUTORY INCOME, the same base as the
     * employee's — gross less the EMPLOYEE's NIS — as ruled, not on gross.
     *
     * HEART IS DECIDED ON THE PAYROLL, NOT THE PERSON. It applies where the
     * employer's monthly payroll exceeds a statutory floor, so the caller works
     * that out once for the whole run and passes the answer in. With the floor at
     * zero — the ruled default, Q-016 — it always applies.
     *
     * @return array{
     *     nis_minor:int, nht_minor:int, education_tax_minor:int, heart_minor:int,
     *     total_minor:int, base_note:string
     * }
     */
    public function employerCost(
        int $grossMinor,
        StatutoryRateVersion $rates,
        int $periodsPerYear,
        bool $heartApplies = true,
        int $approvedPensionMinor = 0,
    ): array {
        if ($periodsPerYear < 1) {
            throw new RuntimeException('periodsPerYear must be at least 1.');
        }

        // The ceiling binds the employer's NIS exactly as it binds the
        // employee's: it is a ceiling on insurable earnings, not on one share.
        $nisableMinor = min($grossMinor, $rates->nisCeilingPerPeriod($periodsPerYear));

        $nisMinor = $this->applyBasisPoints($nisableMinor, $rates->nis_employer_bp);
        $nhtMinor = $this->applyBasisPoints($grossMinor, $rates->nht_employer_bp);

        $employeeNisMinor = $this->applyBasisPoints($nisableMinor, $rates->nis_employee_bp);

        // The same statutory income as the employee's slip, pension and all.
        $statutoryIncomeMinor = $grossMinor - $employeeNisMinor - $this->pension($approvedPensionMinor, $grossMinor - $employeeNisMinor);

        $educationTaxMinor = $this->applyBasisPoints($statutoryIncomeMinor, $rates->education_tax_employer_bp);

        $heartMinor = $heartApplies ? $this->applyBasisPoints($grossMinor, $rates->heart_employer_bp) : 0;

        return [
            'nis_minor' => $nisMinor,
            'nht_minor' => $nhtMinor,
            'education_tax_minor' => $educationTaxMinor,
            'heart_minor' => $heartMinor,
            'total_minor' => $nisMinor + $nhtMinor + $educationTaxMinor + $heartMinor,
            'base_note' => sprintf(
                'Employer Education Tax charged on statutory income (%s), gross less employee NIS, as ruled.',
                MoneyFormatter::fromMinor($statutoryIncomeMinor),
            ),
        ];
    }

    /**
     * PAYE on statutory income above the threshold — a band, never the whole.
     *
     * 25% from the threshold up to the rate card's higher band, 30% above it.
     * One rounding over the whole figure rather than one per band, so a payslip
     * that straddles the 30% line cannot drift a cent from the same sum done by
     * hand.
     */
    private function paye(int $statutoryIncomeMinor, int $thresholdMinor, StatutoryRateVersion $rates, int $periodsPerYear): int
    {
        if ($statutoryIncomeMinor <= $thresholdMinor) {
            return 0;
        }

        // ASSUMPTION Q-018 — ruled (13 §0): the 30% band is measured on
        // STATUTORY income above J$6,000,000 a year / J$500,000 a month, and the
        // boundary is the rate card's `paye_higher_band_annual_minor`, never a
        // constant here. The alternative reading, not applied: 30% on CHARGEABLE
        // income (statutory income after the threshold) above 6,000,000 — which
        // put the line a threshold's width higher. Nobody on either payroll
        // earns near either line.
        $bandTopMinor = $rates->higherBandPerPeriod($periodsPerYear);

        $lowerMinor = max(0, min($statutoryIncomeMinor, $bandTopMinor) - $thresholdMinor);
        $upperMinor = max(0, $statutoryIncomeMinor - max($bandTopMinor, $thresholdMinor));

        return intdiv($lowerMinor * $rates->paye_bp + $upperMinor * $rates->paye_higher_bp + 5_000, 10_000);
    }

    /**
     * An approved pension contribution, refused where it cannot be right.
     *
     * Never negative, and never more than the income it comes out of: a
     * contribution larger than gross less NIS would make statutory income
     * negative and pay somebody to be employed.
     */
    private function pension(int $approvedPensionMinor, int $afterNisMinor): int
    {
        if ($approvedPensionMinor < 0 || $approvedPensionMinor > max(0, $afterNisMinor)) {
            throw new RuntimeException('An approved pension contribution is between zero and the pay it is deducted from.');
        }

        return $approvedPensionMinor;
    }

    /**
     * Says WHY PAYE is zero, naming the threshold and where it came from.
     *
     * A bare 0.00 on a payslip reads as a defect to the person holding it. And a
     * threshold that was derived rather than published says so, because that is
     * the one kind of figure an accountant would query.
     */
    private function belowThresholdNote(
        int $thresholdPerPeriod,
        int $periodsPerYear,
        StatutoryRateVersion $rates,
    ): string {
        $source = $rates->publishedThresholdFor($periodsPerYear) !== null
            ? sprintf(
                "TAJ's published %s figure, %s card",
                self::PERIOD_NAMES[$periodsPerYear],
                $rates->effective_from->format('Y-m'),
            )
            : sprintf(
                // A comma, never an em dash (Law 3): the guard reads this note on the payslip and in the app.
                '%s annually / %d periods, as no TAJ periodic figure is recorded for this card',
                MoneyFormatter::fromMinor($rates->paye_threshold_annual_minor),
                $periodsPerYear,
            );

        return sprintf(
            'Below the PAYE threshold of %s per period (%s).',
            MoneyFormatter::fromMinor($thresholdPerPeriod),
            $source,
        );
    }

    /**
     * Applies a rate held in basis points, rounding half up to the cent.
     *
     * intdiv after adding half the divisor gives banker-free half-up rounding
     * without ever converting to float, which is the whole point: a wage that
     * round-trips through a float is a wage that eventually disagrees with
     * itself by a cent.
     */
    private function applyBasisPoints(int $amountMinor, int $basisPoints): int
    {
        if ($amountMinor <= 0 || $basisPoints <= 0) {
            return 0;
        }

        return intdiv($amountMinor * $basisPoints + 5_000, 10_000);
    }
}
