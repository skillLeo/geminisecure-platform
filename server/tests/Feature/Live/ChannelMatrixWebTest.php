<?php

declare(strict_types=1);

use App\Http\Controllers\Live\LiveAuthController;
use App\Http\Middleware\EndSessionOfInactiveAccount;
use App\Http\Middleware\HandleInertiaRequests;
use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\Principal;
use App\Live\ChannelName;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Stancl\Tenancy\Events\TenancyInitialized;
use Tests\Live\Support\LiveAuthFixture;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HeadOfSecurityScope;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| WHAT A CONSOLE SESSION MAY SUBSCRIBE TO, AND WHAT IT MAY NOT (DESIGN §3.2–§3.7)
|--------------------------------------------------------------------------
|
| Every positive control is a real grant — `auth` is recomputed here as
| key:hmac_sha256(secret, socket_id:channel:channel_data) with credentials nobody else
| uses — and every row of §3.7 that a console session can reach is refused, through the
| door itself, with the broadcast manager left on `null`.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    LiveAuthFixture::credentials();

    $this->estate = FacilitiesFixture::ESTATE;
});

afterEach(function () {
    tenancy()->end();

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/**
 * Where the door is: on an estate's own host when one is named, else on the central
 * host. Always a full URL — a relative one is resolved against the PREVIOUS request's
 * host, which after an estate-host request would be that estate's.
 */
function liveWebDoor(?string $host): string
{
    return ($host === null ? rtrim((string) config('app.url'), '/') : 'http://'.$host).'/live/auth';
}

/**
 * One batch grant request, as the console's socket makes it.
 *
 * @param  list<string>  $wires
 */
function liveWebBatch(TestCase $test, User $user, array $wires, ?string $host = null): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->actingAs($user)->postJson(liveWebDoor($host), [
        'socket_id' => LiveAuthFixture::SOCKET,
        'channel_names' => $wires,
    ]);

    FacilitiesFixture::boot();

    return $response;
}

/** One single-form grant request, as pusher-js's own authoriser makes it. */
function liveWebOne(TestCase $test, User $user, string $wire, ?string $host = null): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->actingAs($user)->postJson(liveWebDoor($host), [
        'socket_id' => LiveAuthFixture::SOCKET,
        'channel_name' => $wire,
    ]);

    FacilitiesFixture::boot();

    return $response;
}

/**
 * The wire names a batch granted, each checked to be a true signature for this user.
 *
 * @param  list<string>  $wires
 * @return list<string>
 */
function liveWebGranted(TestCase $test, User $user, array $wires, ?string $host = null): array
{
    $response = liveWebBatch($test, $user, $wires, $host)->assertOk();
    $data = LiveAuthFixture::channelData(LiveAuthFixture::userKey($user));
    $channels = (array) $response->json('channels');

    foreach ($channels as $wire => $grant) {
        expect($grant['channel_data'])->toBe($data)
            ->and($grant['auth'])->toBe(LiveAuthFixture::expectedAuth((string) $wire, $data), (string) $wire)
            ->and($grant['streams'])->toBe(ChannelName::fromWire((string) $wire)?->streams());
    }

    expect(collect(array_keys($channels))->merge($response->json('refused'))->sort()->values()->all())
        ->toBe(collect($wires)->unique()->sort()->values()->all(), 'every name is answered, granted or refused');

    return array_values(array_map('strval', array_keys($channels)));
}

function liveWire(string $name): string
{
    return ChannelName::WIRE_PREFIX.$name;
}

