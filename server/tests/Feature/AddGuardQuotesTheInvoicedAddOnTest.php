<?php

declare(strict_types=1);

use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Gemini\InvoiceRun;
use App\Support\MoneyFormatter;
use App\Support\PlatformPricing;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The Add Guard form's add-on figure (bug hunt B011)
|--------------------------------------------------------------------------
|
| Board 22 says what one more officer costs the client. The form computed it
| from every guard row at the estate — suspended and on leave included — which
| is neither the contracted count nor what the invoice bills, and then said the
| add-on "does not change by itself" when the invoice run counts the guards
| deployed on the day it is raised, so the new officer is on the next invoice.
|
| And it quoted that charge to the Dispatcher and the Head of Security, neither
| of whom holds Billing & subscriptions (M10 §5): what a client is charged is
| not a figure a role may read on a screen of a module it does hold.
|
| The client here has 4 guards contracted, 2 deployed, 1 suspended and 1 on
| leave. The invoice bills 2; the form must quote 2, and then 3.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->client = 'addguardquote';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->client,
        'name' => 'Add Guard Quote Estate',
        'receipt_prefix' => 'AGQ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'key' => 'addguardquote',
        'name' => 'Quote Test',
        'price_per_unit_minor' => 300_00,
        'currency' => 'JMD',
        'min_units' => 1,
        'is_active' => true,
        'sort' => 99,
    ]);

    Subscription::query()->create([
        'tenant_id' => $this->client,
        'plan_id' => $plan->id,
        'unit_count' => 40,
        'contracted_guards' => 4,
        'status' => 'active',
        'started_on' => now()->startOfMonth()->toDateString(),
    ]);

    // The platform's own per-guard add-on, and nothing else billed per guard.
    DB::connection('mysql')->table('platform_rates')->where('basis', 'guard')->update(['is_active' => false]);

    $existing = DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->exists();

    $rate = [
        'label' => 'Security Provider add-on',
        'applies_to' => 'Applies on top of any tier',
        'amount_minor' => 4_500_00,
        'currency' => 'JMD',
        'basis' => 'guard',
        'is_active' => true,
        'sort' => 10,
        'updated_at' => now(),
    ];

    $existing
        ? DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->update($rate)
        : DB::connection('mysql')->table('platform_rates')->insert(['key' => PlatformPricing::GUARD_RATE_KEY, 'created_at' => now()] + $rate);

    DB::connection('mysql')->table('platform_rate_changes')->delete();
    PlatformPricing::forgetGuardRate();

    foreach (['active', 'active', 'suspended', 'on_leave'] as $n => $status) {
        Guard::query()->create([
            'full_name' => 'Quote Officer '.$n,
            'employee_number' => 'GS-AGQ-'.$n.'-'.uniqid(),
            'psra_number' => 'PSRA-AGQ-'.$n.'-'.uniqid(),
            'psra_expires_on' => now()->addYear()->toDateString(),
            'employment_type' => 'full_time',
            'status' => $status,
            'tenant_id' => $this->client,
        ]);
    }
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    PlatformPricing::forgetGuardRate();
});

/** The client's entry in the Add Guard form's picker, as this viewer receives it. */
function addGuardQuoteFor(User $viewer, string $client): array
{
    $clients = [];

    test()->actingAs($viewer)
        ->get('/guards/new')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$clients): void {
            $clients = $page->toArray()['props']['clients'];
        });

    return collect(json_decode((string) json_encode($clients), true))->firstWhere('id', $client) ?? [];
}

/** What the next invoice would bill this client for its guards, in minor units. */
function addGuardInvoicedMinor(string $client): int
{
    return (int) collect(app(InvoiceRun::class)->draft($client)['lines'])
        ->where('description', 'Security Provider add-on')
        ->sum('total_minor');
}

it('quotes the add-on from the guards the invoice bills, and the next invoice bills what it quoted', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $quote = addGuardQuoteFor($director, $this->client);

    // Two deployed — not four rows, not four contracted.
    expect(addGuardInvoicedMinor($this->client))->toBe(2 * 4_500_00)
        ->and($quote['guards_now'])->toBe(2)
        ->and($quote['next_ordinal'])->toBe('3rd')
        ->and($quote['addon_now'])->toBe(MoneyFormatter::whole(2 * 4_500_00, 'JMD'))
        ->and($quote['addon_next'])->toBe(MoneyFormatter::whole(3 * 4_500_00, 'JMD'));

    $this->actingAs($director)
        ->post('/guards', [
            'full_name' => 'Quote New Hire',
            'psra_number' => 'PSRA-AGQ-NEW-'.uniqid(),
            'psra_expires_on' => now()->addYear()->toDateString(),
            'phone' => '876-555-0101',
            'email' => 'quote.new.hire@example.com',
            'employment_type' => 'full_time',
            'tenant_id' => $this->client,
            'post_id' => '',
            'standard_rate' => '425.00',
        ])
        ->assertSessionHasNoErrors();

    // The officer is on the next invoice straight away: the figure the form said it would become.
    expect(MoneyFormatter::whole(addGuardInvoicedMinor($this->client), 'JMD'))->toBe($quote['addon_next']);
});

it('quotes no charge at all to a Dispatcher or a Head of Security, who hold no Billing', function () {
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create([
        'user_id' => $head->id,
        'tenant_id' => $this->client,
        'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id,
        'is_active' => true,
    ]);

    foreach ([$dispatcher, $head->fresh()] as $viewer) {
        $quote = addGuardQuoteFor($viewer, $this->client);

        expect($quote)->not->toBe([])
            ->and($quote)->not->toHaveKey('addon_now')
            ->and($quote)->not->toHaveKey('addon_next')
            ->and(preg_match('/\$\s?\d[\d,]{2,}/', (string) json_encode($quote)))->toBe(0);
    }
});
