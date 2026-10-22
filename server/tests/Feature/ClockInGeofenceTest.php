<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Notifications\SupervisorOverrideUsed;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\ClockInFence;
use App\Services\Dispatch\ShiftClock;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Gemini\PlatformNotifications;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M12 §1 — how the system knows a guard is at the estate
|--------------------------------------------------------------------------
|
| The six tests of §1.6, against the real API, plus the rule's own arithmetic: the rostered post's fence, else the
| estate's, else none; the fix a circle; a position that cannot be established said to be exactly that; a queued
| clock-in started at the tap on the server's clock, and flagged when the phone's clock disagrees.
|
*/

const FENCE_PIN = '604218';
const GATE_LAT = 18.0179000;
const GATE_LNG = -76.8099000;
/** Metres per degree of latitude on the sphere `Geo` uses (π · 6 371 008.8 m / 180). */
const M_PER_DEG = 111_195.08;

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Fence Gate', 'type' => 'gate', 'is_active' => true]);
    $this->post->forceFill(['latitude' => GATE_LAT, 'longitude' => GATE_LNG, 'geofence_radius_m' => 50])->save();

    $this->guard = Guard::create([
        'full_name' => 'Andre Fence', 'employee_number' => 'GS-FEN-1', 'psra_number' => 'PSRA-FEN-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $this->post->id,
    ]);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Fence handset')['token'];
    $this->shift = fenceShift($this->post);

    $this->supervisor = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    app(SupervisorOverride::class)->setPin($this->supervisor, FENCE_PIN);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function fenceShift(Post $post): Shift
{
    return Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => test()->guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(12), 'status' => 'rostered',
    ]);
}

/** A point `$north` metres north of the gate. */
function northOfGate(float $north): array
{
    return ['latitude' => round(GATE_LAT + $north / M_PER_DEG, 7), 'longitude' => GATE_LNG];
}

/**
 * One call as the handset, held to its catalogue entry. `$tap` is when the act was captured (X-Device-Time);
 * `$sentAt` is the phone's clock as it sends (X-Device-Sent-At) — both default to now, the live case.
 *
 * @param  array<string, mixed>  $body
 */
function fenceCall(TestCase $test, string $name, array $body = [], ?int $status = null, ?CarbonInterface $tap = null, ?CarbonInterface $sentAt = null, array $headers = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.str_replace('{shift}', (string) $test->shift->id, $entry['uri']);
    $all = [
        ...($entry['write'] ? ['Idempotency-Key' => 'fence-'.bin2hex(random_bytes(8)), 'X-Device-Time' => ($tap ?? now())->toIso8601String()] : []),
        'X-Device-Sent-At' => ($sentAt ?? now())->toIso8601String(),
        ...$headers,
    ];

    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->json($entry['method'], $uri, $body, $all);
    $response->assertStatus($status ?? $entry['status']);

    if ($response->status() < 400) {
        ApiContract::assertMatches($response, $name);
    }

    FacilitiesFixture::boot();

    return $response;
}

/** What a build that judges the fence sends with a clock-in. */
function judged(array $fix, array $with = []): array
{
    return ['captured_run' => 'run-test-1', 'captured_mono_ms' => 1_000, 'mock_location' => false, ...$fix, ...$with];
}

/* ── the rule ─────────────────────────────────────────────────────────────── */

it('judges a fix as a circle, not a point — M12\'s own example is inside within the margin of error', function () {
    $fence = ['source' => 'post', 'name' => 'Gate', 'latitude' => GATE_LAT, 'longitude' => GATE_LNG, 'radius_m' => 50];
    $at = fn (float $m, ?int $acc) => ClockInFence::judge($fence, GATE_LAT + $m / M_PER_DEG, GATE_LNG, $acc);

    expect($at(20, 10)['outcome'])->toBe(ClockInFence::INSIDE)
        // 30 m accuracy, 35 m outside a 50 m fence (85 m out): inside, within the margin (twice 30, capped at 50).
        ->and($at(85, 30)['outcome'])->toBe(ClockInFence::INSIDE_MARGIN)
        ->and($at(85, 30)['margin_m'])->toBe(50)
        // A sure fix earns little: 5 m accuracy, 20 m past the fence, is outside.
        ->and($at(70, 5)['outcome'])->toBe(ClockInFence::OUTSIDE)
        // A poor fix cannot carry anybody far: 150 m accuracy still earns only 50 m.
        ->and($at(150, 150)['outcome'])->toBe(ClockInFence::OUTSIDE)
        ->and($at(20, 250)['outcome'])->toBe(ClockInFence::POOR_FIX)
        ->and(ClockInFence::judge($fence, null, null, null)['outcome'])->toBe(ClockInFence::NO_POSITION)
        ->and(ClockInFence::judge(null, GATE_LAT, GATE_LNG, 5)['outcome'])->toBe(ClockInFence::NO_GEOFENCE)
        ->and(ClockInFence::judge(null, GATE_LAT, GATE_LNG, 5)['allowed'])->toBeTrue();

    // Which way to go: from a point north of the gate, the gate is south.
    expect($at(500, 10)['direction'])->toBe('south')->and($at(500, 10)['bearing_deg'])->toBe(180);
});

