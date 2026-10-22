<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Sites & geofences: where the posts and checkpoints stand (M11 §6)
|--------------------------------------------------------------------------
|
| A post had its surveyed position and geofence on the row and nothing to set them from but a seeder; a checkpoint
| had no position at all. Operations → Sites & geofences places them on an OpenStreetMap map. What is set here is
| what the guard's checkpoint map, the dispatch map and the estate's Security map draw — and the geofence a guard
| clocks in within, so every placement is audited, and only a role that may update the workforce can make one.
|
*/

const SITES_OTHER = 'sitesother';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->estate = FacilitiesFixture::ESTATE;
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $this->post = Post::create(['tenant_id' => $this->estate, 'name' => 'Sites Gate', 'type' => 'gate', 'is_active' => true]);
    $this->checkpoint = (int) DB::connection('mysql')->table('patrol_checkpoints')->insertGetId([
        'tenant_id' => $this->estate, 'post_id' => $this->post->id, 'label' => 'Pool gate', 'code' => 'SITES-CP-'.bin2hex(random_bytes(3)),
        'sequence' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('draws the client\'s posts and checkpoints, placed or not, for a role that may view the workforce', function () {
    $this->actingAs($this->director)->get('/guards/sites?estate='.$this->estate)->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Gemini/Operations/Sites')
            ->where('estate', $this->estate)
            ->where('canPlace', true)
            ->where('posts', fn ($posts) => collect($posts)->contains(fn (array $p): bool => $p['id'] === $this->post->id && $p['lat'] === null))
            ->where('checkpoints', fn ($cps) => collect($cps)->contains(fn (array $c): bool => $c['id'] === $this->checkpoint && $c['lat'] === null)));
});

it('places a post and its geofence, audited with who, the position before and after, and WHY (M12 §1.5)', function () {
    $this->actingAs($this->director)->post("/guards/sites/posts/{$this->post->id}", ['lat' => 18.0179451, 'lng' => -76.8099412, 'radius_m' => 40, 'reason' => 'surveyed on site'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $post = Post::query()->findOrFail($this->post->id);
    expect((float) $post->latitude)->toBe(18.0179451)
        ->and((float) $post->longitude)->toBe(-76.8099412)
        ->and((int) $post->geofence_radius_m)->toBe(40);

    $entry = AuditEntry::query()->where('action', 'site.post_placed')->where('entity_id', (string) $post->id)->firstOrFail();
    expect($entry->before)->toHaveKey('latitude')
        ->and($entry->before['latitude'])->toBeNull()
        ->and((float) $entry->after['latitude'])->toBe(18.0179451)
        ->and((int) $entry->after['geofence_radius_m'])->toBe(40)
        ->and($entry->after['reason'])->toBe('surveyed on site');
});

it('never moves a geofence without a reason (M12 §1.5)', function () {
    $this->actingAs($this->director)->post("/guards/sites/posts/{$this->post->id}", ['lat' => 18.0179, 'lng' => -76.8099, 'radius_m' => 40])
        ->assertSessionHasErrors('reason');
    $this->actingAs($this->director)->post("/guards/sites/posts/{$this->post->id}", ['lat' => 18.0179, 'lng' => -76.8099, 'radius_m' => 40, 'reason' => 'x'])
        ->assertSessionHasErrors('reason');

    expect(Post::query()->findOrFail($this->post->id)->latitude)->toBeNull();
});

it('refuses a geofence too tight for a phone to prove, or wider than an estate — and 0, 0, which is an empty field', function () {
    foreach ([2, 9, 5_000] as $radius) {
        $this->actingAs($this->director)->post("/guards/sites/posts/{$this->post->id}", ['lat' => 18.0, 'lng' => -76.8, 'radius_m' => $radius, 'reason' => 'surveyed'])
            ->assertSessionHasErrors('radius_m');
    }

    $this->actingAs($this->director)->post("/guards/sites/posts/{$this->post->id}", ['lat' => 0, 'lng' => 0, 'radius_m' => 40, 'reason' => 'surveyed'])
        ->assertSessionHasErrors('place');

    expect(Post::query()->findOrFail($this->post->id)->latitude)->toBeNull();
});

it('adds a post, placed as it is made — the only way a post comes to exist (M12 §1.1)', function () {
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/posts", [
        'name' => 'Back Gate', 'type' => 'gate', 'lat' => 18.0185, 'lng' => -76.8105, 'radius_m' => 60, 'reason' => 'second gate opened',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $post = Post::query()->where('tenant_id', $this->estate)->where('name', 'Back Gate')->firstOrFail();
    expect((float) $post->latitude)->toBe(18.0185)
        ->and((int) $post->geofence_radius_m)->toBe(60)
        ->and($post->is_active)->toBeTrue();

    $entry = AuditEntry::query()->where('action', 'site.post_added')->where('entity_id', (string) $post->id)->firstOrFail();
    expect($entry->after['reason'])->toBe('second gate opened')->and($entry->after['name'])->toBe('Back Gate');

    // The same name twice at one estate is two posts nobody can tell apart on a roster.
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/posts", [
        'name' => 'back gate', 'type' => 'gate', 'lat' => 18.0185, 'lng' => -76.8105, 'radius_m' => 60, 'reason' => 'again',
    ])->assertSessionHasErrors('add');
});

it('places the estate itself and its supervisor\'s number, audited with the reason, and the board carries both', function () {
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/estate", [
        'lat' => 18.0181, 'lng' => -76.8101, 'radius_m' => 400, 'supervisor_phone' => '876-555-0142', 'reason' => 'estate boundary from the survey',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $this->estate)->first();
    expect((float) $row->latitude)->toBe(18.0181)
        ->and((int) $row->geofence_radius_m)->toBe(400)
        ->and($row->supervisor_phone)->toBe('876-555-0142');

    $entry = AuditEntry::query()->where('action', 'site.estate_placed')->where('entity_id', $this->estate)->firstOrFail();
    expect($entry->before['latitude'])->toBeNull()->and($entry->after['reason'])->toBe('estate boundary from the survey');

    $this->actingAs($this->director)->get('/guards/sites?estate='.$this->estate)->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('site.radius_m', 400)
            ->where('site.supervisor_phone', '876-555-0142'));

    // Half a position, or a number no phone can dial, is refused.
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/estate", ['lat' => 18.0181, 'reason' => 'half'])->assertSessionHasErrors('site');
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/estate", ['supervisor_phone' => 'call me', 'reason' => 'bad number'])->assertSessionHasErrors('site');
});

