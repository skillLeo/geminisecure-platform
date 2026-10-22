<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\LiveMap;
use App\Services\Tracking\GuardTracking;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The dispatch live map
|--------------------------------------------------------------------------
|
| Two things are being defended here, and only one of them is a feature.
|
|   1. A POSITION ONLY FOR A GUARD WHO SHARES ONE. The dispatcher's map is the
|      security company's own view of its staff on duty (Hassam, 12 October:
|      the dispatcher sees everything about a guard), so a guard clocked in who
|      has agreed to the current wording is drawn where their phone puts them,
|      with how fresh that is (GUARD_TRACKING.md 5.5). Everyone else carries no
|      coordinate at all, asserted over the whole rendered payload rather than
|      over the one field somebody remembered. A guard's live coordinates are
|      the most sensitive thing a security company holds on its own staff.
|      (Until guard live tracking the rule here was that the map held no
|      position at all; Hassam's order of 12 October replaced it.)
|
|   2. A site with nobody on it says so. The failure that matters on a
|      coverage picture is not a wrong pin, it is an ABSENT one — an unmanned
|      estate that renders as a quiet estate.
|
*/

/** A tenant row without provisioning: no database, no MySQL user, no side effects. */
function seedMapEstate(string $id, string $name, string $parish): void
{
    DB::table('tenants')->updateOrInsert(
        ['id' => $id],
        ['name' => $name, 'parish' => $parish, 'status' => 'active', 'data' => '{}'],
    );
}

function mapDirector(): User
{
    $role = Role::findOrCreate('test.map_director', 'web');
    $role->forceFill(['scope_default' => AccessScope::All->value])->save();

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    return $user->fresh();
}

/**
 * A dispatcher who looks after exactly this file's two estates, and no other.
 *
 * THE CANVAS HAS FOUR SITE SLOTS AND THE CENTRAL DATABASE IS SHARED (M10, baseline). The map
 * draws the first four estates in parish order and every other one is simply not on it — and
 * other files commit estates to `tenants` and leave them there, several with no parish, which
 * MySQL sorts first. So for a Director the Unmanned Estate (St. Thomas) was the fifth site as
 * often as not, `firstWhere('key', 'mapempty')` found nothing, and the offline-pin test failed
 * on "array offset on null" depending on which suites had run before it. A viewer scoped to
 * these two estates sees exactly two sites, on every run, and every pin rule under test is
 * the same code a Director's map runs.
 */
function mapViewer(): User
{
    $role = Role::findOrCreate('test.map_site_scoped', 'web');
    $role->forceFill(['scope_default' => AccessScope::AssignedSites->value])->save();

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    foreach (['mapcovered', 'mapempty'] as $estate) {
        EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => $estate, 'role_id' => $role->id, 'is_active' => true]);
    }

    return $user->fresh();
}

beforeEach(function () {
    seedMapEstate('mapcovered', 'Covered Estate', 'St. Andrew');
    seedMapEstate('mapempty', 'Unmanned Estate', 'St. Thomas');

    $this->post = Post::create([
        'tenant_id' => 'mapcovered',
        'name' => 'Test Main Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $this->officer = Guard::create([
        'full_name' => 'Test Officer',
        'employee_number' => 'GS-MAP-1',
        'psra_number' => 'PSRA-MAP-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'status' => 'active',
        'tenant_id' => 'mapcovered',
        'post_id' => $this->post->id,
    ]);

    $this->shift = Shift::create([
        'tenant_id' => 'mapcovered',
        'guard_id' => $this->officer->id,
        'post_id' => $this->post->id,
        'rostered_start' => now()->subHour(),
        'rostered_end' => now()->addHours(6),
        'actual_start' => now()->subHour(),
        'status' => 'active',
    ]);
});

afterEach(function () {
    DB::table('guard_positions')->where('guard_id', $this->officer->id)->delete();
    DB::table('guard_tracking_consents')->where('guard_id', $this->officer->id)->delete();
    EstateAssignment::query()->whereIn('tenant_id', ['mapcovered', 'mapempty'])->delete();
    Shift::query()->where('tenant_id', 'mapcovered')->delete();
    Guard::query()->where('employee_number', 'GS-MAP-1')->delete();
    Post::query()->whereIn('tenant_id', ['mapcovered', 'mapempty'])->delete();
    DB::table('tenants')->whereIn('id', ['mapcovered', 'mapempty'])->delete();
});

