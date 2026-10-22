<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\DuressAlert;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\NearestGuards;
use App\Services\Tracking\GuardTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The nearest guards to an open alert (Hassam, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| "Given an open alert and the clocked-in guards' stored positions, return the closest three with their distances. ...
| The system NEVER assigns, it only shows who is nearest and a person sends them."
|
|   - by where each officer is, their newest stored reading, never by their post;
|   - only officers clocked in now, at estates the viewer reaches, who share their position, with a reading this shift;
|   - the officer who raised a duress alert is never one of the nearest;
|   - nothing is written: no officer assigned, the alert as it was;
|   - a closed alert, one with no position, and one placed outside its estate each say why there is no list;
|   - over HTTP, behind the dispatch permission, and an estate the viewer does not reach is a 404, as its alert page.
|
*/

const NEAR_ESTATE = 'nearestest';
const NEAR_OTHER = 'nearestfar';

// The alert, at a gate in Kingston. Readings are placed due north of it: a degree of latitude is 111,195 m.
const NEAR_LAT = 18.0179;
const NEAR_LNG = -76.8099;

function nearEstate(string $id): void
{
    DB::table('tenants')->updateOrInsert(['id' => $id], ['name' => 'Estate '.$id, 'data' => json_encode(['name' => 'Estate '.$id]), 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * An officer at this estate whose POST stands exactly at the alert, so ranking by post would tie them all; their
 * newest reading is $metresNorth north of it (none when null).
 */
function nearOfficer(string $estate, string $name, ?int $metresNorth, bool $consents = true, bool $onDuty = true): Guard
{
    static $n = 0;
    $n++;

    nearEstate($estate);
    $guard = Guard::create([
        'full_name' => $name, 'employee_number' => 'GS-NEAR-'.$n, 'psra_number' => 'PSRA-NEAR-'.$n,
        'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
    ]);
    $post = Post::create(['tenant_id' => $estate, 'name' => 'Post '.$n, 'type' => 'gate', 'is_active' => true, 'latitude' => NEAR_LAT, 'longitude' => NEAR_LNG, 'geofence_radius_m' => 60]);
    $shift = Shift::create([
        'tenant_id' => $estate, 'guard_id' => $guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subHours(2), 'rostered_end' => now()->addHours(10),
        'actual_start' => now()->subHour(), 'actual_end' => $onDuty ? null : now()->subMinutes(5), 'status' => 'on_duty',
    ]);

    if ($consents) {
        DB::table('guard_tracking_consents')->insert([
            'guard_id' => $guard->id, 'policy_version' => GuardTracking::policyVersion(), 'decision' => GuardTracking::GRANTED, 'decided_at' => now()->subHour(),
        ]);
    }

    if ($metresNorth !== null) {
        DB::table('guard_positions')->insert([
            'guard_id' => $guard->id, 'shift_id' => $shift->id, 'tenant_id' => $estate, 'client_id' => (string) Str::uuid(),
            'latitude' => NEAR_LAT + $metresNorth / 111_195, 'longitude' => NEAR_LNG, 'accuracy_m' => 8, 'source' => 'interval',
            'captured_at' => now()->subMinute(), 'received_at' => now()->subMinute(),
        ]);
    }

    return $guard;
}

/** @param  list<string>|null  $reaches  null: every estate; a list: a site-scoped dispatcher who reaches only these */
function nearViewer(?array $reaches = null, bool $mayDispatch = true): User
{
    $role = Role::findOrCreate(($reaches === null ? 'test.nearest_all' : 'test.nearest_scoped_'.implode('_', $reaches)).($mayDispatch ? '' : '_nodispatch'), 'web');
    $role->forceFill(['console' => Console::Gemini->value, 'scope_default' => ($reaches === null ? AccessScope::All : AccessScope::AssignedSites)->value])->save();

    if ($mayDispatch) {
        $permission = config('permission.models.permission');
        $permission::findOrCreate('gemini.dispatch.view', 'web');
        $role->givePermissionTo('gemini.dispatch.view');
    }

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    foreach ($reaches ?? [] as $estate) {
        nearEstate($estate);
        EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => $estate, 'role_id' => $role->id, 'is_active' => true]);
    }

    return $user->fresh();
}

/** @param  array<string, mixed>  $attributes */
function nearAlert(array $attributes = []): DuressAlert
{
    nearEstate(NEAR_ESTATE);

    return DuressAlert::withoutEvents(fn (): DuressAlert => DuressAlert::create([
        'tenant_id' => NEAR_ESTATE, 'kind' => 'panic', 'raised_by_name' => 'A Resident', 'unit_reference' => 'Lot 1',
        'status' => 'open', 'server_time' => now()->subMinutes(2), 'latitude' => NEAR_LAT, 'longitude' => NEAR_LNG,
        ...$attributes,
    ]));
}

/** @return list<string> */
function nearNames(array $answer): array
{
    return array_map(static fn (array $guard): string => $guard['name'], $answer['guards']);
}

it('ranks any officer rows by distance, leaving out a row with no position', function () {
    $rows = [
        ['key' => 'a', 'lat' => NEAR_LAT + 900 / 111_195, 'lng' => NEAR_LNG],
        ['key' => 'b', 'lat' => NEAR_LAT + 300 / 111_195, 'lng' => NEAR_LNG],
        ['key' => 'c'],
        ['key' => 'd', 'lat' => NEAR_LAT + 600 / 111_195, 'lng' => NEAR_LNG],
        ['key' => 'e', 'lat' => NEAR_LAT + 1_500 / 111_195, 'lng' => NEAR_LNG],
    ];

    $ranked = NearestGuards::rank($rows, NEAR_LAT, NEAR_LNG);

    expect(array_column($ranked, 'key'))->toBe(['b', 'd', 'a'])
        ->and(array_column($ranked, 'distance_m'))->toBe([300, 600, 900])
        ->and(array_column(NearestGuards::rank($rows, NEAR_LAT, NEAR_LNG, 2), 'key'))->toBe(['b', 'd']);
});

it('names the three officers nearest the alert by their stored readings, never by their post, each with its distance', function () {
    nearOfficer(NEAR_ESTATE, 'Nine Hundred', 900);
    nearOfficer(NEAR_ESTATE, 'Three Hundred', 300);
    nearOfficer(NEAR_ESTATE, 'Fifteen Hundred', 1_500);
    nearOfficer(NEAR_ESTATE, 'Six Hundred', 600);

    $answer = app(NearestGuards::class)->forAlert(nearAlert(), nearViewer());

    expect(nearNames($answer))->toBe(['Three Hundred', 'Six Hundred', 'Nine Hundred'])
        ->and(array_column($answer['guards'], 'distance_m'))->toBe([300, 600, 900])
        ->and($answer['note'])->toBeNull()
        ->and($answer['guards'][0]['href'])->toStartWith('/guards/');
});

it('leaves out an officer off duty, one who does not share, one with no reading, and the officer who raised the duress', function () {
    nearOfficer(NEAR_ESTATE, 'Gone Home', 50, onDuty: false);
    nearOfficer(NEAR_ESTATE, 'Not Sharing', 60, consents: false);
    nearOfficer(NEAR_ESTATE, 'No Reading Yet', null);
    $raiser = nearOfficer(NEAR_ESTATE, 'In Trouble', 70);
    nearOfficer(NEAR_ESTATE, 'Free To Go', 800);

    $answer = app(NearestGuards::class)->forAlert(nearAlert(['kind' => 'duress', 'guard_id' => $raiser->id, 'raised_by_name' => null]), nearViewer());

    expect(nearNames($answer))->toBe(['Free To Go'])
        ->and($answer['guards'][0]['distance_m'])->toBe(800);
});

it('shows a site-scoped dispatcher only the officers of estates they reach', function () {
    nearOfficer(NEAR_OTHER, 'Other Estate', 100);
    nearOfficer(NEAR_ESTATE, 'This Estate', 400);
    $alert = nearAlert();

    expect(nearNames(app(NearestGuards::class)->forAlert($alert, nearViewer([NEAR_ESTATE]))))->toBe(['This Estate'])
        ->and(nearNames(app(NearestGuards::class)->forAlert($alert, nearViewer())))->toBe(['Other Estate', 'This Estate']);
});

it('says why there is no list: a closed alert, an alert with no position, and one placed outside its estate', function () {
    nearOfficer(NEAR_ESTATE, 'Near', 200);
    $viewer = nearViewer();
    $nearest = app(NearestGuards::class);

    $closed = $nearest->forAlert(nearAlert(['status' => 'resolved']), $viewer);
    $nowhere = $nearest->forAlert(nearAlert(['latitude' => null, 'longitude' => null]), $viewer);

    // A boundary a kilometre square, well north of the alert: the alert is not confirmed on its estate.
    DB::table('estate_sites')->updateOrInsert(['tenant_id' => NEAR_ESTATE], [
        'boundary' => json_encode([[18.05, -76.82], [18.05, -76.81], [18.06, -76.81], [18.06, -76.82]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $outside = $nearest->forAlert(nearAlert(), $viewer);

    expect($closed['guards'])->toBe([])
        ->and($closed['note'])->toBe('This alert is closed, so nobody needs sending.')
        ->and($nowhere['guards'])->toBe([])
        ->and($nowhere['note'])->toBe('This alert carries no position, so the nearest guards cannot be worked out.')
        ->and($outside['guards'])->toBe([])
        ->and(str_contains((string) $outside['note'], 'outside the estate\'s boundary'))->toBeTrue();
});

it('assigns nobody: the alert is exactly as it was', function () {
    nearOfficer(NEAR_ESTATE, 'Near', 200);
    $alert = nearAlert();
    $before = DB::table('duress_alerts')->where('id', $alert->id)->first();

    app(NearestGuards::class)->forAlert($alert, nearViewer());

    expect((array) DB::table('duress_alerts')->where('id', $alert->id)->first())->toBe((array) $before);
});

it('answers the dispatcher as JSON, refuses a role without dispatch, and is a 404 for an estate the viewer does not reach', function () {
    nearOfficer(NEAR_ESTATE, 'Near', 250);
    $alert = nearAlert();
    $url = '/dispatch/alerts/'.$alert->id.'/nearest';

    $this->actingAs(nearViewer())->getJson($url)->assertOk()
        ->assertJsonPath('alert_id', $alert->id)
        ->assertJsonPath('guards.0.name', 'Near')
        ->assertJsonPath('guards.0.distance_m', 250)
        ->assertJsonPath('note', null);

    $this->actingAs(nearViewer(mayDispatch: false))->getJson($url)->assertForbidden();
    $this->actingAs(nearViewer([NEAR_OTHER]))->getJson($url)->assertNotFound();
});