/* ── 1. inside ───────────────────────────────────────────────────────────── */

it('1 · clocks in inside the geofence: allowed, and the fix, its accuracy and the fence are kept on the shift', function () {
    $pre = fenceCall($this, 'shifts.preflight', [...northOfGate(12), 'accuracy_m' => 8]);
    expect($pre->json('allowed'))->toBeTrue()
        ->and($pre->json('outcome'))->toBe('inside')
        ->and($pre->json('fence.source'))->toBe('post')
        ->and($pre->json('fence.radius_m'))->toBe(50);

    $in = fenceCall($this, 'shifts.clock_in', judged([...northOfGate(12), 'accuracy_m' => 8]));
    expect($in->json('geofence_outcome'))->toBe('inside')->and($in->json('review_reasons'))->toBe([]);

    $shift = $this->shift->refresh();
    expect($shift->actual_start)->not->toBeNull()
        ->and($shift->clock_in_accuracy_m)->toBe(8)
        ->and(round($shift->clock_in_latitude, 5))->toBe(round(GATE_LAT + 12 / M_PER_DEG, 5))
        ->and($shift->geofence_source)->toBe('post')
        ->and($shift->geofence_radius_m)->toBe(50)
        ->and($shift->geofence_distance_m)->toBe(12);
});

/* ── 2. 500 m away ───────────────────────────────────────────────────────── */

it('2 · refuses a clock-in 500 m away, naming the post, the distance and the way to go — never "not in the estate area"', function () {
    $pre = fenceCall($this, 'shifts.preflight', [...northOfGate(500), 'accuracy_m' => 6]);
    expect($pre->json('allowed'))->toBeFalse()
        ->and($pre->json('outcome'))->toBe('outside')
        ->and($pre->json('distance_m'))->toBe(500)
        ->and($pre->json('beyond_m'))->toBe(450)
        ->and($pre->json('direction'))->toBe('south')
        ->and($pre->json('override_reason'))->toBe('outside_geofence');

    $detail = collect($pre->json('checks'))->firstWhere('key', 'geofence')['detail'];
    expect($detail)->toContain('500m from Fence Gate')->toContain('Move closer to the gate')->not->toContain('estate area');

    $refused = fenceCall($this, 'shifts.clock_in', judged([...northOfGate(500), 'accuracy_m' => 6]), 409);
    expect($refused->json('error.code'))->toBe('outside_geofence')->and($refused->json('error.message'))->toContain('500m');
    expect($this->shift->refresh()->actual_start)->toBeNull();
});

/* ── 3. a poor fix ───────────────────────────────────────────────────────── */

it('3 · says plainly that a poor fix could not establish the position, refuses the ordinary start, and the override starts it', function () {
    Notification::fake();

    $pre = fenceCall($this, 'shifts.preflight', [...northOfGate(20), 'accuracy_m' => 320]);
    expect($pre->json('outcome'))->toBe('poor_fix')
        ->and($pre->json('override_reason'))->toBe('position_unconfirmed')
        ->and(collect($pre->json('checks'))->firstWhere('key', 'geofence')['detail'])->toContain('could not be established');

    fenceCall($this, 'shifts.clock_in', judged([...northOfGate(20), 'accuracy_m' => 320]), 409)
        ->assertJsonPath('error.code', 'position_unconfirmed');

    $override = fenceCall($this, 'shifts.clock_in_override', [
        'supervisor' => $this->supervisor->email, 'pin' => FENCE_PIN, 'reason' => 'position_unconfirmed', ...northOfGate(20), 'accuracy_m' => 320,
    ], 200);
    expect($override->json('override_reason'))->toBe('position_unconfirmed');

    $shift = $this->shift->refresh();
    expect($shift->start_method)->toBe(SupervisorOverride::START_METHOD)
        ->and($shift->geofence_outcome)->toBe('poor_fix')
        ->and($shift->clock_in_accuracy_m)->toBe(320);
});

