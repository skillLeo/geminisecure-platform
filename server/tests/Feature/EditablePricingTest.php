<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Gemini\BillingOverview;
use App\Services\Gemini\InvoiceRun;
use App\Services\Gemini\PlatformSettings;
use App\Support\MoneyFormatter;
use App\Support\PlatformPricing;
use Database\Seeders\BillingSeeder;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Pricing must be editable — M10 §3, the Gemini half
|--------------------------------------------------------------------------
|
| "Gemini Director can edit the subscription tiers and rates shown in Platform
| settings → Pricing & rates: the per-unit price of each tier, the per-guard
| add-on, and any client line items. Changing a rate must not silently rewrite
| invoices already issued — issued invoices keep the rate they were raised on,
| and the change applies from the next billing period. Every change is
| audited." And: "Both screens show clearly what a change will affect and from
| when."
|
| TIME IS FROZEN ON 24 SEPTEMBER 2026, mid-month, because every rule here is
| about which billing period a date falls in. Run on the 1st of a month, "a
| change dated today" would be first billed for the month under way and half
| of these assertions would be about a different case.
|
| A client of this file's own — its own tier, two guards, a subscription from
| 1 September — inside a transaction, like InvoiceRunTest, so no other file's
| invoices move the period this one raises and nothing here is left behind.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    // The board's own per-guard rate, seeded here for the reason
    // PlatformSettingsWritesTest gives: the suite runs in random order.
    if (! DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->exists()) {
        Artisan::call('db:seed', ['--class' => BillingSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PlatformCatalogueSeeder::class, '--force' => true]);
    }

    $this->travelTo(Carbon::parse('2026-09-24 10:00:00'));
    $this->withoutVite();

    // Only that rate is active, at its board price, so the add-on line is known.
    DB::connection('mysql')->table('platform_rates')->where('key', '!=', PlatformPricing::GUARD_RATE_KEY)->update(['is_active' => false]);
    DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)
        ->update(['is_active' => true, 'basis' => 'guard', 'amount_minor' => 4_500_00]);

    $this->rate = DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->first();

    // Remembered for the life of the process — whatever an earlier file read.
    PlatformPricing::forgetGuardRate();

    $this->client = 'editablepricingtest';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->client,
        'name' => 'Editable Pricing Test Estate',
        'receipt_prefix' => 'EPT',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->plan = Plan::query()->create([
        'key' => 'editablepricingtest',
        'name' => 'Pricing Test',
        'price_per_unit_minor' => 300_00,
        'currency' => 'JMD',
        'min_units' => 1,
        'is_active' => true,
        'sort' => 98,
    ]);

    Subscription::query()->create([
        'tenant_id' => $this->client,
        'plan_id' => $this->plan->id,
        'unit_count' => 40,
        'contracted_guards' => 2,
        'status' => 'active',
        'started_on' => '2026-09-01',
    ]);

    foreach ([1, 2] as $n) {
        Guard::create([
            'full_name' => 'Pricing Test Guard '.$n,
            'employee_number' => 'GS-EPT'.$n,
            'psra_number' => 'PSRA-EPT'.$n,
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

/** An issued invoice exactly as stored, and exactly as the invoice screen reads it. */
function issuedInvoice(int $invoiceId): array
{
    return [
        'invoice' => (array) DB::connection('mysql')->table('invoices')->where('id', $invoiceId)->first(),
        'lines' => DB::connection('mysql')->table('invoice_lines')->where('invoice_id', $invoiceId)->orderBy('id')->get()
            ->map(fn (object $line): array => (array) $line)->all(),
        'screen' => app(BillingOverview::class)->invoice($invoiceId),
    ];
}

/** @return list<array{0: string, 1: int, 2: int, 3: int}> */
function linesOf(array $lines): array
{
    return array_map(
        fn (array $line): array => [(string) $line['description'], (int) $line['quantity'], (int) $line['unit_price_minor'], (int) $line['total_minor']],
        $lines,
    );
}

it('changes the per-guard add-on from a date, never retroactively and never unexplained, audited, and applied when due', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $settings = app(PlatformSettings::class);
    $rateId = (int) $this->rate->id;

    // THE PERMISSION IS THE REFUSAL, not a hidden button — and since M11 B3 it
    // is the prices one, not the whole of configure.
    expect(Route::getRoutes()->getByName('gemini.platform_settings.rate')->gatherMiddleware())
        ->toContain('can:gemini.platform_settings.set_prices');

    $this->actingAs($dispatcher)
        ->post('/settings/rates', ['rate_id' => $rateId, 'amount' => '5000.00', 'effective_from' => '2026-09-24', 'reason' => 'x'])
        ->assertForbidden();

    // NEVER RETROACTIVE.
    $this->actingAs($director)
        ->post('/settings/rates', ['rate_id' => $rateId, 'amount' => '5000.00', 'effective_from' => '2026-09-23', 'reason' => 'Backdated'])
        ->assertSessionHasErrors('rate');

    // NEVER UNEXPLAINED.
    $this->actingAs($director)
        ->post('/settings/rates', ['rate_id' => $rateId, 'amount' => '5000.00', 'effective_from' => '2026-09-24', 'reason' => ''])
        ->assertSessionHasErrors('reason');

    // NOT A CHANGE NOBODY MADE.
    $this->actingAs($director)
        ->post('/settings/rates', ['rate_id' => $rateId, 'amount' => '4500.00', 'effective_from' => '2026-09-24', 'reason' => 'Same again'])
        ->assertSessionHasErrors('rate');

    expect((int) DB::connection('mysql')->table('platform_rates')->where('id', $rateId)->value('amount_minor'))->toBe(4_500_00)
        ->and(DB::connection('mysql')->table('platform_rate_changes')->where('platform_rate_id', $rateId)->exists())->toBeFalse();

    // Dated today: in force at once, and the flash says who it reaches and from which period.
    $this->actingAs($director)
        ->post('/settings/rates', [
            'rate_id' => $rateId,
            'amount' => '5000.00',
            'effective_from' => '2026-09-24',
            'reason' => 'Guard wage settlement, board minute 2026-09.',
        ])
        ->assertRedirect('/settings')
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'in force from today')
            && str_contains($flash, 'Takes effect for invoices for October 2026 onward (the next billing period).')
            && str_contains($flash, 'Editable Pricing Test Estate (2 guards)')
            && str_contains($flash, 'Invoices already issued are not changed.'));

    expect((int) DB::connection('mysql')->table('platform_rates')->where('id', $rateId)->value('amount_minor'))->toBe(5_000_00);

    $change = DB::connection('mysql')->table('platform_rate_changes')->where('platform_rate_id', $rateId)->sole();

    expect((int) $change->from_minor)->toBe(4_500_00)
        ->and((int) $change->to_minor)->toBe(5_000_00)
        ->and($change->applied_at)->not->toBeNull()
        ->and((int) $change->changed_by_id)->toBe($director->getKey());

    // EVERY CHANGE IS AUDITED: actor, before, after, effective date, reason.
    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'platform.rate_changed')->orderByDesc('id')->first();
    $before = json_decode((string) $entry->before, true);
    $after = json_decode((string) $entry->after, true);

    expect($entry->actor_name)->toBe($director->name)
        ->and($entry->entity_id)->toBe((string) $rateId)
        ->and($before['amount_minor'])->toBe(4_500_00)
        ->and($after['amount_minor'])->toBe(5_000_00)
        ->and($after['effective_from'])->toBe('2026-09-24')
        ->and($after['first_billed_period'])->toBe('2026-10')
        ->and($after['reason'])->toContain('Guard wage settlement')
        ->and($after['applied'])->toBeTrue()
        ->and($after['clients'])->toContain('Editable Pricing Test Estate');

    // Dated ahead: recorded, and nothing has changed yet.
    $settings->changeRate($rateId, '5250.00', '2026-11-01', 'Second step of the settlement', $director);

    $pending = collect($settings->pendingPriceChanges())->where('kind', 'rate')->values();

    expect((int) DB::connection('mysql')->table('platform_rates')->where('id', $rateId)->value('amount_minor'))->toBe(5_000_00)
        ->and($pending)->toHaveCount(1)
        ->and($pending[0]['key'])->toBe('rate-'.$pending[0]['id'])
        ->and($pending[0]['per'])->toBe('guard')
        ->and($pending[0]['first_billed'])->toBe('November 2026');

    // The day arrives, and it is applied once.
    $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));

    expect($settings->applyDuePriceChanges())->toBe(1)
        ->and((int) DB::connection('mysql')->table('platform_rates')->where('id', $rateId)->value('amount_minor'))->toBe(5_250_00)
        ->and($settings->applyDuePriceChanges())->toBe(0);
});

