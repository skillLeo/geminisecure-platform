<?php

declare(strict_types=1);

use App\Live\Access\Evictor;
use App\Live\Channels;
use App\Live\Events\ResyncLive;
use App\Live\LiveBus;
use App\Live\Relay\Breaker;
use App\Live\Relay\Clock;
use App\Live\Relay\Publisher;
use App\Live\Relay\Relay;
use App\Live\Relay\Transport;
use App\Live\Relay\Wake;
use App\Live\Relay\Waker;
use App\Live\Stream;
use App\Live\Tick\RuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Redis;
use Tests\Live\Fixtures\Tick\ProbeTickRule;
use Tests\Live\Support\FakeBell;
use Tests\Live\Support\FakeClock;
use Tests\Live\Support\FakeTransport;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;

/*
|--------------------------------------------------------------------------
| The relay's loop: who is drained when, and what it never lets one stream do to another (§1.5)
|--------------------------------------------------------------------------
|
| Driven by a clock and a bell the test holds, so "within a second of its time", "every 10 s" and
| "the 30-second sweep" are checked as the relay counts them rather than by waiting for them.
|
*/

beforeEach(function () {
    LiveLog::reset();
    app(Breaker::class)->close();
    app()->instance(Waker::class, new SpyWaker);

    $this->clock = new FakeClock;
    $this->bell = new FakeBell($this->clock);
    $this->transport = new FakeTransport;
    $this->estates = [];

    $this->relay = fn (): Relay => new Relay(
        new Publisher($this->transport, app(Breaker::class), $this->evictor = new Evictor($this->transport, $this->clock), $this->clock),
        $this->bell,
        $this->evictor,
        $this->clock,
        fn (): array => $this->estates,
    );

    // MySQL's named locks are the SERVER's, not a database's: a relay running for any copy of the app on this MySQL (a
    // local server's live:relay) holds this one, and these tests cannot run beside it (M22's full suite).
    expect(Relay::acquireLock())->toBeTrue('Another relay on this MySQL server holds '.Relay::LOCK.' — stop it (a local server\'s live:relay) and run again');
});

afterEach(function () {
    Relay::releaseLock();
    RuleRegistry::reset();
    ProbeTickRule::$windows = [];
    ProbeTickRule::$failNext = false;

    if (tenancy()->initialized) {
        LiveLog::restoreClock('tenant');
    }

    LiveLog::leave();
    DB::purge('live_second');
});

function relayRecordCentral(string $id, array $channels = []): void
{
    DB::connection('mysql')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::central(), $channels === [] ? [Channels::user(7)] : $channels, $id)));
}

function relayPublished(string $id, string $connection = 'mysql'): bool
{
    return DB::connection($connection)->table('live_events')->where('entity_id', $id)->whereNotNull('published_at')->exists();
}

it('drains a rung stream at once, the central one every second unrung, and an estate only at its 30-second sweep', function () {
    $this->estates = ['e:'.LiveLog::ESTATE];
    LiveLog::enter();
    $relay = ($this->relay)();

    // The first turn sweeps everything once.
    expect($relay->tick())->toBe(['c', 'e:'.LiveLog::ESTATE]);

    DB::connection('tenant')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::estate(LiveLog::ESTATE), [Channels::estateFeed(LiveLog::ESTATE, 'facilities')], 'estate-row')));

    // Unrung, the estate waits for its sweep while the central stream is drained every second.
    $drained = [];

    foreach (range(1, 5) as $turn) {
        $drained[] = $relay->tick();
    }

    expect(collect($drained)->flatten()->unique()->values()->all())->toBe(['c'])
        ->and(relayPublished('estate-row', 'tenant'))->toBeFalse()
        ->and(max($this->bell->waits))->toBeLessThanOrEqual(1.0);

    // Rung, it is drained on the very next turn.
    $this->bell->rings[] = ['e:'.LiveLog::ESTATE];

    expect($relay->tick())->toContain('e:'.LiveLog::ESTATE)
        ->and(relayPublished('estate-row', 'tenant'))->toBeTrue();

    // And the sweep comes round on its own 30 s after the last drain.
    $this->clock->advance(30);

    expect($relay->tick())->toContain('e:'.LiveLog::ESTATE);
});

