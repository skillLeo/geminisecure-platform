<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Http\Controllers\Gemini\PlacesController;
use App\Models\Guard;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Gemini\SiteGeography;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M30 §7.2 step 3, §13: Places, the patrol — where it starts, its checkpoints and their order
|--------------------------------------------------------------------------
|
| The route's order is set by dragging in the list; what the Guard App tours is
| that order. It is read here as the Guard App's own endpoint reads it
| (`PatrolCheckpoint::tourFor`, then by sequence, as `PatrolController::
| checkpoints` lists it), without enrolling a device: no credential is made for
| this test, on any tenant (M30 §0.3).
|
*/

const M30_PATROL = 'm30patrol';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    FacilitiesFixture::platform();

    DB::connection('mysql')->table('tenants')->where('id', M30_PATROL)->delete();
    DB::connection('mysql')->table('tenants')->insert(['id' => M30_PATROL, 'name' => 'Patrol Test Estate', 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    SiteTime::flush();

    $this->main = Post::query()->create(['tenant_id' => M30_PATROL, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0, 'longitude' => -76.8, 'geofence_radius_m' => 80]);
    $this->oldRoute = Post::query()->create(['tenant_id' => M30_PATROL, 'name' => 'Old Patrol Route', 'type' => 'patrol', 'is_active' => true]);

    $stop = static fn (string $label, int $sequence): PatrolCheckpoint => PatrolCheckpoint::query()->create([
        'tenant_id' => M30_PATROL, 'post_id' => null, 'label' => $label, 'code' => PatrolCheckpoint::newCode(), 'sequence' => $sequence, 'is_active' => true, 'radius_m' => PatrolCheckpoint::DEFAULT_RADIUS_M,
    ]);
    $this->pool = $stop('Pool deck', 1);
    $this->court = $stop('Basketball court', 2);
    $this->church = $stop('Church gate', 3);

    $this->director = User::factory()->create(['name' => 'Dora Director', 'email' => 'm30-patrol-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $this->director->syncRoles([Role::DIRECTOR]);
    $this->director = $this->director->fresh();

    // A guard on a patrol shift from the Main Gate, clocked in: the tour the Guard App shows him is the estate's route.
    $this->guard = Guard::query()->create(['full_name' => 'Andre Campbell', 'employee_number' => 'GS-PT-1', 'psra_number' => 'PSRA-PT-1', 'psra_expires_on' => '2027-06-23', 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => M30_PATROL]);
    Shift::query()->create(['tenant_id' => M30_PATROL, 'guard_id' => $this->guard->id, 'post_id' => $this->main->id, 'duty' => 'patrol', 'rostered_start' => now()->subHour(), 'rostered_end' => now()->addHours(7), 'actual_start' => now()->subHour(), 'status' => 'on_duty']);

    // The tour, as the Guard App's endpoint lists it.
    $this->tour = fn (): array => DB::connection('mysql')->table('patrol_checkpoints')
        ->whereIn('id', PatrolCheckpoint::tourFor($this->guard->id, M30_PATROL))
        ->orderBy('sequence')->orderBy('id')->pluck('label')->all();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
});

it('says a refusal from the classic page\'s service in the new screens\' words (M31 B7)', function () {
    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/checkpoints/many', ['lines' => "Pool gate\n".str_repeat('Long name ', 13)])
        ->assertRedirect()
        ->assertSessionHasErrors(['places' => 'Line 2: A checkpoint needs a name a guard can find, such as "Pool gate", of at most 120 characters.']);

    expect(PatrolCheckpoint::query()->where('tenant_id', M30_PATROL)->where('label', 'Pool gate')->exists())->toBeFalse('all of the list or none of it');
});

it('puts the route in the order it was dragged to, and that is the order the Guard App tours', function () {
    expect(($this->tour)())->toBe(['Pool deck', 'Basketball court', 'Church gate']);

    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/route', ['order' => [$this->church->id, $this->pool->id, $this->court->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'The route\'s new order is saved. Print the tags again if their numbers matter on the wall.');

    expect(($this->tour)())->toBe(['Church gate', 'Pool deck', 'Basketball court'])
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'site.route_reordered')->where('entity_id', M30_PATROL)->exists())->toBeTrue()
        // The codes never change: tags already on walls still scan.
        ->and($this->church->fresh()->code)->toBe($this->church->code);
});

