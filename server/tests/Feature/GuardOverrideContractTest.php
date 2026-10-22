<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\SupervisorOverrideUsed;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\SupervisorOverride;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M4 §3.4 — the three guard-side rulings that needed the server
|--------------------------------------------------------------------------
|
| A. SUPERVISOR PIN OVERRIDE AT CLOCK-IN. "Server sets and verifies the PIN. Every
|    use is logged, flags the shift record, and is reported to the Head of Security."
| B. THE GUARD'S OWN LICENCE on `GET /guards/me`.
| C. READ STATE PER GUARD, PER PHONE on the messages endpoints.
|
| Every answer is held to its catalogue entry (`ApiContract`): a key the catalogue
| does not list fails, and no key on a guard route may name money.
|
*/

const OVERRIDE_PIN = '482913';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Override Gate', 'type' => 'gate', 'is_active' => true]);
    $this->post->forceFill(['latitude' => 18.0179000, 'longitude' => -76.8099000, 'geofence_radius_m' => 5])->save();

    $this->guard = overrideGuard('Kemar Override', 'GS-OVR-1', $this->post->id);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Override handset')['token'];

    $this->shift = Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $this->guard->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(12), 'status' => 'rostered',
    ]);

    // A dispatcher holds `gemini.guard_workforce.update` across every estate: a supervisor.
    $this->supervisor = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    app(SupervisorOverride::class)->setPin($this->supervisor, OVERRIDE_PIN);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function overrideGuard(string $name, string $number, ?int $postId, ?string $expires = '+1 year'): Guard
{
    return Guard::create([
        'full_name' => $name,
        'employee_number' => $number,
        'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => $expires === null ? null : now()->modify($expires)->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $postId,
    ]);
}

/**
 * One call as a handset, held to its catalogue entry. Every write gets a NEW
 * Idempotency-Key — as the app does for each try of an override.
 *
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function overrideCall(TestCase $test, string $token, string $name, array $params = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'ovr-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();

    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson($uri.($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $test->withToken($token)->json($entry['method'], $uri, $body, $headers);

    $response->assertStatus($status ?? $entry['status']);

    ApiContract::assertMatches($response, $name);

    FacilitiesFixture::boot();

    return $response;
}

/** @return array<string, mixed> */
function overrideBody(User $supervisor, array $with = []): array
{
    return [
        'supervisor' => $supervisor->email,
        'pin' => OVERRIDE_PIN,
        'reason' => 'biometric_failed',
        'latitude' => 18.0179100,
        'longitude' => -76.8099100,
        'accuracy_m' => 4,
        'mock_location' => false,
        ...$with,
    ];
}

/* ── A. The supervisor PIN override ─────────────────────────────────────── */

