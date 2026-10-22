<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\Principal;
use App\Live\ChannelName;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\PersonalAccessToken;
use App\Models\ResidentAccount;
use App\Support\SessionWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Live\Support\LiveAuthFixture;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| WHAT A HANDSET MAY SUBSCRIBE TO, AND WHAT IT MAY NOT (DESIGN §3.1–§3.7)
|--------------------------------------------------------------------------
|
| `live.config` lists the channels a guard's or a resident's handset holds, and
| `live.auth` signs them — the array form only — with the same rules as the API: the
| token's own abilities, the estate `ResolveDevice` opened, the household
| `ResidentAccounts::home()` names. Every answer is held to its catalogue entry.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    LiveAuthFixture::credentials();

    $this->t = FacilitiesFixture::ESTATE;

    // Two households of this file's own, in the fixture's scratch phase.
    foreach (['h1' => 'Lot 991', 'h2' => 'Lot 992'] as $key => $reference) {
        $unit = FacilitiesFixture::scratchUnit($reference);
        $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => $reference.' household', 'access_restricted' => false]);
        $resident = Resident::query()->firstOrCreate(
            ['household_id' => $household->id, 'full_name' => 'Live '.$reference],
            ['relationship' => 'owner', 'is_primary' => true],
        );

        $this->{$key} = ['unit' => $unit, 'household' => $household, 'resident' => $resident];
    }

    $this->phaseKey = ChannelName::phaseKey((string) $this->h1['unit']->block);
});

afterEach(function () {
    tenancy()->end();

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** @param  list<string>  $wires */
function liveTokenAuth(TestCase $test, string $token, array $wires): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->postJson('/api/v1/live/auth', [
        'socket_id' => LiveAuthFixture::SOCKET,
        'channel_names' => $wires,
    ]);

    FacilitiesFixture::boot();

    return $response;
}

function liveTokenConfig(TestCase $test, string $token): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->getJson('/api/v1/live/config');

    FacilitiesFixture::boot();

    return $response;
}

/**
 * The wire names granted, each a true signature for this principal; every refusal
 * the one shape.
 *
 * @param  list<string>  $wires
 * @return list<string>
 */
function liveTokenGranted(TestCase $test, string $token, string $principalKey, array $wires): array
{
    $response = liveTokenAuth($test, $token, $wires)->assertOk();

    ApiContract::assertMatches($response, 'live.auth');

    $data = LiveAuthFixture::channelData($principalKey);
    $granted = [];

    expect(collect($response->json('channels'))->pluck('name')->all())->toBe(array_values(array_unique($wires)), 'answered in the order asked');

    foreach ((array) $response->json('channels') as $item) {
        if ($item['refused']) {
            expect($item)->toBe(['name' => $item['name'], 'auth' => null, 'channel_data' => null, 'streams' => [], 'refused' => true]);

            continue;
        }

        expect($item['channel_data'])->toBe($data)
            ->and($item['auth'])->toBe(LiveAuthFixture::expectedAuth($item['name'], $data))
            ->and($item['streams'])->toBe(ChannelName::fromWire($item['name'])?->streams());

        $granted[] = $item['name'];
    }

    return $granted;
}

function liveTokenWire(string $name): string
{
    return ChannelName::WIRE_PREFIX.$name;
}

/** An active account of household h1 (or another), with its token. */
function liveTokenResident(TestCase $test, string $household = 'h1', array $attributes = []): ResidentAccount
{
    return LiveAuthFixture::account($test->t, [
        'status' => ResidentAccount::ACTIVE,
        'resident_id' => $test->{$household}['resident']->id,
        'unit_id' => $test->{$household}['unit']->id,
        'claim_id' => 424242,
        ...$attributes,
    ]);
}

it('signs one grant per family a handset can hold, each a true HMAC over socket, channel and principal', function () {
    $t = $this->t;
    $guard = LiveAuthFixture::guard($t);

    $guardWires = [
        liveTokenWire("guard.{$t}.{$guard->id}"),
        liveTokenWire("estate.{$t}.guards"),
        liveTokenWire("estate.{$t}.passes"),
        liveTokenWire("approval.{$t}.77"),
    ];

    expect(liveTokenGranted($this, LiveAuthFixture::token($guard), 'g-'.$guard->id, $guardWires))->toBe($guardWires);

    $account = liveTokenResident($this);
    $h1 = $this->h1['household']->id;

    $residentWires = [
        liveTokenWire("resident.{$t}.{$account->id}"),
        liveTokenWire("household.{$t}.{$h1}"),
        liveTokenWire("community.{$t}"),
        liveTokenWire("phase.{$t}.{$this->phaseKey}"),
        liveTokenWire("claim.{$t}.424242"),
    ];

    expect(liveTokenGranted($this, LiveAuthFixture::token($account), 'r-'.$account->id, $residentWires))->toBe($residentWires);
});

