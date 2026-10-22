<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HeadOfSecurityScope;

/*
|--------------------------------------------------------------------------
| Pricing a client is Billing's — bug hunt B004 (and B069, B136)
|--------------------------------------------------------------------------
|
| The plan screen, its save and the new-client form were behind Clients alone,
| which the Head of Security holds in full for its sites. So a role with no
| Billing & subscriptions cell was sent the platform's whole MRR, every tier's
| per-unit price and the per-guard rate, and could post a new tier and unit
| count that changed what the client is invoiced from the next cycle. D-139
| names this role as losing exactly those figures, and the client record next
| door already leaves them out for it. The guard-assignment screen, reached from
| that record's own button, named the client's tier in its subtitle.
|
| Now all four routes need one of Billing's writes as well — pricing a client
| sets what it is invoiced, as raising and crediting an invoice change what a
| client owes (routes/gemini/billing.php) — and since M11 B3 split Billing's
| `update`, that write is its own act, Price clients. The first fix
| put them on Billing's read verb, which left the Operations Manager, whose
| Billing cell is View, re-pricing clients (B069). The tier falls off the
| roster's subtitle for a reader without Billing; a site-scoped role that HAS
| been given it is sent the price list but not the platform's total, and is not
| named another estate on the new-client form.
|
| NOT CHANGED, AND ON PURPOSE: "Mark onboarding complete" stays on Clients
| update. It shows no figure — the checklist it reads keeps "plan prepared" for
| exactly this reader — and routes/gemini/clients.php records it as a decision.
| What it says when the plan is missing is changed: a reader who cannot open
| the plan screen is told who can, not told to set the plan.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    HeadOfSecurityScope::seed($this);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('refuses the Head of Security the plan screen, its save and the new-client form, and changes nothing', function () {
    $this->actingAs($this->head)->get('/clients/'.$this->mine.'/plan')->assertForbidden();

    $this->actingAs($this->head)
        ->post('/clients/'.$this->mine.'/plan', ['plan_id' => $this->cheapPlanId, 'units' => 10, 'guards' => 0])
        ->assertForbidden();

    $subscription = DB::connection('mysql')->table('subscriptions')->where('tenant_id', $this->mine)->first();

    expect((int) $subscription->plan_id)->toBe($this->planId)
        ->and((int) $subscription->unit_count)->toBe(120);

    $this->actingAs($this->head)->get('/clients/new')->assertForbidden();

    $this->actingAs($this->head)->post('/clients/new', [
        'name' => $this->token.' Newcomer',
        'address' => 'Mandeville, Manchester',
        'units' => 40,
        'phases' => 1,
        'plan_id' => $this->cheapPlanId,
        'guards' => 0,
        'term_months' => null,
        'contact_name' => 'New Contact',
        'contact_email' => 'newcontact'.$this->suffix.'@example.test',
        'contact_phone' => '876-555-0100',
    ])->assertForbidden();

    expect(DB::connection('mysql')->table('tenants')->where('name', $this->token.' Newcomer')->exists())->toBeFalse();

    // The Director, who holds Billing's Price clients act, still prices a client, with every figure the screen needs.
    $props = HeadOfSecurityScope::props($this->actingAs($this->director)->get('/clients/'.$this->mine.'/plan'));

    expect($props)->toHaveKey('platform_mrr')
        ->and($props['plans'][0])->toHaveKey('price_minor');

    $this->actingAs($this->director)->get('/clients/new')->assertOk();
});

it('refuses a role that may only READ Billing the plan screen, its save and the new-client form, and changes nothing (B069)', function () {
    /*
     * Faked because, were the route to let the request through, `Tenant::create()` would fire the
     * pipeline that builds a real MySQL database — infrastructure a rolled-back transaction cannot
     * take away. Nothing this test asserts is written by that pipeline.
     */
    Event::fake([TenantCreated::class]);

    // The Operations Manager: Clients in full, Billing & subscriptions to read and no more.
    expect($this->operations->can('gemini.clients.update'))->toBeTrue()
        ->and($this->operations->can('gemini.clients.create'))->toBeTrue()
        ->and($this->operations->can('gemini.billing_subscriptions.view'))->toBeTrue()
        ->and($this->operations->can('gemini.billing_subscriptions.price_clients'))->toBeFalse();

    $this->actingAs($this->operations)->get('/clients/'.$this->mine.'/plan')->assertForbidden();

    $this->actingAs($this->operations)
        ->post('/clients/'.$this->mine.'/plan', ['plan_id' => $this->cheapPlanId, 'units' => 10, 'guards' => 0])
        ->assertForbidden();

    $subscription = DB::connection('mysql')->table('subscriptions')->where('tenant_id', $this->mine)->first();

    expect((int) $subscription->plan_id)->toBe($this->planId)
        ->and((int) $subscription->unit_count)->toBe(120);

    $this->actingAs($this->operations)->get('/clients/new')->assertForbidden();

    $this->actingAs($this->operations)->post('/clients/new', [
        'name' => $this->token.' Readonly',
        'address' => 'Mandeville, Manchester',
        'units' => 40,
        'phases' => 1,
        'plan_id' => $this->cheapPlanId,
        'guards' => 0,
        'term_months' => null,
        'contact_name' => 'Readonly Contact',
        'contact_email' => 'readonly'.$this->suffix.'@example.test',
        'contact_phone' => '876-555-0101',
    ])->assertForbidden();

    expect(DB::connection('mysql')->table('tenants')->where('name', $this->token.' Readonly')->exists())->toBeFalse();

    // The directory's "Onboard new client" says why, instead of leading onto that refusal.
    $directory = HeadOfSecurityScope::props($this->actingAs($this->operations)->get('/clients'));

    expect($directory['canCreate'])->toBeFalse()
        ->and($directory['createBlockedReason'])->toContain('Price clients permission on Billing & subscriptions');

    // The save itself works for a role that holds the act: the refusal above is the permission. (The units change, so it says why: M32 F2.)
    $this->actingAs($this->director)
        ->post('/clients/'.$this->mine.'/plan', ['plan_id' => $this->cheapPlanId, 'units' => 10, 'guards' => 0, 'term_months' => null, 'reason' => 'down to the units in use'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $repriced = DB::connection('mysql')->table('subscriptions')->where('tenant_id', $this->mine)->first();

    expect((int) $repriced->plan_id)->toBe($this->cheapPlanId)
        ->and((int) $repriced->unit_count)->toBe(10);
});

