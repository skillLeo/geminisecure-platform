<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Live\Channels;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The mobile API's foundations — 13 D1
|--------------------------------------------------------------------------
|
| Abilities derived from one matrix; a handset enrolled with a one-time code;
| a second handset bound only when a supervisor says so, recorded on the shift;
| a retried write answered with the first response; and the estate, the guard
| and the shift taken from the token, never from the body.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);

    $this->guard = Guard::create([
        'full_name' => 'Marcus Whyte',
        'employee_number' => 'GS-FND-1',
        'psra_number' => 'PSRA-FND-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $this->post->id,
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function foundationsKey(): string
{
    return sodium_bin2base64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

it('derives every ability from the app matrix, and every route asks for one its apps can hold', function () {
    $guard = AppMatrix::abilitiesFor(AppMatrix::GUARD);
    $resident = AppMatrix::abilitiesFor(AppMatrix::RESIDENT);

    // The gap the audit found: a guard's handset can clock on.
    expect($guard)->toContain('shifts:write')
        ->and($guard)->toContain('gate:write')
        ->and($resident)->not->toContain('gate:write')
        ->and($resident)->not->toContain('shifts:write')
        ->and($resident)->toContain('alerts:write');

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'api.v1.'));

    // The router holds exactly the catalogue, no more and no fewer.
    expect($routes->map(fn ($r) => substr((string) $r->getName(), 7))->sort()->values()->all())
        ->toBe(collect(array_keys(Catalogue::endpoints()))->sort()->values()->all());

    foreach (Catalogue::endpoints() as $name => $entry) {
        if ($entry['capability'] === null) {
            continue;
        }

        $ability = AppMatrix::ability($entry['capability'], $entry['access']);

        expect(collect($entry['apps'])->contains(fn (string $app) => AppMatrix::allows($app, $entry['capability'], $entry['access'])))
            ->toBeTrue("[{$name}] asks for {$ability}, which none of its apps holds");

        expect($routes->first(fn ($r) => $r->getName() === 'api.v1.'.$name)->gatherMiddleware())->toContain('ability:'.$ability);
    }

    // And no ability is written by hand anywhere else in the application.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'AppMatrix.php')) {
            continue;
        }

        expect((string) file_get_contents($file->getPathname()))
            ->not->toMatch("/'(alerts|gate|shifts|orders|passes):(raise|verify|clock|acknowledge|read|write)'/", $file->getPathname());
    }
});

it('enrols a handset with a one-time code, and refuses the code a second time', function () {
    $code = app(DeviceEnrolment::class)->issueCode($this->guard);

    $response = $this->postJson('/api/v1/devices/enrol', [
        'enrolment_code' => $code,
        'device_uid' => 'ios-install-001',
        'platform' => 'ios',
        'public_key' => foundationsKey(),
        'label' => 'iPhone 15 Pro · Main Gate',
    ])->assertCreated()->assertJsonPath('status', 'enrolled');

    // Where the Guard App first learns its site, it learns the site's ZONE — by IANA name (M4 §3.1).
    $response->assertJsonPath('site.timezone', 'America/Jamaica');

    ApiContract::assertMatches($response, 'devices.enrol');

    expect($response->json('abilities'))->toBe(AppMatrix::abilitiesFor(AppMatrix::GUARD))
        ->and($this->guard->refresh()->device_uid)->toBe('ios-install-001')
        ->and($this->guard->device_platform)->toBe('ios');

    // The token works.
    $this->withToken($response->json('token'))
        ->getJson('/api/v1/sites/'.FacilitiesFixture::ESTATE.'/pass-keys')
        ->assertOk();

    // A code is good once.
    $this->postJson('/api/v1/devices/enrol', [
        'enrolment_code' => $code,
        'device_uid' => 'ios-install-002',
        'platform' => 'ios',
        'public_key' => foundationsKey(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'enrolment_code_invalid');
});

it('binds a second handset only when a supervisor approves it, recorded on the guard\'s shift', function () {
    $enrolment = app(DeviceEnrolment::class);
    $first = $enrolment->enrol($this->guard, 'Old handset', ['device_uid' => 'old-phone', 'platform' => 'android', 'public_key' => foundationsKey()]);

    $shift = Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'guard_id' => $this->guard->id,
        'post_id' => $this->post->id,
        'rostered_start' => now()->addHour(),
        'rostered_end' => now()->addHours(13),
        'status' => 'rostered',
    ]);

    // Events from here on only: `request` is a leave request's entity too, and another file's committed leave request
    // can carry this rebind request's id (an order dependence seen in a shuffled run, seed 1791147623).
    $since = (int) DB::connection('mysql')->table('live_events')->max('id');

    $pending = $this->postJson('/api/v1/devices/enrol', [
        'enrolment_code' => $enrolment->issueCode($this->guard),
        'device_uid' => 'new-phone',
        'platform' => 'ios',
        'public_key' => foundationsKey(),
    ])->assertStatus(202)->assertJsonPath('status', 'pending_approval');

    ApiContract::assertMatches($pending, 'devices.enrol');

    // The guard's page on the Gemini console is told at once, so Approve / Refuse appears without a reload (M18).
    $told = fn (): array => DB::connection('mysql')->table('live_events')->where('id', '>', $since)->where('entity', 'request')
        ->where('entity_id', (string) $pending->json('rebind_request_id'))->orderBy('id')->get(['type', 'channels'])
        ->map(fn (object $r): array => ['type' => $r->type, 'channels' => json_decode((string) $r->channels, true)])->all();

    expect($told())->toHaveCount(1)
        ->and($told()[0]['type'])->toBe('request.changed')
        ->and($told()[0]['channels'])->toEqualCanonicalizing(Channels::gemini('workforce', FacilitiesFixture::ESTATE));

    $collect = ['claim_secret' => $pending->json('claim_secret'), 'device_uid' => 'new-phone'];
    $url = '/api/v1/devices/enrol/'.$pending->json('rebind_request_id').'/collect';

    // Nothing yet — and the old handset still works.
    $this->postJson($url, $collect)->assertOk()->assertJsonPath('status', 'pending_approval');
    $this->withToken($first['token'])->getJson('/api/v1/sites/'.FacilitiesFixture::ESTATE.'/pass-keys')->assertOk();

    // A wrong secret learns nothing.
    $this->postJson($url, [...$collect, 'claim_secret' => 'wrong'])->assertNotFound();

    // A role that only reads the workforce may not approve it; the Director may.
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::ACCOUNTANT))
        ->post('/guards/'.$this->guard->id.'/device-rebinds/'.$pending->json('rebind_request_id').'/approve')
        ->assertForbidden();

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)
        ->post('/guards/'.$this->guard->id.'/device-rebinds/'.$pending->json('rebind_request_id').'/approve')
        ->assertRedirect();

    $request = DB::connection('mysql')->table('device_rebind_requests')->where('id', $pending->json('rebind_request_id'))->first();

    expect($request->status)->toBe('approved')
        ->and((int) $request->shift_id)->toBe($shift->id)
        ->and($request->decided_by_name)->toBe($director->name)
        // The decision moves the page too: the panel goes.
        ->and($told())->toHaveCount(2);

    app('auth')->forgetGuards();

    // Collected once: the new handset has its token, the old one is dead.
    $collected = $this->postJson($url, $collect)->assertOk()->assertJsonPath('status', 'approved');
    ApiContract::assertMatches($collected, 'devices.enrol.status');

    app('auth')->forgetGuards();
    $this->withToken($first['token'])->getJson('/api/v1/sites/'.FacilitiesFixture::ESTATE.'/pass-keys')->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->withToken($collected->json('token'))->getJson('/api/v1/sites/'.FacilitiesFixture::ESTATE.'/pass-keys')->assertOk();

    expect($this->guard->refresh()->device_uid)->toBe('new-phone')
        ->and($this->guard->tokens()->count())->toBe(1)
        ->and($told())->toHaveCount(3);
});

