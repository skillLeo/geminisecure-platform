<?php

declare(strict_types=1);

use App\Models\StatutoryRateVersion;
use App\Services\Payroll\StatutoryRates;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The Gemini rate table, after the payroll pass (11 Oct): names in plain words, no em dash, the rates untouched
|--------------------------------------------------------------------------
|
| The table printed "NIS — National Insurance Scheme", "NHT — National Housing Trust" and "PAYE — Income Tax" (Law 3: no
| em dash in anything a person reads). They read in plain words with the abbreviation after. Only the names moved: every
| rate, threshold and ceiling the lines carry is read from the card as before.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::platform();

    if (! DB::connection('mysql')->table('statutory_rate_versions')->exists()) {
        Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);
    }
});

it('names every deduction in plain words, the abbreviation after, never an em dash', function (): void {
    $version = StatutoryRateVersion::query()->orderByDesc('effective_from')->firstOrFail();
    $lines = app(StatutoryRates::class)->deductionLines($version);

    expect(array_column($lines, 'name'))->toBe(['National Insurance Scheme (NIS)', 'National Housing Trust (NHT)', 'Education Tax', 'Income Tax (PAYE)'])
        // The engine's order, unchanged: NIS first, because Education Tax and PAYE are computed after it.
        ->and(array_column($lines, 'key'))->toBe(['nis', 'nht', 'education_tax', 'paye']);
});
