<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Console\Commands\PhoenixTwoGates;
use App\Models\AuditEntry;
use App\Models\Estate\Phase;
use App\Models\Guard;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\ClockInFence;
use App\Services\Dispatch\GateLog;
use App\Services\Dispatch\LiveMap;
use App\Services\Dispatch\PatrolMonitor;
use App\Services\Dispatch\PostCoverage;
use App\Services\Gemini\DutyBoard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| What Phoenix Park actually looks like — M26, M27
|--------------------------------------------------------------------------
|
| The client, 2 October 2026: "We only have two gates… the main gate and the
| back gate, which is what we call gate number five or phase five gate." "Patrols
| will be done via a vehicle." "Their patrol would start at the main gate." And
| fifteen checkpoints, "three on average per phase".
|
|   a post's local names    shown wherever the post is: the Guard App's shift, the clock-in's refusal, the gate log
|   a patrol shift          posted at the Main Gate beside the gate officer, clocked in inside the Main Gate's own
|                           fence, toured on the estate's route, never counted as the gate stood
|   the estate's route      made fifteen at once with no position, placed from the installers' messages in one go,
|                           ordered, on an interval the Director sets, every tag printed together with its phase
|   phoenix:two-gates       the correction of an estate the demonstration seeded with four posts
|
*/

const TWO_GATES_LAT = 18.0179000;
const TWO_GATES_LNG = -76.8099000;
const TWO_GATES_M_PER_DEG = 111_195.08;

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-10-13 15:00:00', 'UTC'));

    $this->estate = FacilitiesFixture::ESTATE;
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    // Named apart from any post the shared fixture estate already has.
    $this->main = Post::create(['tenant_id' => $this->estate, 'name' => 'Main Gate M27', 'type' => 'gate', 'is_active' => true]);
    $this->main->forceFill(['latitude' => TWO_GATES_LAT, 'longitude' => TWO_GATES_LNG, 'geofence_radius_m' => 30])->save();
    $this->back = Post::create(['tenant_id' => $this->estate, 'name' => 'Back Gate M27', 'also_called' => 'Gate 5, Phase 5 Gate', 'type' => 'gate', 'is_active' => true]);
    $this->back->forceFill(['latitude' => TWO_GATES_LAT + 0.01, 'longitude' => TWO_GATES_LNG, 'geofence_radius_m' => 30])->save();

    $this->guard = Guard::create([
        'full_name' => 'Kemar Patrol', 'employee_number' => 'GS-M27-1', 'psra_number' => 'PSRA-M27-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->estate, 'post_id' => $this->main->id,
    ]);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Patrol handset')['token'];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    Carbon::setTestNow();
});

