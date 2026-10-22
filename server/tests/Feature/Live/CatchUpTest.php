<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Live\Audience;
use App\Live\Auth\Principal;
use App\Live\CatchUp\CatchUp;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\Registry;
use App\Live\Relay\Sequencer;
use App\Live\Relay\Waker;
use App\Live\Stream;
use App\Models\ResidentAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Live\Fixtures\FixtureHydrator;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Catch-up: everything after N, from the log, for exactly who may have it (DESIGN.md §5)
|--------------------------------------------------------------------------
|
| A socket can drop, a phone can sleep, a message can be lost. None of that loses an event, because
| every screen asks the log for everything after the last one it applied, and the log answers from
| what was SEQUENCED, published or not. These prove the answer is exact, bounded, re-authorised
| channel by channel, and says `reset` in every case where "everything after N" cannot be told.
|
*/

beforeEach(function () {
    LiveLog::reset();
    Registry::reset();
    FixtureHydrator::$calls = [];
    FixtureHydrator::$queries = 0;
    app()->instance(Waker::class, new SpyWaker);

    $this->t = LiveLog::ESTATE;
    LiveLog::estate();
    LiveLog::enter();
    LiveLog::leave();

    $this->made = [];
    $this->account = catchUpAccount($this, ['status' => ResidentAccount::ACTIVE]);
    $this->own = Channels::resident($this->t, $this->account->id);
});

afterEach(function () {
    LiveLog::leave();
    Registry::reset();

    foreach ($this->made as [$table, $id]) {
        DB::connection('mysql')->table($table)->where('id', $id)->delete();
    }
});

/**
 * A Resident App account at the live estate, removed again after the test. Made without its own `account.changed`
 * (M18H §6): these tests count the log's sequence numbers, and the only events in it are the ones each one records.
 */
function catchUpAccount(object $test, array $attributes): ResidentAccount
{
    $account = ResidentAccount::withoutEvents(fn (): ResidentAccount => ResidentAccount::query()->create([
        'tenant_id' => LiveLog::ESTATE,
        'channel' => 'email',
        'destination' => 'catchup.'.bin2hex(random_bytes(5)).'@example.com',
        'full_name' => 'Catch Up',
        'verified_at' => now(),
        ...$attributes,
    ]));

    $test->made[] = ['resident_accounts', $account->id];

    return $account;
}

function catchUpCentral(string $id, array $channels, array $audience = []): void
{
    DB::connection('mysql')->transaction(fn () => LiveBus::record(
        new ProbeEvent(Stream::central(LiveLog::ESTATE), [new Audience($channels, ...$audience)], id: $id),
    ));
}

function catchUpEstate(string $id, array $channels): void
{
    tenancy()->initialize(LiveLog::estate());
    DB::connection('tenant')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::estate(LiveLog::ESTATE), $channels, $id)));
    Sequencer::run(Stream::estate(LiveLog::ESTATE));
    LiveLog::leave();
}

/** @param  list<array<string, mixed>>  $channels */
function catchUpAsk(Principal $principal, array $channels): array
{
    return app(CatchUp::class)->answer($principal, array_map(fn (array $c) => [
        'name' => $c['name'], 'c' => $c['c'] ?? null, 'e' => $c['e'] ?? null, 'ep' => $c['ep'] ?? [],
    ], $channels));
}

function catchUpChannel(array $answer, string $name): ?array
{
    return collect($answer['channels'])->firstWhere('name', $name);
}