it('answers a retried write with the first response, once, and refuses the key for a different request', function () {
    $token = app(DeviceEnrolment::class)->enrol($this->guard, 'Handset')['token'];
    // A handset clock ten minutes AHEAD: no queue can put an act in the future, so it is a wrong clock (B066).
    $headers = ['Idempotency-Key' => 'panic-7f3a', 'X-Device-Time' => now()->addMinutes(10)->toIso8601String()];

    $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/alerts', ['kind' => 'duress'])->assertCreated();

    app('auth')->forgetGuards();
    $replay = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/alerts', ['kind' => 'duress'])->assertCreated();

    ApiContract::assertMatches($first, 'alerts.store');

    // THE REPLAY: the same body, marked as a replay, and one row.
    expect($replay->getContent())->toBe($first->getContent())
        ->and($replay->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($first->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and(DuressAlert::query()->where('guard_id', $this->guard->id)->count())->toBe(1)

        // Device time beside server time, and the ten-minute skew is flagged.
        ->and($first->json('clock_skewed'))->toBeTrue()
        ->and($first->json('device_time'))->not->toBeNull()
        ->and($first->json('server_time'))->not->toBeNull();

    // The same key for a different act is a bug in the app, and is refused.
    app('auth')->forgetGuards();
    $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/alerts', ['kind' => 'fire'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('takes the estate, the guard and the shift from the token, never from the body', function () {
    $token = app(DeviceEnrolment::class)->enrol($this->guard, 'Handset')['token'];

    // Another estate's name in the body: refused.
    $this->withToken($token)->postJson('/api/v1/alerts', ['kind' => 'duress', 'tenant_id' => 'someotherestate', 'idempotency_key' => 'x1'])
        ->assertForbidden()->assertJsonPath('error.code', 'wrong_site');

    // Another guard's shift: refused, and not started.
    $other = Guard::create([
        'full_name' => 'Devon Palmer', 'employee_number' => 'GS-FND-2', 'psra_number' => 'PSRA-FND-2',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
    ]);
    $theirs = Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $other->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->subHour(), 'rostered_end' => now()->addHours(11), 'status' => 'rostered',
    ]);

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson("/api/v1/shifts/{$theirs->id}/clock-in")
        ->assertForbidden()->assertJsonPath('error.code', 'not_your_shift');

    expect($theirs->refresh()->actual_start)->toBeNull();

    // A console session is not a handset: the API answers tokens only.
    app('auth')->forgetGuards();
    $this->actingAs(User::factory()->create())->postJson('/api/v1/alerts', ['kind' => 'duress', 'idempotency_key' => 'x2'])->assertUnauthorized();
});

it('serves the guard\'s own site its public pass keys, and no other site\'s', function () {
    $token = app(DeviceEnrolment::class)->enrol($this->guard, 'Handset')['token'];

    $keys = $this->withToken($token)->getJson('/api/v1/sites/'.FacilitiesFixture::ESTATE.'/pass-keys')->assertOk();

    ApiContract::assertMatches($keys, 'passes.keys');

    expect($keys->json('algorithm'))->toBe('Ed25519')
        ->and($keys->json('keys'))->not->toBeEmpty()
        ->and($keys->getContent())->not->toContain('secret');

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/sites/phoenixpark/pass-keys')->assertForbidden()->assertJsonPath('error.code', 'wrong_site');
});