it('places a checkpoint, and takes it off the map again', function () {
    $this->actingAs($this->director)->post("/guards/sites/checkpoints/{$this->checkpoint}", ['lat' => 18.01801, 'lng' => -76.81002])->assertSessionHasNoErrors();
    expect((float) DB::connection('mysql')->table('patrol_checkpoints')->where('id', $this->checkpoint)->value('latitude'))->toBe(18.01801);

    $this->actingAs($this->director)->post("/guards/sites/checkpoints/{$this->checkpoint}", ['lat' => null, 'lng' => null])->assertSessionHasNoErrors();
    expect(DB::connection('mysql')->table('patrol_checkpoints')->where('id', $this->checkpoint)->value('latitude'))->toBeNull()
        ->and(AuditEntry::query()->where('action', 'site.checkpoint_unplaced')->where('entity_id', (string) $this->checkpoint)->exists())->toBeTrue();
});

it('never places a post of a client outside the viewer\'s scope, and says nothing about whether it exists', function () {
    Tenant::withoutEvents(function (): void {
        (new Tenant)->forceFill(['id' => SITES_OTHER, 'name' => 'Other (sites test)', 'receipt_prefix' => 'SOT', 'status' => 'active'])->save();
    });
    $foreign = Post::create(['tenant_id' => SITES_OTHER, 'name' => 'Foreign Gate', 'type' => 'gate', 'is_active' => true]);

    $scoped = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    if (in_array(SITES_OTHER, $scoped->accessibleEstateIds(), true) || ! $scoped->can('gemini.guard_workforce.update')) {
        $this->markTestSkipped('The Head of Security in this matrix either reaches every estate or cannot update the workforce.');
    }

    $this->actingAs($scoped)->post("/guards/sites/posts/{$foreign->id}", ['lat' => 18.0, 'lng' => -76.8, 'radius_m' => 30, 'reason' => 'surveyed'])
        ->assertSessionHasErrors('place');

    expect(Post::query()->findOrFail($foreign->id)->latitude)->toBeNull();
});

it('the guard\'s patrol read carries each placed checkpoint\'s position and the site\'s post geofences', function () {
    $this->post->forceFill(['latitude' => 18.0179, 'longitude' => -76.8099, 'geofence_radius_m' => 35])->save();
    DB::connection('mysql')->table('patrol_checkpoints')->where('id', $this->checkpoint)->update(['latitude' => 18.018, 'longitude' => -76.81]);

    $guard = Guard::create([
        'full_name' => 'Delroy Sites', 'employee_number' => 'GS-SITES-1', 'psra_number' => 'PSRA-SITES-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->estate, 'post_id' => $this->post->id,
    ]);
    $token = app(DeviceEnrolment::class)->enrol($guard, 'Sites handset')['token'];

    $answer = $this->withToken($token)->getJson("/api/v1/sites/{$this->estate}/checkpoints")->assertOk();
    FacilitiesFixture::boot();

    $item = collect($answer->json('items'))->firstWhere('id', $this->checkpoint);
    $post = collect($answer->json('posts'))->firstWhere('id', $this->post->id);

    expect($item['latitude'])->toBe(18.018)
        ->and($item['longitude'])->toBe(-76.81)
        ->and($post['radius_m'])->toBe(35)
        ->and($post['latitude'])->toBe(18.0179);
});