it('signs one grant per family a console session can hold, each a true HMAC over socket, channel and principal', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $t = $this->estate;

    $estateWires = [
        liveWire('user.'.$president->id),
        liveWire("estate.{$t}.security"),
        liveWire("estate.{$t}.facilities"),
        liveWire("estate.{$t}.console"),
        liveWire("estate.{$t}.facilities.ticket.1039"),
        liveWire("estate.{$t}.governance.meeting.3"),
        liveWire("estate.{$t}.ledger.unit.4"),
        liveWire("estate.{$t}.residents.unit.4"),
    ];

    expect(liveWebGranted($this, $president, $estateWires))->toBe($estateWires);

    $geminiWires = [
        liveWire('user.'.$director->id),
        liveWire('gemini.dispatch'),
        liveWire('gemini.dashboard'),
        liveWire("gemini.{$t}.dispatch"),
        liveWire("gemini.{$t}.workforce"),
        liveWire("gemini.{$t}.dispatch.alert.55"),
        liveWire("gemini.{$t}.workforce.guard.9"),
    ];

    expect(liveWebGranted($this, $director, $geminiWires))->toBe($geminiWires);

    // Pusher's own single form, for the same grant.
    $one = liveWebOne($this, $president, liveWire("estate.{$t}.security"))->assertOk();
    $data = LiveAuthFixture::channelData('u-'.$president->id);

    expect($one->json())->toBe([
        'auth' => LiveAuthFixture::expectedAuth(liveWire("estate.{$t}.security"), $data),
        'channel_data' => $data,
    ]);

    // A signature is bound to its socket: the same grant for another socket is another HMAC.
    expect(LiveAuthFixture::expectedAuth(liveWire("estate.{$t}.security"), $data, '9.9'))->not->toBe($one->json('auth'));
});

it('refuses an estate user every channel of another estate, and answers a foreign estate and a missing one byte for byte alike', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $other = LiveAuthFixture::bareEstate();

    $wires = [];

    foreach (ChannelName::ESTATE_FEEDS as $feed) {
        $wires[] = liveWire("estate.{$other}.{$feed}");
        $wires[] = liveWire("estate.zzzq.{$feed}");
    }

    $wires[] = liveWire("estate.{$other}.facilities.ticket.1039");
    $wires[] = liveWire("gemini.{$this->estate}.dispatch");
    $wires[] = liveWire('gemini.dispatch');

    expect(liveWebGranted($this, $president, $wires))->toBe([]);

    $foreign = liveWebOne($this, $president, liveWire("estate.{$other}.security"));
    $missing = liveWebOne($this, $president, liveWire('estate.zzzq.security'));

    expect($foreign->status())->toBe(403)
        ->and($missing->status())->toBe(403)
        ->and($foreign->getContent())->toBe($missing->getContent())
        ->and($foreign->json())->toBe(LiveAuthController::REFUSAL);
});

it('refuses somebody else\'s own channel exactly as it refuses one nobody has', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $someoneElse = FacilitiesFixture::viewer(Role::TREASURER);

    $foreign = liveWebOne($this, $president, liveWire('user.'.$someoneElse->id));
    $missing = liveWebOne($this, $president, liveWire('user.987654321'));

    expect($foreign->status())->toBe(403)
        ->and($foreign->getContent())->toBe($missing->getContent())
        ->and($foreign->json())->toBe(LiveAuthController::REFUSAL);
});

it('refuses Treasurer, Secretary and Admin Assistant the security feed, the Admin Assistant the estate\'s dues, and the Property Manager dues and governance', function () {
    $t = $this->estate;

    foreach ([Role::TREASURER, Role::SECRETARY, Role::ESTATE_ADMIN_ASSISTANT] as $role) {
        expect(liveWebGranted($this, FacilitiesFixture::viewer($role), [liveWire("estate.{$t}.security")]))->toBe([], $role.' may not see Security');
    }

    expect(liveWebGranted($this, FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT), [liveWire("estate.{$t}.dues")]))
        ->toBe([], 'the Admin Assistant holds the ledger but not the estate\'s position');

    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    expect(liveWebGranted($this, $manager, [liveWire("estate.{$t}.dues"), liveWire("estate.{$t}.governance"), liveWire("estate.{$t}.governance.meeting.3")]))->toBe([])
        ->and(liveWebGranted($this, $manager, [liveWire("estate.{$t}.facilities"), liveWire("estate.{$t}.security")]))->toBe([liveWire("estate.{$t}.facilities"), liveWire("estate.{$t}.security")]);

    expect(liveWebGranted($this, FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY), [liveWire("estate.{$t}.dues"), liveWire("estate.{$t}.security")]))
        ->toBe([liveWire("estate.{$t}.security")]);

    // The controls: the roles that may, may.
    expect(liveWebGranted($this, FacilitiesFixture::viewer(Role::TREASURER), [liveWire("estate.{$t}.dues")]))->toBe([liveWire("estate.{$t}.dues")])
        ->and(liveWebGranted($this, FacilitiesFixture::viewer(Role::SECRETARY), [liveWire("estate.{$t}.governance")]))->toBe([liveWire("estate.{$t}.governance")]);
});

