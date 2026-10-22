<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Http\Controllers\Gemini\PlacesController;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\ClockInFence;
use App\Support\SiteTime;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M30 §7, §13: Places — the estate, its gates, the map and the place search
|--------------------------------------------------------------------------
|
| Every write goes through SiteGeography, the classic page's service, so a
| position is held to the same rules and audited the same way; only no reason is
| asked. The place search is our server asking Nominatim: our own name on every
| request, one request a second across the platform, an answer kept for 30 days,
| and an empty list with one line when Nominatim cannot answer.
|
*/

const M30_PLACES = 'm30places';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    FacilitiesFixture::platform();

    DB::connection('mysql')->table('tenants')->where('id', M30_PLACES)->delete();
    DB::connection('mysql')->table('tenants')->insert(['id' => M30_PLACES, 'name' => 'Aaa Places Test Estate', 'address_line' => 'Portmore', 'parish' => 'St Catherine', 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    SiteTime::flush();

    $this->main = Post::query()->create(['tenant_id' => M30_PLACES, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0, 'longitude' => -76.8, 'geofence_radius_m' => 80]);
    $this->back = Post::query()->create(['tenant_id' => M30_PLACES, 'name' => 'Back Gate', 'type' => 'gate', 'is_active' => true]);
    $this->patrol = Post::query()->create(['tenant_id' => M30_PLACES, 'name' => 'Old Patrol Route', 'type' => 'patrol', 'is_active' => true]);

    $this->director = User::factory()->create(['name' => 'Dora Director', 'email' => 'm30-places-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $this->director->syncRoles([Role::DIRECTOR]);
    $this->director = $this->director->fresh();

    config(['services.nominatim.url' => 'https://nominatim.example.test', 'services.nominatim.contact' => 'ops@example.test']);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
});

it('opens on the client asked for, its gates in step 2 and its older patrol route kept for step 3, and Kingston for an empty map', function () {
    $this->actingAs($this->director)->get('/guards/places?client='.M30_PLACES.'&step=gates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Gemini/Operations/Places')
            ->where('client.id', M30_PLACES)
            ->where('step', 'gates')
            ->where('estate.where', 'Portmore, St Catherine')
            ->where('gates', fn ($gates) => collect($gates)->pluck('name')->sort()->values()->all() === ['Back Gate', 'Main Gate'])
            ->where('patrolPosts.0.name', 'Old Patrol Route')
            ->where('kingston', PlacesController::KINGSTON)
            ->where('limits.gate', PlacesController::GATE_RADIUS));
});

it('saves a gate\'s position as sent, from a click or from pasted numbers, with no reason asked, and audits it before and after', function () {
    $this->actingAs($this->director)->post('/guards/places/gate/'.$this->back->id, ['lat' => 17.9612345, 'lng' => -76.8821234, 'radius_m' => 60])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Back Gate\'s location is saved.');

    $placed = $this->back->fresh();
    expect((float) $placed->latitude)->toBe(17.9612345)
        ->and((float) $placed->longitude)->toBe(-76.8821234)
        ->and($placed->geofence_radius_m)->toBe(60);

    $logged = DB::connection('mysql')->table('audit_log')->where('action', 'site.post_placed')->where('entity_id', (string) $this->back->id)->first();
    expect($logged->actor_name)->toBe('Dora Director')
        ->and(json_decode((string) $logged->before, true))->toMatchArray(['latitude' => null, 'longitude' => null])
        ->and(json_decode((string) $logged->after, true))->toMatchArray(['geofence_radius_m' => 60, 'reason' => PlacesController::REASON]);
});

