<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Gemini\InvoiceRun;
use App\Services\Gemini\PlatformSettings;
use App\Support\MoneyFormatter;
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
| The preview's "Raise … at J$X?" is the total the raise posts (B076)
|--------------------------------------------------------------------------
|
| The preview used to price the tier and the add-on for the period being
| previewed, but pick the client's line items by TODAY's date — while the
| invoice run picks them by the period. So on 25 September, previewing
| October:
|
|   an addition dated 1 October was left out of the quoted total, and posted;
|   a removal ended 30 September was taken off the quote, and not the invoice;
|   a one-off from an earlier month was quoted every month, and never posted.
|
| An issued invoice can only be corrected by a credit note, so the one figure
| the confirmation quotes has to be the one the raise posts. The preview now
| IS the run's draft.
|
| TIME: 25 September 2026, 10:00 in Kingston. September is invoiced, so the
| next period is October — a month ahead, which the run allows.
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
    $this->withoutVite();

    DB::connection('mysql')->table('platform_rates')->where('key', '!=', PlatformPricing::GUARD_RATE_KEY)->update(['is_active' => false]);
    DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)
        ->update(['is_active' => true, 'basis' => 'guard', 'amount_minor' => 4_500_00]);
    DB::connection('mysql')->table('platform_rate_changes')->delete();
    PlatformPricing::forgetGuardRate();

    $this->client = 'previewtotaltest';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->client,
        'name' => 'Preview Total Test Estate',
        'receipt_prefix' => 'PTT',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'key' => 'previewtotaltest',
        'name' => 'Preview Total',
        'price_per_unit_minor' => 300_00,
        'currency' => 'JMD',
        'min_units' => 1,
        'is_active' => true,
        'sort' => 97,
    ]);

    Subscription::query()->create([
        'tenant_id' => $this->client,
        'plan_id' => $plan->id,
        'unit_count' => 40,
        'contracted_guards' => 2,
        'status' => 'active',
        'started_on' => '2026-09-01',
    ]);

    foreach ([1, 2] as $n) {
        Guard::create([
            'full_name' => 'Preview Total Guard '.$n,
            'employee_number' => 'GS-PTT'.$n,
            'psra_number' => 'PSRA-PTT'.$n,
            'psra_expires_on' => '2027-09-24',
            'employment_type' => 'full_time',
            'status' => 'active',
            'tenant_id' => $this->client,
        ]);
    }

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    // September: 40 × $300 + 2 × $4,500 = $21,000. October is next.
    app(InvoiceRun::class)->raise($this->client, $this->director);
});

afterEach(function () {
    PlatformPricing::forgetGuardRate();
    DB::connection('mysql')->rollBack();
});

/** The preview's quoted total, as the confirmation reads it, and the raise's posted one. */
function quotedThenPosted(object $test): array
{
    $quoted = null;
    $projection = [];

    $test->actingAs($test->director)
        ->get('/billing/preview/'.$test->client)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$quoted, &$projection) {
            $page->component('Gemini/Billing/Preview')->where('projection.period', 'October 2026');
            $quoted = $page->toArray()['props']['bill']['total'];
            $projection = $page->toArray()['props']['projection'];
        });

    // Confirmed as the screen confirms it: the period and the total it quoted go with the press.
    $test->actingAs($test->director)
        ->post('/billing/preview/'.$test->client.'/raise', [
            'period_start' => $projection['period_start'],
            'total_minor' => $projection['total_minor'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $october = Invoice::query()->where('tenant_id', $test->client)->whereDate('period_start', '2026-10-01')->sole();

    return [$quoted, $october];
}

it('quotes an addition that starts inside the period, because the raise posts it', function () {
    app(PlatformSettings::class)->addLineItem($this->client, [
        'name' => 'Additional night patrol',
        'type' => 'addition',
        'amount' => '25000',
        'effective_from' => '2026-10-01',
    ], $this->director);

    [$quoted, $october] = quotedThenPosted($this);

    expect($october->total_minor)->toBe(46_000_00)
        ->and($quoted)->toBe(MoneyFormatter::whole($october->total_minor, 'JMD').'/mo');
});

it('does not quote a removal that ends before the period, because the raise does not post it', function () {
    $settings = app(PlatformSettings::class);

    $settings->addLineItem($this->client, [
        'name' => 'Pilot discount',
        'type' => 'removal',
        'amount' => '10000',
        'effective_from' => '2026-09-25',
    ], $this->director);

    $settings->endLineItem(
        (int) DB::connection('mysql')->table('subscription_line_items')->where('tenant_id', $this->client)->value('id'),
        '2026-09-30',
        $this->director,
    );

    [$quoted, $october] = quotedThenPosted($this);

    expect($october->total_minor)->toBe(21_000_00)
        ->and($quoted)->toBe(MoneyFormatter::whole($october->total_minor, 'JMD').'/mo');
});

it('does not quote a one-off from an earlier month, because it belongs to that month alone', function () {
    DB::connection('mysql')->table('subscription_line_items')->insert([
        'tenant_id' => $this->client,
        'type' => 'addition',
        'description' => 'Migration fee',
        'reason' => 'Billed once',
        'amount_minor' => 9_999_00,
        'currency' => 'JMD',
        'recurrence' => 'one_off',
        'effective_from' => '2026-09-01',
        'effective_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [$quoted, $october] = quotedThenPosted($this);

    expect($october->total_minor)->toBe(21_000_00)
        ->and($quoted)->toBe(MoneyFormatter::whole($october->total_minor, 'JMD').'/mo');
});

it('draws the preview from the run\'s own lines, section by section', function () {
    $settings = app(PlatformSettings::class);

    $settings->addLineItem($this->client, [
        'name' => 'Additional night patrol',
        'type' => 'addition',
        'amount' => '25000',
        'effective_from' => '2026-10-01',
    ], $this->director);

    $settings->addLineItem($this->client, [
        'name' => 'Loyalty removal',
        'type' => 'removal',
        'amount' => '1000',
        'effective_from' => '2026-10-15',
    ], $this->director);

    $draft = app(InvoiceRun::class)->draft($this->client);

    $this->actingAs($this->director)
        ->get('/billing/preview/'.$this->client)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bill.base.0.price', '$12,000/mo')
            ->where('bill.base.1.price', '$9,000/mo')
            ->where('bill.additions.0.name', 'Additional night patrol')
            ->where('bill.additions.0.price', '$25,000/mo')
            ->where('bill.removals.0.name', 'Loyalty removal')
            // The sign is in the price, as on board 44 — the screen does not add a second one.
            ->where('bill.removals.0.price', '−$1,000/mo')
            ->where('bill.total', MoneyFormatter::whole($draft['total_minor'], 'JMD').'/mo')
            ->where('projection.due_on', $draft['due_on']->format('M j, Y')));

    expect($draft['total_minor'])->toBe(12_000_00 + 9_000_00 + 25_000_00 - 1_000_00);
});

it('says on the screen that the line items are the period\'s, not today\'s', function () {
    $screen = (string) file_get_contents(resource_path('js/Pages/Gemini/Billing/Preview.vue'));
    $template = preg_match('/<template>(.*)<\/template>/s', $screen, $found) === 1 ? $found[1] : $screen;
    $template = (string) preg_replace('/<!--.*?-->/s', '', $template);

    expect($template)->not->toContain('line items in force today')
        // A removal's price already carries its sign; a second one read "−−$1,000/mo".
        ->and($template)->not->toContain('−{{ row.price }}');
});