it('refuses the Gemini Accountant dispatch and admits it to the workforce', function () {
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);
    $t = $this->estate;

    expect(liveWebGranted($this, $accountant, [
        liveWire("gemini.{$t}.dispatch"),
        liveWire('gemini.dispatch'),
        liveWire("gemini.{$t}.dispatch.alert.5"),
        liveWire("gemini.{$t}.workforce"),
        liveWire('gemini.workforce'),
    ]))->toBe([liveWire("gemini.{$t}.workforce"), liveWire('gemini.workforce')]);
});

it('confines a Head of Security to its own sites, and one with no sites to its own channel and the platform dashboard', function () {
    $t = $this->estate;
    $other = LiveAuthFixture::bareEstate();
    $head = HeadOfSecurityScope::assigned(FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY), Role::HEAD_OF_SECURITY, $t);

    expect(liveWebGranted($this, $head, [
        liveWire("gemini.{$t}.dispatch"),
        liveWire("gemini.{$t}.dispatch.alert.5"),
        liveWire("gemini.{$t}.clients"),
        liveWire("gemini.{$other}.dispatch"),
        liveWire("gemini.{$other}.dispatch.alert.5"),
        liveWire('gemini.dispatch'),
        liveWire('gemini.workforce'),
    ]))->toBe([liveWire("gemini.{$t}.dispatch"), liveWire("gemini.{$t}.dispatch.alert.5"), liveWire("gemini.{$t}.clients")]);

    $unassigned = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    $everything = [];

    foreach ([$t, $other] as $estate) {
        foreach (array_diff(ChannelName::GEMINI_FEEDS, ChannelName::GEMINI_PLATFORM_ONLY) as $feed) {
            $everything[] = liveWire("gemini.{$estate}.{$feed}");
        }

        $everything[] = liveWire("gemini.{$estate}.dispatch.alert.5");
        $everything[] = liveWire("gemini.{$estate}.workforce.incident.5");
    }

    foreach (array_diff(ChannelName::GEMINI_FEEDS, ['dashboard']) as $feed) {
        $everything[] = liveWire('gemini.'.$feed);
    }

    expect(liveWebGranted($this, $unassigned, $everything))->toBe([], 'a Head of Security with no sites reaches no estate and no platform-wide feed');

    // The two it keeps, by design: its own channel, and the dashboard every Gemini role may open (DESIGN §3.1).
    expect(liveWebGranted($this, $unassigned, [liveWire('user.'.$unassigned->id), liveWire('gemini.dashboard')]))
        ->toBe([liveWire('user.'.$unassigned->id), liveWire('gemini.dashboard')]);
});

it('refuses the demonstration estate\'s live feeds to everybody, whatever their scope', function () {
    $demo = LiveAuthFixture::bareEstate('livedemo', demo: true);
    $t = $this->estate;

    $viewers = [
        FacilitiesFixture::geminiViewer(Role::DIRECTOR),
        FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER),
        FacilitiesFixture::geminiViewer(Role::DISPATCHER),
        HeadOfSecurityScope::assigned(FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY), Role::HEAD_OF_SECURITY, $demo),
    ];

    foreach ($viewers as $viewer) {
        $demoWires = [
            liveWire("gemini.{$demo}.dispatch.alert.5"),
            liveWire("gemini.{$demo}.workforce.guard.5"),
        ];

        foreach (array_diff(ChannelName::GEMINI_FEEDS, ChannelName::GEMINI_PLATFORM_ONLY) as $feed) {
            $demoWires[] = liveWire("gemini.{$demo}.{$feed}");
        }

        expect(liveWebGranted($this, $viewer, $demoWires))->toBe([], $viewer->getRoleNames()->implode(',').' reached the demonstration estate');
    }

    // The control: the same Director is not simply refused everything.
    expect(liveWebGranted($this, $viewers[0], [liveWire("gemini.{$t}.dispatch")]))->toBe([liveWire("gemini.{$t}.dispatch")]);
});