it('grants the household, community and phase channels only at a level that reads everything they carry, and a person\'s own at every level', function () {
    $t = $this->t;
    $h1 = $this->h1['household']->id;

    $shared = [
        liveTokenWire("household.{$t}.{$h1}"),
        liveTokenWire("community.{$t}"),
        liveTokenWire("phase.{$t}.{$this->phaseKey}"),
    ];

    // Member reads passes, dues, the gate, notices, meetings and elections; a Dependent and a Helper do not
    // (AppMatrix::LEVELS), and one channel cannot carry some of that to some of its audience.
    foreach ([AppMatrix::MEMBER => true, AppMatrix::DEPENDENT => false, AppMatrix::HELPER => false] as $level => $holdsShared) {
        $person = Resident::query()->firstOrCreate(
            ['household_id' => $h1, 'full_name' => 'Live level '.$level],
            ['relationship' => 'relative', 'is_primary' => false],
        );
        $person->forceFill(['access_level' => $level, 'is_primary' => false])->save();

        $account = liveTokenResident($this, 'h1', ['resident_id' => $person->id]);
        $token = LiveAuthFixture::token($account);
        $own = [liveTokenWire("resident.{$t}.{$account->id}"), liveTokenWire("claim.{$t}.424242")];

        expect(liveTokenGranted($this, $token, 'r-'.$account->id, [...$own, ...$shared]))
            ->toBe($holdsShared ? [...$own, ...$shared] : $own, "at the {$level} level");

        // live.config offers exactly what the grant gives: never a channel the door would refuse.
        expect(collect(liveTokenConfig($this, $token)->assertOk()->json('channels'))->pluck('name')->map(fn (string $name): string => liveTokenWire($name))->all())
            ->toBe($holdsShared ? [$own[0], $own[1], ...$shared] : $own, "live.config at the {$level} level");
    }

    // A register entry that cannot be found is the narrowest level (AccessLevels::of), never a wider one.
    $orphan = liveTokenResident($this, 'h1', ['resident_id' => 987654321]);

    expect(liveTokenGranted($this, LiveAuthFixture::token($orphan), 'r-'.$orphan->id, $shared))->toBe([]);
});

it('lists in live.config exactly the channels the handset holds, with the socket\'s key, port and scheme and no host', function () {
    $t = $this->t;
    $guard = LiveAuthFixture::guard($t);
    $token = LiveAuthFixture::token($guard);

    $config = liveTokenConfig($this, $token)->assertOk();
    ApiContract::assertMatches($config, 'live.config');

    expect(array_keys((array) $config->json('ws')))->toBe(['key', 'port', 'scheme', 'path'])
        ->and($config->json('ws.key'))->toBe(LiveAuthFixture::KEY)
        ->and($config->json('ws.scheme'))->toBeIn(['http', 'https'])
        ->and($config->json('ws.port'))->toBeInt()
        ->and($config->json('pulse'))->toBe('live-pulse')
        ->and($config->json('principal'))->toBe('g-'.$guard->id)
        ->and((string) $config->getContent())->not->toContain('"host"')
        ->and($config->json('channels'))->toBe([
            ['name' => "guard.{$t}.{$guard->id}", 'streams' => ['c', 'e']],
            ['name' => "estate.{$t}.guards", 'streams' => ['c', 'e']],
            ['name' => "estate.{$t}.passes", 'streams' => ['e']],
        ]);

    // Everything listed is granted: the list cannot offer what the door refuses.
    $listed = array_map(fn (array $c): string => liveTokenWire($c['name']), (array) $config->json('channels'));
    expect(liveTokenGranted($this, $token, 'g-'.$guard->id, $listed))->toBe($listed);

    $account = liveTokenResident($this);
    $h1 = $this->h1['household']->id;

    expect(collect(liveTokenConfig($this, LiveAuthFixture::token($account))->assertOk()->json('channels'))->pluck('name')->all())->toBe([
        "resident.{$t}.{$account->id}",
        "claim.{$t}.424242",
        "household.{$t}.{$h1}",
        "community.{$t}",
        "phase.{$t}.{$this->phaseKey}",
    ]);

    $pending = LiveAuthFixture::account($t, ['claim_id' => 515151]);

    expect(collect(liveTokenConfig($this, LiveAuthFixture::token($pending))->assertOk()->json('channels'))->pluck('name')->all())
        ->toBe(["resident.{$t}.{$pending->id}", "claim.{$t}.515151"]);
});