it('starts the shift on a supervisor\'s PIN, flags it, writes the audit log and tells the Head of Security — and keeps the PIN nowhere', function () {
    Notification::fake();

    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create(['user_id' => $head->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true]);
    $elsewhere = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY); // covers no site: told nothing

    $answer = overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor, ['reason' => 'no_biometrics', 'mock_location' => true]));

    expect($answer->json('status'))->toBe('on_duty')
        ->and($answer->json('overridden'))->toBeTrue()
        ->and($answer->json('override_reason'))->toBe('no_biometrics')
        ->and($answer->json('overridden_by'))->toBe($this->supervisor->name)
        ->and($answer->json('mock_location_flag'))->toBeTrue()
        ->and($answer->json('actual_start'))->not->toBeNull()
        ->and($answer->json('server_time'))->not->toBeNull();

    // THE FLAG ON THE SHIFT RECORD — and the position handled exactly as an ordinary start handles it.
    $row = DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->first();
    expect((int) $row->overridden_by)->toBe($this->supervisor->id)
        ->and($row->override_reason)->toBe('no_biometrics')
        ->and($row->overridden_at)->not->toBeNull()
        ->and($row->start_method)->toBe(SupervisorOverride::START_METHOD)
        ->and((int) $row->geofence_distance_m)->toBeLessThan(5)      // the server's own sum from the coordinates
        ->and((bool) $row->mock_location_flag)->toBeTrue();            // recorded and flagged, never refused

    // THE AUDIT ROW — who, why, for whom. Never the PIN.
    $audit = AuditEntry::query()->where('action', 'shift.supervisor_override')->where('entity_id', (string) $this->shift->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($audit->after['overridden_by'])->toBe($this->supervisor->id)
        ->and($audit->after['override_reason'])->toBe('no_biometrics')
        ->and($audit->actor_role)->toBe('Guard App handset');

    foreach (AuditEntry::query()->get() as $entry) {
        expect(json_encode([$entry->before, $entry->after]))->not->toContain(OVERRIDE_PIN);
    }

    // THE IDEMPOTENCY RECORD IS OPAQUE: its hash covers the path only, so six digits cannot be recovered from it.
    // And the answer it keeps is in the ESTATE's database, not in gs_platform — the central row has the key, not the content.
    $stored = DB::connection('mysql')->table('api_idempotency_keys')->where('route', 'api.v1.shifts.clock_in_override')->first();
    $answer = (string) DB::connection('tenant')->table('api_idempotency_responses')->where('key_id', $stored->id)->value('response_body');

    expect($stored->request_hash)->toBe(hash('sha256', 'POST api/v1/shifts/'.$this->shift->id.'/clock-in/override'))
        ->and($stored->body_store)->toBe('tenant')
        ->and($stored->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($stored->response_body)->toBeNull()
        ->and($answer)->not->toBe('')
        ->and($answer)->not->toContain(OVERRIDE_PIN);

    // THE PIN IS STORED ONLY AS A HASH.
    $pin = DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $this->supervisor->id)->first();
    expect($pin->pin_hash)->not->toContain(OVERRIDE_PIN)
        ->and(Hash::check(OVERRIDE_PIN, $pin->pin_hash))->toBeTrue()
        ->and($pin->last_used_at)->not->toBeNull()
        ->and((int) $pin->failed_attempts)->toBe(0);

    // REPORTED TO THE HEAD OF SECURITY who covers this estate — and to nobody who does not.
    Notification::assertSentTo($head, SupervisorOverrideUsed::class);
    Notification::assertNotSentTo($elsewhere, SupervisorOverrideUsed::class);
    Notification::assertNotSentTo($this->supervisor, SupervisorOverrideUsed::class);

    // …and on their bell, scoped to their sites.
    $this->actingAs($head)->get('/notifications')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('items', fn ($items) => collect($items)->pluck('key')->contains('override:'.$this->shift->id)));
    $this->actingAs($elsewhere)->get('/notifications')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('items', fn ($items) => collect($items)->pluck('key')->doesntContain('override:'.$this->shift->id)));
    FacilitiesFixture::boot();

    // A second override of a shift that has started records nothing against anybody.
    overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor), 409)
        ->assertJsonPath('error.code', 'shift_already_started');
    expect(AuditEntry::query()->where('action', 'shift.supervisor_override')->count())->toBe(1);
});

it('says what the mail says without the PIN or anybody\'s pay', function () {
    $mail = (new SupervisorOverrideUsed('Kemar Override', 'GS-OVR-1', 'Phoenix Park', 'Override Gate', 'Dana Dispatcher', 'biometric_failed', 'Sep 20, 2026 7:02 AM', null, true, 41))
        ->toMail(new stdClass);

    $text = implode("\n", [$mail->subject, $mail->greeting, ...array_map('strval', $mail->introLines)]);

    expect($text)->toContain('Kemar Override')->toContain('Dana Dispatcher')->toContain('sent no position')->toContain('MOCKED')
        ->and($text)->not->toContain('J$');
});

