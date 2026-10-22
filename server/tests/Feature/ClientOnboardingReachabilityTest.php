<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Gemini\ClientOnboarding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An estate onboarded from the console has to be reachable at its own address
|--------------------------------------------------------------------------
|
| `InitializeTenancyBySubdomain` takes the host, strips the central domain off
| it, and looks the remainder up in `domains`. A tenant with no row in that table
| cannot be identified, so every request to <estate>.geminisl.com answers 500 —
| not 404, because failing to identify a tenant is an exception rather than a
| miss.
|
| `EstateProvisioner::provision()` has always written that row, and `DemoSeed`
| writes it too. `ClientOnboarding::start()` — the ONLY path a person can reach
| from a screen, behind "Onboard new client" — did not. So every client taken on
| through the console was listed as Active in the directory, had its database
| built by the TenantCreated pipeline, had a subscription and an invited
| president, and had no console: the one table that turns a hostname into a
| tenant was empty for it.
|
| It went unnoticed because every estate anybody had tested with was made by the
| command, which writes the row. There was no test over this service at all.
|
| WRITTEN AGAINST THE OLD BEHAVIOUR FIRST: with the `domains()->create()` line
| removed from `ClientOnboarding::start()`, the first test below fails.
|
| No RefreshDatabase: this suite shares gs_platform_test with the rest of the
| console, so everything here happens inside a transaction that is rolled back.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    /*
     * `Tenant::create()` fires TenantCreated, whose pipeline creates a MySQL
     * DATABASE and a dedicated user per estate — real infrastructure, on a test
     * machine, that a rolled-back transaction cannot remove. Faking the event
     * leaves everything this file is about untouched: the tenant row, the domain
     * row, the subscription and the audit entry are all written by the service
     * itself, on the central connection, inside the transaction above.
     */
    Event::fake([TenantCreated::class]);

    $this->plan = Plan::query()->create([
        'key' => 'onboardreach',
        'name' => 'Onboarding Reachability',
        'price_per_unit_minor' => 180_00,
        'currency' => 'JMD',
        'min_units' => 25,
        'is_active' => true,
        'sort' => 98,
    ]);

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->payload = [
        'name' => 'Harbour Lights Estate',
        'address' => '14 Harbour Road, St. Ann',
        'phases' => 2,
        'plan_id' => $this->plan->id,
        'units' => 300,
        'guards' => 0,
        'term_months' => 12,
        'contact_name' => 'Hassam Ahmed',
        'contact_email' => 'president@harbourlights.test',
        'contact_phone' => '+1 876 555 0142',
        'timezone' => 'America/Jamaica',
    ];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('gives a newly onboarded client the domain row its console is resolved by', function () {
    $estate = app(ClientOnboarding::class)->start($this->payload, $this->director);

    $domains = $estate->domains()->pluck('domain')->all();

    expect($domains)->toHaveCount(1)
        ->and($domains[0])->toBe($estate->getTenantKey());
});

it('stores the bare subdomain and never the full hostname', function () {
    /*
     * The resolver searches for what is LEFT once the central domain is removed.
     * A row holding "harbourlights.geminisl.com" makes the tenant unfindable in
     * exactly the same way as holding nothing, and it is the mistake the
     * provisioner's own comment warns about — so it is asserted rather than
     * assumed.
     */
    $estate = app(ClientOnboarding::class)->start($this->payload, $this->director);

    $domain = (string) $estate->domains()->value('domain');

    expect($domain)->not->toContain('.')
        ->and($domain)->toMatch('/^[a-z][a-z0-9]{2,29}$/');
});

it('resolves that domain back to the tenant, which is what the middleware does', function () {
    $estate = app(ClientOnboarding::class)->start($this->payload, $this->director);

    /*
     * The lookup `InitializeTenancyBySubdomain` performs, spelled out. Asserting
     * the row exists proves the write; asking the same question the middleware
     * asks proves the row is the one it will find.
     */
    $found = Tenant::query()
        ->whereHas('domains', fn ($q) => $q->where('domain', $estate->getTenantKey()))
        ->first();

    expect($found)->not->toBeNull()
        ->and($found->getTenantKey())->toBe($estate->getTenantKey());
});

it('onboards the client through the console route and the estate is reachable after it', function () {
    $before = DB::connection('mysql')->table('domains')->count();

    /*
     * `assertSessionHasNoErrors` before the count, because a validation failure
     * also answers with a redirect — the first version of this test asserted
     * only `assertRedirect()` and passed happily against a request that had
     * created nothing at all.
     */
    $this->actingAs($this->director)
        ->post('/clients/new', $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $after = DB::connection('mysql')->table('domains')->count();

    /*
     * Through the ROUTE, not the service, because the defect was that the one
     * path with a button on it behaved differently from the one with a command
     * behind it. A test that only ever called the service directly would pass
     * against a controller that had stopped calling it.
     */
    expect($after)->toBe($before + 1);
});
