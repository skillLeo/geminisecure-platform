<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\CheckpointScan;
use App\Models\Guard;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\PatrolMonitor;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A patrol, end to end: the tag is made, printed, scanned and missed (M21 §1.1)
|--------------------------------------------------------------------------
|
| Before M21 a checkpoint came from a seeder and nothing else: no screen made one and no screen printed its tag, so a
| client could buy patrols and never walk one. And a scan proved only that the tag's text reached the server — from
| any shift or none, from any distance, as often as the guard liked. This file holds the whole loop:
|
|   the office      adds a checkpoint to a patrol route (Sites & geofences), placed as it is made, with a code no
|                   guard could guess, and prints its tag — the QR of that code;
|   the guard       scans it ON SHIFT and NEAR IT, or is told plainly why not; an early scan is recorded and said
|                   to be early; a scan made with no signal is placed on the shift it was made on;
|   the dispatcher  sees a missed checkpoint on the patrol board — also after the first round, which it did not.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-10-12 15:00:00', 'UTC'));

    $this->estate = FacilitiesFixture::ESTATE;
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->route = Post::create(['tenant_id' => $this->estate, 'name' => 'Patrol - North loop', 'type' => 'patrol', 'is_active' => true]);
    $this->route->forceFill(['latitude' => 18.0179000, 'longitude' => -76.8099000, 'geofence_radius_m' => 150])->save();

    $this->guard = Guard::create([
        'full_name' => 'Andre Patrol', 'employee_number' => 'GS-PTL-1', 'psra_number' => 'PSRA-PTL-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->estate, 'post_id' => $this->route->id,
    ]);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Patrol handset')['token'];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    Carbon::setTestNow();
});