it('refuses a guard another estate\'s channels, another guard\'s own, the security feed and every household channel', function () {
    $t = $this->t;
    $other = LiveAuthFixture::twinEstate();
    $guard = LiveAuthFixture::guard($t);
    $colleague = LiveAuthFixture::guard($t);
    $elsewhere = LiveAuthFixture::guard($other);
    $account = liveTokenResident($this);
    $h1 = $this->h1['household']->id;
    $token = LiveAuthFixture::token($guard);

    expect(liveTokenGranted($this, $token, 'g-'.$guard->id, [
        liveTokenWire("estate.{$other}.guards"),
        liveTokenWire("estate.{$other}.passes"),
        liveTokenWire("guard.{$other}.{$guard->id}"),
        liveTokenWire("guard.{$other}.{$elsewhere->id}"),
        liveTokenWire("approval.{$other}.77"),
        liveTokenWire("guard.{$t}.{$colleague->id}"),
        liveTokenWire("guard.{$t}.987654321"),
        liveTokenWire("estate.{$t}.security"),
        liveTokenWire("estate.{$t}.facilities"),
        liveTokenWire("household.{$t}.{$h1}"),
        liveTokenWire("resident.{$t}.{$account->id}"),
        liveTokenWire("community.{$t}"),
        liveTokenWire("phase.{$t}.{$this->phaseKey}"),
        liveTokenWire("claim.{$t}.424242"),
        liveTokenWire('gemini.dispatch'),
        liveTokenWire("gemini.{$t}.dispatch"),
        liveTokenWire('user.'.$guard->id),
        'private-estate.'.$t.'.alerts',
        'presence-live.guard.'.$t.'.'.$guard->id,
    ]))->toBe([]);

    // Another guard's channel and one nobody has are refused alike.
    $foreign = liveTokenAuth($this, $token, [liveTokenWire("guard.{$t}.{$colleague->id}")])->json('channels.0');
    $missing = liveTokenAuth($this, $token, [liveTokenWire("guard.{$t}.987654321")])->json('channels.0');

    expect(array_diff_key($foreign, ['name' => true]))->toBe(array_diff_key($missing, ['name' => true]));
});

it('asks the token for the ability each channel carries', function () {
    $t = $this->t;
    $guard = LiveAuthFixture::guard($t);
    $alertsRead = AppMatrix::ability('alerts', AppMatrix::READ);
    $gateRead = AppMatrix::ability('gate', AppMatrix::READ);

    $wires = [
        liveTokenWire("guard.{$t}.{$guard->id}"),
        liveTokenWire("estate.{$t}.passes"),
        liveTokenWire("estate.{$t}.guards"),
        liveTokenWire("approval.{$t}.5"),
    ];

    expect(liveTokenGranted($this, LiveAuthFixture::token($guard, [$alertsRead]), 'g-'.$guard->id, $wires))
        ->toBe([liveTokenWire("guard.{$t}.{$guard->id}")])
        ->and(liveTokenGranted($this, LiveAuthFixture::token($guard, [$alertsRead, $gateRead]), 'g-'.$guard->id, $wires))
        ->toBe([liveTokenWire("guard.{$t}.{$guard->id}"), liveTokenWire("estate.{$t}.passes"), liveTokenWire("approval.{$t}.5")]);

    // A token without the door's own ability does not reach the door.
    app('auth')->forgetGuards();
    $this->withToken(LiveAuthFixture::token($guard, [$gateRead]))
        ->postJson('/api/v1/live/auth', ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => $wires])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'missing_ability');
    FacilitiesFixture::boot();
});

