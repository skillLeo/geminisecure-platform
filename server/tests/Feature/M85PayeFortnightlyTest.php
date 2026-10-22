<?php

declare(strict_types=1);

use App\Console\Commands\DemoSeed;
use App\Models\Guard;
use App\Models\Payslip;
use App\Models\StatutoryRateVersion;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M85 A1, PAY-B-08: a fortnightly payslip is taxed on the card in force on its pay date (11 October 2026)
|--------------------------------------------------------------------------
|
| The reported slip: 84,000.00 a fortnight (80 hours at 1,050.00), PAYE 1,105.62 and net 76,861.08 where TAJ's
| arithmetic gives PAYE 2,061.28 and net 75,905.42. THE CAUSE, NAMED BEFORE ANY NUMBER MOVED: the engine is right (the
| golden suite proves it at 26 periods a year); the slip came from `DemoSeed`, which took the NEWEST rate card, TAJ
| 2027/28. That card carries an annual threshold only, so its fortnightly threshold is 2,003,496.00 / 26 = 77,057.53,
| and 25% of 81,480.00 above it is 1,105.62. The card in force on the slip's pay date is TAJ 2026/27, whose published
| fortnightly threshold is 73,234.90.
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

it('pays the demonstration guard on the card in force on the pay date, never the newest card (PAY-B-08)', function () {
    // A June fortnight: the TAJ 2026/27 card is in force, and the 2027/28 card is the newest on the platform.
    $this->travelTo(Carbon::parse('2026-06-17 12:00:00'));

    $guard = Guard::create([
        'full_name' => 'PAY-B-08 Fortnight Officer', 'employee_number' => 'GS-PAYB08-1', 'psra_number' => 'PSRA-PAYB08-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'standard_rate_minor' => 1_050_00,
    ]);

    (new ReflectionMethod(DemoSeed::class, 'payslip'))->invoke(app(DemoSeed::class), $guard);

    $slip = Payslip::query()->where('guard_id', $guard->id)->latest('id')->firstOrFail();
    $run = $slip->run;

    expect($run->periods_per_year)->toBe(26)
        ->and($run->statutory_rate_version_id)->toBe(StatutoryRateVersion::forPayDate($run->period_end)->id)
        ->and($slip->gross_minor)->toBe(84_000_00)
        ->and($slip->nis_minor)->toBe(2_520_00)
        ->and($slip->education_tax_minor)->toBe(1_833_30)
        ->and($slip->paye_threshold_minor)->toBe(73_234_90)
        ->and($slip->paye_minor)->toBe(2_061_28)
        ->and($slip->nht_minor)->toBe(1_680_00)
        ->and($slip->net_minor)->toBe(75_905_42);
});