/** One Guard App call, as the handset. */
function twoGatesCall(TestCase $test, string $name, Shift $shift, array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.str_replace('{shift}', (string) $shift->id, $entry['uri']);

    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->json($entry['method'], $uri, $body, [
        ...($entry['write'] ? ['Idempotency-Key' => 'm27-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : []),
        'X-Device-Sent-At' => now()->toIso8601String(),
    ]);
    $response->assertStatus($status ?? $entry['status']);
    FacilitiesFixture::boot();

    return $response;
}

/** A shift for the test guard at a post, rostered from ten minutes ago. */
function twoGatesShift(Post $post, string $duty = Shift::DUTY_POST, bool $started = false): Shift
{
    return Shift::create([
        'tenant_id' => (string) $post->tenant_id, 'guard_id' => test()->guard->id, 'post_id' => $post->id, 'duty' => $duty,
        'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(11),
        'actual_start' => $started ? now()->subMinutes(10) : null, 'status' => $started ? 'on_duty' : 'rostered',
    ]);
}

/* ── a post's local names ──────────────────────────────────────────────────────────────────────────────────── */

it('names the Back Gate as the estate knows it on the Guard App\'s shift, in the clock-in\'s refusal and in the gate log', function () {
    expect($this->back->label())->toBe('Back Gate M27 (Gate 5, Phase 5 Gate)')
        ->and(Post::labelFor('Main Gate', null))->toBe('Main Gate')
        ->and(Post::labelFor(str_repeat('x', 110), 'Gate 5, Phase 5 Gate'))->toBe(str_repeat('x', 110));

    $shift = twoGatesShift($this->back);

    $current = twoGatesCall($this, 'shifts.me.current', $shift)->json('shift');
    expect($current['post']['name'])->toBe('Back Gate M27 (Gate 5, Phase 5 Gate)')
        ->and($current['fence']['name'])->toBe('Back Gate M27 (Gate 5, Phase 5 Gate)');

    // 500 m south of the Back Gate: refused, by the gate's local names too.
    $far = ['latitude' => round(TWO_GATES_LAT + 0.01 - 500 / TWO_GATES_M_PER_DEG, 7), 'longitude' => TWO_GATES_LNG, 'accuracy_m' => 6];
    $checks = collect(twoGatesCall($this, 'shifts.preflight', $shift, $far)->json('checks'));
    expect($checks->firstWhere('key', 'geofence')['detail'])->toContain('from Back Gate M27 (Gate 5, Phase 5 Gate)');

    $event = app(GateLog::class)->record($this->estate, 'admit', 'Visitor', 'Lot 47 visitor', 'QR pass', $this->guard->id, $this->back->id);
    expect($event->post_name)->toBe('Back Gate M27 (Gate 5, Phase 5 Gate)');
});

/* ── a patrol shift ────────────────────────────────────────────────────────────────────────────────────────── */

it('posts a patrol shift at the Main Gate beside the gate officer\'s, and refuses two patrols at once', function () {
    $window = ['starts_at' => now()->addDay()->setTime(7, 0)->format('Y-m-d\TH:i'), 'ends_at' => now()->addDay()->setTime(19, 0)->format('Y-m-d\TH:i')];

    $this->actingAs($this->director)->post('/guards/roster/shifts', ['post_id' => $this->main->id, ...$window])->assertSessionHasNoErrors();
    $this->actingAs($this->director)->post('/guards/roster/shifts', ['post_id' => $this->main->id, 'duty' => 'patrol', ...$window])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $s): bool => str_starts_with($s, 'Main Gate M27 · patrol is on the rota'));

    FacilitiesFixture::boot();
    expect(Shift::query()->where('post_id', $this->main->id)->pluck('duty')->sort()->values()->all())->toBe(['patrol', 'post']);

    $this->actingAs($this->director)->post('/guards/roster/shifts', ['post_id' => $this->main->id, 'duty' => 'patrol', ...$window])
        ->assertSessionHasErrors('starts_at');
    expect(session('errors')->first('starts_at'))->toContain('already has a patrol shift');
});

it('clocks a patrol officer in inside the Main Gate\'s own fence, and names the shift "Main Gate · patrol"', function () {
    $shift = twoGatesShift($this->main, Shift::DUTY_PATROL);

    expect(twoGatesCall($this, 'shifts.me.current', $shift)->json('shift.post.name'))->toBe('Main Gate M27 · patrol');

    $near = ['latitude' => round(TWO_GATES_LAT + 12 / TWO_GATES_M_PER_DEG, 7), 'longitude' => TWO_GATES_LNG, 'accuracy_m' => 6];
    $pre = twoGatesCall($this, 'shifts.preflight', $shift, $near);
    expect($pre->json('outcome'))->toBe(ClockInFence::INSIDE)
        ->and(collect($pre->json('checks'))->firstWhere('key', 'geofence')['detail'])->toBe('12m from Main Gate M27, within its 30m geofence.');

    // 300 m out, the same refusal a gate officer reads, by the gate.
    $out = ['latitude' => round(TWO_GATES_LAT + 300 / TWO_GATES_M_PER_DEG, 7), 'longitude' => TWO_GATES_LNG, 'accuracy_m' => 6];
    expect(collect(twoGatesCall($this, 'shifts.preflight', $shift, $out)->json('checks'))->firstWhere('key', 'geofence')['detail'])
        ->toContain('You are 300m from Main Gate M27')->toContain('Main Gate M27 is south of you');
});

