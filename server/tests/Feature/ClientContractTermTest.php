<?php

declare(strict_types=1);

use App\Services\Gemini\ClientPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HeadOfSecurityScope;

/*
|--------------------------------------------------------------------------
| A pricing form sent without its contract term is told to choose one
|--------------------------------------------------------------------------
|
| Found while fixing B069. Both forms that price a client — the plan screen and
| the new-client form — declared `term_months` as `nullable` and nothing more,
| and both services then read `$data['term_months']` from the validated input.
| `nullable` lets a field be null; it does not make it be there. A request that
| left the field out passed validation, reached the service, and answered 500 on
| an undefined array key — after the permission check, so it was a role allowed
| to price a client that got a stack trace instead of a sentence.
|
| The screens always send the field ("Monthly rolling" is an empty string, which
| arrives as null), so nothing a person clicks reached it. The field is now
| `present` as well: null still means "no committed term", and leaving it out is
| a validation error that names the choice, not a guess at which term was meant.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // The new-client form's success path would otherwise build a real estate database.
    Event::fake([TenantCreated::class]);

    HeadOfSecurityScope::seed($this);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('refuses a plan saved without a contract term with a sentence, not a 500, and leaves the subscription alone', function () {
    $this->actingAs($this->director)
        ->from('/clients/'.$this->mine.'/plan')
        ->post('/clients/'.$this->mine.'/plan', ['plan_id' => $this->cheapPlanId, 'units' => 10, 'guards' => 0])
        ->assertRedirect('/clients/'.$this->mine.'/plan')
        ->assertSessionHasErrors(['term_months' => ClientPlan::TERM_REQUIRED]);

    $subscription = DB::connection('mysql')->table('subscriptions')->where('tenant_id', $this->mine)->first();

    expect((int) $subscription->plan_id)->toBe($this->planId)
        ->and((int) $subscription->unit_count)->toBe(120);

    // Null is still an answer: "Monthly rolling — no committed term". (The units change, so it says why: M32 F2.)
    $this->actingAs($this->director)
        ->post('/clients/'.$this->mine.'/plan', ['plan_id' => $this->cheapPlanId, 'units' => 10, 'guards' => 0, 'term_months' => null, 'reason' => 'down to the units in use'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(DB::connection('mysql')->table('subscriptions')->where('tenant_id', $this->mine)->value('term_months'))->toBeNull();
});

it('refuses a new client sent without a contract term with a sentence, not a 500, and records nothing', function () {
    $payload = [
        'name' => $this->token.' Termless',
        'address' => 'Mandeville, Manchester',
        'units' => 40,
        'phases' => 1,
        'plan_id' => $this->cheapPlanId,
        'guards' => 0,
        'contact_name' => 'Termless Contact',
        'contact_email' => 'termless'.$this->suffix.'@example.test',
        'contact_phone' => '876-555-0102',
        'timezone' => 'America/Jamaica',
    ];

    $this->actingAs($this->director)
        ->from('/clients/new')
        ->post('/clients/new', $payload)
        ->assertRedirect('/clients/new')
        ->assertSessionHasErrors(['term_months' => ClientPlan::TERM_REQUIRED]);

    expect(DB::connection('mysql')->table('tenants')->where('name', $this->token.' Termless')->exists())->toBeFalse();

    $this->actingAs($this->director)
        ->post('/clients/new', [...$payload, 'term_months' => null])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(DB::connection('mysql')->table('tenants')->where('name', $this->token.' Termless')->exists())->toBeTrue();
});