it('puts no coordinate anywhere in the payload for a guard on duty who has not shared a position, and still lists them', function () {
    $map = (new LiveMap)->forViewer(mapDirector());
    $payload = strtolower((string) json_encode($map));

    /*
     * The whole rendered structure, not one field. A position could arrive on
     * a pin, on a site card, on the banner or on a KPI, and the point of
     * checking the serialised whole is that it catches the one nobody thought
     * of. This guard is clocked in and has agreed to nothing.
     */
    foreach (['latitude', 'longitude', '"lat"', '"lng"', 'coordinate', 'geofence', '"accuracy_m"'] as $forbidden) {
        expect(str_contains($payload, $forbidden))->toBeFalse('the payload carries '.$forbidden.' for a guard who shares no position');
    }

    // Never left off: the dispatcher reads why there is no pin.
    $officer = collect($map['geo']['officers'])->firstWhere('key', 'shift-'.$this->shift->id);

    expect($officer)->not->toBeNull()
        ->and($officer['freshness'])->toBe('not_shared')
        ->and($officer['freshness_label'])->toBe('Position not shared');
});

it('draws a guard on duty who shares their position where the phone puts them, with how fresh it is, what they are doing and what the phone says', function () {
    DB::table('guard_tracking_consents')->insert(['guard_id' => $this->officer->id, 'policy_version' => GuardTracking::policyVersion(), 'decision' => 'granted', 'decided_at' => now()]);
    DB::table('guard_positions')->insert([
        'guard_id' => $this->officer->id, 'shift_id' => $this->shift->id, 'tenant_id' => 'mapcovered', 'client_id' => (string) Str::uuid(),
        'latitude' => 18.0179, 'longitude' => -76.8099, 'accuracy_m' => 7, 'source' => 'background', 'battery_pct' => 18, 'is_moving' => true,
        'captured_at' => now()->subSeconds(20), 'device_captured_at' => now()->subSeconds(20), 'received_at' => now()->subSeconds(20),
    ]);

    $map = (new LiveMap)->forViewer(mapViewer());
    $key = 'shift-'.$this->shift->id;
    $officer = collect($map['geo']['officers'])->firstWhere('key', $key);

    expect($officer)->not->toBeNull()
        ->and([$officer['lat'], $officer['lng'], $officer['accuracy_m']])->toBe([18.0179, -76.8099, 7])
        ->and($officer['freshness'])->toBe('live')
        ->and($officer['state_label'])->toBe('At post')
        ->and($officer['battery_pct'])->toBe(18)
        ->and($officer['is_moving'])->toBeTrue()
        ->and($officer['source_label'])->toBe('App in the background')
        // The pin on the map, and the dispatcher's card for the same shift, by the same key.
        ->and(collect($map['geo']['guards'])->pluck('key')->all())->toBe([$key])
        ->and(collect($map['roster'])->pluck('key')->all())->toBe([$key]);
});

it('says which clock each roster row reads its times on, so a freshness label ticking on screen reads as the row does', function () {
    $row = collect((new LiveMap)->forViewer(mapViewer())['roster'])->firstWhere('key', 'shift-'.$this->shift->id);
    $zone = SiteTime::zone('mapcovered');

    expect($row['clock'])->toBe(['zone' => $zone, 'tag' => SiteTime::clockTag($zone)])
        ->and($row['clock']['tag'])->toBe('JA');
});

it('reads each guard\'s freshness from the one shared rule on the dispatcher\'s page, and keeps no rule of its own', function () {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Gemini/Dispatch/Map.vue');

    expect(str_contains($page, "from '../../../lib/guardFreshness.js'"))->toBeTrue('the map does not import the shared freshness rule')
        ->and(str_contains($page, 'guardFreshness('))->toBeTrue('the map never calls the shared freshness rule')
        ->and(str_contains($page, 'stale_after_s'))->toBeFalse('the map keeps a staleness rule of its own')
        ->and(preg_match('/const ageOf\b|Date\.parse\(\w+\.captured_at\)/', $page))->toBe(0, 'the map works out a position\'s age by a rule of its own')
        ->and(str_contains($page, 'geo.unshared'))->toBeFalse('a guard off the map is said twice: on their card and in a list of their own');
});