/** A scan as the Guard App sends it (patrol.scan). */
function patrolScan(TestCase $test, string $token, int $checkpoint, array $body, ?Carbon $deviceTime = null, ?string $key = null): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->postJson("/api/v1/checkpoints/{$checkpoint}/scan", $body, [
        'Idempotency-Key' => $key ?? 'ptl-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => ($deviceTime ?? now())->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

/** A checkpoint on the route, placed at 18.0180, -76.8100 with a 25 m circle unless told otherwise. */
function patrolCheckpoint(Post $route, string $label, int $sequence, ?float $lat = 18.0180000, ?float $lng = -76.8100000): PatrolCheckpoint
{
    return PatrolCheckpoint::query()->create([
        'tenant_id' => (string) $route->tenant_id, 'post_id' => $route->id, 'label' => $label, 'code' => PatrolCheckpoint::newCode(),
        'sequence' => $sequence, 'is_active' => true, 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => 25,
    ]);
}

function onShiftSince(Guard $guard, Post $route, Carbon $start, ?Carbon $end = null): Shift
{
    return Shift::create([
        'tenant_id' => (string) $guard->tenant_id, 'guard_id' => $guard->id, 'post_id' => $route->id,
        'rostered_start' => $start, 'rostered_end' => $end ?? $start->copy()->addHours(12),
        'actual_start' => $start, 'actual_end' => $end, 'status' => $end === null ? 'on_duty' : 'completed',
    ]);
}

/* ── The office makes the tag ─────────────────────────────────────────────────────────────────────────────── */

it('adds a checkpoint to a patrol route, placed as it is made, with an unguessable code — audited without the code', function () {
    $this->actingAs($this->director)->post("/guards/sites/posts/{$this->route->id}/checkpoints", [
        'label' => 'North fence', 'lat' => 18.0181, 'lng' => -76.8101, 'radius_m' => 25, 'reason' => 'new patrol route surveyed',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $first = PatrolCheckpoint::query()->where('post_id', $this->route->id)->where('label', 'North fence')->firstOrFail();
    expect($first->code)->toStartWith(PatrolCheckpoint::CODE_PREFIX)
        ->and(strlen($first->code))->toBe(strlen(PatrolCheckpoint::CODE_PREFIX) + 12)
        ->and($first->code)->toMatch('/^GSCP-[2-9A-HJKMNP-Z]{12}$/')
        ->and($first->sequence)->toBe(1)
        ->and($first->radius())->toBe(25)
        ->and((float) $first->latitude)->toBe(18.0181)
        ->and($first->tenant_id)->toBe($this->estate);

    $entry = AuditEntry::query()->where('action', 'site.checkpoint_added')->where('entity_id', (string) $first->id)->firstOrFail();
    expect($entry->after['reason'])->toBe('new patrol route surveyed')
        ->and($entry->after['post'])->toBe('Patrol - North loop')
        ->and(json_encode($entry->after))->not->toContain($first->code);

    // The next one takes the next place on the route.
    $this->actingAs($this->director)->post("/guards/sites/posts/{$this->route->id}/checkpoints", [
        'label' => 'Pool gate', 'lat' => 18.0185, 'lng' => -76.8105, 'radius_m' => 40, 'reason' => 'new patrol route surveyed',
    ])->assertSessionHasNoErrors();
    $second = PatrolCheckpoint::query()->where('post_id', $this->route->id)->where('label', 'Pool gate')->firstOrFail();
    expect($second->sequence)->toBe(2)->and($second->code)->not->toBe($first->code);

    // The board carries each checkpoint's circle and the bounds the form offers.
    $this->actingAs($this->director)->get('/guards/sites?estate='.$this->estate)->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('checkpointRadius.max', 200)
            ->where('checkpoints', fn ($cps) => collect($cps)->contains(fn (array $c): bool => $c['id'] === $second->id && $c['radius_m'] === 40 && $c['post_id'] === $this->route->id)));
});

it('refuses a checkpoint on a post that is not a patrol route, a name used twice on the route, and a circle a room could not hold', function () {
    $gate = Post::create(['tenant_id' => $this->estate, 'name' => 'Patrol test gate', 'type' => 'gate', 'is_active' => true]);
    $this->actingAs($this->director)->post("/guards/sites/posts/{$gate->id}/checkpoints", [
        'label' => 'Gate box', 'lat' => 18.0181, 'lng' => -76.8101, 'radius_m' => 25, 'reason' => 'surveyed',
    ])->assertSessionHasErrors('checkpoint');

    patrolCheckpoint($this->route, 'North fence', 1);
    $this->actingAs($this->director)->post("/guards/sites/posts/{$this->route->id}/checkpoints", [
        'label' => 'north FENCE', 'lat' => 18.0181, 'lng' => -76.8101, 'radius_m' => 25, 'reason' => 'surveyed',
    ])->assertSessionHasErrors('checkpoint');

    foreach ([5, 500] as $radius) {
        $this->actingAs($this->director)->post("/guards/sites/posts/{$this->route->id}/checkpoints", [
            'label' => 'Wide '.$radius, 'lat' => 18.0181, 'lng' => -76.8101, 'radius_m' => $radius, 'reason' => 'surveyed',
        ])->assertSessionHasErrors('radius_m');
    }

    $this->actingAs($this->director)->post("/guards/sites/posts/{$this->route->id}/checkpoints", [
        'label' => 'No reason', 'lat' => 18.0181, 'lng' => -76.8101, 'radius_m' => 25,
    ])->assertSessionHasErrors('reason');

    expect(PatrolCheckpoint::query()->where('post_id', $this->route->id)->count())->toBe(1)
        ->and(PatrolCheckpoint::query()->where('post_id', $gate->id)->count())->toBe(0);
});

it('prints the tag: the QR of the checkpoint\'s own code, its name, its place on the route and the code in letters', function () {
    $first = patrolCheckpoint($this->route, 'North fence', 1);
    patrolCheckpoint($this->route, 'Pool gate', 2);

    $html = (string) $this->actingAs($this->director)->get("/guards/sites/checkpoints/{$first->id}/tag")->assertOk()->getContent();

    // The QR is exactly the code's: the same renderer, the same text — so the Guard App reads this checkpoint's code.
    $svg = (new Writer(new ImageRenderer(new RendererStyle(600, 2), new SvgImageBackEnd)))->writeString($first->code);
    expect($html)->toContain('data:image/svg+xml;base64,'.base64_encode($svg))
        ->and($html)->toContain('North fence')
        ->and($html)->toContain($first->code)
        ->and($html)->toContain('Checkpoint 1 of 2')
        ->and($html)->toContain('Patrol - North loop')
        ->and($html)->toContain('within 25 m')
        ->and($html)->not->toContain('<script');

    expect(AuditEntry::query()->where('action', 'site.checkpoint_tag_printed')->where('entity_id', (string) $first->id)->exists())->toBeTrue();

    // Printing again is the same code: a torn tag is replaced, not re-keyed.
    $this->actingAs($this->director)->get("/guards/sites/checkpoints/{$first->id}/tag")->assertOk()->assertSee($first->code);
    expect(PatrolCheckpoint::query()->findOrFail($first->id)->code)->toBe($first->code);

    $this->actingAs($this->director)->get('/guards/sites/checkpoints/999999999/tag')->assertNotFound();
});

it('lets a role that may only view the workforce see the checkpoints, and neither make one nor print its tag', function () {
    $viewer = FacilitiesFixture::geminiViewer(Role::ADMIN_ASSISTANT);
    if (! $viewer->can('gemini.guard_workforce.view') || $viewer->can('gemini.guard_workforce.update')) {
        $this->markTestSkipped('The Admin Assistant in this matrix is not view-only on the workforce.');
    }
    $first = patrolCheckpoint($this->route, 'North fence', 1);

    $this->actingAs($viewer)->get('/guards/sites?estate='.$this->estate)->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('canPlace', false));
    $this->actingAs($viewer)->post("/guards/sites/posts/{$this->route->id}/checkpoints", [
        'label' => 'Sneaky', 'lat' => 18.0181, 'lng' => -76.8101, 'radius_m' => 25, 'reason' => 'surveyed',
    ])->assertForbidden();
    $this->actingAs($viewer)->get("/guards/sites/checkpoints/{$first->id}/tag")->assertForbidden();
    $this->actingAs($viewer)->post("/guards/sites/checkpoints/{$first->id}/retire", ['reason' => 'tidying up'])->assertForbidden();
    expect(PatrolCheckpoint::query()->findOrFail($first->id)->is_active)->toBeTrue();
});

it('takes a checkpoint off its route with a reason: out of the tour, its tag refused, the rest numbered again, its scans kept (M22)', function () {
    $first = patrolCheckpoint($this->route, 'North fence', 1);
    $second = patrolCheckpoint($this->route, 'Pool gate', 2);
    $third = patrolCheckpoint($this->route, 'Back wall', 3);
    onShiftSince($this->guard, $this->route, now()->subHour());
    $near = ['latitude' => 18.0180100, 'longitude' => -76.8100100, 'accuracy_m' => 6];
    patrolScan($this, $this->token, $first->id, ['code' => $first->code, ...$near])->assertCreated();

    // Said why, or nothing happens.
    $this->actingAs($this->director)->post("/guards/sites/checkpoints/{$first->id}/retire", ['reason' => ''])->assertSessionHasErrors('reason');
    expect(PatrolCheckpoint::query()->findOrFail($first->id)->is_active)->toBeTrue();

    $this->actingAs($this->director)->post("/guards/sites/checkpoints/{$first->id}/retire", ['reason' => 'placed in error'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(PatrolCheckpoint::query()->findOrFail($first->id)->is_active)->toBeFalse()
        ->and(PatrolCheckpoint::query()->findOrFail($second->id)->sequence)->toBe(1)
        ->and(PatrolCheckpoint::query()->findOrFail($third->id)->sequence)->toBe(2)
        // What was scanned there stays on the record, under its name.
        ->and(CheckpointScan::query()->where('patrol_checkpoint_id', $first->id)->count())->toBe(1);

    $entry = AuditEntry::query()->where('action', 'site.checkpoint_retired')->where('entity_id', (string) $first->id)->firstOrFail();
    expect($entry->after['reason'])->toBe('placed in error')
        ->and($entry->after['left_on_route'])->toBe(2)
        ->and(json_encode([$entry->before, $entry->after]))->not->toContain($first->code);

    // Out of the guard's tour and the board; the tags left read "1 of 2" and "2 of 2".
    app('auth')->forgetGuards();
    $tour = $this->withToken($this->token)->getJson("/api/v1/sites/{$this->estate}/checkpoints")->assertOk();
    FacilitiesFixture::boot();
    expect(collect($tour->json('items'))->pluck('id')->all())->toBe([$second->id, $third->id]);
    $this->actingAs($this->director)->get('/guards/sites?estate='.$this->estate)->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('checkpoints', fn ($cps) => ! collect($cps)->contains(fn (array $c): bool => $c['id'] === $first->id)));
    $this->actingAs($this->director)->get("/guards/sites/checkpoints/{$third->id}/tag")->assertOk()->assertSee('Checkpoint 2 of 2');
    $this->actingAs($this->director)->get("/guards/sites/checkpoints/{$first->id}/tag")->assertNotFound();

    // Its tag, still on the wall, is refused in words, and nothing is recorded for it.
    $this->travel(20)->minutes();
    $refused = patrolScan($this, $this->token, $first->id, ['code' => $first->code, ...$near])
        ->assertStatus(422)->assertJsonPath('error.code', 'checkpoint_retired');
    expect($refused->json('error.message'))->toBe('North fence is no longer a checkpoint on your route, so this scan is not counted. Tell your supervisor the tag is still up.')
        ->and(CheckpointScan::query()->where('patrol_checkpoint_id', $first->id)->count())->toBe(1);

    // Taken off once; there is nothing to take off again.
    $this->actingAs($this->director)->post("/guards/sites/checkpoints/{$first->id}/retire", ['reason' => 'again'])->assertSessionHasErrors('retire');
});

/* ── The guard scans it ───────────────────────────────────────────────────────────────────────────────────── */

it('records a scan on shift and near the tag with its time, position and shift — and refuses one off shift, far away or with the wrong tag', function () {
    $cp = patrolCheckpoint($this->route, 'North fence', 1);
    $near = ['latitude' => 18.0180100, 'longitude' => -76.8100100, 'accuracy_m' => 6];

    // Off shift: nothing is recorded, and the guard is told why.
    patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, ...$near])
        ->assertStatus(422)->assertJsonPath('error.code', 'not_on_shift');

    $shift = onShiftSince($this->guard, $this->route, now()->subHour());

    // The wrong tag.
    patrolScan($this, $this->token, $cp->id, ['code' => 'GSCP-NOTTHISONE1', ...$near])
        ->assertStatus(422)->assertJsonPath('error.code', 'code_mismatch');

    // The right tag, photographed and scanned from the gatehouse ~220 m away.
    $far = patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, 'latitude' => 18.0200, 'longitude' => -76.8100, 'accuracy_m' => 5])
        ->assertStatus(422)->assertJsonPath('error.code', 'outside_checkpoint');
    expect($far->json('error.message'))->toMatch('/^You are \d+m from North fence — \d+m outside the 25m around its tag\. North fence is \w+ of you\. Scan the tag where it hangs\.$/');
    expect(CheckpointScan::query()->where('patrol_checkpoint_id', $cp->id)->count())->toBe(0);

    // At the tag.
    $ok = patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, ...$near])->assertCreated();
    expect($ok->json('position'))->toBe('inside')
        ->and($ok->json('shift_id'))->toBe($shift->id)
        ->and($ok->json('early'))->toBeFalse()
        ->and($ok->json('note'))->toBeNull()
        ->and($ok->json('distance_m'))->toBeLessThan(5)
        ->and(Carbon::parse($ok->json('next_due_at'))->equalTo(now()->addMinutes(PatrolCheckpoint::CADENCE_MINUTES)))->toBeTrue();

    $row = CheckpointScan::query()->findOrFail($ok->json('scan_id'));
    expect($row->shift_id)->toBe($shift->id)
        ->and((float) $row->latitude)->toBe(18.01801)
        ->and((float) $row->longitude)->toBe(-76.81001)
        ->and($row->accuracy_m)->toBe(6)
        ->and($row->distance_m)->toBeLessThan(5)
        ->and($row->early)->toBeFalse()
        ->and($row->server_time->equalTo(now()))->toBeTrue();
});