it('refuses a resident another household, its own household at another estate, and another estate\'s community', function () {
    $t = $this->t;
    $other = LiveAuthFixture::twinEstate();
    $account = liveTokenResident($this);
    $neighbour = liveTokenResident($this, 'h2', ['claim_id' => 434343]);
    $h1 = $this->h1['household']->id;
    $h2 = $this->h2['household']->id;
    $token = LiveAuthFixture::token($account);

    expect(liveTokenGranted($this, $token, 'r-'.$account->id, [
        liveTokenWire("household.{$t}.{$h2}"),
        liveTokenWire("household.{$t}.987654321"),
        liveTokenWire("household.{$other}.{$h1}"),
        liveTokenWire("community.{$other}"),
        liveTokenWire("phase.{$other}.{$this->phaseKey}"),
        liveTokenWire("phase.{$t}.".ChannelName::phaseKey('Phase 1')),
        liveTokenWire("resident.{$t}.{$neighbour->id}"),
        liveTokenWire("resident.{$other}.{$account->id}"),
        liveTokenWire("claim.{$t}.434343"),
        liveTokenWire("estate.{$t}.guards"),
        liveTokenWire("estate.{$t}.passes"),
        liveTokenWire("estate.{$t}.security"),
        liveTokenWire("guard.{$t}.{$account->id}"),
        liveTokenWire('user.'.$account->id),
    ]))->toBe([]);

    $foreign = liveTokenAuth($this, $token, [liveTokenWire("household.{$t}.{$h2}")])->json('channels.0');
    $missing = liveTokenAuth($this, $token, [liveTokenWire("household.{$t}.987654321")])->json('channels.0');

    expect(array_diff_key($foreign, ['name' => true]))->toBe(array_diff_key($missing, ['name' => true]));

    // And the other way round: a resident of the other estate on this estate's households.
    $theirs = LiveAuthFixture::account($other, ['status' => ResidentAccount::ACTIVE, 'resident_id' => $this->h1['resident']->id, 'unit_id' => $this->h1['unit']->id]);

    expect(liveTokenGranted($this, LiveAuthFixture::token($theirs), 'r-'.$theirs->id, [
        liveTokenWire("household.{$t}.{$h1}"),
        liveTokenWire("community.{$t}"),
        liveTokenWire("resident.{$t}.{$theirs->id}"),
        liveTokenWire("resident.{$other}.{$theirs->id}"),
    ]))->toBe([liveTokenWire("resident.{$other}.{$theirs->id}")]);
});

it('lets a pending account hold its own channel and its claim\'s, and nothing of a household', function () {
    $t = $this->t;
    $h1 = $this->h1['household']->id;

    // Even pointed at a unit: pending is pending until the estate approves the claim.
    $pending = LiveAuthFixture::account($t, [
        'status' => ResidentAccount::PENDING,
        'resident_id' => $this->h1['resident']->id,
        'unit_id' => $this->h1['unit']->id,
        'claim_id' => 525252,
    ]);

    expect(liveTokenGranted($this, LiveAuthFixture::token($pending), 'r-'.$pending->id, [
        liveTokenWire("resident.{$t}.{$pending->id}"),
        liveTokenWire("claim.{$t}.525252"),
        liveTokenWire("household.{$t}.{$h1}"),
        liveTokenWire("community.{$t}"),
        liveTokenWire("phase.{$t}.{$this->phaseKey}"),
    ]))->toBe([liveTokenWire("resident.{$t}.{$pending->id}"), liveTokenWire("claim.{$t}.525252")]);
});

it('refuses revoked, suspended and deleted accounts at the door, and in the rules for whoever asks without a token', function () {
    $t = $this->t;
    $h1 = $this->h1['household']->id;
    $registry = app(ChannelRegistry::class);

    $cases = [
        'account_revoked' => ['status' => ResidentAccount::REVOKED, 'revoked_at' => now(), 'revoked_reason' => 'Moved out', 'revoked_by' => 'Office'],
        'account_suspended' => ['status' => ResidentAccount::SUSPENDED],
    ];

    foreach ($cases as $code => $attributes) {
        $account = liveTokenResident($this);
        $token = LiveAuthFixture::token($account);
        $account->forceFill($attributes)->save();

        liveTokenAuth($this, $token, [liveTokenWire("resident.{$t}.{$account->id}")])->assertForbidden()->assertJsonPath('error.code', $code);
        liveTokenConfig($this, $token)->assertForbidden()->assertJsonPath('error.code', $code);

        $asked = Principal::server($account->fresh() ?? $account);

        foreach (["resident.{$t}.{$account->id}", "household.{$t}.{$h1}", "community.{$t}", "claim.{$t}.424242", "phase.{$t}.{$this->phaseKey}"] as $name) {
            expect($registry->decide($asked, $name))->toBeFalse($code.' '.$name);
        }
    }

    // An account its owner deleted: suspended and emptied, and refused everything.
    $deleted = liveTokenResident($this);
    $deleted->forceFill(['status' => ResidentAccount::SUSPENDED, 'deleted_at' => now()])->save();

    expect($registry->decide(Principal::server($deleted), "resident.{$t}.{$deleted->id}"))->toBeFalse()
        ->and($registry->decide(Principal::server($deleted), "claim.{$t}.424242"))->toBeFalse();

    // The control: the same questions about an active account are answered yes.
    $active = liveTokenResident($this);
    expect($registry->decide(Principal::server($active), "household.{$t}.{$h1}"))->toBeTrue();
});

