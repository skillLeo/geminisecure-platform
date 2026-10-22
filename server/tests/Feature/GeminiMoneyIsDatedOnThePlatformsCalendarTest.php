<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Gemini\InvoiceActions;
use App\Services\Gemini\InvoiceRun;
use App\Services\Gemini\PlatformSettings;
use App\Support\PlatformPricing;
use Database\Seeders\BillingSeeder;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Gemini's money is dated on Gemini's calendar, not UTC's (B082)
|--------------------------------------------------------------------------
|
| The server's clock is UTC and stays UTC (M4 §3.1), and Kingston is five hours
| behind it all year. From 7 p.m. to midnight in Kingston the UTC DATE is
| already tomorrow — and every business date on the Gemini money paths was the
| UTC date: an invoice's journal, a credit note's issue date and its journal,
| "a price change is never retroactive", "applied from today", the line items
| in force today, and the billing month the run is allowed to raise.
|
| So at 8 p.m. on 30 September in Kingston, a credit note was issued on 1
| October, journalled CN-2026-10-…, and the Director's change dated 30
| September — their today — was refused as retroactive.
|
| The platform's calendar is Jamaica's: Gemini Security Limited is one Jamaican
| company with one set of books, so every client's postings share one calendar.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    if (! DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->exists()) {
        Artisan::call('db:seed', ['--class' => BillingSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PlatformCatalogueSeeder::class, '--force' => true]);
    }

    // 8 p.m. on 30 September in Kingston: 01:00 on 1 October in UTC.
    $this->travelTo(Carbon::parse('2026-09-30 20:00:00', 'America/Jamaica'));
    $this->withoutVite();

    DB::connection('mysql')->table('platform_rates')->where('key', '!=', PlatformPricing::GUARD_RATE_KEY)->update(['is_active' => false]);
    DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)
        ->update(['is_active' => true, 'basis' => 'guard', 'amount_minor' => 4_500_00]);
    DB::connection('mysql')->table('platform_rate_changes')->delete();
    PlatformPricing::forgetGuardRate();

    $this->client = 'platformcalendartest';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->client,
        'name' => 'Platform Calendar Test Estate',
        'receipt_prefix' => 'PCT',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->plan = Plan::query()->create([
        'key' => 'platformcalendartest',
        'name' => 'Calendar Test',
        'price_per_unit_minor' => 300_00,
        'currency' => 'JMD',
        'min_units' => 1,
        'is_active' => true,
        'sort' => 95,
    ]);

    Subscription::query()->create([
        'tenant_id' => $this->client,
        'plan_id' => $this->plan->id,
        'unit_count' => 40,
        'contracted_guards' => 1,
        'status' => 'active',
        'started_on' => '2026-09-01',
    ]);

    Guard::create([
        'full_name' => 'Calendar Test Guard',
        'employee_number' => 'GS-PCT1',
        'psra_number' => 'PSRA-PCT1',
        'psra_expires_on' => '2027-09-24',
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => $this->client,
    ]);

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    PlatformPricing::forgetGuardRate();
    DB::connection('mysql')->rollBack();
});

function journalOf(string $reference): object
{
    return DB::connection('mysql')->table('platform_journals')->where('reference', $reference)->sole();
}

it('journals an invoice raised on a Kingston evening on the Kingston date, in the Kingston month', function () {
    $invoice = app(InvoiceRun::class)->raise($this->client, $this->director);

    $journal = journalOf((string) $invoice->journal_ref);

    expect($journal->posted_on)->toBe('2026-09-30')
        ->and($invoice->journal_ref)->toStartWith('INV-2026-09-');
});

it('issues and journals a credit note on the Kingston date', function () {
    $invoice = app(InvoiceRun::class)->raise($this->client, $this->director);

    app(InvoiceActions::class)->creditNote($invoice->id, '100.00', 'Evening correction', $this->director);

    $note = DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->sole();

    expect($note->issued_on)->toBe('2026-09-30')
        ->and($note->journal_ref)->toStartWith('CN-2026-09-')
        ->and(journalOf((string) $note->journal_ref)->posted_on)->toBe('2026-09-30');
});

it('bills at most a month ahead of the Kingston month, not the UTC one', function () {
    $run = app(InvoiceRun::class);

    // September and October may be raised on 30 September.
    $run->raise($this->client, $this->director);
    $run->raise($this->client, $this->director);

    // November is two months ahead of September, whatever UTC's date says.
    expect(fn () => $run->draft($this->client))->toThrow(DomainException::class, 'at most a month ahead');
});

it('takes a price change dated the Director\'s today as today\'s, and one dated tomorrow as tomorrow\'s', function () {
    $settings = app(PlatformSettings::class);

    $today = $settings->changePlanPrice($this->plan->id, '350.00', '2026-09-30', 'Board decision', $this->director);

    expect($today['applied'])->toBeTrue()
        ->and((int) Plan::query()->whereKey($this->plan->id)->value('price_per_unit_minor'))->toBe(350_00);

    // Dated 1 October: recorded, and the live column does not move until it is 1 October in Kingston.
    $tomorrow = $settings->changePlanPrice($this->plan->id, '360.00', '2026-10-01', 'Second step', $this->director);

    expect($tomorrow['applied'])->toBeFalse()
        ->and((int) Plan::query()->whereKey($this->plan->id)->value('price_per_unit_minor'))->toBe(350_00)
        ->and($settings->applyDuePriceChanges())->toBe(0);

    // The same for the per-guard add-on.
    $rateId = (int) DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->value('id');

    expect($settings->changeRate($rateId, '4600.00', '2026-09-30', 'Wage settlement', $this->director)['applied'])->toBeTrue()
        ->and($settings->changeRate($rateId, '4700.00', '2026-10-01', 'Second step', $this->director)['applied'])->toBeFalse();

    // And the screen that asks before the change says the same: not refused, not retroactive.
    expect($settings->changeImpact('tier', $this->plan->id, '2026-09-30')['refused'])->toBeNull();
});

it('accepts a line item dated the Director\'s today, and counts it as in force today', function () {
    $settings = app(PlatformSettings::class);

    $settings->addLineItem($this->client, [
        'name' => 'Evening addition',
        'type' => 'addition',
        'amount' => '1000',
        'effective_from' => '2026-09-30',
    ], $this->director);

    expect(collect($settings->lineItems($this->client)['additions'])->pluck('name')->all())->toContain('Evening addition')
        ->and($settings->lineItemImpact($this->client, '2026-09-30', ending: false)['refused'])->toBeNull()
        ->and($settings->lineItemImpact($this->client, '2026-09-30', ending: true)['refused'])->toBeNull();

    // An override dated tomorrow in Kingston has not started.
    $settings->addLineItem($this->client, [
        'name' => 'Tomorrow addition',
        'type' => 'addition',
        'amount' => '2000',
        'effective_from' => '2026-10-01',
    ], $this->director);

    expect(collect($settings->lineItems($this->client)['upcoming'])->pluck('name')->all())->toContain('Tomorrow addition');
});

it('hands the settings screens the platform\'s today, the day the server judges a date by', function () {
    $this->actingAs($this->director)
        ->get('/settings/line-items?client='.$this->client)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('today', '2026-09-30'));

    $this->actingAs($this->director)
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('nextPeriodStart', '2026-10-01'));
});