it('records a scan too soon after the last as EARLY, says when the next round is due, and does not call the next one early', function () {
    $cp = patrolCheckpoint($this->route, 'North fence', 1);
    onShiftSince($this->guard, $this->route, now()->subHour());
    $near = ['latitude' => 18.0180100, 'longitude' => -76.8100100, 'accuracy_m' => 6];

    patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, ...$near])->assertCreated();
    $first = now()->copy();

    $this->travel(6)->minutes();
    $early = patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, ...$near])->assertCreated();
    expect($early->json('early'))->toBeTrue()
        ->and(Carbon::parse($early->json('next_due_at'))->equalTo($first->copy()->addMinutes(PatrolCheckpoint::CADENCE_MINUTES)))->toBeTrue()
        // 15:15 UTC is 10:15 AM in Kingston: said in the estate's own clock, with its label (M11 §4.3).
        ->and($early->json('note'))->toBe('Recorded as early: you scanned North fence 6 min ago. The next round here is due at 10:15 AM JA.');

    $this->travel(20)->minutes();
    $onTime = patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, ...$near])->assertCreated();
    expect($onTime->json('early'))->toBeFalse()->and($onTime->json('note'))->toBeNull();

    expect(CheckpointScan::query()->where('patrol_checkpoint_id', $cp->id)->pluck('early')->all())->toBe([false, true, false]);
});

