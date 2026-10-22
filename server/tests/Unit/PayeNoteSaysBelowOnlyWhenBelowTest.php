<?php

declare(strict_types=1);

use App\Models\StatutoryRateVersion;
use App\Services\Payroll\PayrollCalculator;

/*
|--------------------------------------------------------------------------
| The PAYE note says "Below the PAYE threshold" only when the income is (the test campaign, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| One cent above the threshold, PAYE is 25% of 0.01, which rounds to nil, and the payslip used to explain the nil with
| "Below the PAYE threshold" for an income a cent above it. The guard reads that note on the payslip and in the app.
|
| A card built in memory, so no database: the TAJ 2026/27 rates with the monthly threshold left to division
| (1,902,360.00 / 12 = 158,530.00, TAJ's published monthly figure too), and no date, which needs no application.
|
*/

function payeNoteCard(): StatutoryRateVersion
{
    $card = new StatutoryRateVersion;
    $card->forceFill([
        'nis_employee_bp' => 300, 'nis_employer_bp' => 300, 'nis_ceiling_annual_minor' => 5_000_000_00,
        'nht_employee_bp' => 200, 'nht_employer_bp' => 300,
        'education_tax_employee_bp' => 225, 'education_tax_employer_bp' => 350,
        'paye_bp' => 2500, 'paye_threshold_annual_minor' => 1_902_360_00,
    ]);

    return $card;
}

it('gives no below-the-threshold note for an income one cent above it, though its PAYE rounds to nil', function () {
    // 163,433.00 less NIS of 4,902.99 is 158,530.01.
    $slip = (new PayrollCalculator)->payslip(16_343_300, payeNoteCard(), 12);

    expect($slip['paye_base_minor'])->toBe(15_853_001)
        ->and($slip['paye_threshold_minor'])->toBe(15_853_000)
        ->and($slip['paye_minor'])->toBe(0)
        ->and($slip['paye_note'])->toBeNull();
});

it('writes the note for a card without TAJ\'s periodic figures without an em dash (Law 3)', function () {
    // The guard reads this on the payslip PDF and in the app.
    $note = (string) (new PayrollCalculator)->payslip(12_000_000, payeNoteCard(), 12)['paye_note'];

    expect(str_contains($note, 'no TAJ periodic figure is recorded for this card'))->toBeTrue()
        ->and(str_contains($note, '—'))->toBeFalse();
});

it('still explains a nil PAYE at the threshold and below it', function () {
    // 163,432.99 less NIS of 4,902.99 is 158,530.00, the threshold itself.
    $at = (new PayrollCalculator)->payslip(16_343_299, payeNoteCard(), 12);
    $below = (new PayrollCalculator)->payslip(12_000_000, payeNoteCard(), 12);

    expect($at['paye_base_minor'])->toBe(15_853_000)
        ->and($at['paye_minor'])->toBe(0)
        ->and(str_starts_with((string) $at['paye_note'], 'Below the PAYE threshold of '))->toBeTrue()
        ->and(str_starts_with((string) $below['paye_note'], 'Below the PAYE threshold of '))->toBeTrue();
});