it('shows a patrol shift on every board as a patrol: toured on the estate\'s route, never the gate stood', function () {
    FacilitiesFixture::boot();
    $route = collect(['North fence', 'Pool deck', 'Basketball court'])->map(fn (string $label, int $i): PatrolCheckpoint => PatrolCheckpoint::query()->create([
        'tenant_id' => $this->estate, 'post_id' => null, 'label' => $label.' M27', 'code' => PatrolCheckpoint::newCode(), 'sequence' => $i + 1, 'is_active' => true,
    ]));
    $shift = twoGatesShift($this->main, Shift::DUTY_PATROL, started: true);

    // The alertness board: a patrol, from the Main Gate, three checkpoints to make.
    $row = collect(app(PatrolMonitor::class)->forViewer($this->director)['rows'])->firstWhere('name', 'Kemar Patrol');
    expect($row['post'])->toBe('Patrol — from Main Gate M27')
        ->and($row['tour'])->toHaveCount(3)
        ->and($row['last_scan'])->toBe('Tour not started');

    // The estate's interval: 30 minutes after the clock-in the first checkpoint is not yet missed on a 30-minute patrol…
    DB::connection('mysql')->table('estate_sites')->updateOrInsert(['tenant_id' => $this->estate], ['patrol_interval_minutes' => 30, 'updated_at' => now(), 'created_at' => now()]);
    Carbon::setTestNow(now()->addMinutes(25));
    expect(collect(app(PatrolMonitor::class)->forViewer($this->director)['rows'])->firstWhere('name', 'Kemar Patrol')['state'])->not->toBe('Missed checkpoint');

    // …and is, past its interval and grace.
    Carbon::setTestNow(now()->addMinutes(20));
    expect(collect(app(PatrolMonitor::class)->forViewer($this->director)['rows'])->firstWhere('name', 'Kemar Patrol')['state'])->toBe('Missed checkpoint');

    // The coverage board: the Main Gate is not stood by the patrol; the patrol has its own line.
    $coverage = collect(app(PostCoverage::class)->forViewer($this->director)['rows']);
    expect(collect($coverage->firstWhere('key', 'post-'.$this->main->id)['cells'])->pluck('label')->unique()->all())->toBe(['Uncovered'])
        ->and($coverage->firstWhere('key', 'patrol-'.$this->main->id)['post'])->toBe('Main Gate M27 · patrol')
        ->and($coverage->firstWhere('key', 'patrol-'.$this->main->id)['assigned'])->toBe('Kemar Patrol');

    // The duty board and the live map.
    expect(collect(app(DutyBoard::class)->for($this->director)['onDuty'])->firstWhere('id', $shift->id)['post'])->toBe('Main Gate M27 · patrol');
    $duty = collect(app(LiveMap::class)->forViewer($this->director)['onDuty'])->firstWhere('key', 'duty-'.$shift->id);
    expect($duty['colour'])->toBe('var(--navy-300)')->and($duty['where'])->toContain('Main Gate M27 · patrol');

    // The Guard App's tour is the same three.
    expect(PatrolCheckpoint::tourFor($this->guard->id, $this->estate))->toBe($route->map(fn (PatrolCheckpoint $c): int => (int) $c->id)->all());
});