it('records a scan with no position, a poor one, or at a checkpoint nobody has placed — and says so rather than refusing the tag', function () {
    onShiftSince($this->guard, $this->route, now()->subHour());
    $placed = patrolCheckpoint($this->route, 'North fence', 1);
    $unplaced = patrolCheckpoint($this->route, 'Pool gate', 2, null, null);

    $none = patrolScan($this, $this->token, $placed->id, ['code' => $placed->code])->assertCreated();
    expect($none->json('position'))->toBe('no_position')->and($none->json('note'))->toContain('No position came from this phone');

    $this->travel(16)->minutes();
    $poor = patrolScan($this, $this->token, $placed->id, ['code' => $placed->code, 'latitude' => 18.0190, 'longitude' => -76.8100, 'accuracy_m' => 900])->assertCreated();
    expect($poor->json('position'))->toBe('poor_fix')->and($poor->json('note'))->toContain('could not say where it was closely enough');

    $there = patrolScan($this, $this->token, $unplaced->id, ['code' => $unplaced->code, 'latitude' => 18.03, 'longitude' => -76.80, 'accuracy_m' => 5])->assertCreated();
    expect($there->json('position'))->toBe('unplaced')
        ->and($there->json('distance_m'))->toBeNull()
        ->and($there->json('note'))->toContain('not on the map yet');
});