it('answers ONE refusal for an unknown supervisor, somebody who is not one, one of other sites, no PIN set and a wrong PIN', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);                 // a console user, not a supervisor
    $otherSites = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);           // a supervisor, of no site here
    app(SupervisorOverride::class)->setPin($otherSites, OVERRIDE_PIN);
    $noPin = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);              // a supervisor who never set one
    $suspended = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    app(SupervisorOverride::class)->setPin($suspended, OVERRIDE_PIN);
    $suspended->forceFill(['status' => 'suspended'])->save();

    // An Accountant cannot even be given a PIN.
    expect(fn () => app(SupervisorOverride::class)->setPin($accountant, OVERRIDE_PIN))->toThrow(InvalidArgumentException::class);

    $bodies = [];

    foreach ([
        'unknown' => overrideBody($this->supervisor, ['supervisor' => 'nobody.at.all@example.test']),
        'not a supervisor' => overrideBody($accountant),
        'other sites' => overrideBody($otherSites),
        'no pin' => overrideBody($noPin),
        'suspended' => overrideBody($suspended),
        'wrong pin' => overrideBody($this->supervisor, ['pin' => '000001']),
    ] as $case => $body) {
        $refusal = overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], $body, 403);

        expect($refusal->json('error.code'))->toBe('override_refused', $case);
        $bodies[$case] = $refusal->json();
    }

    // The SAME answer, to the byte — and it never says how close a guess was.
    expect(array_unique(array_map('json_encode', $bodies)))->toHaveCount(1)
        ->and(json_encode($bodies['wrong pin']))->not->toMatch('/digit|correct|close/i');

    // Nothing started, nothing flagged, nobody told.
    $row = DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->first();
    expect($row->actual_start)->toBeNull()->and($row->overridden_at)->toBeNull()->and($row->status)->toBe('rostered');

    // Only the real supervisor's wrong PIN counted against anybody.
    expect((int) DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $this->supervisor->id)->value('failed_attempts'))->toBe(1)
        ->and((int) DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $otherSites->id)->value('failed_attempts'))->toBe(0);
});

it('locks a supervisor\'s PIN after five wrong tries until they set a new one', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    for ($try = 1; $try <= SupervisorOverride::MAX_FAILED_ATTEMPTS; $try++) {
        overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor, ['pin' => '11111'.$try]), 403);
    }

    $pin = DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $this->supervisor->id)->first();
    expect($pin->locked_at)->not->toBeNull()->and((int) $pin->failed_attempts)->toBe(5)
        ->and(AuditEntry::query()->where('action', 'user.override_pin_locked')->where('entity_id', (string) $this->supervisor->id)->exists())->toBeTrue();

    // Locked: the RIGHT PIN is refused too, with the same sentence.
    overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor), 403)
        ->assertJsonPath('error.code', 'override_refused');
    expect(DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->value('actual_start'))->toBeNull();

    // Setting a new PIN is the only way back — and the old one is dead.
    app(SupervisorOverride::class)->setPin($this->supervisor, '907351');
    expect(AuditEntry::query()->where('action', 'user.override_pin_rotated')->where('entity_id', (string) $this->supervisor->id)->exists())->toBeTrue();

    overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor), 403);
    expect(overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor, ['pin' => '907351']))->json('overridden'))->toBeTrue();
});

it('throttles PIN tries per handset', function () {
    for ($try = 1; $try <= 5; $try++) {
        overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor, ['supervisor' => 'nobody@example.test']), 403);
    }

    overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor), 429)
        ->assertJsonPath('error.code', 'rate_limited');

    expect(DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->value('actual_start'))->toBeNull();
});

