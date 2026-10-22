<?php

declare(strict_types=1);

use App\Events\AlertRaised;
use App\Models\DuressAlert;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A panic reaches the estate's committee, wherever they are — M10 §1
|--------------------------------------------------------------------------
|
| The client's words: "It must go to that community's main dashboard where the
| president and all the people can see it: a pop-up, and something blinking on
| it… they must know there is an emergency at a specific lot."
|
| The estate console received NOTHING when a panic was raised — not a bell, not a
| badge, not a row. Every surface that showed one was a Gemini console screen, so
| a committee member could be reading the arrears register while a resident held
| a panic button at Lot 47 and nothing on their screen would move.
|
| TWO THINGS HAD TO BE TRUE and this file proves both.
|
| First, the broadcast has to CARRY the lot. The payload was deliberately thin —
| id, kind, estate, urgency, time — on the reasoning that a dispatcher opens the
| alert for detail. That was right while the only subscriber sat in front of the
| queue; a committee member is somewhere else entirely and needs the address
| before they can do anything.
|
| Second, the shared prop that turns the subscription on has to reach EVERY
| estate screen and only the right people. It is gated on `estate.security.view`,
| which the matrix already makes exactly the five roles §1.2 names — so there is
| no second rule to keep in step.
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

it('broadcasts the lot and the person, not only the kind', function () {
    $alert = DuressAlert::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'kind' => 'panic',
        'raised_by_name' => 'Andrea Fletcher',
        'unit_reference' => 'Lot 47',
        'status' => 'open',
        'server_time' => now(),
        'is_simulated' => false,
    ]);

    $payload = (new AlertRaised($alert))->broadcastWith();

    /*
     * The lot is the whole point of the pop-up — a responder's first question is
     * where, not what.
     */
    expect($payload['unit'])->toBe('Lot 47')
        ->and($payload['who'])->toBe('Andrea Fletcher')
        ->and($payload['kind_label'])->not->toBeEmpty()
        ->and($payload['is_urgent'])->toBeTrue();
});

it('broadcasts on the estate\'s own private channel and nobody else\'s', function () {
    $alert = DuressAlert::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'kind' => 'panic',
        'raised_by_name' => 'Andrea Fletcher',
        'unit_reference' => 'Lot 47',
        'status' => 'open',
        'server_time' => now(),
        'is_simulated' => false,
    ]);

    /*
     * This is what makes carrying the lot safe. The channel is private and per
     * estate, and routes/channels.php admits only a Gemini dispatcher for the
     * estates in their scope, or a user who canAccessEstate() AND holds
     * `estate.security.view` — the same gate as the prop below (bug hunt B044;
     * EstatePanicChannelNeedsSecurityTest). So the socket's audience is the
     * prop's, and adding the address gives that audience the fact it needed.
     */
    $channels = (new AlertRaised($alert))->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0]->name)->toBe('private-estate.'.FacilitiesFixture::ESTATE.'.alerts');
});

it('turns the watch on for the five roles the client named, and nobody else', function () {
    /*
     * Asserted through a real screen, because the prop is shared by middleware
     * and a unit test of the middleware would not prove it reaches a page.
     *
     * `estate.security.view` is the gate, and the matrix already resolves it to
     * exactly this list — Community Super Admin and Head of Security in full,
     * President, Vice President and Property Manager as view.
     */
    $watches = [
        Role::COMMUNITY_SUPER_ADMIN,
        Role::PRESIDENT,
        Role::VICE_PRESIDENT,
        Role::PROPERTY_MANAGER,
        Role::ESTATE_HEAD_OF_SECURITY,
    ];

    foreach ($watches as $role) {
        $this->actingAs(FacilitiesFixture::viewer($role))
            ->get(FacilitiesFixture::url('/residents'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('panicWatch.estate', FacilitiesFixture::ESTATE)
            );
    }
});

it('does not turn it on for a role with no business in security', function () {
    /*
     * The Treasurer and the Secretary keep the estate's money and its minutes.
     * Neither is a security role, and a pop-up naming a household's lot is not
     * something to hand out for tidiness.
     */
    foreach ([Role::TREASURER, Role::SECRETARY] as $role) {
        $this->actingAs(FacilitiesFixture::viewer($role))
            ->get(FacilitiesFixture::url('/residents'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('panicWatch', null));
    }
});

/**
 * Six alerts: two nobody in the control room has acknowledged — one open, one an officer took
 * from the handset — and one acknowledged, one resolved, one simulated, and one at another estate.
 *
 * @return array<string, DuressAlert>
 */
function estateWatchAlerts(string $elsewhere): array
{
    $make = static fn (array $attributes): DuressAlert => DuressAlert::withoutEvents(static fn (): DuressAlert => DuressAlert::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'kind' => 'panic',
        'raised_by_name' => 'Andrea Fletcher',
        'status' => 'open',
        'is_simulated' => false,
        ...$attributes,
    ]));

    return [
        'open' => $make(['unit_reference' => 'Lot 47', 'server_time' => now()->subMinutes(2)]),
        'responding' => $make(['unit_reference' => 'Lot 12', 'status' => 'responding', 'en_route_at' => now()->subMinute(), 'server_time' => now()->subMinutes(3)]),
        'acknowledged' => $make(['unit_reference' => 'Lot 5', 'status' => 'acknowledged', 'acknowledged_at' => now()->subMinutes(4), 'server_time' => now()->subMinutes(5)]),
        'resolved' => $make(['unit_reference' => 'Lot 6', 'status' => 'resolved', 'resolved_at' => now()->subMinutes(6), 'server_time' => now()->subMinutes(7)]),
        'simulated' => $make(['unit_reference' => 'Lot 8', 'is_simulated' => true, 'server_time' => now()->subMinutes(1)]),
        'elsewhere' => $make(['tenant_id' => $elsewhere, 'unit_reference' => 'Lot 1', 'server_time' => now()->subMinutes(1)]),
    ];
}