it('returns exactly the events after the cursor, in seq order, published or not, and the heads to take', function () {
    foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
        catchUpCentral($id, [$this->own]);
    }

    // Another person's channel, interleaved: never in this answer.
    catchUpCentral('elsewhere', [Channels::user(1)]);
    Sequencer::run(Stream::central());
    catchUpEstate('on-estate', [$this->own]);

    // Only the first two ever reached Reverb; catch-up does not care.
    DB::connection('mysql')->table('live_events')->whereIn('entity_id', ['a', 'b'])->update(['published_at' => DB::raw('UTC_TIMESTAMP(6)')]);

    $answer = catchUpAsk(Principal::server($this->account), [['name' => $this->own, 'c' => 2, 'e' => 0]]);
    $mine = catchUpChannel($answer, $this->own);
    $epochC = substr((string) DB::connection('mysql')->table('live_streams')->value('epoch'), 0, 8);

    expect($mine['status'])->toBe('ok')
        ->and(collect($mine['events'])->map(fn ($e) => [$e['s'], $e['q'], $e['i']])->all())->toBe([
            ['c', 3, 'c'], ['c', 4, 'd'], ['c', 5, 'e'], ['e', 1, 'on-estate'],
        ])
        ->and($mine['head'])->toBe(['c' => 6, 'e' => 1])
        ->and($mine['ep']['c'])->toBe($epochC)
        ->and($mine['more'])->toBeFalse()
        ->and($answer['relay']['ok'])->toBeTrue()
        ->and(array_keys($answer))->toBe(['channels', 'relay', 'build']);

    // At the head: nothing, and the same head.
    $again = catchUpChannel(catchUpAsk(Principal::server($this->account), [['name' => $this->own, 'c' => 6, 'e' => 1]]), $this->own);

    expect($again['events'])->toBe([])->and($again['head'])->toBe(['c' => 6, 'e' => 1]);

    // With no cursor at all: the heads and no events, and the client resyncs once.
    $blind = catchUpChannel(catchUpAsk(Principal::server($this->account), [['name' => $this->own]]), $this->own);

    expect($blind['status'])->toBe('ok')->and($blind['events'])->toBe([])->and($blind['head'])->toBe(['c' => 6, 'e' => 1]);
});

it('answers a channel slipped in that this principal may not hold as refused with no events, whatever it names', function () {
    $other = catchUpAccount($this, ['status' => ResidentAccount::ACTIVE]);
    $theirs = Channels::resident($this->t, $other->id);

    catchUpCentral('theirs', [$theirs]);
    catchUpCentral('mine', [$this->own]);
    Sequencer::run(Stream::central());

    $answer = catchUpAsk(Principal::server($this->account), [
        ['name' => $theirs, 'c' => 0],
        ['name' => 'user.1', 'c' => 0],
        ['name' => 'resident.'.$this->t.'.987654321', 'c' => 0],
        ['name' => 'Not.A.Channel', 'c' => 0],
        ['name' => $this->own, 'c' => 0, 'e' => 0],
    ]);

    foreach ([$theirs, 'user.1', 'resident.'.$this->t.'.987654321', 'Not.A.Channel'] as $refused) {
        expect(catchUpChannel($answer, $refused))->toBe(['name' => $refused, 'status' => 'refused']);
    }

    expect(collect(catchUpChannel($answer, $this->own)['events'])->pluck('i')->all())->toBe(['mine'])
        ->and(json_encode($answer))->not->toContain('theirs');
});

it('says reset for a cursor below the floor, above the head, from another epoch, or more than 500 behind', function () {
    foreach (range(1, 4) as $n) {
        catchUpCentral('r'.$n, [$this->own]);
    }

    Sequencer::run(Stream::central());
    DB::connection('mysql')->table('live_streams')->update(['floor_seq' => 2]);

    $ask = fn (array $entry) => catchUpChannel(catchUpAsk(Principal::server($this->account), [['name' => $this->own, 'e' => 0] + $entry]), $this->own);

    expect($ask(['c' => 1])['status'])->toBe('reset')
        ->and($ask(['c' => 2])['status'])->toBe('ok')
        ->and($ask(['c' => 99])['status'])->toBe('reset')
        ->and($ask(['c' => 3, 'ep' => ['c' => 'ZZZZZZZZ']])['status'])->toBe('reset')
        ->and($ask(['c' => 3])['head'])->toBe(['c' => 4, 'e' => 0]);

    $reset = $ask(['c' => 99]);

    expect(array_keys($reset))->toBe(['name', 'status', 'head', 'ep'])
        ->and($reset['head'])->toBe(['c' => 4, 'e' => 0]);

    // 501 behind: the screen resyncs rather than being handed a list the size of a day.
    DB::connection('mysql')->transaction(function () {
        foreach (range(1, 501) as $n) {
            LiveBus::record(new ProbeEvent(Stream::central(LiveLog::ESTATE), [new Audience([$this->own])], id: 'bulk'.$n));
        }
    });
    Sequencer::run(Stream::central());
    Sequencer::run(Stream::central());

    expect($ask(['c' => 4])['status'])->toBe('reset')
        ->and($ask(['c' => 5])['status'])->toBe('ok')
        ->and(count($ask(['c' => 5])['events']))->toBe(500);
});