it('checks the ability, then the app, then whose shift it is — and only then the body and the PIN', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $uri = '/api/v1/shifts/'.$this->shift->id.'/clock-in/override';
    $stamp = fn (): array => ['Idempotency-Key' => 'ord-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String()];

    // No token at all.
    app('auth')->forgetGuards();
    $this->postJson($uri, overrideBody($this->supervisor), $stamp())->assertStatus(401);

    // A Resident App token: refused at the ability, whether or not the shift exists — it learns nothing about shift ids.
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'sms', 'destination' => '+18765550199',
        'full_name' => 'Resident Override', 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $residentToken = $account->createToken('resident-app', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    foreach ([$uri, '/api/v1/shifts/999999999/clock-in/override', '/api/v1/messages/read'] as $path) {
        app('auth')->forgetGuards();
        $refused = $this->withToken($residentToken)->postJson($path, overrideBody($this->supervisor), $stamp());
        expect($refused->status())->toBe(403)->and($refused->json('error.code'))->toBeIn(['missing_ability', 'wrong_app']);
        FacilitiesFixture::boot();
    }

    app('auth')->forgetGuards();
    expect($this->withToken($residentToken)->getJson('/api/v1/guards/me')->status())->toBe(403);
    FacilitiesFixture::boot();

    // Another guard's shift and an id nobody has are THE SAME 404 — even with the right supervisor and PIN.
    $other = overrideGuard('Other Officer', 'GS-OVR-2', $this->post->id);
    $theirs = Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $other->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(12), 'status' => 'rostered',
    ]);

    $notMine = overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $theirs->id], overrideBody($this->supervisor), 404);
    $nobodys = overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => 999_999_999], overrideBody($this->supervisor), 404);
    expect($notMine->json())->toBe($nobodys->json())
        ->and(DB::connection('mysql')->table('shifts')->where('id', $theirs->id)->value('actual_start'))->toBeNull();

    // A malformed body is a 422 that names the field, never echoes the PIN, and costs the supervisor no try.
    $short = overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor, ['pin' => '12345']), 422);
    expect($short->json('errors.pin.0'))->toBe('A supervisor PIN is 6 digits.')->and((string) $short->getContent())->not->toContain('12345');
    overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor, ['reason' => 'in_a_hurry']), 422);
    overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], ['pin' => OVERRIDE_PIN, 'reason' => 'no_biometrics'], 422);
    expect((int) DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $this->supervisor->id)->value('failed_attempts'))->toBe(0);

    // A write with no Idempotency-Key is refused before anything is checked.
    app('auth')->forgetGuards();
    $this->withToken($this->token)->postJson($uri, overrideBody($this->supervisor), ['X-Device-Time' => now()->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');
    FacilitiesFixture::boot();

    // ONLINE ONLY: it cannot ride in the offline batch.
    $batch = overrideCall($this, $this->token, 'sync.batch', [], ['operations' => [[
        'id' => 'op-1', 'endpoint' => 'shifts.clock_in_override', 'params' => ['shift' => $this->shift->id],
        'body' => overrideBody($this->supervisor), 'idempotency_key' => 'queued-override-1', 'device_time' => now()->toIso8601String(),
    ]]]);
    expect($batch->json('results.0.outcome'))->toBe('refused')
        ->and($batch->json('results.0.body.error.code'))->toBe('endpoint_not_batchable')
        ->and(DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->value('actual_start'))->toBeNull();

    // A shift that already started the ordinary way is not re-labelled as overridden, and costs no try.
    overrideCall($this, $this->token, 'shifts.clock_in', ['shift' => $this->shift->id], ['geofence_distance_m' => 2]);
    overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor, ['pin' => '000001']), 409);
    $row = DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->first();
    expect($row->overridden_at)->toBeNull()->and($row->start_method)->toBe('app')
        ->and((int) DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $this->supervisor->id)->value('failed_attempts'))->toBe(0);
});

it('replaces the IDENTITY check and nothing else: outside the post the position is recorded truthfully, and the handset cannot talk its way inside', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();

    // ~220 m due north of the post, which has a 5 m geofence.
    $far = ['latitude' => 18.0199000, 'longitude' => -76.8099000];

    // The server's own pre-flight, from the position the override is about to send: NOT allowed, NOT within.
    // This is the answer the app blocks on — the override screen runs the same check and never draws the PIN
    // form outside the boundary (apps/guard/src/features/shift/override.ts · overrideBlocked).
    $pre = overrideCall($this, $this->token, 'shifts.preflight', ['shift' => $this->shift->id], [...$far, 'accuracy_m' => 5, 'mock_location' => true]);
    expect($pre->json('allowed'))->toBeFalse()
        ->and($pre->json('within_geofence'))->toBeFalse()
        ->and((float) $pre->json('distance_m'))->toBeGreaterThan(100.0);

    /*
     * A PIN reaches the server anyway (a handset is not a trusted client). What must hold is that the
     * override is NEITHER WEAKER NOR STRONGER than `shifts.clock_in` about the position: geofencing is
     * deferred (D-033) and neither endpoint refuses on distance — refusing would leave a post reading as
     * unmanned while somebody stands at it. Both RECORD, and the server works the distance out ITSELF from
     * the coordinates, so a handset claiming "2 m" from 220 m away is not believed.
     */
    overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($this->supervisor, [...$far, 'geofence_distance_m' => 2, 'mock_location' => true]));

    $row = DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->first();
    expect((int) $row->geofence_distance_m)->toBeGreaterThan(100)
        ->and((bool) $row->mock_location_flag)->toBeTrue()
        ->and($row->start_method)->toBe(SupervisorOverride::START_METHOD);

    // …and the distance and the mocked location go to the people who decide about it, not into a silence.
    $audit = AuditEntry::query()->where('action', 'shift.supervisor_override')->where('entity_id', (string) $this->shift->id)->first();
    expect((int) $audit->after['geofence_distance_m'])->toBeGreaterThan(100)
        ->and($audit->after['mock_location_flag'])->toBeTrue();
});

