<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Support\PlatformPricing;
use Database\Seeders\BillingSeeder;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Re-running the catalogue seeders leaves the Director's prices alone — M10 §3
|--------------------------------------------------------------------------
|
| Since §3 the Director changes a tier's per-unit price and the per-guard add-on
| from a date, and the invoice run prices each period from that dated history.
| Both seeders used to write the BOARD's price over the live column on every run
| (updateOrInsert / updateOrCreate), so re-seeding a live server would have put
| the rate card back to the board while the invoices went on at the Director's
| prices: two answers to "what does this cost", on the screen that exists to give
| one. A price is now written when its row is created and never again.
|
| Inside a transaction, so nothing here outlives the test.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();

    DB::connection('mysql')->beginTransaction();

    Artisan::call('db:seed', ['--class' => BillingSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => PlatformCatalogueSeeder::class, '--force' => true]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    PlatformPricing::forgetGuardRate();
});

it('keeps a tier price and the per-guard rate the Director set when both seeders run again', function () {
    $plan = Plan::query()->where('key', 'standard')->firstOrFail();
    $plan->forceFill(['price_per_unit_minor' => 987_65, 'name' => 'Renamed by hand'])->save();

    DB::connection('mysql')->table('platform_rates')
        ->where('key', PlatformPricing::GUARD_RATE_KEY)
        ->update(['amount_minor' => 5_432_10, 'label' => 'Relabelled by hand']);

    Artisan::call('db:seed', ['--class' => BillingSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => PlatformCatalogueSeeder::class, '--force' => true]);

    $plan->refresh();
    $rate = DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->first();

    // The prices are the Director's …
    expect((int) $plan->price_per_unit_minor)->toBe(987_65)
        ->and((int) $rate->amount_minor)->toBe(5_432_10);

    // … and everything that is not a price is still brought back to the catalogue.
    expect($plan->name)->not->toBe('Renamed by hand')
        ->and($rate->label)->not->toBe('Relabelled by hand');
});

it('still gives a row the board price the first time it is created', function () {
    DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->delete();

    Artisan::call('db:seed', ['--class' => PlatformCatalogueSeeder::class, '--force' => true]);

    $rate = DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->first();

    expect($rate)->not->toBeNull()
        ->and((int) $rate->amount_minor)->toBeGreaterThan(0);
});
