<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| accounts:retitle-em-dashes: the seeded chart's two old names, retitled on an estate that still holds them (Law 3)
|--------------------------------------------------------------------------
|
| The seed named 5050 "Security Services — Gemini Security Ltd" and 5200 "Utilities — Common Areas"; it says them
| without the em dash now, and a live estate keeps what it was seeded with until this runs. A dry run unless told
| --confirm; an account whose name is not exactly the old seeded one is the office's own and is left alone.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    FacilitiesFixture::boot();

    $this->names = Account::query()->whereIn('code', ['5050', '5200'])->pluck('name', 'code')->all();
});

afterEach(function (): void {
    FacilitiesFixture::boot();

    // The fixture estate is shared and committed: each account goes back to what it held before the test.
    foreach ($this->names as $code => $name) {
        DB::connection('tenant')->table('accounts')->where('code', $code)->update(['name' => $name]);
    }
});

it('says the seed\'s own words for the two accounts', function (): void {
    $chart = collect(ChartOfAccountsSeeder::CHART)->mapWithKeys(static fn (array $row): array => [$row[0] => $row[1]]);

    expect($chart['5050'])->toBe('Security Services (Gemini Security Ltd)')
        ->and($chart['5200'])->toBe('Utilities (Common Areas)');
});

it('changes nothing without --confirm, retitles with it, and leaves an account the office renamed alone', function (): void {
    DB::connection('tenant')->table('accounts')->where('code', '5050')->update(['name' => 'Security Services — Gemini Security Ltd']);
    DB::connection('tenant')->table('accounts')->where('code', '5200')->update(['name' => 'Common area power and water']);

    expect(Artisan::call('accounts:retitle-em-dashes', ['--estate' => FacilitiesFixture::ESTATE]))->toBe(0);
    $dry = Artisan::output();

    FacilitiesFixture::boot();
    expect(Account::query()->where('code', '5050')->value('name'))->toBe('Security Services — Gemini Security Ltd')
        ->and($dry)->toContain('Security Services (Gemini Security Ltd)')
        ->and($dry)->toContain('Nothing is written without --confirm');

    expect(Artisan::call('accounts:retitle-em-dashes', ['--estate' => FacilitiesFixture::ESTATE, '--confirm' => true]))->toBe(0);

    FacilitiesFixture::boot();
    expect(Account::query()->where('code', '5050')->value('name'))->toBe('Security Services (Gemini Security Ltd)')
        ->and(Account::query()->where('code', '5200')->value('name'))->toBe('Common area power and water')
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'account.em_dash_names_retitled')->where('tenant_id', FacilitiesFixture::ESTATE)->exists())->toBeTrue();

    // Run again, nothing left to do.
    expect(Artisan::call('accounts:retitle-em-dashes', ['--estate' => FacilitiesFixture::ESTATE, '--confirm' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing was changed');
});

it('refuses an estate it does not know, and changes nothing', function (): void {
    expect(Artisan::call('accounts:retitle-em-dashes', ['--estate' => 'no-such-estate', '--confirm' => true]))->not->toBe(0)
        ->and(Artisan::output())->toContain('Nothing was changed');
});
