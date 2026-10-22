<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Role;
use App\Models\SecurityIncident;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The Gemini Console bell (12 §2, item 41)
|--------------------------------------------------------------------------
|
| Derived from the records that own each fact, gated per module, and scoped;
| only the read mark is stored, per person.
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

it('derives what needs somebody, per module, and remembers only who has seen it', function () {
    $guard = Guard::create([
        'full_name' => 'Bell Test Officer',
        'employee_number' => 'GS-BELL-1',
        'psra_number' => 'PSRA-BELL-1',
        'psra_expires_on' => now()->addDays(5)->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
    ]);

    $incident = SecurityIncident::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'kind' => 'Bell test — attempted unauthorized access',
        'detail' => 'Followed a resident vehicle through the barrier.',
        'severity' => 'high',
        'status' => SecurityIncident::OPEN,
        'occurred_at' => now()->subHour(),
    ]);

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    $keys = null;

    $this->actingAs($director)
        ->get('/notifications')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$keys) {
            $page->component('Gemini/Notifications/Index');
            $keys = collect($page->toArray()['props']['items'])->pluck('key')->all();
        });

    $licenceKey = 'licence:'.$guard->id.':'.$guard->psra_expires_on->toDateString();

    expect($keys)->toContain($licenceKey)
        ->and($keys)->toContain('incident:'.$incident->id);

    // GATED PER ITEM: the Accountant holds no Dispatch access, so no alert and
    // no pending request reaches their list, whatever is open.
    $this->actingAs($accountant)
        ->get('/notifications')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'items',
            fn ($items) => collect($items)->every(fn ($item) => ! str_starts_with($item['key'], 'alert:') && ! str_starts_with($item['key'], 'request:'))
        ));

    // Read marks are per person.
    $this->actingAs($director)->post('/notifications/read', ['keys' => [$licenceKey]])->assertRedirect();

    $this->actingAs($director)
        ->get('/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'items',
            fn ($items) => collect($items)->firstWhere('key', $licenceKey)['is_read'] === true
        ));

    $this->actingAs($accountant)
        ->get('/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'items',
            fn ($items) => (collect($items)->firstWhere('key', $licenceKey)['is_read'] ?? false) === false
        ));

    // DEALT WITH, GONE: closing the incident takes it off the list, with nothing to tidy.
    $incident->forceFill(['status' => SecurityIncident::RESOLVED, 'resolution' => 'Escorted off.', 'closed_at' => now()])->save();

    $this->actingAs($director)
        ->get('/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'items',
            fn ($items) => collect($items)->pluck('key')->doesntContain('incident:'.$incident->id)
        ));

    // The bell carries the count.
    $this->actingAs($director)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('unread'));
});
