<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\StatutoryRateVersion;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\RateTable;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M85 A1, PAY-B-08 and PAY-S-16: the rate table says what the engine did, on the card it did it with (11 October 2026)
|--------------------------------------------------------------------------
|
| The rate table's worked example "disagreed with the engine". There is one engine; the screen took the NEWEST run's
| payslip (calculated on TAJ 2027/28) and set it under the card being viewed (TAJ 2026/27), and with no run at all it
| assumed a fortnight. Now a card's example and threshold come from a run calculated ON THAT CARD, a card nobody has run
| shows its published periodic thresholds, and the PAYE line names the 30% band (PAY-S-16).
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** A calculated fortnightly run on this card, with one payslip from the engine. */
function m85RateRun(StatutoryRateVersion $card, string $reference, string $start, string $end, int $grossMinor): PayrollRun
{
    $guard = Guard::create([
        'full_name' => 'Rate Table Officer '.$reference, 'employee_number' => 'GS-'.$reference, 'psra_number' => 'PSRA-'.$reference,
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
    ]);

    $run = PayrollRun::query()->create([
        'reference' => $reference, 'period_label' => $reference, 'period_start' => $start, 'period_end' => $end,
        'periods_per_year' => 26, 'statutory_rate_version_id' => $card->id, 'status' => 'calculated', 'currency' => 'JMD',
    ]);

    Payslip::query()->create([
        'payroll_run_id' => $run->id, 'guard_id' => $guard->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'currency' => 'JMD',
        ...app(PayrollCalculator::class)->payslip($grossMinor, $card, 26),
    ]);

    return $run;
}

it('takes a card\'s worked example and threshold from a run calculated on that card, not the newest run', function () {
    $current = StatutoryRateVersion::forPayDate('2026-06-14');
    $newest = StatutoryRateVersion::forPayDate('2027-06-13');

    m85RateRun($current, 'RT-2026-06', '2026-06-01', '2026-06-14', 84_000_00);
    // The newest run on the platform, on the newest card: before, the screen showed this one under every card.
    m85RateRun($newest, 'RT-2027-06', '2027-05-31', '2027-06-13', 84_000_00);

    $table = app(RateTable::class)->forVersion($current->id);

    expect($table['worked_example']['head'])->toContain('RT-2026-06')
        ->and(collect($table['worked_example']['rows'])->firstWhere('label', 'Net pay')['value'])->toContain('75,905.42')
        ->and($table['threshold']['rows'][0]['value'])->toContain('73,234.90');
});

it('shows a card nobody has run at its published monthly, fortnightly and weekly thresholds, never an assumed fortnight', function () {
    $card = StatutoryRateVersion::forPayDate('2026-01-31');

    $table = app(RateTable::class)->forVersion($card->id);
    $values = collect($table['threshold']['rows'])->pluck('value', 'label')->all();

    expect($table['worked_example'])->toBeNull()
        ->and($values)->toBe([
            'Tax-free threshold, a month' => '$149,948.00',
            'Tax-free threshold, a fortnight' => '$69,307.26',
            'Tax-free threshold, a week' => '$34,603.00',
        ]);
});

it('names the 30% band on the PAYE line, from the card (PAY-S-16)', function () {
    $card = StatutoryRateVersion::forPayDate('2026-06-14');

    $paye = collect(app(RateTable::class)->forVersion($card->id)['deductions'])->firstWhere('key', 'paye');

    expect($paye['basis'])->toContain('30% on statutory income above $6,000,000 a year')
        ->and($paye['rate'])->toBe('25% / 30%');
});