function estateWatchOtherEstate(): string
{
    $id = 'watchother'.random_int(1000, 9999);

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id, 'name' => 'Other Estate', 'status' => 'active', 'data' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('hands every estate page what nobody in the control room has acknowledged, so the bar outlives the page', function () {
    /*
     * B039 / B103. The watch carried the estate's key and nothing else, and the pop-up kept its
     * alerts in the component: a click on any sidebar link — or on the pop-up's own "Open
     * Security" — built a new shell with an empty list, so the blinking bar M10 §1.2 item 6
     * asks for ("until the alert is acknowledged") went out with nobody having acknowledged
     * anything, and a Vice President who opened the console after the panic saw nothing at all.
     */
    $alerts = estateWatchAlerts(estateWatchOtherEstate());
    $watch = null;

    $this->actingAs(FacilitiesFixture::viewer(Role::VICE_PRESIDENT))
        ->get(FacilitiesFixture::url('/governance/meetings'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$watch): void {
            $watch = $page->toArray()['props']['panicWatch'] ?? null;
        });

    // OUTSTANDING: open, and the one an officer took from the handset — newest first. Not the
    // acknowledged or resolved one, not the simulated one, and never another estate's.
    expect($watch['estate'])->toBe(FacilitiesFixture::ESTATE)
        ->and(collect($watch['alerts'])->pluck('id')->all())->toBe([$alerts['open']->id, $alerts['responding']->id])
        ->and($watch['poll'])->toBe('/security/panic-watch')
        ->and($watch['checked_at'])->not->toBeEmpty();

    // What the pop-up and the bar say — the lot first — and the standing, which is never claimed.
    $row = collect($watch['alerts'])->firstWhere('id', $alerts['open']->id);

    expect($row)->toMatchArray([
        'estate' => FacilitiesFixture::ESTATE,
        'unit' => 'Lot 47',
        'who' => 'Andrea Fletcher',
        'kind_label' => 'Panic button',
        'status' => 'open',
        'acknowledged_at' => null,
        'is_simulated' => false,
    ])->and($row)->toHaveKeys(['server_time', 'is_drill', 'alerted_handsets', 'alerted_via', 'call', 'control_room']);

    // NO LINK INTO GEMINI'S CONSOLE: the estate reads an alert's standing on its own Security screen.
    expect($row)->not->toHaveKey('href');

    // And nothing the alert screen keeps behind its own permission.
    $json = strtolower((string) json_encode($watch));

    foreach (['latitude', 'longitude', 'medical_notes', 'resolution_note'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

it('answers the estate\'s own poll with what is outstanding and where the alerts on screen stand, and nothing about another estate', function () {
    $alerts = estateWatchAlerts($elsewhere = estateWatchOtherEstate());

    $asked = [$alerts['acknowledged']->id, $alerts['simulated']->id, $alerts['elsewhere']->id];
    $poll = fn ($viewer) => $this->actingAs($viewer)
        ->getJson(FacilitiesFixture::url('/security/panic-watch?'.http_build_query(['ids' => $asked])));

    $answer = $poll(FacilitiesFixture::viewer(Role::PRESIDENT))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $ids = collect($answer->json('alerts'))->pluck('id');

    // Everything outstanding, as on the page — and each asked-about alert of THIS estate, so the
    // bar stops once the control room has acknowledged it, and a simulated alert the pop-up was
    // sent live is told when it closes.
    expect($ids->all())->toContain($alerts['open']->id, $alerts['responding']->id, $alerts['acknowledged']->id, $alerts['simulated']->id)
        ->and($ids->all())->not->toContain($alerts['elsewhere']->id)
        ->and($ids->all())->not->toContain($alerts['resolved']->id);

    expect(collect($answer->json('alerts'))->firstWhere('id', $alerts['acknowledged']->id))
        ->toMatchArray(['status' => 'acknowledged'])
        ->and(collect($answer->json('alerts'))->firstWhere('id', $alerts['acknowledged']->id)['acknowledged_at'])->not->toBeNull();

    // A role with no business in security is refused, as the Security screen refuses it.
    $poll(FacilitiesFixture::viewer(Role::TREASURER))->assertForbidden();
});

it('carries the watch on every estate screen, not just one', function () {
    /*
     * The failure this guards against: a pop-up that only appears on the screen
     * somebody is already looking at. The prop is shared by middleware precisely
     * so no screen can ship without it, and this walks several unrelated ones.
     */
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    foreach (['/residents', '/facilities/maintenance', '/governance/meetings', '/security'] as $path) {
        $this->actingAs($admin)
            ->get(FacilitiesFixture::url($path))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('panicWatch.estate', FacilitiesFixture::ESTATE)
            );
    }
});
