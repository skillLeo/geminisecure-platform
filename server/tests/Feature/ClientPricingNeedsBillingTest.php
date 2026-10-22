<?php

declare(strict_types=1);

use App\Models\EstateAssignment;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Gemini\ClientPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Pricing a client asks for Billing & subscriptions — M11 B3's audit, D-153
|--------------------------------------------------------------------------
|
| `gemini.clients.update` opens the roster, the committee's messages, going live
| — and the plan screen, which shows every tier's price, the per-guard rate and
| the platform's whole MRR, and re-prices the client. `gemini.clients.create`
| opens the new-client form, which prices the client it records. The Head of
| Security holds both (Scoped is Full on its assigned sites) and holds no Billing,
| so one module's permission carried a second module's power.
|
| M11 B3's permission audit found it; bug hunt B004 and B069 closed it first, and
| more strictly than the audit had: the two screens and their two writes need
| one of Billing & subscriptions' writes, `ClientPlan::PRICING_PERMISSION` — the
| Price clients act since M11 B3 split Billing's `update` into its four powers —
| because choosing a price changes what a client owes. This file holds the
| audit's finding against that fix: operations stay on `clients.update`, pricing
| does not, and the directory's "Onboard new client" says which of the two a
| reader lacks.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // A client recorded through the form would otherwise build a real estate
    // database, which no rolled-back transaction can take away.
    Event::fake([TenantCreated::class]);

    $this->plan = Plan::query()->create([
        'key' => 'pricingneedsbilling',
        'name' => 'Pricing Needs Billing',
        'price_per_unit_minor' => 175_00,
        'currency' => 'JMD',
        'min_units' => 10,
        'is_active' => true,
        'sort' => 97,
    ]);

    $this->client = FacilitiesFixture::ESTATE;

    $this->newClient = [
        'name' => 'Pricing Test Gardens',
        'address' => '3 Pricing Road, St. Andrew',
        'phases' => 1,
        'plan_id' => $this->plan->id,
        'units' => 40,
        'guards' => 0,
        'term_months' => 12,
        'contact_name' => 'Pat Pricing',
        'contact_email' => 'president@pricingtest.test',
        'contact_phone' => '+1 876 555 0199',
        'timezone' => 'America/Jamaica',
    ];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('gates the plan screen, its save and the new-client form on Billing\'s Price clients act as well as on Clients', function () {
    $money = 'can:'.ClientPlan::PRICING_PERMISSION;

    expect(ClientPlan::PRICING_PERMISSION)->toBe('gemini.billing_subscriptions.price_clients');

    foreach ([
        'gemini.clients.plan' => 'can:gemini.clients.update',
        'gemini.clients.plan.save' => 'can:gemini.clients.update',
        'gemini.clients.create' => 'can:gemini.clients.create',
        'gemini.clients.store' => 'can:gemini.clients.create',
    ] as $name => $module) {
        $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

        expect($middleware)->toContain($module)->toContain($money);
    }

    // And nothing else on a client asks for the money: operations stay operations.
    foreach (['gemini.clients.guards', 'gemini.clients.guards.save', 'gemini.clients.message', 'gemini.clients.message.send', 'gemini.clients.onboarding.complete'] as $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->not->toContain($money);
    }
});

it('refuses the Head of Security the pricing of its own sites and the taking on of a client, and leaves it the rest', function () {
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    EstateAssignment::create([
        'user_id' => $head->id,
        'tenant_id' => $this->client,
        'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id,
        'is_active' => true,
    ]);

    // As the matrix gives it: Clients, on its sites, and no money module.
    expect($head->can('gemini.clients.update'))->toBeTrue()
        ->and($head->can('gemini.clients.create'))->toBeTrue()
        ->and($head->can('gemini.billing_subscriptions.view'))->toBeFalse();

    // THE CONTROL: this client is the Head of Security's to reach and to manage.
    $this->actingAs($head)->get('/clients/'.$this->client)->assertOk();
    $this->actingAs($head)->get('/clients/'.$this->client.'/guards')->assertOk();

    $subscription = Subscription::query()->where('tenant_id', $this->client)->first()?->toArray();
    $tenants = Tenant::query()->count();

    // The pricing is not — screen or save — and neither is a new client.
    $this->actingAs($head)->get('/clients/'.$this->client.'/plan')->assertForbidden();
    $this->actingAs($head)->post('/clients/'.$this->client.'/plan', [
        'plan_id' => $this->plan->id, 'units' => 40, 'guards' => 0, 'term_months' => 12,
    ])->assertForbidden();

    $this->actingAs($head)->get('/clients/new')->assertForbidden();
    $this->actingAs($head)->post('/clients/new', $this->newClient)->assertForbidden();

    expect(Subscription::query()->where('tenant_id', $this->client)->first()?->toArray())->toBe($subscription)
        ->and(Tenant::query()->count())->toBe($tenants);

    // The directory says so on the button rather than leading to a 403.
    $this->actingAs($head)->get('/clients')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCreate', false)
            ->where('createBlockedReason', fn (string $reason) => str_contains($reason, 'Price clients permission on Billing & subscriptions')));
});

it('keeps pricing and onboarding with the Director, withholds them from a reader of Billing, and tells each reader why not', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)->get('/clients/'.$this->client.'/plan')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Gemini/Clients/Plan'));

    $this->actingAs($director)->get('/clients/new')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Gemini/Clients/Create'));

    $this->actingAs($director)->get('/clients')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', true)->where('createBlockedReason', null));

    // The Director takes a client on, through the route.
    $this->actingAs($director)
        ->post('/clients/new', $this->newClient)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(Tenant::query()->where('name', 'Pricing Test Gardens')->exists())->toBeTrue();

    /*
     * THE OPERATIONS MANAGER HOLDS CLIENTS IN FULL AND BILLING TO READ (B069).
     * Reading what a client pays is not setting it, so it may do neither, and
     * the button names the permission it lacks rather than saying it holds no Billing.
     */
    $operations = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);

    expect($operations->can('gemini.clients.create'))->toBeTrue()
        ->and($operations->can('gemini.billing_subscriptions.view'))->toBeTrue()
        ->and($operations->can(ClientPlan::PRICING_PERMISSION))->toBeFalse();

    $this->actingAs($operations)->get('/clients/'.$this->client.'/plan')->assertForbidden();
    $this->actingAs($operations)->get('/clients/new')->assertForbidden();

    $this->actingAs($operations)->get('/clients')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCreate', false)
            ->where('createBlockedReason', fn (string $reason) => str_contains($reason, 'Price clients permission on Billing & subscriptions')));

    // A reader of the directory who may not take a client on at all is told
    // that, not sent to a form that refuses them.
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DISPATCHER))->get('/clients')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCreate', false)
            ->where('createBlockedReason', fn (string $reason) => str_contains($reason, 'Clients create access')));
});
