<?php

declare(strict_types=1);

use App\Live\Access\Evictor;
use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\Relay\Breaker;
use App\Live\Relay\Publisher;
use App\Live\Relay\Relay;
use App\Live\Relay\Waker;
use App\Live\Stream;
use Illuminate\Support\Facades\DB;
use Tests\Live\Support\FakeBell;
use Tests\Live\Support\FakeClock;
use Tests\Live\Support\FakeTransport;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;

/*
|--------------------------------------------------------------------------
| A permission change takes effect in the open session (DESIGN.md §1.7, §7.1)
|--------------------------------------------------------------------------
|
| An access change is recorded HELD, so it cannot be published before the permission cache that
| decides it has been flushed; once released it is published on the principal's own channel, and
| 250 ms later every socket that principal holds is closed, so each reconnects and is authorised
| again under the new rules. The message goes first because a socket closed first would never
| hear why.
|
*/

beforeEach(function () {
    LiveLog::reset();
    app(Breaker::class)->close();
    app()->instance(Waker::class, new SpyWaker);

    $this->clock = new FakeClock;
    $this->transport = new FakeTransport;
    $this->evictor = new Evictor($this->transport, $this->clock);
    $this->relay = new Relay(
        new Publisher($this->transport, app(Breaker::class), $this->evictor, $this->clock),
        new FakeBell($this->clock),
        $this->evictor,
        $this->clock,
        fn (): array => [],
    );

    // The lock is the MySQL server's, not this database's (see RelayTest).
    expect(Relay::acquireLock())->toBeTrue('Another relay on this MySQL server holds '.Relay::LOCK.' — stop it (a local server\'s live:relay) and run again');
});

afterEach(function () {
    Relay::releaseLock();
});

it('names the principal of a person\'s own channel, and nobody for any other channel', function () {
    expect(Evictor::principalOf('user.12'))->toBe('u-12')
        ->and(Evictor::principalOf('guard.phoenix.5'))->toBe('g-5')
        ->and(Evictor::principalOf('resident.phoenix.9'))->toBe('r-9')
        ->and(Evictor::principalOf('household.phoenix.9'))->toBeNull()
        ->and(Evictor::principalOf('estate.phoenix.security'))->toBeNull()
        ->and(Evictor::principalOf('user.0'))->toBeNull()
        ->and(Evictor::principalOf('user.12.extra'))->toBeNull();
});

it('publishes a held access change only after LiveBus::release, and closes the principal\'s sockets 250 ms after publishing it', function () {
    $t = LiveLog::ESTATE;
    $event = new ProbeEvent(
        Stream::central($t),
        [new Audience([Channels::user(12), Channels::guard($t, 5), Channels::resident($t, 9)], summary: ['effect' => 'reduced'])],
        'access.changed',
        'role-7',
        isHeld: true,
        about: 'access',
    );

    DB::connection('mysql')->transaction(fn () => LiveBus::record($event));

    // Held: the relay sees it and leaves it, however often it looks.
    foreach (range(1, 3) as $turn) {
        $this->relay->tick();
    }

    expect($this->transport->envelopes())->toBe([])
        ->and($this->transport->terminated)->toBe([]);

    // RoleEditor has flushed the permission cache: release.
    LiveBus::release(LiveBus::idsOf($event), Stream::central($t));
    $this->relay->tick();

    expect(collect($this->transport->envelopes())->pluck('t')->unique()->all())->toBe(['access.changed'])
        ->and($this->transport->envelopes(Channels::user(12))[0]['sum'])->toBe(['effect' => 'reduced'])
        // The message's head start: nobody's socket is closed in the same breath.
        ->and($this->transport->terminated)->toBe([])
        ->and(collect($this->evictor->pending())->keys()->sort()->values()->all())->toBe(['g-5', 'r-9', 'u-12']);

    $this->clock->advance(Evictor::DELAY_SECONDS + 0.01);
    $this->relay->tick();

    expect(collect($this->transport->terminated)->sort()->values()->all())->toBe(['g-5', 'r-9', 'u-12'])
        ->and($this->evictor->pending())->toBe([]);

    // Once only.
    $this->clock->advance(5);
    $this->relay->tick();

    expect($this->transport->terminated)->toHaveCount(3);
});

it('evicts nobody for an event that is not an access change', function () {
    DB::connection('mysql')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::central(), [Channels::user(12)], 'note')));

    $this->relay->tick();
    $this->clock->advance(1);
    $this->relay->tick();

    expect($this->transport->envelopes(Channels::user(12)))->toHaveCount(1)
        ->and($this->transport->terminated)->toBe([]);
});

it('releases a held access change by the 10-second backstop when the process died before its release, and evicts then', function () {
    $event = new ProbeEvent(Stream::central(), [new Audience([Channels::user(44)])], 'access.changed', 'orphan', isHeld: true, about: 'access');
    DB::connection('mysql')->transaction(fn () => LiveBus::record($event));

    $this->relay->tick();
    expect($this->transport->envelopes(Channels::user(44)))->toBe([]);

    LiveLog::moveClock('mysql', 11);
    $this->clock->advance(11);
    $this->relay->tick();

    expect($this->transport->envelopes(Channels::user(44)))->toHaveCount(1);

    $this->clock->advance(0.3);
    $this->relay->tick();

    LiveLog::restoreClock('mysql');

    expect($this->transport->terminated)->toBe(['u-44']);
});