it('ends a suspended account\'s session at the door (B068), a Gemini account that keeps its role included', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $president->forceFill(['status' => 'suspended'])->save();

    liveWebBatch($this, $president, [liveWire('user.'.$president->id)])->assertUnauthorized();
    expect(Auth::guard('web')->check())->toBeFalse('the session was ended, not just refused');

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $director->forceFill(['status' => 'suspended'])->save();

    liveWebBatch($this, $director, [liveWire('gemini.dispatch')])->assertUnauthorized();
    expect($director->fresh()?->hasRole(Role::DIRECTOR))->toBeTrue('suspension keeps the role; the door still refuses');

    // And the rules refuse them too, for whoever asks without a session (the reconcile backstop).
    $registry = app(ChannelRegistry::class);

    expect($registry->decide(Principal::server($president->fresh() ?? $president), 'user.'.$president->id))->toBeFalse()
        ->and($registry->decide(Principal::server($director->fresh() ?? $director), 'gemini.dispatch'))->toBeFalse();
});

it('refuses an ended assignment and an unverified committee member', function () {
    $t = $this->estate;
    $ended = FacilitiesFixture::viewer(Role::PRESIDENT);
    EstateAssignment::query()->where('user_id', $ended->id)->update(['is_active' => false]);

    expect(liveWebGranted($this, $ended->fresh() ?? $ended, [liveWire("estate.{$t}.security"), liveWire("estate.{$t}.console")]))->toBe([]);

    $unverified = User::factory()->unverifiedContact()->create(['console' => 'estate', 'status' => 'active']);
    $unverified->syncRoles([Role::PRESIDENT]);
    EstateAssignment::create([
        'user_id' => $unverified->id,
        'tenant_id' => $t,
        'role_id' => Role::query()->where('name', Role::PRESIDENT)->firstOrFail()->id,
        'is_active' => true,
    ]);

    expect(liveWebGranted($this, $unverified->fresh() ?? $unverified, [
        liveWire('user.'.$unverified->id),
        liveWire("estate.{$t}.security"),
        liveWire("estate.{$t}.console"),
    ]))->toBe([], 'no proven alert address, no console page — and no socket wider than the pages');
});

it('refuses every name that is not a canonical private-live name, with the one refusal body', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $t = $this->estate;
    $u = $president->id;

    $refused = [
        'presence-live.user.'.$u,
        'private-live.User.'.$u,
        'private-live.user.0'.$u,
        "private-live.ESTATE.{$t}.security",
        "private-live.estate.{$t}.Security",
        "private-estate.{$t}.alerts",
        "presence-estate.{$t}.alerts",
        "estate.{$t}.security",
        'user.'.$u,
        'private-encrypted-live.user.'.$u,
        "private-live.estate.{$t}.alerts",
        'private-App.Models.User.'.$u,
        'live-pulse',
        'private-live.live-pulse',
        'private-live.user.'.$u.'.',
        'private-live..user.'.$u,
    ];

    expect(liveWebGranted($this, $president, [...$refused, liveWire('user.'.$u)]))->toBe([liveWire('user.'.$u)]);

    $bodies = [];

    foreach ($refused as $wire) {
        $response = liveWebOne($this, $president, $wire);
        expect($response->status())->toBe(403, "[{$wire}]");
        $bodies[] = $response->getContent();
    }

    expect(array_values(array_unique($bodies)))->toBe([json_encode(LiveAuthController::REFUSAL)]);
});

it('refuses a console user a handset\'s channels, even under its own id', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $t = $this->estate;
    $n = $president->id;

    expect(liveWebGranted($this, $president, [
        liveWire("guard.{$t}.{$n}"),
        liveWire("resident.{$t}.{$n}"),
        liveWire("household.{$t}.{$n}"),
        liveWire("claim.{$t}.{$n}"),
        liveWire("approval.{$t}.{$n}"),
        liveWire("estate.{$t}.guards"),
        liveWire("estate.{$t}.passes"),
        liveWire("community.{$t}"),
        liveWire("phase.{$t}.".ChannelName::phaseKey('Phase 1')),
    ]))->toBe([]);
});

it('refuses anybody who is not signed in', function () {
    $this->postJson('/live/auth', ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => [liveWire('user.1')]])->assertUnauthorized();
    $this->postJson('/live/auth', ['socket_id' => LiveAuthFixture::SOCKET, 'channel_name' => liveWire('user.1')])->assertUnauthorized();
});