it('saves the clock-in radius, and that radius is what a clock-in is then judged by', function () {
    $shift = Shift::query()->create(['tenant_id' => M30_PLACES, 'post_id' => $this->main->id, 'duty' => 'post', 'rostered_start' => now()->addDay(), 'rostered_end' => now()->addDay()->addHours(12), 'status' => Shift::OPEN]);
    // About 100 metres north of the Main Gate, a good fix.
    $guardAt = [18.0009, -76.8];

    $this->actingAs($this->director)->post('/guards/places/gate/'.$this->main->id, ['lat' => 18.0, 'lng' => -76.8, 'radius_m' => 120])->assertRedirect()->assertSessionHasNoErrors();
    $wide = ClockInFence::judge(ClockInFence::fenceFor($shift->fresh()), $guardAt[0], $guardAt[1], 5);

    $this->actingAs($this->director)->post('/guards/places/gate/'.$this->main->id, ['lat' => 18.0, 'lng' => -76.8, 'radius_m' => 50])->assertRedirect()->assertSessionHasNoErrors();
    $tight = ClockInFence::judge(ClockInFence::fenceFor($shift->fresh()), $guardAt[0], $guardAt[1], 5);

    expect($wide['radius_m'])->toBe(120)->and($wide['outcome'])->toBe(ClockInFence::INSIDE)
        ->and($tight['radius_m'])->toBe(50)->and($tight['outcome'])->toBe(ClockInFence::OUTSIDE);
});

it('adds a gate with only its name, placed where the map was clicked', function () {
    $this->actingAs($this->director)->post('/guards/places/'.M30_PLACES.'/gates', ['name' => 'Phase 5 Gate', 'lat' => 17.95, 'lng' => -76.87, 'radius_m' => 50])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Phase 5 Gate is added, with its location.');

    $made = Post::query()->where('tenant_id', M30_PLACES)->where('name', 'Phase 5 Gate')->first();
    expect($made->type)->toBe('gate')->and((float) $made->latitude)->toBe(17.95)->and($made->geofence_radius_m)->toBe(50);

    // A name another gate has is refused, in its own words, and nothing is made.
    $this->actingAs($this->director)->post('/guards/places/'.M30_PLACES.'/gates', ['name' => 'main gate', 'lat' => 17.95, 'lng' => -76.87, 'radius_m' => 50])
        ->assertSessionHasErrors('places');
    expect(Post::query()->where('tenant_id', M30_PLACES)->where('is_active', true)->count())->toBe(4);
});