it('never rewrites an issued invoice, and raises the next billing period at the new tier price and the new rate', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $settings = app(PlatformSettings::class);
    $run = app(InvoiceRun::class);

    $september = $run->raise($this->client, $director);
    $issued = issuedInvoice($september->id);

    expect($september->period_start->toDateString())->toBe('2026-09-01')
        ->and(linesOf($issued['lines']))->toBe([
            ['Pricing Test subscription', 40, 300_00, 12_000_00],
            [(string) $this->rate->label, 2, 4_500_00, 9_000_00],
        ])
        ->and((int) $issued['invoice']['total_minor'])->toBe(21_000_00);

    // Both changed, from today, and the due changes swept.
    $settings->changePlanPrice($this->plan->id, '350.00', '2026-09-24', 'Annual review.', $director);
    $settings->changeRate((int) $this->rate->id, '5000.00', '2026-09-24', 'Guard wage settlement.', $director);
    $settings->applyDuePriceChanges();

    // THE ISSUED INVOICE IS BYTE-IDENTICAL: its row, its lines, and the screen that reads it.
    expect(issuedInvoice($september->id))->toBe($issued);

    // THE NEXT BILLING PERIOD CARRIES THE NEW PRICES.
    $october = $run->raise($this->client, $director);

    expect($october->period_start->toDateString())->toBe('2026-10-01')
        ->and(linesOf(issuedInvoice($october->id)['lines']))->toBe([
            ['Pricing Test subscription', 40, 350_00, 14_000_00],
            [(string) $this->rate->label, 2, 5_000_00, 10_000_00],
        ])
        ->and($october->total_minor)->toBe(24_000_00)

        // And raising it moved nothing on September's either.
        ->and(issuedInvoice($september->id))->toBe($issued);
});