it('refuses an order that is not the whole route as it stands, and moves nothing', function () {
    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/route', ['order' => [$this->church->id, $this->pool->id]])
        ->assertSessionHasErrors(['places' => 'The route changed since it was shown. Nothing was moved: look at it again.']);

    expect(($this->tour)())->toBe(['Pool deck', 'Basketball court', 'Church gate']);
});

it('sets where the patrol starts, from the estate\'s own gates only, and shows it', function () {
    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/patrol-start', ['post_id' => $this->main->id])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved: the patrol starts at Main Gate.');

    expect(SiteGeography::patrolStart(M30_PATROL))->toBe($this->main->id);
    $this->actingAs($this->director)->get('/guards/places?client='.M30_PATROL.'&step=patrol')
        ->assertInertia(fn ($page) => $page->where('patrolStart', $this->main->id));

    // An older patrol-route post is not a gate a patrol starts from.
    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/patrol-start', ['post_id' => $this->oldRoute->id])
        ->assertSessionHasErrors(['places' => 'A patrol starts at one of this estate\'s gates. Choose a gate.']);

    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/patrol-start', ['post_id' => null])->assertRedirect()->assertSessionHasNoErrors();
    expect(SiteGeography::patrolStart(M30_PATROL))->toBeNull();
});

it('adds a checkpoint by name with its phase, many at once, and places one where the map is clicked, asking no reason', function () {
    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/checkpoints', ['label' => 'Front entrance'])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Front entrance is on the route. Set its location when its tag is up.');

    $front = PatrolCheckpoint::query()->where('tenant_id', M30_PATROL)->where('label', 'Front entrance')->first();
    expect($front->sequence)->toBe(4)->and($front->latitude)->toBeNull()->and($front->post_id)->toBeNull();

    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/checkpoints/many', ['lines' => "Playground\nService gate"])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(($this->tour)())->toBe(['Pool deck', 'Basketball court', 'Church gate', 'Front entrance', 'Playground', 'Service gate']);

    $this->actingAs($this->director)->post('/guards/places/checkpoint/'.$front->id, ['lat' => 18.0012, 'lng' => -76.8004])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect((float) $front->fresh()->latitude)->toBe(18.0012);

    $added = DB::connection('mysql')->table('audit_log')->where('action', 'site.checkpoint_added')->where('entity_id', (string) $front->id)->first();
    expect(json_decode((string) $added->after, true)['reason'])->toBe(PlacesController::REASON);
});

it('places many checkpoints at once from the installers\' messages, as they were confirmed, asking no reason', function () {
    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/checkpoints/place', ['places' => [
        ['id' => $this->pool->id, 'lat' => 18.0012, 'lng' => -76.8004],
        ['id' => $this->court->id, 'lat' => 18.0021, 'lng' => -76.8011],
    ]])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', '2 checkpoints now have their locations, where their tags hang.');

    expect((float) $this->pool->fresh()->latitude)->toBe(18.0012)
        ->and((float) $this->court->fresh()->longitude)->toBe(-76.8011)
        ->and($this->church->fresh()->latitude)->toBeNull();

    $logged = DB::connection('mysql')->table('audit_log')->where('action', 'site.checkpoint_placed')->where('entity_id', (string) $this->pool->id)->first();
    expect(json_decode((string) $logged->after, true)['reason'])->toBe(PlacesController::REASON);
});

it('takes a checkpoint off the route, and the rest close up behind it', function () {
    $this->actingAs($this->director)->post('/guards/places/checkpoint/'.$this->court->id.'/retire')
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->court->fresh()->is_active)->toBeFalse()
        ->and(($this->tour)())->toBe(['Pool deck', 'Church gate'])
        ->and($this->church->fresh()->sequence)->toBe(2);
});

it('sets how long after one checkpoint the next is due, and puts the platform\'s own back', function () {
    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/patrol-interval', ['minutes' => 30])->assertRedirect()->assertSessionHasNoErrors();
    expect(PatrolCheckpoint::cadenceFor(M30_PATROL))->toBe(30);

    $this->actingAs($this->director)->post('/guards/places/'.M30_PATROL.'/patrol-interval', ['minutes' => null])->assertRedirect()->assertSessionHasNoErrors();
    expect(PatrolCheckpoint::cadenceFor(M30_PATROL))->toBe(PatrolCheckpoint::CADENCE_MINUTES);
});
