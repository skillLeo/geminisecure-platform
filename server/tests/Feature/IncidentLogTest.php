<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\SecurityIncident;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The incident log's two writes — board 27 (12 §2, item 27)
|--------------------------------------------------------------------------
|
| A STRUCTURED INTAKE, because an incident record is evidence. A RESOLUTION
| CLOSES AN INCIDENT FOR GOOD. A role scoped to assigned sites neither logs
| against nor reads an incident at a client it does not cover.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('logs an incident only through the whole intake, and closes it once, with what was done', function () {
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    /*
     * "WHEN IT HAPPENED" IS TYPED ON THE ESTATE'S CLOCK (M4 §3.1). Board 27's intake is a
     * date-and-time field with no offset in it, and a dispatcher types the time the estate
     * was looking at — so the moment is built in that estate's zone here too. Built under
     * UTC, three hours ago at the estate read as two hours in the FUTURE and the intake
     * refused it, which is exactly the refusal below.
     */
    $atTheEstate = fn (int $hoursBack): string => SiteTime::now(FacilitiesFixture::ESTATE)->subHours($hoursBack)->format('Y-m-d\TH:i');

    $intake = [
        'tenant_id' => FacilitiesFixture::ESTATE,
        'occurred_at' => $atTheEstate(3),
        'kind' => 'Attempted unauthorized access',
        'severity' => 'med',
        'detail' => 'Individual attempted to follow a resident vehicle through the barrier at Main Gate.',
    ];

    // Reading the log is not adding to it.
    $this->actingAs($accountant)->post('/guards/incidents', $intake)->assertForbidden();

    $this->actingAs($accountant)
        ->get('/guards/incidents')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canLog', false));

    // HALF A RECORD IS REFUSED: no account of what happened.
    $this->actingAs($dispatcher)
        ->post('/guards/incidents', [...$intake, 'detail' => 'gate thing'])
        ->assertSessionHasErrors('incident');

    // Nor one dated in the future.
    $this->actingAs($dispatcher)
        ->post('/guards/incidents', [...$intake, 'occurred_at' => $atTheEstate(-24)])
        ->assertSessionHasErrors('incident');

    $response = $this->actingAs($dispatcher)->post('/guards/incidents', $intake);

    $incident = SecurityIncident::query()->where('kind', 'Attempted unauthorized access')->latest('id')->firstOrFail();

    $response->assertRedirect('/guards/incidents/'.$incident->id);

    expect($incident->status)->toBe(SecurityIncident::OPEN)
        ->and($incident->logged_by_name)->toBe($dispatcher->name)
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'operations.incident_logged')->where('entity_id', (string) $incident->id)->exists())->toBeTrue();

    $this->actingAs($accountant)
        ->get('/guards/incidents/'.$incident->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Operations/Incident')
            ->where('incident.severity_label', 'Medium')
            ->where('incident.status', 'open')
            ->where('canResolve', false));

    $this->actingAs($accountant)
        ->post('/guards/incidents/'.$incident->id.'/resolve', ['resolution' => 'Challenged and escorted off.'])
        ->assertForbidden();

    // "Resolved" with nothing after it is not a resolution.
    $this->actingAs($dispatcher)
        ->post('/guards/incidents/'.$incident->id.'/resolve', ['resolution' => 'done'])
        ->assertSessionHasErrors('resolution');

    $this->actingAs($dispatcher)
        ->post('/guards/incidents/'.$incident->id.'/resolve', ['resolution' => 'Challenged and escorted off the property by the guard on post.'])
        ->assertSessionHasNoErrors();

    $incident->refresh();

    expect($incident->isSettled())->toBeTrue()
        ->and($incident->closed_at)->not->toBeNull();

    // FINAL: a closed incident is not rewritten.
    $this->actingAs($dispatcher)
        ->post('/guards/incidents/'.$incident->id.'/resolve', ['resolution' => 'A different account of what was done.'])
        ->assertSessionHasErrors('resolution');

    expect($incident->fresh()->resolution)->toBe('Challenged and escorted off the property by the guard on post.');
});

it('keeps a site-scoped role out of an incident at a client it does not cover', function () {
    $headOfSecurity = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    $incident = SecurityIncident::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'kind' => 'Equipment fault — barrier arm sensor',
        'detail' => 'Barrier arm failed to lower after three consecutive vehicles.',
        'severity' => 'low',
        'status' => SecurityIncident::OPEN,
        'occurred_at' => now()->subDay(),
        'logged_by_name' => $dispatcher->name,
    ]);

    // Not a 403 that confirms it exists: the same 404 as an id nobody has.
    $this->actingAs($headOfSecurity)->get('/guards/incidents/'.$incident->id)->assertNotFound();

    $this->actingAs($headOfSecurity)
        ->post('/guards/incidents', [
            'tenant_id' => FacilitiesFixture::ESTATE,
            // On the estate's clock (M4 §3.1), so the refusal below is the SCOPE refusing and
            // nothing else — a time read on the wrong clock would land in the future and refuse
            // this for a reason that has nothing to do with what is being tested.
            'occurred_at' => SiteTime::now(FacilitiesFixture::ESTATE)->subHour()->format('Y-m-d\TH:i'),
            'kind' => 'Attempted unauthorized access',
            'severity' => 'low',
            'detail' => 'An attempt at a client this role does not cover.',
        ])
        ->assertSessionHasErrors('incident');
});