it('places a scan made with no signal on the shift it was made on, refuses one made between shifts, and records a retried one once', function () {
    $cp = patrolCheckpoint($this->route, 'North fence', 1);
    $earlier = onShiftSince($this->guard, $this->route, now()->subHours(12), now()->subHours(2));
    $current = onShiftSince($this->guard, $this->route, now()->subHour());
    $near = ['latitude' => 18.0180100, 'longitude' => -76.8100100, 'accuracy_m' => 6, 'captured_offline' => true];

    $lastNight = patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, ...$near], now()->subHours(3), 'ptl-offline-1')->assertCreated();
    expect($lastNight->json('shift_id'))->toBe($earlier->id);

    // The same act sent again after a lost answer: one row.
    patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, ...$near], now()->subHours(3), 'ptl-offline-1');
    expect(CheckpointScan::query()->where('idempotency_key', 'ptl-offline-1')->count())->toBe(1);

    patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, ...$near], now()->subMinutes(90))
        ->assertStatus(422)->assertJsonPath('error.code', 'not_on_shift');

    expect(patrolScan($this, $this->token, $cp->id, ['code' => $cp->code, ...$near], now()->subMinutes(20))->assertCreated()->json('shift_id'))->toBe($current->id);
});

it('shows a patrol officer their own route, not every route at the estate interleaved — and counts the tour on it', function () {
    $mine = patrolCheckpoint($this->route, 'North fence', 1);
    patrolCheckpoint($this->route, 'Pool gate', 2, 18.0180500, -76.8100500);
    $gate = Post::create(['tenant_id' => $this->estate, 'name' => 'Patrol test gate', 'type' => 'gate', 'is_active' => true]);
    patrolCheckpoint($gate, 'Gatehouse', 1, 18.0190, -76.8110);

    // Not on a shift yet: the site's checkpoints, as before.
    app('auth')->forgetGuards();
    $before = $this->withToken($this->token)->getJson("/api/v1/sites/{$this->estate}/checkpoints")->assertOk();
    FacilitiesFixture::boot();
    expect(collect($before->json('items'))->pluck('label')->all())->toContain('Gatehouse');

    onShiftSince($this->guard, $this->route, now()->subHour());
    app('auth')->forgetGuards();
    $on = $this->withToken($this->token)->getJson("/api/v1/sites/{$this->estate}/checkpoints")->assertOk();
    FacilitiesFixture::boot();
    expect(collect($on->json('items'))->pluck('label')->all())->toBe(['North fence', 'Pool gate'])
        ->and($on->json('tour.total'))->toBe(2);

    $scan = patrolScan($this, $this->token, $mine->id, ['code' => $mine->code, 'latitude' => 18.01801, 'longitude' => -76.81001, 'accuracy_m' => 5])->assertCreated();
    expect($scan->json('tour'))->toBe(['total' => 2, 'scanned_today' => 1]);

    // The clock-out screen's shift summary counts the same tour (it said "2/11" on the emulator).
    app('auth')->forgetGuards();
    $summary = $this->withToken($this->token)->getJson('/api/v1/guards/me/activity-summary')->assertOk();
    FacilitiesFixture::boot();
    expect($summary->json('checkpoints_total'))->toBe(2)->and($summary->json('checkpoints_scanned'))->toBe(1);
});