it('publishes a scheduled row within a second of its time, and not before, though its estate is only swept every 30 s', function () {
    $this->estates = ['e:'.LiveLog::ESTATE];
    LiveLog::enter();

    DB::connection('tenant')->transaction(fn () => LiveBus::record(new ProbeEvent(
        Stream::estate(LiveLog::ESTATE),
        [new App\Live\Audience([Channels::estateFeed(LiveLog::ESTATE, 'facilities')])],
        id: 'patrol-due',
        at: Carbon::now()->addSeconds(5),
    )));

    $relay = ($this->relay)();
    $relay->tick();

    $startedAt = $this->clock->now();

    // Four seconds on, by the relay's clock and the database's: not due, not published.
    while ($this->clock->now() - $startedAt < 4) {
        LiveLog::moveClock('tenant', $this->clock->now() - $startedAt);
        $relay->tick();
    }

    expect(relayPublished('patrol-due', 'tenant'))->toBeFalse();

    // Its time comes: the relay's timer drains the estate for it within the second.
    $published = null;

    while ($this->clock->now() - $startedAt < 7) {
        LiveLog::moveClock('tenant', $this->clock->now() - $startedAt);

        if (in_array('e:'.LiveLog::ESTATE, $relay->tick(), true) && relayPublished('patrol-due', 'tenant')) {
            $published = $this->clock->now() - $startedAt;

            break;
        }
    }

    expect($published)->not->toBeNull()
        ->and($published)->toBeGreaterThanOrEqual(5.0)
        ->and($published)->toBeLessThan(6.0);
});

it('skips an estate that cannot be opened, says so once, and drains the central stream and every other estate', function () {
    Exceptions::fake();

    LiveLog::enter();
    DB::connection('tenant')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::estate(LiveLog::ESTATE), [Channels::estateFeed(LiveLog::ESTATE, 'facilities')], 'good-estate')));
    LiveLog::leave();

    relayRecordCentral('central-row');

    // First in the list, so a relay that stopped at a failure would reach nothing after it.
    $this->estates = ['e:nosuchestate', 'e:'.LiveLog::ESTATE];
    $relay = ($this->relay)();

    $relay->tick();

    expect(relayPublished('central-row'))->toBeTrue()
        ->and(tenancy()->initialized)->toBeFalse()
        ->and(array_keys($relay->failing()))->toBe(['e:nosuchestate'])
        ->and(Illuminate\Support\Facades\Cache::store()->get(Relay::FAILING_KEY))->toHaveKey('e:nosuchestate');

    // Opened as it is (LiveLog::enter would empty it first).
    tenancy()->initialize(LiveLog::estate());
    expect(relayPublished('good-estate', 'tenant'))->toBeTrue();
    LiveLog::leave();

    // Another sweep of the broken estate is not another report.
    $this->clock->advance(31);
    $relay->tick();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'could not drain the stream e:nosuchestate'));
});

it('publishes the pulse every 10 s by its own clock, and not in between', function () {
    config(['live.build' => 'a342c56']);
    $relay = ($this->relay)();
    $startedAt = $this->clock->now();

    while ($this->clock->now() - $startedAt < 25) {
        $relay->tick();
    }

    $pulses = collect($this->transport->items())->where('channel', 'live-pulse');

    expect($pulses)->toHaveCount(3)
        ->and($pulses->pluck('data.build')->unique()->all())->toBe(['a342c56']);
});

it('stops when its session no longer holds the lock', function () {
    $relay = ($this->relay)();
    Relay::releaseLock();

    $relay->run(30);

    expect($relay->lostLock())->toBeTrue();

    Relay::acquireLock();
});

it('lets only one relay run: a second waits for the lock and, when it never comes free, exits without having published', function () {
    Relay::releaseLock();

    $second = DB::connection(LiveLog::secondConnection());
    expect((int) $second->selectOne("SELECT GET_LOCK('gs_live_relay', 0) AS got")->got)->toBe(1);

    relayRecordCentral('guarded');

    app()->instance(Clock::class, new FakeClock);
    app()->instance(Transport::class, $this->transport);

    $exit = Artisan::call('live:relay', ['--max-time' => 12]);
    $output = Artisan::output();

    $second->selectOne("SELECT RELEASE_LOCK('gs_live_relay')");

    expect($exit)->toBe(1)
        ->and($output)->toContain('Another live relay holds the lock')
        ->and($output)->toContain('exits without having run')
        ->and($this->transport->calls)->toBe(0)
        ->and(relayPublished('guarded'))->toBeFalse();

    expect(Relay::acquireLock())->toBeTrue();
});