/* ── 4. a post with no coordinates ───────────────────────────────────────── */

it('4 · a post with no coordinates falls back to the estate\'s fence and the audit entry says so; with neither, starts and is marked "no geofence configured"', function () {
    $bare = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Back Gate (unsurveyed)', 'type' => 'gate', 'is_active' => true]);
    $this->shift = fenceShift($bare);

    // Neither placed: allowed, never pretended — the shift says no geofence was configured.
    $pre = fenceCall($this, 'shifts.preflight', [...northOfGate(5), 'accuracy_m' => 5]);
    expect($pre->json('outcome'))->toBe('no_geofence')->and($pre->json('fence.source'))->toBe('none')->and($pre->json('allowed'))->toBeTrue();

    $in = fenceCall($this, 'shifts.clock_in', judged([...northOfGate(5), 'accuracy_m' => 5]));
    expect($in->json('geofence_outcome'))->toBe('no_geofence')->and($in->json('review_reasons'))->toContain(ShiftClock::REVIEW_NO_GEOFENCE);

    // With the estate placed, another shift at the same post is judged against the ESTATE's fence, and the record says so.
    DB::connection('mysql')->table('estate_sites')->insert(['tenant_id' => FacilitiesFixture::ESTATE, 'latitude' => GATE_LAT, 'longitude' => GATE_LNG, 'geofence_radius_m' => 300, 'created_at' => now(), 'updated_at' => now()]);
    $this->shift = fenceShift($bare);

    $pre = fenceCall($this, 'shifts.preflight', [...northOfGate(200), 'accuracy_m' => 5]);
    expect($pre->json('fence.source'))->toBe('estate')->and($pre->json('outcome'))->toBe('inside')->and($pre->json('radius_m'))->toBe(300);

    fenceCall($this, 'shifts.clock_in', judged([...northOfGate(200), 'accuracy_m' => 5]));
    $entry = AuditEntry::query()->where('action', 'shift.clocked_in')->where('entity_id', (string) $this->shift->id)->firstOrFail();
    expect($entry->after['geofence_source'])->toBe('estate')->and($entry->after['judged_against'])->toContain('ESTATE');
});

/* ── 5. offline ──────────────────────────────────────────────────────────── */

it('5 · a clock-in queued offline starts at the TAP on the server\'s clock, and is flagged when the phone\'s clock disagrees', function () {
    // Tapped 40 minutes ago with no signal; the phone's clock is right. Starts 40 minutes ago, nothing to review.
    $in = fenceCall($this, 'shifts.clock_in', judged([...northOfGate(10), 'accuracy_m' => 7]), tap: now()->subMinutes(40));
    expect($in->json('review_reasons'))->toBe([]);
    expect(abs($this->shift->refresh()->actual_start->diffInSeconds(now()->subMinutes(40))))->toBeLessThan(5);

    // Another guard's phone is 9 minutes FAST: its tap time is moved back by the error, and the shift is flagged.
    $this->shift = fenceShift($this->post);
    $in = fenceCall($this, 'shifts.clock_in', judged([...northOfGate(10), 'accuracy_m' => 7]), tap: now()->subMinutes(20)->addMinutes(9), sentAt: now()->addMinutes(9));
    expect($in->json('review_reasons'))->toContain(ShiftClock::REVIEW_CLOCK_DISAGREES);
    $shift = $this->shift->refresh();
    expect(abs($shift->actual_start->diffInSeconds(now()->subMinutes(20))))->toBeLessThan(5)
        ->and(abs($shift->clock_offset_s + 540))->toBeLessThan(5);

    // Queued, and judged outside when it arrives (the fence moved since the phone cached it): started, never
    // refused hours later, and flagged — a post the guard worked must not read as unmanned.
    $this->shift = fenceShift($this->post);
    $in = fenceCall($this, 'shifts.clock_in', judged([...northOfGate(600), 'accuracy_m' => 7]), tap: now()->subMinutes(30));
    expect($in->json('geofence_outcome'))->toBe('outside')->and($in->json('review_reasons'))->toContain(ShiftClock::REVIEW_OUTSIDE_AT_CAPTURE);

    // The clock was CHANGED while it waited: in one run of the app the wall clock moved 2 hours, the steady clock 5 minutes.
    $this->shift = fenceShift($this->post);
    $in = fenceCall($this, 'shifts.clock_in', judged([...northOfGate(10), 'accuracy_m' => 7], ['captured_mono_ms' => 1_000]), tap: now()->subHours(2), headers: ['X-Device-Run' => 'run-test-1', 'X-Device-Mono' => (string) (1_000 + 5 * 60_000)]);
    expect($in->json('review_reasons'))->toContain(ShiftClock::REVIEW_CLOCK_CHANGED);

    // Every flagged start is on the console's bell — here the Director's, who covers every site.
    $items = collect(app(PlatformNotifications::class)->attention(FacilitiesFixture::geminiViewer(Role::DIRECTOR))['items']);
    expect($items->filter(fn (array $i): bool => str_starts_with((string) $i['key'], 'clockreview:'))->count())->toBeGreaterThanOrEqual(3);
});

