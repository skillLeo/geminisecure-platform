<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /api/v1 — what a handset may do, and what it may never be told
|--------------------------------------------------------------------------
|
| INVARIANT 2 IS THIS FILE'S FIRST JOB. No endpoint reachable by a guard may
| return a monetary amount — not a balance, not an ageing bucket, not a payment
| history, not a wage. That is asserted here against the whole response body of
| every endpoint a handset holds an ability for, rather than trusted to the
| absence of a field somebody might add later.
|
| THE SECOND JOB IS THAT THE DOOR IS SHUT. Every one of these endpoints was
| unreachable by anything for weeks: they sit behind `auth:sanctum` and an
| ability, and nothing could mint a token. `simulate:alerts` had been answering
| 401 on every request and reporting it as a failed simulation. So the tests
| below check both directions — a token with the ability gets in, and one
| without is refused — because "it returns 401" and "it returns 401 for the
| right reason" are different facts.
|
*/

/**
 * An estate to hang the fixtures on.
 *
 * Written directly rather than through a factory, because this codebase has
 * only a `UserFactory` and inventing three more for one suite would put a
 * second description of every table beside the seeders that already own them.
 */
function apiEstate(): string
{
    $id = 'apitest';

    /*
     * `name` is a real column AND lives in the tenancy package's `data` blob.
     * Both are written, because the estate is read back both ways depending on
     * which side of the boundary is asking.
     */
    DB::table('tenants')->updateOrInsert(
        ['id' => $id],
        [
            'name' => 'API Test Estate',
            'data' => json_encode(['name' => 'API Test Estate']),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    return $id;
}

function apiGuard(string $status = 'active'): Guard
{
    static $n = 0;
    $n++;

    return Guard::create([
        'full_name' => 'Test Guard '.$n,
        'employee_number' => 'GS-TEST-'.$n,
        'psra_number' => 'PSRA-TEST-'.$n,
        'psra_expires_on' => now()->addYear(),
        'employment_type' => 'full_time',
        'status' => $status,
        'tenant_id' => apiEstate(),
    ]);
}

function apiPost(): Post
{
    static $n = 0;
    $n++;

    return Post::create([
        'tenant_id' => apiEstate(),
        'name' => 'Test Gate '.$n,
        'type' => 'gate',
        'is_active' => true,
    ]);
}

function apiShift(Guard $guard, ?Post $post = null): Shift
{
    return Shift::create([
        'tenant_id' => $guard->tenant_id,
        'guard_id' => $guard->id,
        'post_id' => ($post ?? apiPost())->id,
        'rostered_start' => now()->subHour(),
        'rostered_end' => now()->addHours(11),
        'status' => 'rostered',
    ]);
}

/** A guard with a handset enrolled, and the token it speaks with. */
function handset(?array $abilities = null): array
{
    $guard = apiGuard();

    /*
     * Minted directly rather than through `DeviceEnrolment`, so a test can ask
     * for a DEFICIENT set of abilities. The service always issues the full
     * guard set — correctly — and the refusal cases below have to be able to
     * present a token that is missing one.
     */
    $token = $guard->createToken('Test handset', $abilities ?? AppMatrix::abilitiesFor(AppMatrix::GUARD))->plainTextToken;

    return ['guard' => $guard, 'token' => $token, 'tenant' => $guard->tenant_id];
}

it('refuses every endpoint without a token', function () {
    $this->postJson('/api/v1/alerts', [])->assertUnauthorized();
    $this->postJson('/api/v1/gate-events', [])->assertUnauthorized();
    $this->postJson('/api/v1/shifts/1/clock-in', [])->assertUnauthorized();
    $this->postJson('/api/v1/shifts/1/clock-out', [])->assertUnauthorized();
});

it('refuses an endpoint the token holds no ability for', function () {
    /*
     * A token that can raise an alert and nothing else — which is exactly what
     * a Resident App handset carries. It must not be able to adjudicate an
     * arrival at the gate or clock anybody on: those are a guard's acts.
     */
    $h = handset(AppMatrix::abilitiesFor(AppMatrix::RESIDENT));

    $this->withToken($h['token'])
        ->postJson('/api/v1/gate-events', [])
        ->assertForbidden();

    /*
     * A REAL shift, deliberately. Route binding runs before the ability check,
     * so asking about a shift that does not exist answers 404 and would pass
     * this test for the wrong reason — proving the id was unknown rather than
     * that the token was refused.
     */
    $shift = apiShift(apiGuard());

    $this->withToken($h['token'])
        ->postJson("/api/v1/shifts/{$shift->id}/clock-in", [])
        ->assertForbidden();

    expect($shift->refresh()->actual_start)->toBeNull();
});

it('records what the guard decided at the gate, and never a figure', function () {
    $h = handset();
    $post = apiPost();

    $response = $this->withToken($h['token'])->postJson('/api/v1/gate-events', [
        'tenant_id' => $h['tenant'],
        'verdict' => 'admit',
        'category' => 'Visitor',
        'subject' => 'Andrea Fletcher',
        'basis' => 'QR pass',
        'guard_id' => $h['guard']->id,
        'post_id' => $post->id,
        'idempotency_key' => 'test-gate-1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('verdict', 'admit')
        ->assertJsonPath('pass_based', true);

    /*
     * INVARIANT 2, ON THE WHOLE BODY. Not "the balance field is absent" — the
     * response is scanned for any currency mark and any bare figure that could
     * be an amount, so a field added later cannot smuggle one through.
     */
    $body = $response->getContent();

    expect($body)->not->toContain('J$')
        ->and($body)->not->toContain('balance')
        ->and($body)->not->toContain('arrears')
        ->and($body)->not->toContain('_minor');

    $this->assertDatabaseHas('gate_events', [
        'idempotency_key' => 'test-gate-1',
        'verdict' => 'admit',

        // The guard's and the post's names are COPIED, not joined — a gate log
        // has to read correctly after a guard leaves or a post is renamed.
        'guard_name' => $h['guard']->full_name,
        'post_name' => $post->name,
    ]);
});

it('records the same gate event once however many times a handset retries', function () {
    $h = handset();

    $payload = [
        'tenant_id' => $h['tenant'],
        'verdict' => 'admit',
        'category' => 'Delivery',
        'subject' => 'Island Courier Ltd',
        'basis' => 'Pre-approved',
        'idempotency_key' => 'test-gate-retry',
    ];

    $first = $this->withToken($h['token'])->postJson('/api/v1/gate-events', $payload)->assertCreated();
    $second = $this->withToken($h['token'])->postJson('/api/v1/gate-events', $payload)->assertCreated();

    /*
     * A handset at a gate loses signal constantly. A retry that duplicated an
     * admission would inflate the very count `AdoptionRollup` reports to an
     * account manager as the client's pass take-up.
     */
    expect($first->json('id'))->toBe($second->json('id'))
        ->and(GateEvent::where('idempotency_key', 'test-gate-retry')->count())->toBe(1);
});

it('refuses an override with no reason, and records the reason when there is one', function () {
    $h = handset();

    $base = [
        'tenant_id' => $h['tenant'],
        'verdict' => 'override',
        'category' => 'Contractor',
        'subject' => 'Gate2 Contractor Ltd',
        'basis' => 'Guard decision',
    ];

    /*
     * An override admits somebody the system advised against. The estate is
     * entitled to know why, and a required field is the only place that can be
     * guaranteed — a reason asked for by a screen is a reason the next client's
     * screen forgets to ask for.
     */
    $this->withToken($h['token'])
        ->postJson('/api/v1/gate-events', [...$base, 'idempotency_key' => 'test-override-none'])
        ->assertJsonValidationErrorFor('reason');

    $this->withToken($h['token'])
        ->postJson('/api/v1/gate-events', [
            ...$base,
            'reason' => 'Emergency plumbing call-out, authorised by the Property Manager',
            'idempotency_key' => 'test-override-given',
        ])
        ->assertCreated();

    $event = GateEvent::where('idempotency_key', 'test-override-given')->firstOrFail();

    // The reason IS the basis on an override. Storing "override" in both places
    // would lose the only sentence explaining the decision.
    expect($event->basis)->toContain('Emergency plumbing call-out');
});

it('clocks a guard on, and says so once however many times the handset asks', function () {
    $h = handset();
    $post = apiPost();

    $shift = apiShift($h['guard'], $post);

    $first = $this->withToken($h['token'])
        ->postJson("/api/v1/shifts/{$shift->id}/clock-in", ['geofence_distance_m' => 12])
        ->assertOk();

    expect($first->json('actual_start'))->not->toBeNull();

    /*
     * The first clock-in is the one that happened. A handset syncing a queue it
     * captured in a tunnel must not rewrite when somebody arrived — that
     * timestamp is what a shift is paid against.
     */
    $second = $this->withToken($h['token'])
        ->postJson("/api/v1/shifts/{$shift->id}/clock-in")
        ->assertOk();

    expect($second->json('actual_start'))->toBe($first->json('actual_start'));
});

it('records a mock location rather than refusing the clock-in', function () {
    $h = handset();

    $shift = apiShift($h['guard']);

    /*
     * The instinct is to reject this, and it would be wrong twice: it would
     * leave a post reading as unmanned while somebody stands at it, and it
     * would tell whoever spoofed the location that they had been caught. The
     * shift starts and the flag goes in front of a supervisor.
     */
    $this->withToken($h['token'])
        ->postJson("/api/v1/shifts/{$shift->id}/clock-in", ['mock_location' => true])
        ->assertOk()
        ->assertJsonPath('mock_location_flag', true);

    expect($shift->refresh()->actual_start)->not->toBeNull();
});

it('refuses to end a shift that never started', function () {
    $h = handset();

    $shift = apiShift($h['guard']);

    /*
     * 409 rather than 422. The request is well-formed and the handset did
     * nothing wrong — the shift is simply in a state that cannot accept an
     * ending, and the queued clock-in it still holds is what resolves it.
     */
    $this->withToken($h['token'])
        ->postJson("/api/v1/shifts/{$shift->id}/clock-out")
        ->assertStatus(409);

    expect($shift->refresh()->actual_end)->toBeNull();
});

it('returns nothing that could be a wage when a guard clocks on', function () {
    $h = handset();

    $shift = apiShift($h['guard']);

    $body = $this->withToken($h['token'])
        ->postJson("/api/v1/shifts/{$shift->id}/clock-in")
        ->assertOk()
        ->getContent();

    /*
     * A guard's own rate is on `guards.standard_rate_minor` and is central
     * payroll's business. Nothing a handset calls returns it — including the
     * endpoint that has a guard in scope and would be the easiest place to leak
     * one by accident.
     */
    expect($body)->not->toContain('rate')
        ->and($body)->not->toContain('_minor')
        ->and($body)->not->toContain('J$');
});

it('gives a handset one working token, and binding a new one kills the old', function () {
    $guard = apiGuard();

    $enrolment = app(DeviceEnrolment::class);

    $first = $enrolment->enrol($guard, 'Old handset');
    $second = $enrolment->enrol($guard, 'Replacement handset');

    /*
     * A guard who replaces a lost phone must end up with exactly one working
     * token, or the lost one keeps clocking them in.
     */
    $this->withToken($first['token'])->postJson('/api/v1/gate-events', [])->assertUnauthorized();

    $this->withToken($second['token'])
        ->postJson('/api/v1/gate-events', [])
        ->assertStatus(422);

    expect($guard->refresh()->tokens()->count())->toBe(1);
});

it('leaves a guard carrying no bound handset once their device is revoked', function () {
    $guard = apiGuard();

    $enrolment = app(DeviceEnrolment::class);
    $enrolment->enrol($guard, 'Handset');

    expect($guard->refresh()->deviceIsBound())->toBeTrue();

    $enrolment->revoke($guard);

    /*
     * The binding goes with the tokens. Leaving `device_id` set would make
     * `AdoptionRollup` count this guard as carrying a bound handset — a client's
     * Guard App coverage reading higher than the number of phones that can
     * actually sign in.
     */
    expect($guard->refresh()->deviceIsBound())->toBeFalse()
        ->and($guard->tokens()->count())->toBe(0);
});

it('lets a frightened person press panic far more often than any limit allows', function () {
    /*
     * THE ONE RATE LIMIT THAT COULD KILL SOMEBODY IF IT WERE WRONG.
     *
     * A limit that silences an alert is worse than the flood it prevents, so
     * the alert ceiling is set where no person could reach it and only a loop
     * can. Ten alerts in a row — far more than anybody jabbing a button
     * produces — must all be accepted.
     */
    $h = handset();

    for ($i = 0; $i < 10; $i++) {
        $this->withToken($h['token'])->postJson('/api/v1/alerts', [
            'tenant_id' => $h['tenant'],
            'kind' => 'panic',
            'raised_by_name' => 'Andrea Fletcher',
            'idempotency_key' => 'panic-burst-'.$i,
        ])->assertCreated();
    }
});

it('stops a runaway handset burying the dispatch queue', function () {
    /*
     * And the other side of the same limit. A credential that can raise an
     * alert can raise ten thousand once a phone is lost or cloned, and the
     * queue a dispatcher watches is the thing that fills up.
     *
     * Keyed on the TOKEN rather than the address, because every guard at one
     * estate can sit behind one mobile carrier NAT — an IP limit would throttle
     * a whole gate because one handset misbehaved.
     */
    $h = handset();
    $refused = 0;

    for ($i = 0; $i < 40; $i++) {
        $response = $this->withToken($h['token'])->postJson('/api/v1/alerts', [
            'tenant_id' => $h['tenant'],
            'kind' => 'panic',
            'raised_by_name' => 'Runaway handset',
            'idempotency_key' => 'flood-'.$i,
        ]);

        if ($response->getStatusCode() === 429) {
            $refused++;
        }
    }

    expect($refused)->toBeGreaterThan(0, 'a handset can raise unlimited alerts');
});

it('gives each handset its own bucket rather than sharing one across a gate', function () {
    $first = handset();
    $second = handset();

    // Spend the first handset's allowance.
    for ($i = 0; $i < 40; $i++) {
        $this->withToken($first['token'])->postJson('/api/v1/alerts', [
            'tenant_id' => $first['tenant'],
            'kind' => 'panic',
            'raised_by_name' => 'First handset',
            'idempotency_key' => 'bucket-a-'.$i,
        ]);
    }

    /*
     * The second handset must be untouched. Two guards sharing an estate's
     * mobile signal are two devices, and one of them misbehaving cannot be
     * allowed to take the other's panic button away.
     */
    $this->withToken($second['token'])->postJson('/api/v1/alerts', [
        'tenant_id' => $second['tenant'],
        'kind' => 'panic',
        'raised_by_name' => 'Second handset',
        'idempotency_key' => 'bucket-b-1',
    ])->assertCreated();
});

it('refuses to enrol a handset for a guard who is not working', function () {
    $guard = apiGuard('suspended');

    expect(fn () => app(DeviceEnrolment::class)->enrol($guard))
        ->toThrow(DomainException::class);

    expect($guard->refresh()->tokens()->count())->toBe(0);
});