it('lists each officer on duty with you once, and not one who never clocked out days ago', function () {
    onShiftSince($this->guard, $this->route, now()->subHour());
    $gate = Post::create(['tenant_id' => $this->estate, 'name' => 'Colleague gate', 'type' => 'gate', 'is_active' => true]);
    $other = Guard::create([
        'full_name' => 'Simone Colleague', 'employee_number' => 'GS-PTL-2', 'psra_number' => 'PSRA-PTL-2',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->estate, 'post_id' => $gate->id,
    ]);
    $stale = Guard::create([
        'full_name' => 'Forgot Toclockout', 'employee_number' => 'GS-PTL-3', 'psra_number' => 'PSRA-PTL-3',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->estate, 'post_id' => $gate->id,
    ]);
    // The same officer "on duty" twice (a clock-in on a shift the office never closed, then today's), and one from three nights ago.
    onShiftSince($other, $gate, now()->subHours(9));
    onShiftSince($other, $gate, now()->subHours(2));
    onShiftSince($stale, $gate, now()->subDays(3));

    app('auth')->forgetGuards();
    $colleagues = collect($this->withToken($this->token)->getJson('/api/v1/shifts/me/current')->assertOk()->json('shift.colleagues'));
    FacilitiesFixture::boot();

    expect($colleagues->where('name', 'Simone Colleague')->count())->toBe(1)
        ->and($colleagues->pluck('name')->all())->not->toContain('Forgot Toclockout');
});