it('labels a guard pin with the post they are standing, not a place', function () {
    $map = (new LiveMap)->forViewer(mapViewer());

    $site = collect($map['sites'])->firstWhere('key', 'mapcovered');
    $pin = collect($site['pins'])->firstWhere('dot', 'post');

    expect($pin['label'])->toBe('Test Officer · Test Main Gate');
});

it('draws the offline pin for a site with nobody on duty', function () {
    $map = (new LiveMap)->forViewer(mapViewer());

    $site = collect($map['sites'])->firstWhere('key', 'mapempty');

    // The failure worth catching is the silent one: an unmanned estate that
    // renders identically to a manned estate whose pins are simply off screen.
    expect(collect($site['pins'])->pluck('dot'))->toContain('offline')
        ->and(collect($site['pins'])->firstWhere('dot', 'offline')['label'])
        ->toBe('No Gemini guards on site');
});

it('does not count a rostered shift nobody started as a guard on duty', function () {
    $this->shift->forceFill(['actual_start' => null, 'status' => 'rostered'])->save();

    $map = (new LiveMap)->forViewer(mapViewer());

    // A roster is a plan. Drawing a pin for a shift that never began would put
    // a guard on the map who is not at the gate.
    expect($map['onDuty'])->toBeEmpty()
        ->and(collect($map['sites'])->firstWhere('key', 'mapcovered')['pins'])
        ->toHaveCount(1);
});

it('gives the dispatcher every guard on duty in full: who, licence, post, shift and how they clocked in (Hassam, 12 October)', function () {
    /*
     * THE FULL VERSION. The estate's map is deliberately limited (Security's allow-list); the security company's own
     * console reads everything it holds about its staff on duty. A guard twenty minutes late, ten days from a lapsed
     * licence, clocked in 312 m outside the post's fence on a phone that reported a mocked location: every one of
     * those is on the dispatcher's row, in words.
     */
    $this->officer->forceFill(['phone' => '876-555-0101', 'psra_expires_on' => SiteTime::date()->addDays(10)->toDateString()])->save();
    $this->shift->forceFill([
        'rostered_start' => now()->subMinutes(80),
        'actual_start' => now()->subMinutes(60),
        'geofence_outcome' => 'outside',
        'geofence_distance_m' => 312,
        'review_reasons' => ['mock_location'],
    ])->save();

    $row = collect((new LiveMap)->forViewer(mapViewer())['roster'])->firstWhere('key', 'shift-'.$this->shift->id);

    expect($row)->not->toBeNull()
        ->and($row['name'])->toBe('Test Officer')
        ->and($row['employee_number'])->toBe('GS-MAP-1')
        ->and($row['phone'])->toBe('876-555-0101')
        ->and($row['licence'])->toBe(['label' => 'Licence PSRA-MAP-1 · lapses in 10 days', 'tone' => 'warn'])
        ->and($row['post'])->toBe('Test Main Gate')
        ->and($row['estate'])->toBe('Covered Estate')
        ->and($row['href'])->toBe('/guards/'.$this->officer->id)
        ->and(str_contains((string) $row['clocked_in'], ', 20 min late'))->toBeTrue()
        ->and($row['clock_in_check'])->toBe(['label' => '312 m from the post, outside the post\'s fence at clock-in', 'tone' => 'warn'])
        ->and(str_contains((string) $row['review'], 'MOCKED location'))->toBeTrue()
        ->and($row['alert'])->toBeNull();
});

it('shows a site-scoped viewer only the estates assigned to them', function () {
    $role = Role::findOrCreate('test.map_site_scoped', 'web');
    $role->forceFill(['scope_default' => AccessScope::AssignedSites->value])->save();

    $viewer = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $viewer->syncRoles([$role->name]);

    // No assignments at all: a scoped viewer with nothing assigned sees an
    // empty platform, never everyone else's.
    expect((new LiveMap)->forViewer($viewer->fresh())['sites'])->toBeEmpty();
});