it('refuses the old SILENT unconfirmed start: a handset may only clock in as `app`', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    // `manual` was how a phone with no fingerprint or face set up started a shift nobody had identified.
    // M4 §3.4 closed that: the only unconfirmed start is a supervisor's PIN, which is flagged and reported.
    overrideCall($this, $this->token, 'shifts.clock_in', ['shift' => $this->shift->id], ['method' => 'manual', 'geofence_distance_m' => 2], 422);

    $row = DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->first();
    expect($row->actual_start)->toBeNull()->and($row->start_method)->toBeNull()->and($row->status)->toBe('rostered');

    // The ordinary, CONFIRMED start is untouched.
    overrideCall($this, $this->token, 'shifts.clock_in', ['shift' => $this->shift->id], ['method' => 'app', 'geofence_distance_m' => 2]);
    expect(DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->value('start_method'))->toBe('app');
});

it('lets a supervisor set their own PIN in the console — with their password, six digits, nothing guessable — and nobody else', function () {
    // The write is throttled like sign-in (five a minute); this test makes more than five on purpose.
    $this->withoutMiddleware(ThrottleRequests::class);

    $pinRow = fn (User $user) => DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $user->id)->first();
    $manager = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);

    $this->actingAs($manager)->get('/account/override-pin')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Account/OverridePin')
            ->where('pin.is_set', false)
            ->where('identifier', $manager->email)
            ->where('auth.user.is_supervisor', true)
            ->missing('pin.pin_hash'));

    // The wrong password, a short PIN, a mismatch and a guessable PIN are each refused, and nothing is stored.
    $this->actingAs($manager)->post('/account/override-pin', ['current_password' => 'not-it', 'pin' => '739104', 'pin_confirmation' => '739104'])->assertSessionHasErrors('current_password');
    $this->actingAs($manager)->post('/account/override-pin', ['current_password' => 'password', 'pin' => '7391', 'pin_confirmation' => '7391'])->assertSessionHasErrors('pin');
    $this->actingAs($manager)->post('/account/override-pin', ['current_password' => 'password', 'pin' => '739104', 'pin_confirmation' => '739105'])->assertSessionHasErrors('pin');
    $this->actingAs($manager)->post('/account/override-pin', ['current_password' => 'password', 'pin' => '111111', 'pin_confirmation' => '111111'])->assertSessionHasErrors('pin');
    $this->actingAs($manager)->post('/account/override-pin', ['current_password' => 'password', 'pin' => '654321', 'pin_confirmation' => '654321'])->assertSessionHasErrors('pin');
    expect($pinRow($manager))->toBeNull();

    $this->actingAs($manager)->post('/account/override-pin', ['current_password' => 'password', 'pin' => '739104', 'pin_confirmation' => '739104'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($pinRow($manager)->pin_hash)->not->toContain('739104')
        ->and(Hash::check('739104', $pinRow($manager)->pin_hash))->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'user.override_pin_set')->where('entity_id', (string) $manager->id)->exists())->toBeTrue();

    $this->actingAs($manager)->get('/account/override-pin')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pin.is_set', true)->where('pin.locked', false));

    // It works at the phone.
    FacilitiesFixture::boot();
    expect(overrideCall($this, $this->token, 'shifts.clock_in_override', ['shift' => $this->shift->id], overrideBody($manager, ['pin' => '739104']))->json('overridden_by'))->toBe($manager->name);

    // A role that may only READ the roster has no PIN to set: refused by the route, and not offered the link.
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);
    $this->actingAs($accountant)->get('/account/override-pin')->assertForbidden();
    $this->actingAs($accountant)->post('/account/override-pin', ['current_password' => 'password', 'pin' => '739104', 'pin_confirmation' => '739104'])->assertForbidden();
    $this->actingAs($accountant)->get('/dashboard')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.is_supervisor', false));
    expect($pinRow($accountant))->toBeNull();
});