it('binds a grant to its host: an estate\'s own host is granted that estate\'s channels and the user\'s own, and nothing else', function () {
    $t = $this->estate;
    $other = LiveAuthFixture::twinEstate();
    $domain = config('app.estate_domain');
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    expect(liveWebGranted($this, $president, [liveWire("estate.{$t}.security"), liveWire('user.'.$president->id)], "{$t}.{$domain}"))
        ->toBe([liveWire("estate.{$t}.security"), liveWire('user.'.$president->id)]);

    // The same session's grant, asked from another estate's host, is refused its own estate.
    expect(liveWebGranted($this, $president, [liveWire("estate.{$t}.security"), liveWire('user.'.$president->id)], "{$other}.{$domain}"))
        ->toBe([liveWire('user.'.$president->id)]);

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    expect(liveWebGranted($this, $director, [
        liveWire('gemini.dispatch'),
        liveWire('gemini.dashboard'),
        liveWire("gemini.{$other}.dispatch"),
        liveWire("gemini.{$t}.dispatch"),
        liveWire('user.'.$director->id),
    ], "{$t}.{$domain}"))->toBe([liveWire("gemini.{$t}.dispatch"), liveWire('user.'.$director->id)]);

    // The control: on the central host the Director is granted all of it.
    expect(liveWebGranted($this, $director, [liveWire('gemini.dispatch'), liveWire("gemini.{$other}.dispatch")]))
        ->toBe([liveWire('gemini.dispatch'), liveWire("gemini.{$other}.dispatch")]);
});

it('counts a batch of 200 channels once against the limit of sixty a minute per principal, and opens the estate once for it', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $t = $this->estate;

    $wires = [liveWire('user.'.$president->id)];

    for ($n = 1; count($wires) < 200; $n++) {
        $wires[] = liveWire("estate.{$t}.facilities.ticket.{$n}");
    }

    $opened = [];
    Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) use (&$opened): void {
        $opened[] = (string) $event->tenancy->tenant?->getTenantKey();
    });

    $batch = liveWebBatch($this, $president, $wires)->assertOk();

    expect($batch->json('channels'))->toHaveCount(200)
        ->and($batch->headers->get('X-RateLimit-Limit'))->toBe('60')
        ->and($batch->headers->get('X-RateLimit-Remaining'))->toBe('59')
        ->and($opened)->toBe([$t], 'one estate opened for 199 of its channels');

    for ($i = 0; $i < 59; $i++) {
        liveWebOne($this, $president, liveWire('user.'.$president->id))->assertOk();
    }

    liveWebOne($this, $president, liveWire('user.'.$president->id))->assertStatus(429);

    // A bearer header of the session's own invention does not start a fresh bucket.
    app('auth')->forgetGuards();
    $this->actingAs($president)->withToken('invented-'.bin2hex(random_bytes(8)))
        ->postJson(liveWebDoor(null), ['socket_id' => LiveAuthFixture::SOCKET, 'channel_name' => liveWire('user.'.$president->id)])
        ->assertStatus(429);
    $this->withoutToken();
    FacilitiesFixture::boot();

    // Per principal: somebody else at the same address is not held back.
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    liveWebOne($this, $treasurer, liveWire('user.'.$treasurer->id))->assertOk();
});

it('registers the three live limiters, each per principal: grants and catch-up sixty a minute, row refetches six hundred', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $request = Request::create('/live/auth', 'POST');
    $request->setUserResolver(fn () => $president);

    foreach (['live-auth' => 60, 'live-catchup' => 60, 'live-rows' => 600] as $name => $perMinute) {
        $limiter = RateLimiter::limiter($name);

        expect($limiter)->not->toBeNull($name.' is not registered');

        $limit = $limiter($request);

        expect($limit)->toBeInstanceOf(Limit::class)
            ->and($limit->maxAttempts)->toBe($perMinute, $name)
            ->and($limit->decaySeconds)->toBe(60, $name)
            ->and($limit->key)->toBe($name.':u-'.$president->id, $name);
    }
});

