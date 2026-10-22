<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\SecurityIncident;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| One incident on the estate's Security page, end to end (Batch 1 item 10, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| The incidents list said the kind, the severity and whether it was closed, and the row had nowhere to open to — the
| same gap the alerts list had before 26 Sep's report E4. Now it opens: what happened, how serious, who was on post
| (by name only), who logged it, and how it was closed.
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

/** @param  array<string, mixed>  $attributes */
function detailIncident(array $attributes = []): SecurityIncident
{
    return SecurityIncident::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'kind' => 'suspicious',
        'detail' => 'A man walked the perimeter twice and left when challenged.',
        'severity' => 'med',
        'status' => SecurityIncident::OPEN,
        'occurred_at' => now()->subHour(),
        'guard_name' => 'Marcus Reid',
        'logged_by_name' => 'Marcus Reid (Guard App)',
        ...$attributes,
    ]);
}

it('opens an incident with what happened, who was on post, and how it was closed', function () {
    $incident = detailIncident([
        'status' => SecurityIncident::RESOLVED,
        'resolution' => 'Patrol increased on that stretch for the rest of the shift.',
        'closed_at' => now()->subMinutes(10),
    ]);

    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))
        ->get(FacilitiesFixture::url('/security/incidents/'.$incident->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Security/Incident')
            // The label, not the stored code (EstateSecurity::incidentKindLabel), as the list already draws it.
            ->where('incident.kind_label', 'Suspicious activity')
            ->where('incident.severity_label', 'Medium')
            ->where('incident.closed', true)
            ->where('incident.status_label', 'Closed')
            ->where('incident.guard', 'Marcus Reid')
            ->where('incident.resolution', 'Patrol increased on that stretch for the rest of the shift.'));
});

it('says Action required on an incident nobody has closed, and names nobody when none was recorded', function () {
    $incident = detailIncident(['guard_name' => null, 'logged_by_name' => null]);

    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))
        ->get(FacilitiesFixture::url('/security/incidents/'.$incident->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('incident.closed', false)
            ->where('incident.status_label', 'Action required')
            ->where('incident.resolution', null)
            // SEC-7: never "Not supplied" or "Unknown".
            ->where('incident.guard', 'No guard recorded')
            ->where('incident.logged_by', 'Not recorded'));
});

it('answers another estate\'s incident as one that does not exist, and a role without Security as a refusal', function () {
    // security_incidents.tenant_id is a real foreign key; the estate's row only, never the fixture's own.
    // Stream::TENANT_PATTERN refuses a hyphen (Channels::tenant), so a real key, not a label with one, is used here.
    DB::connection('mysql')->table('tenants')->insertOrIgnore(['id' => 'someotherestate', 'name' => 'Some Other Estate', 'data' => json_encode(['name' => 'Some Other Estate']), 'created_at' => now(), 'updated_at' => now()]);
    $elsewhere = detailIncident(['tenant_id' => 'someotherestate']);

    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))
        ->get(FacilitiesFixture::url('/security/incidents/'.$elsewhere->id))
        ->assertNotFound();

    $own = detailIncident();

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/security/incidents/'.$own->id))
        ->assertForbidden();
});