it('never leaves a supervisor\'s chosen PIN in the session when the console form is refused', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $manager = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);
    $chosen = '739104';

    /*
     * A REFUSED FORM REDIRECTS BACK WITH THE OLD INPUT, and the session store is the
     * `sessions` table — plain text in a `payload` column. `pin` is not `password`, so the
     * framework's own never-flash list did not cover it, and the six digits a supervisor is
     * about to keep were written there on every mistyped confirmation and every rejected
     * choice. Nothing else on this server ever holds an override PIN unhashed.
     */
    foreach ([
        ['current_password' => 'not-it', 'pin' => $chosen, 'pin_confirmation' => $chosen],
        ['current_password' => 'password', 'pin' => $chosen, 'pin_confirmation' => '739105'],
        ['current_password' => 'password', 'pin' => $chosen, 'pin_confirmation' => ''],
    ] as $form) {
        $this->actingAs($manager)->post('/account/override-pin', $form)->assertSessionHasErrors();

        // The old input WAS flashed — so this is the real path, not a vacuous pass…
        expect(session()->has('_old_input'))->toBeTrue()
            // …and neither the PIN nor its confirmation is anywhere in it.
            ->and(session('_old_input.pin'))->toBeNull()
            ->and(session('_old_input.pin_confirmation'))->toBeNull()
            ->and(session('_old_input.current_password'))->toBeNull()
            ->and(json_encode(session()->all()))->not->toContain($chosen);
    }

    // And nothing was set by any of them.
    expect(DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $manager->id)->exists())->toBeFalse();
});

/* ── B. The guard's own licence ─────────────────────────────────────────── */

it('sends the guard their OWN licence number and expiry, with the warning worked out on the site\'s calendar', function () {
    $me = overrideCall($this, $this->token, 'guards.me');

    expect($me->json('name'))->toBe('Kemar Override')
        ->and($me->json('employee_number'))->toBe('GS-OVR-1')
        ->and($me->json('site.id'))->toBe(FacilitiesFixture::ESTATE)
        ->and($me->json('post.name'))->toBe('Override Gate')
        ->and($me->json('licence.name'))->toBe('PSRA licence')
        ->and($me->json('licence.number'))->toBe('PSRA-GS-OVR-1')
        ->and($me->json('licence.expires_on'))->toBe($this->guard->psra_expires_on->toDateString())
        ->and($me->json('licence.state'))->toBe('valid')
        ->and($me->json('licence.warning_days'))->toBe(30);

    // The row also holds the guard's rate of pay. It is not in the answer, under any name.
    $this->guard->forceFill(['standard_rate_minor' => 65_000, 'approved_pension_minor' => 1_200_00])->save();
    expect((string) overrideCall($this, $this->token, 'guards.me')->getContent())->not->toContain('65000')->not->toContain('120000')->not->toContain('rate');

    foreach ([['+12 days', 'expiring', 12], ['+0 days', 'expiring', 0], ['-3 days', 'expired', -3], ['+31 days', 'valid', 31]] as [$when, $state, $days]) {
        $this->guard->forceFill(['psra_expires_on' => App\Support\SiteTime::today(FacilitiesFixture::ESTATE)->modify($when)->toDateString()])->save();

        $licence = overrideCall($this, $this->token, 'guards.me')->json('licence');
        expect($licence['state'])->toBe($state, $when)->and($licence['days_left'])->toBe($days, $when);
    }

    // Nothing on file: nulls and `unknown`, never an invented date.
    $this->guard->forceFill(['psra_expires_on' => null, 'psra_number' => ''])->save();
    $none = overrideCall($this, $this->token, 'guards.me')->json('licence');
    expect($none['number'])->toBeNull()->and($none['expires_on'])->toBeNull()->and($none['state'])->toBe('unknown')->and($none['days_left'])->toBeNull();

    // ONLY THEIR OWN: another guard's token reads another guard's licence, and there is no id to pass.
    $other = overrideGuard('Other Officer', 'GS-OVR-3', null, '+5 days');
    $theirs = overrideCall($this, app(DeviceEnrolment::class)->enrol($other, 'Other handset')['token'], 'guards.me');
    expect($theirs->json('licence.number'))->toBe('PSRA-GS-OVR-3')->and($theirs->json('licence.state'))->toBe('expiring')->and($theirs->json('post'))->toBeNull();
});

/*
 * THE CONSOLE READS THE SAME LICENCE ON THE SAME CALENDAR AS THE PHONE (M4 §3.1).
 *
 * `Guard::licenceState()` asked `psra_expires_on->isPast()`, which compares a date against the
 * SERVER's now — and the server stores and runs in UTC. The `date` cast makes the expiry MIDNIGHT
 * UTC, so a licence expiring today was already "past" from the first second of that day: the
 * console called it EXPIRED, refused to roster the guard and struck them off post coverage for the
 * whole of the last day it was actually valid — while `guards.me` on the guard's own phone said
 * "expires today". Two screens, one licence, opposite answers, every time.
 */
