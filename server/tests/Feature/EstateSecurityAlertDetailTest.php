<?php

declare(strict_types=1);

use App\Models\DuressAlert;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| One alert on the estate's Security page, end to end — still a read (the client's report E4, 26 Sep)
|--------------------------------------------------------------------------
|
| "I am unable to click any of the rows." The estate's list said the lot, the kind and whether it was acknowledged,
| and nothing more; the steps of the response were Gemini's screens only. Now each alert opens: every step with its
| time, the officer sent, how it was closed, where it was raised, and who was told — texts counted only when a
| provider accepted them.
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
function detailAlert(array $attributes = []): DuressAlert
{
    // forceFill: the response's steps are the control room's to write, so none of them is mass-assignable.
    return DuressAlert::withoutEvents(static function () use ($attributes): DuressAlert {
        $alert = new DuressAlert;
        $alert->forceFill([
            'tenant_id' => FacilitiesFixture::ESTATE,
            'kind' => 'panic',
            'raised_by_name' => 'Andrea Fletcher',
            'unit_reference' => 'Lot 47',
            'status' => 'open',
            'server_time' => now()->subMinutes(20),
            'is_simulated' => false,
            ...$attributes,
        ])->save();

        return $alert;
    });
}

it('opens an alert with each step of the response, where it was raised and who was told', function () {
    // M12 §4.3: "who acknowledged it and when" — the dispatcher is named, as Gemini's own alert screen names them.
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    $alert = detailAlert([
        'status' => 'resolved',
        'latitude' => 18.0243,
        'longitude' => -76.7896,
        'acknowledged_at' => now()->subMinutes(19),
        'acknowledged_by' => $dispatcher->id,
        'dispatched_at' => now()->subMinutes(18),
        'on_scene_at' => now()->subMinutes(12),
        'resolved_at' => now()->subMinutes(5),
        'resolution_note' => 'Resident safe; gate latch fixed.',
        'alerted_handsets' => 4,
        'sms_recipients' => 2,
        'sms_delivered' => 1,
    ]);

    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))
        ->get(FacilitiesFixture::url('/security/alerts/'.$alert->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Security/Alert')
            ->where('alert.unit', 'Lot 47')
            ->where('alert.status', 'Resolved')
            ->where('alert.lat', 18.0243)
            ->where('alert.steps', fn ($steps): bool => collect($steps)->pluck('key')->all() === ['raised', 'acknowledged', 'dispatched', 'on_scene', 'resolved']
                && collect($steps)->firstWhere('key', 'resolved')['detail'] === 'Resident safe; gate latch fixed.'
                && collect($steps)->firstWhere('key', 'acknowledged')['detail'] === 'By '.$dispatcher->name)
            // A text is counted only as accepted, out of those tried — one of two here, never "2 sent".
            ->where('alert.told', fn ($told): bool => collect($told)->contains('4 security phones alerted')
                && collect($told)->contains('1 of 2 estate emergency numbers texted (accepted by the provider)')));
});

it('shows only the steps that have happened on an alert still open', function () {
    $alert = detailAlert(['acknowledged_at' => now()->subMinutes(19)]);

    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))
        ->get(FacilitiesFixture::url('/security/alerts/'.$alert->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('alert.open', true)
            // The Emergency list's word for the state (Hassam, 11 Oct): Acknowledged, nothing more.
            ->where('alert.status', 'Acknowledged')
            ->where('alert.steps', fn ($steps): bool => collect($steps)->pluck('key')->all() === ['raised', 'acknowledged'])
            ->where('alert.lat', null));
});

it('answers another estate\'s alert as one that does not exist, and a role without Security as a refusal', function () {
    $elsewhere = detailAlert(['tenant_id' => 'some-other-estate']);

    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))
        ->get(FacilitiesFixture::url('/security/alerts/'.$elsewhere->id))
        ->assertNotFound();

    $own = detailAlert();

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/security/alerts/'.$own->id))
        ->assertForbidden();
});