it('runs the session door inside the web group\'s account check, without the page stack', function () {
    $route = Route::getRoutes()->getByName('live.auth');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toBe(['POST']);

    $middleware = app(Router::class)->gatherRouteMiddleware($route);

    expect($middleware)->toContain(EndSessionOfInactiveAccount::class)
        ->and($middleware)->toContain(StartSession::class)
        ->and($middleware)->toContain(PreventRequestForgery::class)
        ->and($middleware)->toContain(Authenticate::class)
        ->and($middleware)->toContain(ThrottleRequests::class.':live-auth')
        ->and($middleware)->not->toContain(HandleInertiaRequests::class)
        ->and($middleware)->not->toContain(AddLinkHeadersForPreloadedAssets::class);
});

it('writes each grant down once per principal, channel and socket, and nothing for a refusal', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $t = $this->estate;
    $other = LiveAuthFixture::bareEstate();

    $ask = [liveWire('user.'.$president->id), liveWire("estate.{$t}.security"), liveWire("estate.{$other}.security")];

    liveWebBatch($this, $president, $ask)->assertOk();
    $first = DB::connection('mysql')->table('live_grants')->where('principal', 'u-'.$president->id)->orderBy('channel')->get();

    liveWebBatch($this, $president, $ask)->assertOk();
    $second = DB::connection('mysql')->table('live_grants')->where('principal', 'u-'.$president->id)->orderBy('channel')->get();

    expect($first->pluck('channel')->all())->toBe(["estate.{$t}.security", 'user.'.$president->id])
        ->and($first->pluck('socket_id')->unique()->all())->toBe([LiveAuthFixture::SOCKET])
        ->and($second)->toHaveCount(2)
        ->and((string) $second->first()->granted_at >= (string) $first->first()->granted_at)->toBeTrue();
});

it('signs with the reverb application\'s own credentials, never the broadcast manager, and says so when there are none', function () {
    // BROADCAST_CONNECTION=null in phpunit.xml: no broadcaster holding these credentials is in play.
    expect(config('broadcasting.default'))->toBeIn([null, 'null'], 'the suite\'s broadcaster admits everything, so a true HMAC here cannot have come from it');

    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    expect(liveWebGranted($this, $president, [liveWire('user.'.$president->id)]))->toBe([liveWire('user.'.$president->id)]);

    config(['broadcasting.connections.reverb.secret' => null]);

    liveWebBatch($this, $president, [liveWire('user.'.$president->id)])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'live_unavailable');
});

it('turns away a request that is not a grant request, before any channel is looked at', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $wire = liveWire('user.'.$president->id);

    foreach ([
        ['socket_id' => 'abc', 'channel_names' => [$wire]],
        ['socket_id' => '1234.5678.9', 'channel_names' => [$wire]],
        ['socket_id' => '1234.5678x', 'channel_names' => [$wire]],
        ['socket_id' => '1234', 'channel_name' => $wire],
        ['channel_names' => [$wire]],
        ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => []],
        ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => ['a' => $wire]],
        ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => [['nested']]],
        ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => array_fill(0, 201, $wire)],
        ['socket_id' => LiveAuthFixture::SOCKET],
    ] as $i => $body) {
        app('auth')->forgetGuards();
        expect($this->actingAs($president)->postJson('/live/auth', $body)->status())->toBe(422, 'case '.$i);
    }
});

it('refuses client events on the socket unless the server is told otherwise', function () {
    expect((string) file_get_contents(config_path('reverb.php')))
        ->toContain("'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'none')");
});

it('refuses the legacy alerts channel as a presence channel at /broadcasting/auth, and still admits it as a private one', function () {
    config(['broadcasting.connections.liveauthlegacy' => [
        'driver' => 'pusher',
        'key' => LiveAuthFixture::KEY,
        'secret' => LiveAuthFixture::SECRET,
        'app_id' => LiveAuthFixture::APP_ID,
        'options' => ['host' => '127.0.0.1', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false],
    ], 'broadcasting.default' => 'liveauthlegacy']);

    Broadcast::purge('liveauthlegacy');

    require base_path('routes/channels.php');

    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    foreach (['private-' => 200, 'presence-' => 403] as $prefix => $status) {
        app('auth')->forgetGuards();

        $response = $this->actingAs($president)->post('/broadcasting/auth', [
            'channel_name' => $prefix.'estate.'.$this->estate.'.alerts',
            'socket_id' => LiveAuthFixture::SOCKET,
        ]);

        expect($response->status())->toBe($status, $prefix.'estate.{t}.alerts');
    }
});