it('tells a reader who cannot set the plan who can, on an unplanned client\'s go-live button, and the Director to set it (B004)', function () {
    // An onboarding client with no subscription, at a site the Head of Security is assigned to.
    $unplanned = 'scopebare'.$this->suffix;

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $unplanned,
        'name' => $this->token.' Bare',
        'status' => 'onboarding',
        'demo' => false,
        'parish' => 'St. Ann',
        'address_line' => 'Ocho Rios',
        'provisioned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $head = HeadOfSecurityScope::assigned($this->head, Role::HEAD_OF_SECURITY, $unplanned);

    // Neither the Head of Security (no Billing) nor the Operations Manager (Billing to read) may open the plan screen.
    foreach (['Head of Security' => $head, 'Operations Manager' => $this->operations] as $who => $reader) {
        $this->actingAs($reader)->get('/clients/'.$unplanned.'/plan')->assertForbidden();

        $reason = HeadOfSecurityScope::props($this->actingAs($reader)->get('/clients/'.$unplanned))['estate']['onboarding']['blockedReason'];

        expect($reason)->toBeString($who)
            ->and(str_contains($reason, 'Set the plan first'))->toBeFalse("{$who} was told to set a plan it may not set")
            ->and($reason)->toContain('Price clients permission on Billing & subscriptions');

        // And the same words if the go-live is posted by hand: the refusal names who can act.
        $this->actingAs($reader)
            ->post('/clients/'.$unplanned.'/onboarding/complete')
            ->assertSessionHasErrors('onboarding');

        $error = (string) session('errors')->first('onboarding');

        expect(str_contains($error, 'Set the plan first'))->toBeFalse("{$who} was told to set a plan it may not set")
            ->and($error)->toContain('Price clients permission on Billing & subscriptions');
    }

    // The Director may set it, and is told to.
    $director = HeadOfSecurityScope::props($this->actingAs($this->director)->get('/clients/'.$unplanned));

    expect($director['estate']['onboarding']['blockedReason'])->toContain('Set the plan first');

    expect(DB::connection('mysql')->table('tenants')->where('id', $unplanned)->value('status'))->toBe('onboarding');
});

it('leaves the tier off the guard-assignment screen for the Head of Security, and on it for the Director', function () {
    $props = HeadOfSecurityScope::props($this->actingAs($this->head)->get('/clients/'.$this->mine.'/guards'));

    expect($props['roster']['subtitle'])->not->toContain($this->planName)
        ->and(HeadOfSecurityScope::scan($props))->not->toContain(strtolower($this->planName));

    $director = HeadOfSecurityScope::props($this->actingAs($this->director)->get('/clients/'.$this->mine.'/guards'));

    expect($director['roster']['subtitle'])->toContain($this->planName.' tier');
});

it('sends a site-scoped role that holds Billing the plan screen without the platform\'s MRR, and names no other client on the new-client form', function () {
    $copy = HeadOfSecurityScope::billingCopy($this->suffix, $this->mine);

    $plan = HeadOfSecurityScope::props($this->actingAs($copy)->get('/clients/'.$this->mine.'/plan'));

    // The price list is Billing's and this role holds Billing; the platform's total is not its sites'.
    expect($plan)->not->toHaveKey('platform_mrr')
        ->and($plan['plans'][0])->toHaveKey('price_minor');

    // "Same as Bayside is now" named a client outside this role's scope.
    $form = HeadOfSecurityScope::props($this->actingAs($copy)->get('/clients/new'));

    expect($form['onboarding_example'])->not->toBe($this->names[$this->onboarding])
        ->and(HeadOfSecurityScope::scan($form))->not->toContain(strtolower($this->names[$this->onboarding]));
});