it('stops at 2,000 events in all and says more, so the client asks again from where it got to', function () {
    // Five channels of 450 events each is 2,250. A registry that lets this principal hold every
    // user channel (its constructor takes a rule per family, for exactly this) makes one answer ask for all five.
    $holdsEveryUser = new class implements App\Live\Auth\Rules\Rule
    {
        public function central(Principal $principal, App\Live\ChannelName $channel): bool
        {
            return true;
        }

        public function needsEstate(): bool
        {
            return false;
        }

        public function estate(Principal $principal, App\Live\ChannelName $channel): bool
        {
            return false;
        }
    };

    $names = array_map(fn (int $u) => Channels::user($u), range(901, 905));

    DB::connection('mysql')->transaction(function () use ($names) {
        foreach (range(1, 450) as $n) {
            LiveBus::record(new ProbeEvent(Stream::central(), [new Audience($names)], id: 'wide'.$n));
        }
    });
    Sequencer::run(Stream::central());

    $catchUp = new CatchUp(new App\Live\Auth\ChannelRegistry([App\Live\ChannelName::USER => $holdsEveryUser]));
    $answer = $catchUp->answer(Principal::server($this->account), array_map(fn ($n) => ['name' => $n, 'c' => 0, 'e' => null, 'ep' => []], $names));

    $counts = collect($answer['channels'])->mapWithKeys(fn ($c) => [$c['name'] => [count($c['events']), $c['more']]])->all();

    expect(array_sum(array_map(fn ($pair) => $pair[0], $counts)))->toBe(CatchUp::TOTAL)
        ->and($counts[$names[0]])->toBe([450, false])
        ->and($counts[$names[3]])->toBe([450, false])
        ->and($counts[$names[4]])->toBe([200, true]);

    // The one cut short ends on its 200th event, and asking again from there brings the rest.
    $short = catchUpChannel($answer, $names[4]);
    $last = end($short['events'])['q'];
    $rest = catchUpChannel($catchUp->answer(Principal::server($this->account), [['name' => $names[4], 'c' => $last, 'e' => null, 'ep' => []]]), $names[4]);

    expect($rest['more'])->toBeFalse()->and(count($rest['events']))->toBe(250);
});

it('merges three events on one record into its latest, hydrated once for every record in the answer', function () {
    Registry::useDirectory(base_path('tests/Live/Fixtures/Events'), 'Tests\\Live\\Fixtures\\Events');

    foreach (['11', '11', '12', '11'] as $id) {
        catchUpCentral($id, [$this->own], ['inline', ['status' => 'open'], 'fixture.ticket']);
    }

    Sequencer::run(Stream::central());

    $mine = catchUpChannel(catchUpAsk(Principal::server($this->account), [['name' => $this->own, 'c' => 0, 'e' => 0]]), $this->own);

    expect(collect($mine['events'])->map(fn ($e) => [$e['i'], $e['q']])->all())->toBe([['12', 3], ['11', 4]])
        ->and($mine['events'][1]['d'])->toBe(['number' => '11', 'lot' => 'Lot 11', 'status' => 'open'])
        ->and($mine['events'][1]['m'])->toBe('inline')
        ->and(FixtureHydrator::$calls)->toHaveCount(1)
        ->and(collect(FixtureHydrator::$calls[0])->sort()->values()->all())->toBe(['11', '12'])
        // The cursor still ends at the head: nothing merged away is asked for again.
        ->and($mine['head']['c'])->toBe(4);
});