it('reads a licence on the SITE\'s calendar, so the console and the phone never disagree about the day it runs out', function () {
    $estate = FacilitiesFixture::ESTATE;
    $zone = App\Support\SiteTime::zone($estate);
    $state = fn (): string => $this->guard->fresh()->licenceState();
    $daysLeft = fn (): ?int => $this->guard->fresh()->licenceDaysLeft();

    expect($zone)->toBe('America/Jamaica');

    $today = App\Support\SiteTime::today($estate)->toDateString();
    $this->guard->forceFill(['psra_expires_on' => $today])->save();

    try {
        /*
         * THE WHOLE OF THE LAST VALID DAY, at the site. 09:00 Kingston is already 14:00 UTC and
         * 19:30 Kingston is past midnight UTC — the old reading called every one of these expired.
         */
        foreach (['09:00', '19:30', '23:59'] as $clock) {
            $this->travelTo(Illuminate\Support\Carbon::parse($today.' '.$clock, $zone));
            expect($state())->toBe('expiring', $clock)->and($daysLeft())->toBe(0, $clock);

            /*
             * …AND THE OLD READING IS STILL WRONG, which is what stops this test going vacuous.
             * `config('app.timezone')` is UTC, so the `date` cast puts the expiry at MIDNIGHT UTC
             * and `isPast()` is already true at every one of these site-clock times. If someone
             * puts `->isPast()` back, the line above fails; this line says why it used to pass.
             */
            expect($this->guard->fresh()->psra_expires_on->isPast())->toBeTrue($clock);
        }

        // And it runs out when the SITE's day turns over, not five hours before.
        $this->travelTo(Illuminate\Support\Carbon::parse($today.' 00:05', $zone)->addDay());
        expect($state())->toBe('expired')->and($daysLeft())->toBe(-1);

        // The warning window is read on the site's calendar too, on both sides of it.
        foreach ([[1, 'expiring'], [30, 'expiring'], [31, 'valid']] as [$ahead, $expected]) {
            $this->travelTo(Illuminate\Support\Carbon::parse($today.' 21:00', $zone));
            $this->guard->forceFill(['psra_expires_on' => App\Support\SiteTime::today($estate)->addDays($ahead)->toDateString()])->save();
            expect($state())->toBe($expected, (string) $ahead)->and($daysLeft())->toBe($ahead, (string) $ahead);
        }

        // Nothing on file is never an invented date.
        $this->guard->forceFill(['psra_expires_on' => null])->save();
        expect($state())->toBe('unknown')->and($daysLeft())->toBeNull();

        /*
         * BY THE ZONE'S NAME, NEVER AN OFFSET. The next territory keeps daylight saving, so the gap
         * to UTC is not even a constant: New York is UTC-4 in July and UTC-5 in January. 20:00 there
         * is past midnight UTC in BOTH halves of the year, and is still the same day at the site in both.
         */
        App\Models\Tenant::query()->findOrFail($estate)->forceFill(['timezone' => 'America/New_York'])->save();
        App\Support\SiteTime::flush();

        $year = now()->year + 1;

        foreach (['July' => $year.'-07-15', 'January' => $year.'-01-15'] as $half => $day) {
            $this->guard->forceFill(['psra_expires_on' => $day])->save();

            $this->travelTo(Illuminate\Support\Carbon::parse($day.' 20:00', 'America/New_York'));
            expect($state())->toBe('expiring', $half)->and($daysLeft())->toBe(0, $half);

            $this->travelTo(Illuminate\Support\Carbon::parse($day.' 09:00', 'America/New_York')->addDay());
            expect($state())->toBe('expired', $half)->and($daysLeft())->toBe(-1, $half);
        }
    } finally {
        // The estate row rolls back with the transaction; what was READ of it is per-process.
        $this->travelBack();
        App\Support\SiteTime::flush();
    }
});

/* ── C. Read state per guard, per phone ─────────────────────────────────── */