it('runs a rule file dropped into the tick directory without anybody editing LiveTick, over the window since the last tick', function () {
    RuleRegistry::useDirectory(base_path('tests/Live/Fixtures/Tick'), 'Tests\\Live\\Fixtures\\Tick');

    expect(Artisan::call('live:tick', ['--stream' => ['c']]))->toBe(0);

    $first = ProbeTickRule::$windows[0];
    $tickedAt = (string) DB::connection('mysql')->table('live_streams')->value('ticked_at');

    // A first tick looks back one minute, and the stream remembers where it got to.
    expect(ProbeTickRule::$windows)->toHaveCount(1)
        ->and($first['stream'])->toBe('c')
        ->and(Carbon::parse($first['from'])->diffInSeconds(Carbon::parse($first['to'])))->toEqual(60)
        ->and(Carbon::parse($tickedAt)->format('Y-m-d H:i:s'))->toBe(Carbon::parse($first['to'])->format('Y-m-d H:i:s'));

    // The next tick starts where that one ended: no minute is skipped, none handed twice.
    LiveLog::moveClock('mysql', 90);
    Artisan::call('live:tick', ['--stream' => ['c']]);

    expect(ProbeTickRule::$windows[1]['from'])->toBe($first['to']);

    // A failed rule leaves the window where it was, so the next tick hands it over again.
    ProbeTickRule::$failNext = true;
    LiveLog::moveClock('mysql', 150);

    Exceptions::fake();
    expect(Artisan::call('live:tick', ['--stream' => ['c']]))->toBe(1);

    Artisan::call('live:tick', ['--stream' => ['c']]);

    expect(ProbeTickRule::$windows[3]['from'])->toBe(ProbeTickRule::$windows[2]['from']);

    LiveLog::restoreClock('mysql');
});

it('opens no estate at all when no rule is for estates', function () {
    RuleRegistry::useDirectory(base_path('tests/Live/Fixtures/Tick'), 'Tests\\Live\\Fixtures\\Tick');

    expect(RuleRegistry::for('estate'))->toBe([])
        ->and(Artisan::call('live:tick'))->toBe(0)
        ->and(ProbeTickRule::$windows)->toHaveCount(1)
        ->and(tenancy()->initialized)->toBeFalse();
});

it('records live.resync on the estate\'s own stream and the central one, naming only that estate\'s channels', function () {
    LiveLog::estate();
    LiveLog::estate(LiveLog::OTHER_ESTATE);
    LiveLog::enter();
    LiveLog::leave();

    expect(Artisan::call('live:resync', ['tenant' => LiveLog::ESTATE]))->toBe(0);

    $central = LiveLog::events()->firstWhere('type', ResyncLive::TYPE);

    tenancy()->initialize(LiveLog::estate());
    $estate = LiveLog::events('tenant')->firstWhere('type', ResyncLive::TYPE);
    LiveLog::leave();

    $estateChannels = json_decode((string) $estate->channels, true);
    $centralChannels = json_decode((string) $central->channels, true);

    expect($estate->entity_id)->toBe(LiveLog::ESTATE)
        ->and($estateChannels)->toContain(Channels::estateFeed(LiveLog::ESTATE, 'security'))
        ->and($estateChannels)->toContain(Channels::community(LiveLog::ESTATE))
        ->and($centralChannels)->toContain(Channels::geminiFeed(LiveLog::ESTATE, 'dispatch'))
        ->and($centralChannels)->not->toContain('gemini.settings')
        ->and(json_encode([$estateChannels, $centralChannels]))->not->toContain(LiveLog::OTHER_ESTATE);

    expect(Artisan::call('live:resync', ['tenant' => 'nosuchestate']))->toBe(1);
});

it('rings the Redis bell and hears it, when Redis is there to ring', function () {
    config(['live.wake' => 'redis']);

    try {
        Redis::connection((string) config('live.redis_connection'))->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis answers on this machine; the bell is proved on the server by 80-live.sh and live:health.');
    }

    $wake = new Wake(new FakeClock);
    $redis = Redis::connection((string) config('live.redis_connection'));
    $redis->del(Wake::DIRTY, Wake::BELL);

    $wake->poke(Stream::central());
    $wake->poke(Stream::estate(LiveLog::ESTATE));
    $wake->poke(Stream::central());

    expect((int) $redis->llen(Wake::BELL))->toBe(1);

    $heard = $wake->wait(1.0);
    sort($heard);

    expect($heard)->toBe(['c', 'e:'.LiveLog::ESTATE])
        ->and($wake->wait(0.1))->toBe([]);
});

it('rings nothing with no bell, and looks at every stream every 250 ms instead', function () {
    config(['live.wake' => 'none']);
    $clock = new FakeClock;
    $wake = new Wake($clock);

    $wake->poke(Stream::central());
    $before = $clock->now();

    expect($wake->wait(1.0))->toBe(['*'])
        ->and($clock->now() - $before)->toBe(0.25);
});
