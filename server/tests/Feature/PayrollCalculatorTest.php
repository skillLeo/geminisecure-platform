<?php

declare(strict_types=1);

use App\Models\StatutoryRateVersion;
use App\Services\Payroll\PayrollCalculator;

/**
 * The STRUCTURAL rules, which hold whatever a card's figures are.
 *
 * Built on an in-memory card that deliberately carries NO TAJ periodic figures,
 * so the threshold below is the division fallback — 1,800,000 / 26 — and these
 * assertions are about order, bands and rounding rather than about any one
 * year's numbers. The client's ruled figures, to the cent on the seeded cards,
 * are `PayrollGoldenPayslipTest`'s (Q-002, D-082).
 */
beforeEach(function () {
    $this->rates = new StatutoryRateVersion([
        'label' => 'Provisional - unverified',
        'effective_from' => '2026-04-01',
        'nis_employee_bp' => 300,          // 3%
        'nis_employer_bp' => 300,
        'nis_ceiling_annual_minor' => 5_000_000_00,
        'nht_employee_bp' => 200,          // 2%
        'nht_employer_bp' => 300,
        'education_tax_employee_bp' => 225, // 2.25%
        'education_tax_employer_bp' => 350,
        'paye_bp' => 2500,                 // 25%
        'paye_threshold_annual_minor' => 1_800_000_00,
        'is_verified' => false,
    ]);

    $this->calculator = new PayrollCalculator;
});

it('divides the annual PAYE threshold by the pay periods', function () {
    // 1,800,000 / 26 = 69,230.769... -> 69,230.76 in whole cents.
    // This single number is why most guards show 0.00 PAYE.
    $threshold = intdiv(1_800_000_00, 26);

    expect($threshold)->toBe(6_923_076);
    expect($threshold / 100)->toBeLessThan(69_231);
});

it('charges no PAYE below the threshold and says why', function () {
    // A fortnightly gross of 55,000 is under the 69,230.76 threshold.
    $result = $this->calculator->payslip(55_000_00, $this->rates, 26);

    expect($result['paye_minor'])->toBe(0);
    expect($result['paye_note'])->toContain('Below the PAYE threshold');
    // The note names the figure, so a bare zero cannot read as a bug.
    expect($result['paye_note'])->toContain('69,230.76');
});

it('deducts NIS before education tax and PAYE', function () {
    $gross = 100_000_00;
    $result = $this->calculator->payslip($gross, $this->rates, 26);

    $nis = 3_000_00;                     // 3% of 100,000
    $afterNis = $gross - $nis;           // 97,000

    expect($result['nis_minor'])->toBe($nis);

    // Education tax is 2.25% of income AFTER NIS, not of gross. If the order
    // were reversed this would be 2,250.00 instead.
    expect($result['education_tax_minor'])->toBe(intdiv($afterNis * 225 + 5000, 10000));
    expect($result['education_tax_minor'])->not->toBe(intdiv($gross * 225 + 5000, 10000));

    // PAYE is 25% of (after-NIS less the period threshold), not of gross.
    $taxable = $afterNis - intdiv(1_800_000_00, 26);
    expect($result['paye_minor'])->toBe(intdiv($taxable * 2500 + 5000, 10000));
});

it('caps NIS at the per-period share of the annual ceiling', function () {
    // A gross far above the ceiling must not attract unbounded NIS.
    $ceilingPerPeriod = intdiv(5_000_000_00, 26);
    $result = $this->calculator->payslip(900_000_00, $this->rates, 26);

    expect($result['nis_minor'])->toBe(intdiv($ceilingPerPeriod * 300 + 5000, 10000));
});

it('nets to gross less every deduction, exactly', function () {
    $result = $this->calculator->payslip(87_432_17, $this->rates, 26);

    $sum = $result['nis_minor']
        + $result['nht_minor']
        + $result['education_tax_minor']
        + $result['paye_minor']
        + $result['net_minor'];

    // Integer arithmetic throughout means this is exact, not approximate.
    expect($sum)->toBe($result['gross_minor']);
});

it('reproduces the same figures for the same inputs', function () {
    // A run must reproduce exactly, to the cent, years later.
    $first = $this->calculator->payslip(76_543_21, $this->rates, 26);
    $second = $this->calculator->payslip(76_543_21, $this->rates, 26);

    expect($first)->toBe($second);
});