it('refuses a suspended guard at the door, and a reassigned guard its old estate', function () {
    $t = $this->t;
    $other = LiveAuthFixture::twinEstate();

    $suspended = LiveAuthFixture::guard($t);
    $token = LiveAuthFixture::token($suspended);
    $suspended->forceFill(['status' => 'suspended'])->save();

    liveTokenAuth($this, $token, [liveTokenWire("guard.{$t}.{$suspended->id}")])->assertForbidden()->assertJsonPath('error.code', 'guard_not_active');

    $moved = LiveAuthFixture::guard($t);
    $movedToken = LiveAuthFixture::token($moved);
    $moved->forceFill(['tenant_id' => $other])->save();

    expect(liveTokenGranted($this, $movedToken, 'g-'.$moved->id, [
        liveTokenWire("guard.{$t}.{$moved->id}"),
        liveTokenWire("estate.{$t}.passes"),
        liveTokenWire("estate.{$t}.guards"),
        liveTokenWire("guard.{$other}.{$moved->id}"),
        liveTokenWire("estate.{$other}.passes"),
    ]))->toBe([liveTokenWire("guard.{$other}.{$moved->id}"), liveTokenWire("estate.{$other}.passes")]);

    expect(Principal::server($suspended->fresh() ?? $suspended)->activeGuard($t))->toBeNull();
});

it('refuses a request with no token, and one whose token is gone', function () {
    $guard = LiveAuthFixture::guard($this->t);
    $token = LiveAuthFixture::token($guard);

    $this->postJson('/api/v1/live/auth', ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => [liveTokenWire('user.1')]])->assertUnauthorized();
    $this->getJson('/api/v1/live/config')->assertUnauthorized();

    // A token idle past the session window is refused as it is on every other endpoint.
    $idle = LiveAuthFixture::guard($this->t);
    $idleToken = LiveAuthFixture::token($idle);
    $stale = now()->subMinutes(SessionWindow::minutes() + 1);
    PersonalAccessToken::query()->whereKey((int) explode('|', $idleToken)[0])->update(['last_used_at' => $stale, 'created_at' => $stale]);

    liveTokenAuth($this, $idleToken, [liveTokenWire("guard.{$this->t}.{$idle->id}")])->assertUnauthorized();

    $guard->tokens()->delete();

    liveTokenAuth($this, $token, [liveTokenWire("guard.{$this->t}.{$guard->id}")])->assertUnauthorized();
});

it('answers the array form only, and turns away what is not a grant request', function () {
    $guard = LiveAuthFixture::guard($this->t);
    $token = LiveAuthFixture::token($guard);
    $wire = liveTokenWire("guard.{$this->t}.{$guard->id}");

    foreach ([
        ['socket_id' => LiveAuthFixture::SOCKET, 'channel_name' => $wire],
        ['socket_id' => 'abc', 'channel_names' => [$wire]],
        ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => []],
        ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => array_fill(0, 201, $wire)],
        ['channel_names' => [$wire]],
    ] as $i => $body) {
        app('auth')->forgetGuards();
        $response = $this->withToken($token)->postJson('/api/v1/live/auth', $body);
        FacilitiesFixture::boot();

        expect($response->status())->toBe(422, 'case '.$i)
            ->and($response->json('error.code'))->toBe('validation_failed');
    }
});

it('writes each handset grant down, counts a batch once, and says so when it cannot sign', function () {
    $t = $this->t;
    $guard = LiveAuthFixture::guard($t);
    $token = LiveAuthFixture::token($guard);

    $wires = [liveTokenWire("guard.{$t}.{$guard->id}"), liveTokenWire("estate.{$t}.passes")];

    for ($n = 1; count($wires) < 200; $n++) {
        $wires[] = liveTokenWire("approval.{$t}.{$n}");
    }

    $response = liveTokenAuth($this, $token, $wires)->assertOk();

    expect(collect($response->json('channels'))->where('refused', false))->toHaveCount(200)
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('60')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('59');

    expect(DB::connection('mysql')->table('live_grants')->where('principal', 'g-'.$guard->id)->count())->toBe(200)
        ->and(DB::connection('mysql')->table('live_grants')->where('principal', 'g-'.$guard->id)->where('channel', "guard.{$t}.{$guard->id}")->value('socket_id'))->toBe(LiveAuthFixture::SOCKET);

    config(['broadcasting.connections.reverb.key' => null]);

    liveTokenAuth($this, $token, [liveTokenWire("guard.{$t}.{$guard->id}")])->assertStatus(503)->assertJsonPath('error.code', 'live_unavailable');
    liveTokenConfig($this, $token)->assertStatus(503)->assertJsonPath('error.code', 'live_unavailable');
});