it('times the scan\'s early rule and next due by the estate\'s own interval', function () {
    $cp = PatrolCheckpoint::query()->create(['tenant_id' => $this->estate, 'post_id' => null, 'label' => 'Pool deck M27', 'code' => PatrolCheckpoint::newCode(), 'sequence' => 1, 'is_active' => true]);
    twoGatesShift($this->main, Shift::DUTY_PATROL, started: true);
    DB::connection('mysql')->table('estate_sites')->updateOrInsert(['tenant_id' => $this->estate], ['patrol_interval_minutes' => 40, 'updated_at' => now(), 'created_at' => now()]);

    $scan = function () use ($cp): TestResponse {
        app('auth')->forgetGuards();
        $r = $this->withToken($this->token)->postJson("/api/v1/checkpoints/{$cp->id}/scan", ['code' => $cp->code], ['Idempotency-Key' => 'm27-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()]);
        FacilitiesFixture::boot();

        return $r;
    };

    $first = $scan()->assertCreated();
    expect(now()->diffInMinutes(Carbon::parse($first->json('next_due_at'))))->toEqualWithDelta(40, 1);

    Carbon::setTestNow(now()->addMinutes(25));
    expect($scan()->assertCreated()->json('early'))->toBeTrue();
});

/* ── the estate's route, from Sites & geofences ───────────────────────────────────────────────────────────── */

it('gives a post its other names, and removes a post that never existed — deleted, its future shifts with it', function () {
    $this->actingAs($this->director)->post("/guards/sites/posts/{$this->main->id}/also-called", ['also_called' => 'Gate 1', 'reason' => 'what the officers call it'])
        ->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect($this->main->refresh()->label())->toBe('Main Gate M27 (Gate 1)');

    $phase3 = Post::create(['tenant_id' => $this->estate, 'name' => 'Phase 3 Pedestrian Gate M27', 'type' => 'gate', 'is_active' => true]);
    Shift::create(['tenant_id' => $this->estate, 'post_id' => $phase3->id, 'rostered_start' => now()->addDay(), 'rostered_end' => now()->addDay()->addHours(8), 'status' => Shift::OPEN]);

    $this->actingAs($this->director)->post("/guards/sites/posts/{$phase3->id}/remove", ['reason' => 'the estate has no gate at Phase 3'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $s): bool => str_contains($s, 'is deleted') && str_contains($s, '1 shift not yet started there is taken off the roster'));

    FacilitiesFixture::boot();
    expect(Post::query()->whereKey($phase3->id)->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'site.post_removed')->where('entity_id', (string) $phase3->id)->exists())->toBeTrue();

    // A patrol is not a new kind of post any more.
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/posts", ['name' => 'Vehicle Patrol', 'type' => 'patrol', 'lat' => TWO_GATES_LAT, 'lng' => TWO_GATES_LNG, 'radius_m' => 50, 'reason' => 'patrol'])
        ->assertSessionHasErrors('type');
});

it('makes fifteen checkpoints at once with no position, places them from the installers\' messages, orders them, and prints every tag together', function () {
    FacilitiesFixture::boot();

    // A phase of its own, taken away again below: the fixture estate's database is shared and committed.
    $made = Phase::query()->create(['name' => 'Phase M27', 'sequence' => 99, 'block_count' => 0, 'officers_assigned' => null, 'status' => Phase::NEW, 'footnote' => 'test']);
    $this->beforeApplicationDestroyed(static function () use ($made): void {
        FacilitiesFixture::boot();
        Phase::query()->whereKey($made->id)->delete();
    });
    $phase = 'Phase M27';

    $names = collect(range(1, 15))->map(fn (int $i): string => ($i <= 3 ? $phase.': ' : '').'Landmark '.$i.' M27')->implode("\n");
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/checkpoints/many", ['lines' => $names, 'reason' => 'the client\'s fifteen'])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $route = PatrolCheckpoint::query()->where('tenant_id', $this->estate)->whereNull('post_id')->where('label', 'like', 'Landmark % M27')->orderBy('sequence')->get();
    expect($route)->toHaveCount(15)
        ->and($route->pluck('latitude')->filter()->all())->toBe([])
        ->and($route->first()->phase)->toBe($phase)
        ->and($route->last()->phase)->toBeNull();

    // A name twice is refused, whole.
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/checkpoints/many", ['lines' => "New one\nNew one", 'reason' => 'twice'])
        ->assertSessionHasErrors('checkpoints');

    // Placed in one go, as the Director confirmed the matched lines.
    $places = $route->take(2)->map(fn (PatrolCheckpoint $c, int $i): array => ['id' => $c->id, 'lat' => TWO_GATES_LAT + 0.001 * ($i + 1), 'lng' => TWO_GATES_LNG])->values()->all();
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/checkpoints/place", ['places' => $places, 'reason' => 'positions sent from site'])
        ->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect((float) $route->first()->refresh()->latitude)->toEqualWithDelta(TWO_GATES_LAT + 0.001, 0.0000001)
        ->and(AuditEntry::query()->where('action', 'site.checkpoint_placed')->where('entity_id', (string) $route->first()->id)->exists())->toBeTrue();

    // Ordered: the second moves up to first.
    $this->actingAs($this->director)->post('/guards/sites/checkpoints/'.$route[1]->id.'/move', ['direction' => 'up'])->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect($route[1]->refresh()->sequence)->toBeLessThan($route[0]->refresh()->sequence);

    // The interval, on the board's own terms.
    $this->actingAs($this->director)->post("/guards/sites/{$this->estate}/patrol-interval", ['minutes' => 20, 'reason' => 'a vehicle patrol across five phases'])->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(PatrolCheckpoint::cadenceFor($this->estate))->toBe(20);

    // Every tag, one print: the QR, the name, the phase and "N of M"; and a single tag again, with the same code.
    $all = $this->actingAs($this->director)->get("/guards/sites/{$this->estate}/tags")->assertOk()->getContent();
    $first = $route->first()->refresh();
    expect($all)->toContain('Landmark 1 M27')->toContain($phase.' · Checkpoint '.$first->sequence.' of ')->toContain($first->code)
        ->and(substr_count($all, 'class="tag"'))->toBeGreaterThanOrEqual(15)
        ->and($all)->toContain('laminate');

    $one = $this->actingAs($this->director)->get('/guards/sites/checkpoints/'.$first->id.'/tag')->assertOk()->getContent();
    expect($one)->toContain($first->code)->toContain('A spare copy is the same tag');

    $large = $this->actingAs($this->director)->get('/guards/sites/checkpoints/'.$first->id.'/tag?size=large')->assertOk()->getContent();
    expect($large)->toContain('size: A4 portrait');
});

it('lets only the Director place a checkpoint: no Guard App endpoint writes a position', function () {
    $writes = collect(Catalogue::endpoints())->filter(fn (array $e): bool => str_contains((string) $e['uri'], 'checkpoints') && $e['method'] !== 'GET');

    expect($writes->keys()->all())->toBe(['patrol.scan']);
});

/* ── phoenix:two-gates ────────────────────────────────────────────────────────────────────────────────────── */

it('corrects an estate the demonstration seeded with four posts and an invented gate, and finds nothing the second time', function () {
    DB::connection('mysql')->table('tenants')->insert(['id' => 'twogatestest', 'name' => 'Two Gates Test', 'receipt_prefix' => 'TGT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $key = 'twogatestest';
    $main = Post::create(['tenant_id' => $key, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $main->forceFill(['latitude' => 18.0243, 'longitude' => -76.7896, 'geofence_radius_m' => 80])->save();
    $back = Post::create(['tenant_id' => $key, 'name' => 'Back Gate', 'type' => 'gate', 'is_active' => true]);
    $phase3 = Post::create(['tenant_id' => $key, 'name' => 'Phase 3 Pedestrian Gate', 'type' => 'gate', 'is_active' => true]);
    $patrol = Post::create(['tenant_id' => $key, 'name' => 'Perimeter Patrol', 'type' => 'patrol', 'is_active' => true]);
    $patrol->forceFill(['latitude' => 18.0247, 'longitude' => -76.79, 'geofence_radius_m' => 150])->save();
    $tag = PatrolCheckpoint::query()->create(['tenant_id' => $key, 'post_id' => $patrol->id, 'label' => 'North fence', 'code' => PatrolCheckpoint::newCode(), 'sequence' => 1, 'is_active' => true]);
    $future = Shift::create(['tenant_id' => $key, 'post_id' => $patrol->id, 'rostered_start' => now()->addDay(), 'rostered_end' => now()->addDay()->addHours(12), 'status' => Shift::OPEN]);
    $officer = Guard::create(['full_name' => 'Old Patrol', 'employee_number' => 'GS-TGT-1', 'psra_number' => 'PSRA-TGT-1', 'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $key, 'post_id' => $patrol->id]);
    $incident = DB::connection('mysql')->table('security_incidents')->insertGetId(['tenant_id' => $key, 'kind' => 'property', 'detail' => PhoenixTwoGates::INCIDENT_BEFORE, 'severity' => 'low', 'status' => 'open', 'occurred_at' => now(), 'is_simulated' => true, 'created_at' => now(), 'updated_at' => now()]);

    // Without --confirm, nothing changes.
    $this->artisan('phoenix:two-gates', ['--estate' => $key])->expectsOutputToContain('5 changes to make')->assertSuccessful();
    expect(Post::query()->where('tenant_id', $key)->where('is_active', true)->count())->toBe(4);

    $this->artisan('phoenix:two-gates', ['--estate' => $key, '--confirm' => true])->expectsOutputToContain('Done.')->assertSuccessful();

    expect(Post::query()->where('tenant_id', $key)->where('is_active', true)->orderBy('name')->pluck('name')->all())->toBe(['Back Gate', 'Main Gate'])
        ->and($main->refresh()->isPlaced())->toBeFalse()
        ->and($back->refresh()->label())->toBe('Back Gate (Gate 5, Phase 5 Gate)')
        ->and(Post::query()->whereKey($phase3->id)->exists())->toBeFalse()
        ->and(Post::query()->whereKey($patrol->id)->exists())->toBeFalse()
        ->and($future->refresh()->post_id)->toBe($main->id)
        ->and($future->duty)->toBe(Shift::DUTY_PATROL)
        ->and($tag->refresh()->post_id)->toBeNull()
        ->and($officer->refresh()->post_id)->toBe($main->id)
        ->and(DB::connection('mysql')->table('security_incidents')->where('id', $incident)->value('detail'))->toBe(PhoenixTwoGates::INCIDENT_AFTER);

    $this->artisan('phoenix:two-gates', ['--estate' => $key, '--confirm' => true])->expectsOutputToContain('Nothing to change')->assertSuccessful();
});