/* ── 6. the override ─────────────────────────────────────────────────────── */

it('6 · the override outside the fence is recorded, flags the shift and tells the Head of Security, with the distance', function () {
    Notification::fake();

    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create(['user_id' => $head->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true]);

    fenceCall($this, 'shifts.clock_in_override', [
        'supervisor' => $this->supervisor->email, 'pin' => FENCE_PIN, 'reason' => 'outside_geofence', ...northOfGate(500), 'accuracy_m' => 6,
    ], 200)->assertJsonPath('override_reason', 'outside_geofence');

    $shift = $this->shift->refresh();
    expect($shift->overridden_by)->toBe($this->supervisor->id)
        ->and($shift->geofence_outcome)->toBe('outside')
        ->and($shift->geofence_distance_m)->toBe(500);

    $entry = AuditEntry::query()->where('action', 'shift.supervisor_override')->where('entity_id', (string) $shift->id)->firstOrFail();
    expect($entry->after['override_reason'])->toBe('outside_geofence')->and($entry->after['geofence_outcome'])->toBe('outside');

    if ($head->alertEmail() !== null) {
        Notification::assertSentTo($head, SupervisorOverrideUsed::class);
    }
});

/* ── a mocked location ───────────────────────────────────────────────────── */

it('flags a mocked location for the Head of Security without refusing it alone', function () {
    $in = fenceCall($this, 'shifts.clock_in', judged([...northOfGate(10), 'accuracy_m' => 5], ['mock_location' => true]));
    expect($in->json('mock_location_flag'))->toBeTrue()->and($in->json('review_reasons'))->toContain(ShiftClock::REVIEW_MOCK_LOCATION);
});

/* ── an older build ──────────────────────────────────────────────────────── */

it('records an older build\'s clock-in exactly as before, and never refuses it', function () {
    $in = fenceCall($this, 'shifts.clock_in', ['geofence_distance_m' => 900, 'mock_location' => false]);
    expect($in->json('geofence_outcome'))->toBeNull();
    expect($this->shift->refresh()->geofence_distance_m)->toBe(900);
});

/* ── offline cache ───────────────────────────────────────────────────────── */

it('carries the rostered post\'s fence and the thresholds with the current shift, for a clock-in with no signal', function () {
    DB::connection('mysql')->table('estate_sites')->insert(['tenant_id' => FacilitiesFixture::ESTATE, 'supervisor_phone' => '876-555-0142', 'created_at' => now(), 'updated_at' => now()]);

    app('auth')->forgetGuards();
    $current = $this->withToken($this->token)->getJson('/api/v1/shifts/me/current')->assertOk();
    FacilitiesFixture::boot();
    ApiContract::assertMatches($current, 'shifts.me.current');

    expect($current->json('shift.fence.source'))->toBe('post')
        ->and($current->json('shift.fence.latitude'))->toBe(GATE_LAT)
        ->and($current->json('shift.fence.radius_m'))->toBe(50)
        ->and($current->json('shift.fence.poor_accuracy_m'))->toBe(ClockInFence::POOR_ACCURACY_M)
        ->and($current->json('shift.supervisor_phone'))->toBe('876-555-0142');
});