it('renames a gate and its other names, writing only what changed', function () {
    $this->actingAs($this->director)->post('/guards/places/gate/'.$this->back->id.'/name', ['name' => 'Back Gate', 'also_called' => 'Gate 5, Phase 5 Gate'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($this->back->fresh()->also_called)->toBe('Gate 5, Phase 5 Gate')
        ->and(DB::connection('mysql')->table('audit_log')->where('entity_id', (string) $this->back->id)->where('action', 'site.post_renamed')->exists())->toBeFalse();

    $this->actingAs($this->director)->post('/guards/places/gate/'.$this->back->id.'/name', ['name' => 'North Gate', 'also_called' => 'Gate 5, Phase 5 Gate'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($this->back->fresh()->name)->toBe('North Gate');

    $this->actingAs($this->director)->post('/guards/places/gate/'.$this->back->id.'/name', ['name' => 'Main Gate'])
        ->assertSessionHasErrors(['places' => 'This estate already has a post called Main Gate. Give this one another name.']);
});

it('saves the estate\'s area and keeps its supervisor\'s number as it was', function () {
    DB::connection('mysql')->table('estate_sites')->insert(['tenant_id' => M30_PLACES, 'supervisor_phone' => '876-555-0142', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->director)->post('/guards/places/'.M30_PLACES.'/estate', ['lat' => 17.9612, 'lng' => -76.8821, 'radius_m' => 400])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'The estate\'s area is saved.');

    $site = DB::connection('mysql')->table('estate_sites')->where('tenant_id', M30_PLACES)->first();
    expect((float) $site->latitude)->toBe(17.9612)->and((int) $site->geofence_radius_m)->toBe(400)->and($site->supervisor_phone)->toBe('876-555-0142');
});

it('searches places through our server, in our own name, and keeps the answer for 30 days', function () {
    Http::fake(['nominatim.example.test/*' => Http::response([
        ['display_name' => 'Portmore, Saint Catherine, Jamaica', 'lat' => '17.9500', 'lon' => '-76.8800', 'boundingbox' => ['17.90', '18.00', '-76.95', '-76.85']],
    ])]);

    $first = $this->actingAs($this->director)->getJson('/guards/places/search?q=Portmore')->assertOk()->json();
    $again = $this->actingAs($this->director)->getJson('/guards/places/search?q=portmore')->assertOk()->json();

    // JSON writes 18.0 as 18: the values are compared, not PHP's types for them.
    expect($first['results'][0])->toEqual(['name' => 'Portmore, Saint Catherine, Jamaica', 'lat' => 17.95, 'lng' => -76.88, 'bbox' => [17.9, 18.0, -76.95, -76.85]])
        ->and($first['message'])->toBeNull()
        ->and($again)->toBe($first);

    Http::assertSentCount(1);
    Http::assertSent(static fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://nominatim.example.test/search')
        && $request['q'] === 'Portmore'
        && $request->header('User-Agent')[0] === 'GeminiSecure/1.0 (security operations console; ops@example.test)');

    // Kept for 30 days, and no longer.
    $this->travel(29)->days();
    $this->actingAs($this->director)->getJson('/guards/places/search?q=Portmore')->assertOk();
    Http::assertSentCount(1);
    $this->travel(2)->days();
    $this->actingAs($this->director)->getJson('/guards/places/search?q=Portmore')->assertOk();
    Http::assertSentCount(2);
});

it('asks Nominatim at most once a second across the platform, and says so rather than failing', function () {
    Http::fake(['nominatim.example.test/*' => Http::response([])]);

    $first = $this->actingAs($this->director)->getJson('/guards/places/search?q=Spanish Town')->assertOk()->json();
    $second = $this->actingAs($this->director)->getJson('/guards/places/search?q=Old Harbour')->assertOk()->json();

    expect($first['message'])->toBe('Nothing found by that name. Try a nearby town or road, or click the map where it is.')
        ->and($second)->toBe(['results' => [], 'message' => 'Place search is busy for a moment. Try again, or click the map where it is.']);
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    $this->actingAs($this->director)->getJson('/guards/places/search?q=Old Harbour')->assertOk();
    Http::assertSentCount(2);
});

it('answers the deploy\'s check from the server, as the screen asks, and an empty list is an answer (M31 §5.3)', function () {
    Http::fake(['nominatim.example.test/*' => Http::sequence()
        ->push([['display_name' => 'Portmore, Saint Catherine, Jamaica', 'lat' => '17.95', 'lon' => '-76.88'], ['display_name' => 'Portmore Pines', 'lat' => '17.956', 'lon' => '-76.871']])
        ->push('Service unavailable', 503)]);

    $this->artisan('places:search', ['text' => 'Portmore, Jamaica'])
        ->expectsOutput('{"answered":true,"results":2,"message":null}')
        ->assertExitCode(0);

    // OpenStreetMap down: no places, its one line, and still an answer.
    $this->travel(2)->seconds();
    $this->artisan('places:search', ['text' => 'Kingston, Jamaica'])
        ->expectsOutput('{"answered":true,"results":0,"message":"Place search is not answering right now. Click the map where it is, or paste its numbers."}')
        ->assertExitCode(0);

    Http::assertSent(static fn (HttpRequest $request): bool => $request->header('User-Agent')[0] === 'GeminiSecure/1.0 (security operations console; ops@example.test)');
});

it('answers with an empty list and one line when Nominatim fails or cannot be reached, and keeps nothing', function () {
    Http::fake(['nominatim.example.test/*' => Http::sequence()->push('Service unavailable', 503)->push([['display_name' => 'Kingston, Jamaica', 'lat' => '18.0', 'lon' => '-76.8']])]);

    $down = $this->actingAs($this->director)->getJson('/guards/places/search?q=Kingston')->assertOk()->json();
    expect($down)->toBe(['results' => [], 'message' => 'Place search is not answering right now. Click the map where it is, or paste its numbers.']);

    // The failure was not kept: the next search asks again, and gets an answer.
    $this->travel(2)->seconds();
    $up = $this->actingAs($this->director)->getJson('/guards/places/search?q=Kingston')->assertOk()->json();
    expect($up['results'][0]['name'])->toBe('Kingston, Jamaica');

    Http::fake(static fn () => throw new ConnectionException('timed out'));
    $this->travel(2)->seconds();
    $unreachable = $this->actingAs($this->director)->getJson('/guards/places/search?q=Mandeville')->assertOk()->json();
    expect($unreachable['results'])->toBe([])->and($unreachable['message'])->toStartWith('Place search is not answering right now.');
});