it('prices a period on its first day, so a change never reaches the period under way and reaches the one it is dated for', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $settings = app(PlatformSettings::class);
    $run = app(InvoiceRun::class);
    $rateId = (int) $this->rate->id;

    // Changed today, 24 September, with September not yet invoiced.
    $settings->changePlanPrice($this->plan->id, '350.00', '2026-09-24', 'Annual review.', $director);
    $settings->changeRate($rateId, '5000.00', '2026-09-24', 'Guard wage settlement.', $director);

    // Today's columns moved — every projection reads them —
    expect((int) Plan::query()->whereKey($this->plan->id)->value('price_per_unit_minor'))->toBe(350_00)
        ->and((int) DB::connection('mysql')->table('platform_rates')->where('id', $rateId)->value('amount_minor'))->toBe(5_000_00);

    // — and September is still raised at what September started at.
    expect(linesOf($run->draft($this->client)['lines']))->toBe([
        ['Pricing Test subscription', 40, 300_00, 12_000_00],
        [(string) $this->rate->label, 2, 4_500_00, 9_000_00],
    ]);

    $run->raise($this->client, $director);

    /*
     * A change dated 1 October reaches October — billed in advance on 24
     * September, before any sweep could have applied it to the column.
     */
    $settings->changeRate($rateId, '5500.00', '2026-10-01', 'October step.', $director);

    expect((int) DB::connection('mysql')->table('platform_rates')->where('id', $rateId)->value('amount_minor'))->toBe(5_000_00);

    $october = $run->draft($this->client);

    expect(linesOf($october['lines']))->toBe([
        ['Pricing Test subscription', 40, 350_00, 14_000_00],
        [(string) $this->rate->label, 2, 5_500_00, 11_000_00],
    ]);

    // The preview quotes what the raise will post, not today's column.
    $this->actingAs($director)
        ->get('/billing/preview/'.$this->client)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bill.total', MoneyFormatter::whole($october['total_minor'], 'JMD').'/mo'));
});