/* ── The dispatcher sees it missed ────────────────────────────────────────────────────────────────────────── */

it('raises a missed checkpoint on the dispatcher\'s patrol board — before the first scan, and again after a round is complete', function () {
    $a = patrolCheckpoint($this->route, 'North fence', 1);
    $b = patrolCheckpoint($this->route, 'Pool gate', 2, 18.0180500, -76.8100500);
    onShiftSince($this->guard, $this->route, now()->subHour());

    $rowOf = fn (): array => collect(app(PatrolMonitor::class)->forViewer($this->director)['rows'])->firstWhere('key', 'guard-'.$this->guard->id);

    // An hour on duty and nothing scanned: missed.
    expect($rowOf()['state'])->toBe('Missed checkpoint')
        ->and($rowOf()['last_scan'])->toBe('Tour not started')
        ->and(collect($rowOf()['tour'])->pluck('class')->all())->toBe(['missed', 'pending']);

    // The dispatcher's own screen says so, with the banner.
    $this->actingAs($this->director)->get('/dispatch/alertness')->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('rows', fn ($rows) => collect($rows)->contains(fn (array $r): bool => $r['key'] === 'guard-'.$this->guard->id && $r['state'] === 'Missed checkpoint'))
            ->where('banner', fn ($banner) => $banner !== null && str_contains((string) $banner['title'], 'missed checkpoint')));

    // One round walked: all well.
    $near = fn (PatrolCheckpoint $cp): array => ['code' => $cp->code, 'latitude' => (float) $cp->latitude, 'longitude' => (float) $cp->longitude, 'accuracy_m' => 5];
    patrolScan($this, $this->token, $a->id, $near($a))->assertCreated();
    $this->travel(3)->minutes();
    patrolScan($this, $this->token, $b->id, $near($b))->assertCreated();
    expect($rowOf()['state'])->toBe('Active')->and($rowOf()['last_scan'])->toBe('Round 1 complete · 0 min ago');

    // …and then the guard stops. Twenty-six minutes later the next round's first checkpoint is missed. Before M21
    // a complete round silenced this board for the rest of the shift.
    $this->travel(26)->minutes();
    expect($rowOf()['state'])->toBe('Missed checkpoint')
        ->and(collect($rowOf()['tour'])->pluck('class')->all())->toBe(['missed', 'pending']);

    // Walking again clears it, and progress counts the new round.
    patrolScan($this, $this->token, $a->id, $near($a))->assertCreated();
    expect($rowOf()['state'])->toBe('Active')->and($rowOf()['last_scan'])->toBe('Checkpoint 1 of 2 · round 2 · 0 min ago');
});

it('times a round walked with no signal by when each tag was reached, not when the phone found signal', function () {
    $a = patrolCheckpoint($this->route, 'North fence', 1);
    patrolCheckpoint($this->route, 'Pool gate', 2, 18.0180500, -76.8100500);
    onShiftSince($this->guard, $this->route, now()->subHours(2));

    // Scanned forty minutes ago with no signal, synced now: the guard has been quiet for forty minutes, which is late.
    patrolScan($this, $this->token, $a->id, ['code' => $a->code, 'captured_offline' => true], now()->subMinutes(40))->assertCreated();

    $row = collect(app(PatrolMonitor::class)->forViewer($this->director)['rows'])->firstWhere('key', 'guard-'.$this->guard->id);
    expect($row['state'])->toBe('Missed checkpoint')->and($row['last_scan'])->toBe('Checkpoint 1 of 2 · 40 min ago');
});