it('answers a pending account ok for its own channel and its claim, and refused for the household, community and phase, in one request', function () {
    $pending = catchUpAccount($this, ['status' => ResidentAccount::PENDING, 'claim_id' => 515151]);
    $own = Channels::resident($this->t, $pending->id);
    $claim = Channels::claim($this->t, 515151);

    catchUpCentral('for-pending', [$own]);
    Sequencer::run(Stream::central());

    $answer = catchUpAsk(Principal::server($pending), [
        ['name' => $own, 'c' => 0, 'e' => 0],
        ['name' => $claim, 'e' => 0],
        ['name' => Channels::household($this->t, 9), 'c' => 0, 'e' => 0],
        ['name' => Channels::community($this->t), 'e' => 0],
        ['name' => Channels::phase($this->t, 'Block A'), 'e' => 0],
    ]);

    expect(collect($answer['channels'])->mapWithKeys(fn ($c) => [$c['name'] => $c['status']])->all())->toBe([
        $own => 'ok',
        $claim => 'ok',
        Channels::household($this->t, 9) => 'refused',
        Channels::community($this->t) => 'refused',
        Channels::phase($this->t, 'Block A') => 'refused',
    ])->and(collect(catchUpChannel($answer, $own)['events'])->pluck('i')->all())->toBe(['for-pending']);
});

it('is the handset\'s live.catch_up too: the same answer through the API, held to its catalogue entry, for a pending account', function () {
    // A whole estate this time: the handset door opens the account's estate and reads its claims.
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    $estate = FacilitiesFixture::ESTATE;
    tenancy()->end();

    $pending = ResidentAccount::query()->create([
        'tenant_id' => $estate, 'channel' => 'email', 'destination' => 'catchup.api.'.bin2hex(random_bytes(5)).'@example.com',
        'full_name' => 'Catch Up API', 'verified_at' => now(), 'status' => ResidentAccount::PENDING, 'claim_id' => 616161,
    ]);
    $this->made[] = ['resident_accounts', $pending->id];
    $token = $pending->createToken('catch-up', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    $own = Channels::resident($estate, $pending->id);

    DB::connection('mysql')->transaction(fn () => LiveBus::record(new ProbeEvent(Stream::central($estate), [new Audience([$own])], id: 'api-row')));
    Sequencer::run(Stream::central());

    $response = $this->withToken($token)->postJson('/api/v1/live/catch-up', ['channels' => [
        ['name' => $own, 'c' => 0, 'e' => null],
        ['name' => Channels::household($estate, 9), 'c' => 0],
    ]])->assertOk();

    FacilitiesFixture::boot();
    tenancy()->end();

    ApiContract::assertMatches($response, 'live.catch_up');

    expect($response->json('channels.0.status'))->toBe('ok')
        ->and($response->json('channels.0.events.0.i'))->toBe('api-row')
        ->and($response->json('channels.0.events.0.at'))->toMatch('/\+00:00$/')
        ->and($response->json('channels.1'))->toBe(['name' => Channels::household($estate, 9), 'status' => 'refused'])
        // Its heads are in its body; X-Live-Head rides the reads only (CaptureLiveHeads), and this is a POST.
        ->and($response->json('channels.0.head'))->toHaveKeys(['c', 'e'])
        ->and($response->headers->has('X-Live-Head'))->toBeFalse();

    DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_id', $pending->id)->where('tokenable_type', $pending->getMorphClass())->delete();
});

it('is the console session\'s /live/catch-up: re-authorised for the signed-in user, and a malformed request is a 422', function () {
    $user = User::factory()->create(['console' => 'gemini', 'status' => 'active']);
    $this->made[] = ['users', $user->id];

    DB::connection('mysql')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::central(), [Channels::user($user->id)], 'web-row')));
    Sequencer::run(Stream::central());

    $response = $this->actingAs($user)->postJson('/live/catch-up', ['channels' => [
        ['name' => Channels::user($user->id), 'c' => 0],
        ['name' => Channels::user($user->id + 1), 'c' => 0],
    ]])->assertOk();

    expect($response->json('channels.0.events.0.i'))->toBe('web-row')
        ->and($response->json('channels.1.status'))->toBe('refused');

    foreach ([[], ['channels' => []], ['channels' => [['c' => 1]]], ['channels' => array_fill(0, 201, ['name' => 'user.1'])], ['channels' => [['name' => 'user.1', 'c' => -1]]]] as $bad) {
        expect($this->actingAs($user)->postJson('/live/catch-up', $bad)->status())->toBe(422);
    }

    app('auth')->forgetGuards();
    $this->app['auth']->guard('web')->logout();

    expect($this->postJson('/live/catch-up', ['channels' => [['name' => 'user.1']]])->status())->toBe(401);
});