it('says, before a change is saved, what it will affect and from when', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $settings = app(PlatformSettings::class);
    $run = app(InvoiceRun::class);
    $planId = (int) $this->plan->id;
    $rateId = (int) $this->rate->id;

    // Invoiced through October, in advance — so a change from October does not reach this client until November.
    $run->raise($this->client, $director);
    $run->raise($this->client, $director);

    $this->actingAs($director)
        ->get('/settings?change=tier-'.$planId.'&from=2026-10-01')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('nextPeriodStart', '2026-10-01')
            ->has('editableRates', 1)
            ->where('editableRates.0.id', $rateId)
            ->where('rates', fn ($rates): bool => $rates->contains(
                fn (array $row): bool => $row['target'] === 'rate-'.$rateId && $row['editable'] === true
            ))
            ->where('impact.kind', 'tier')
            ->where('impact.id', $planId)
            ->where('impact.effective_from', '2026-10-01')
            ->where('impact.period', '2026-10')
            ->where('impact.refused', null)
            ->where('impact.lines.0', 'Takes effect for invoices for October 2026 onward (the next billing period).')
            ->where('impact.lines.1', '1 client on this tier: Editable Pricing Test Estate.')
            ->where('impact.lines.2', "Already invoiced for October 2026 and not changed: Editable Pricing Test Estate. Its first invoice at the new price is November 2026's.")
            ->where('impact.lines.3', 'Invoices already issued are not changed.')
            // The panel asks again as the date changes, for this prop alone.
            ->reloadOnly('impact', fn (AssertableInertia $reload) => $reload->missing('admins')));

    // The per-guard add-on names who it reaches by their guards.
    $this->actingAs($director)
        ->get('/settings?change=rate-'.$rateId.'&from=2026-10-01')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('impact.kind', 'rate')
            ->where('impact.lines.1', fn (string $line): bool => str_contains($line, 'Editable Pricing Test Estate (2 guards)')));

    // A stale or hand-edited query lands on the next billing period rather than an error.
    $this->actingAs($director)
        ->get('/settings?change=tier-0&from=2026-02-31')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('impact.effective_from', '2026-10-01'));

    // Dated inside a month, it says the period is priced on its first day.
    expect($settings->changeImpact('tier', $planId, '2026-09-24')['lines'][0])
        ->toBe('Takes effect for invoices for October 2026 onward (the next billing period). It is dated 24 September 2026, and a period is invoiced at the price in force on its first day.')

        // Dated further ahead, it names the months it does not reach.
        ->and($settings->changeImpact('tier', $planId, '2026-12-01')['lines'][0])
        ->toBe('Takes effect for invoices for December 2026 onward. Invoices for October 2026 to November 2026 are not affected by it.')

        // Backdated, the panel says why before the save does.
        ->and($settings->changeImpact('tier', $planId, '2026-09-23')['refused'])->toContain('never retroactive');
});

it('forgets the remembered guard rate, so a revenue figure never multiplies by the old one', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $settings = app(PlatformSettings::class);
    $rateId = (int) $this->rate->id;

    $mrr = fn (): int => (int) DB::connection('mysql')->table('subscriptions')
        ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
        ->where('subscriptions.tenant_id', $this->client)
        ->sum(DB::raw(PlatformPricing::mrrSql()));

    // Read first, so the process remembers the old rate — the case that bites.
    expect(PlatformPricing::guardRateMinor())->toBe(4_500_00)
        ->and($mrr())->toBe(12_000_00 + 9_000_00);

    $settings->changeRate($rateId, '5000.00', '2026-09-24', 'Guard wage settlement.', $director);

    expect(PlatformPricing::guardRateMinor())->toBe(5_000_00)
        ->and($mrr())->toBe(12_000_00 + 10_000_00);

    // And the sweep forgets it too, when a dated change comes due.
    $settings->changeRate($rateId, '5250.00', '2026-10-01', 'October step.', $director);

    expect(PlatformPricing::guardRateMinor())->toBe(5_000_00);

    $this->travelTo(Carbon::parse('2026-10-01 08:00:00'));
    $settings->applyDuePriceChanges();

    expect(PlatformPricing::guardRateMinor())->toBe(5_250_00)
        ->and($mrr())->toBe(12_000_00 + 10_500_00);
});

it('lists pending tier and rate changes together, each under its own key', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $settings = app(PlatformSettings::class);

    $settings->changePlanPrice($this->plan->id, '350.00', '2026-10-01', 'Tier step.', $director);
    $settings->changeRate((int) $this->rate->id, '5000.00', '2026-10-01', 'Rate step.', $director);

    $pending = collect($settings->pendingPriceChanges());

    /*
     * THE IDS COLLIDE ACROSS THE TWO TABLES — both count from 1 — so the
     * screen keys each row on `kind-id` and the server says so.
     */
    expect($pending->pluck('kind')->unique()->sort()->values()->all())->toBe(['rate', 'tier'])
        ->and($pending->pluck('key')->unique())->toHaveCount($pending->count())
        ->and($pending->every(fn (array $row): bool => $row['key'] === $row['kind'].'-'.$row['id']))->toBeTrue()
        ->and($pending->firstWhere('kind', 'tier')['per'])->toBe('unit')
        ->and($pending->firstWhere('kind', 'rate')['per'])->toBe('guard')
        ->and($pending->pluck('first_billed')->unique()->values()->all())->toBe(['October 2026']);
});