it('keeps a broadcast\'s read state per guard and per phone — one guard reading or replying never marks it read for another', function () {
    $site = FacilitiesFixture::ESTATE;
    $other = overrideGuard('Second Officer', 'GS-OVR-4', $this->post->id);
    $otherToken = app(DeviceEnrolment::class)->enrol($other, 'Second handset')['token'];

    $insert = fn (array $row): int => (int) DB::connection('mysql')->table('dispatch_messages')->insertGetId([...$row, 'tenant_id' => $site, 'created_at' => now(), 'updated_at' => now()]);

    $broadcast = $insert(['direction' => 'broadcast', 'body' => 'Water lock-off 10 AM to 2 PM.', 'sent_at' => now()->subMinutes(30)]);
    $second = $insert(['direction' => 'broadcast', 'body' => 'Gate 2 closed for repairs.', 'sent_at' => now()->subMinutes(25)]);
    $toMe = $insert(['direction' => 'outbound', 'guard_id' => $this->guard->id, 'body' => 'Call the control room when you can.', 'sent_at' => now()->subMinutes(20)]);
    $toThem = $insert(['direction' => 'outbound', 'guard_id' => $other->id, 'body' => 'Your relief is running late.', 'sent_at' => now()->subMinutes(15)]);

    $mine = fn () => collect(overrideCall($this, $this->token, 'messages.index')->json('items'))->keyBy('id');
    $theirs = fn () => collect(overrideCall($this, $otherToken, 'messages.index')->json('items'))->keyBy('id');

    // Nothing was backfilled and nothing is shared: everybody starts unread, and nobody sees the other's line.
    expect(overrideCall($this, $this->token, 'messages.index')->json('unread'))->toBe(3)
        ->and($mine()->has($toThem))->toBeFalse()
        ->and($theirs()->has($toMe))->toBeFalse();

    // I read ONE broadcast. Somebody else's message and an id nobody has are left out, silently.
    $marked = overrideCall($this, $this->token, 'messages.read', [], ['ids' => [$broadcast, $toThem, 999_999_999]]);
    expect($marked->json('ids'))->toBe([$broadcast])->and($marked->json('unread'))->toBe(2);

    expect($mine()[$broadcast]['read_at'])->not->toBeNull()
        ->and($mine()[$second]['read_at'])->toBeNull()
        ->and($theirs()[$broadcast]['read_at'])->toBeNull()                       // THE BUG: this used to be shared
        ->and(overrideCall($this, $otherToken, 'messages.index')->json('unread'))->toBe(3)
        ->and(DB::connection('mysql')->table('dispatch_message_reads')->where('dispatch_message_id', $toThem)->exists())->toBeFalse()
        // The shared column is no longer written for a broadcast.
        ->and(DB::connection('mysql')->table('dispatch_messages')->where('id', $broadcast)->value('read_at'))->toBeNull();

    // Idempotent: marking again keeps the FIRST read.
    $first = $mine()[$broadcast]['read_at'];
    $this->travel(5)->minutes();
    overrideCall($this, $this->token, 'messages.read', [], ['ids' => [$broadcast]]);
    expect($mine()[$broadcast]['read_at'])->toBe($first)
        ->and(DB::connection('mysql')->table('dispatch_message_reads')->where('dispatch_message_id', $broadcast)->where('guard_id', $this->guard->id)->count())->toBe(1);

    // Replying reads MY line with dispatch — not a broadcast, and nothing of anybody else's.
    overrideCall($this, $this->token, 'messages.store', [], ['body' => 'On my way to the phone now.']);
    expect($mine()[$toMe]['read_at'])->not->toBeNull()
        ->and($mine()[$second]['read_at'])->toBeNull()
        ->and($theirs()[$broadcast]['read_at'])->toBeNull()
        ->and($theirs()[$toThem]['read_at'])->toBeNull()
        ->and(overrideCall($this, $otherToken, 'messages.index')->json('unread'))->toBe(3);

    // PER PHONE: the same guard on a newly bound handset has read nothing on it yet.
    $this->guard->forceFill(['device_uid' => 'a-different-handset'])->save();
    expect($mine()[$broadcast]['read_at'])->toBeNull()
        ->and(overrideCall($this, $this->token, 'messages.index')->json('unread'))->toBe(3);

    // A malformed mark is a 422, not a silent success.
    overrideCall($this, $this->token, 'messages.read', [], ['ids' => []], 422);
    overrideCall($this, $this->token, 'messages.read', [], ['ids' => ['abc']], 422);
});
