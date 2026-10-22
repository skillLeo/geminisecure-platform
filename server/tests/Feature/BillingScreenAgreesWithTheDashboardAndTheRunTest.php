<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Gemini\BillingOverview;
use App\Services\Gemini\InvoiceRun;
use App\Services\Gemini\PlatformOverview;
use App\Services\Gemini\PlatformSettings;
use App\Support\MoneyFormatter;
use App\Support\PlatformPricing;
use Database\Seeders\BillingSeeder;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The billing screen's money agrees with the dashboard and with the run (B077)
|--------------------------------------------------------------------------
|
| /billing summed "Platform MRR" as units × tier price and nothing else, while
| the dashboard's card of the same name adds the guards on each contract at the
| platform rate (`PlatformPricing::mrrSql()`). Phoenix Park — 450 units at
| J$340 and 4 guards at J$4,500 — read $171,000 on the dashboard and $153,000
| here. And each "Not yet invoiced" row quoted the tier alone, while the
| preview it opens and the raise behind it post the add-on and the line items
| too.
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

    $this->travelTo(Carbon::parse('2026-09-25 10:00:00', 'America/Jamaica'));

    DB::connection('mysql')->table('platform_rates')->where('key', '!=', PlatformPricing::GUARD_RATE_KEY)->update(['is_active' => false]);
    DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)
        ->update(['is_active' => true, 'basis' => 'guard', 'amount_minor' => 4_500_00]);
    DB::connection('mysql')->table('platform_rate_changes')->delete();
    PlatformPricing::forgetGuardRate();

    $this->client = 'mrragreestest';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->client,
        'name' => 'MRR Agrees Test Estate',
        'receipt_prefix' => 'MAT',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'key' => 'mrragreestest',
        'name' => 'MRR Agrees',
        'price_per_unit_minor' => 340_00,
        'currency' => 'JMD',
        'min_units' => 1,
        'is_active' => true,
        'sort' => 96,
    ]);

    Subscription::query()->create([
        'tenant_id' => $this->client,
        'plan_id' => $plan->id,
        'unit_count' => 450,
        'contracted_guards' => 4,
        'status' => 'active',
        'started_on' => '2026-09-01',
    ]);

    foreach ([1, 2, 3, 4] as $n) {
        Guard::create([
            'full_name' => 'MRR Agrees Guard '.$n,
            'employee_number' => 'GS-MAT'.$n,
            'psra_number' => 'PSRA-MAT'.$n,
            'psra_expires_on' => '2027-09-24',
            'employment_type' => 'full_time',
            'status' => 'active',
            'tenant_id' => $this->client,
        ]);
    }
});

afterEach(function () {
    PlatformPricing::forgetGuardRate();
    DB::connection('mysql')->rollBack();
});

it('prints the dashboard\'s Platform MRR under the same label, guards included', function () {
    $billing = collect(app(BillingOverview::class)->kpis())->firstWhere('key', 'mrr');

    // The dashboard answers for who is looking (bug hunt B007), and the billing screen's
    // figure is the platform's, so the one to compare it with is a Director's.
    $dashboard = collect(app(PlatformOverview::class)->kpis(FacilitiesFixture::geminiViewer(Role::DIRECTOR), true))->firstWhere('key', 'mrr');

    expect($billing['label'])->toBe($dashboard['label'])
        ->and($billing['value'])->toBe($dashboard['value']);

    // And this client's share of it is the board's: 450 × $340 + 4 × $4,500.
    $clientMrr = (int) DB::connection('mysql')->table('subscriptions')
        ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
        ->where('subscriptions.status', 'active')
        ->where('subscriptions.tenant_id', $this->client)
        ->sum(DB::raw(PlatformPricing::mrrSql()));

    expect($clientMrr)->toBe(171_000_00);
});

it('quotes each Not yet invoiced period at what the run would raise it at', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    app(PlatformSettings::class)->addLineItem($this->client, [
        'name' => 'Additional night patrol',
        'type' => 'addition',
        'amount' => '25000',
        'effective_from' => '2026-09-25',
    ], $director);

    $draft = app(InvoiceRun::class)->draft($this->client);

    $row = collect(app(BillingOverview::class)->invoiceRows())->firstWhere('key', 'projected-'.$this->client);

    // 450 × $340 + 4 × $4,500 + $25,000: the tier, the add-on and the line item.
    expect($draft['total_minor'])->toBe(196_000_00)
        ->and($row['period'])->toBe('September 2026')
        ->and($row['amount'])->toBe(MoneyFormatter::fromMinor($draft['total_minor'], 'JMD'))
        ->and($row['due_on'])->toBe($draft['due_on']->format('M j, Y'));

    // Raised, the row moves on to the next period and quotes that one the same way.
    app(InvoiceRun::class)->raise($this->client, $director);

    $next = app(InvoiceRun::class)->draft($this->client);
    $row = collect(app(BillingOverview::class)->invoiceRows())->firstWhere('key', 'projected-'.$this->client);

    expect($row['period'])->toBe('October 2026')
        ->and($row['amount'])->toBe(MoneyFormatter::fromMinor($next['total_minor'], 'JMD'))
        ->and($row['due_on'])->toBe($next['due_on']->format('M j, Y'));
});

it('still quotes a period the run may not raise yet, with the run\'s own arithmetic', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $run = app(InvoiceRun::class);

    // September and October raised: November is more than a month away, so the run refuses it.
    $run->raise($this->client, $director);
    $run->raise($this->client, $director);

    expect(fn () => $run->draft($this->client))->toThrow(DomainException::class, 'at most a month ahead');

    $row = collect(app(BillingOverview::class)->invoiceRows())->firstWhere('key', 'projected-'.$this->client);
    $preview = $run->preview($this->client);

    expect($row['period'])->toBe('November 2026')
        ->and($preview['refusal'])->toContain('at most a month ahead')
        ->and($row['amount'])->toBe(MoneyFormatter::fromMinor($preview['draft']['total_minor'], 'JMD'))
        ->and($preview['draft']['total_minor'])->toBe(171_000_00);
});