it('adds, withdraws and ends client line items from the screen, saying which invoice each reaches, and never touches one issued', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $run = app(InvoiceRun::class);

    $september = $run->raise($this->client, $director);
    $issued = issuedInvoice($september->id);

    // The panel's question, answered before the press.
    $this->actingAs($director)
        ->get('/settings/line-items?client='.$this->client.'&from=2026-09-24')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected', $this->client)
            ->where('today', '2026-09-24')
            ->where('addImpact.effective_from', '2026-09-24')
            ->where('addImpact.period', '2026-10')
            ->where('addImpact.refused', null)
            ->where('addImpact.lines.0', "First billed on this client's invoice for October 2026, at the full monthly amount. An override is billed in full for every month it touches, not pro-rated.")
            ->where('addImpact.lines.1', 'This client is already invoiced for September 2026, and an issued invoice is not changed, so it starts with the next one.')
            ->where('endImpact.refused', null));

    // Added from today: first on October's invoice, and the flash says so.
    $this->actingAs($director)
        ->post('/settings/line-items', [
            'client' => $this->client,
            'name' => 'Extra patrol reporting',
            'reason' => 'Asked for at the September meeting.',
            'type' => 'addition',
            'amount' => '2500.00',
            'effective_from' => '2026-09-24',
        ])
        ->assertRedirect('/settings/line-items?client='.$this->client)
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, "First billed on this client's invoice for October 2026"));

    $added = json_decode((string) DB::connection('mysql')->table('audit_log')
        ->where('action', 'platform.line_item_added')->orderByDesc('id')->value('after'), true);

    expect($added['reason'])->toBe('Asked for at the September meeting.')
        ->and($added['by'])->toBe($director->name)
        ->and($added['first_billed_period'])->toBe('2026-10');

    // Recorded ahead, it can be seen again — and withdrawn before it starts.
    $this->actingAs($director)
        ->post('/settings/line-items', [
            'client' => $this->client,
            'name' => 'Quarterly audit pack',
            'type' => 'addition',
            'amount' => '1000.00',
            'effective_from' => '2026-11-01',
        ])
        ->assertRedirect();

    $upcoming = collect(app(PlatformSettings::class)->lineItems($this->client)['upcoming']);

    expect($upcoming->pluck('name')->all())->toBe(['Quarterly audit pack']);

    $this->actingAs($director)
        ->post('/settings/line-items/'.$upcoming[0]['id'].'/end', ['client' => $this->client, 'effective_to' => '2026-09-24'])
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'withdrawn before it started'));

    $ended = json_decode((string) DB::connection('mysql')->table('audit_log')
        ->where('action', 'platform.line_item_ended')->orderByDesc('id')->value('after'), true);

    expect($ended['withdrawn'])->toBeTrue()
        ->and(app(PlatformSettings::class)->lineItems($this->client)['upcoming'])->toBe([]);

    // October carries the addition, and nothing on September moved.
    $october = $run->raise($this->client, $director);

    expect(collect(issuedInvoice($october->id)['lines'])->pluck('description')->all())->toContain('Extra patrol reporting')
        ->and(issuedInvoice($september->id))->toBe($issued);

    // Ending it now says October's invoice keeps it — it is issued.
    expect(app(PlatformSettings::class)->lineItemImpact($this->client, '2026-09-24', ending: true)['lines'])->toBe([
        'On no invoice raised from now on: this client is already invoiced for September 2026 to October 2026, and its next invoice, for November 2026, will not carry it.',
        'An issued invoice that carried it still does. A correction to one is a credit note.',
        'Invoices already issued are not changed.',
    ]);

    // The withdrawn one is on no invoice, even for the month it was dated for.
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    expect(collect($run->draft($this->client)['lines'])->pluck('description')->all())->not->toContain('Quarterly audit pack');
});
