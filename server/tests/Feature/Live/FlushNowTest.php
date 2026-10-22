<?php

declare(strict_types=1);

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\Relay\Breaker;
use App\Live\Relay\PanicPublishBudget;
use App\Live\Relay\Publisher;
use App\Live\Relay\PusherTransport;
use App\Live\Relay\Relay;
use App\Live\Relay\Sequencer;
use App\Live\Relay\Transport;
use App\Live\Relay\Waker;
use App\Live\Stream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Live\Support\FakeTransport;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;

/*
|--------------------------------------------------------------------------
| A panic's own flush, inside the budget the fan-out waits on (DESIGN.md §1.6, §13.2)
|--------------------------------------------------------------------------
|
| `alert.raised` goes to the consoles from inside the panic's request, ahead of the guards' push,
| the mail and the texts. So everything it does comes out of their time, and the rule is that it
| may never cost more than today's publish did: one 2 s budget, 0.5 s to connect, shared by every
| publish before the fan-out. These prove the bound holds when Reverb is healthy, busy, down, and
| hung — the last one against a real socket that accepts the connection and never answers.
|
*/

beforeEach(function () {
    LiveLog::reset();
    app(Breaker::class)->close();

    app()->instance(Waker::class, $this->waker = new SpyWaker);
    app()->instance(Transport::class, $this->transport = new FakeTransport);
    app()->forgetInstance(Publisher::class);
});

afterEach(function () {
    app(Breaker::class)->close();
    DB::purge('live_second');
});

/** An urgent alert.raised, committed; the bell its commit rang is forgotten, so a test sees only what flushNow rings. */
function flushRecordUrgent(string $id): ProbeEvent
{
    $event = new ProbeEvent(Stream::central(), [new Audience([Channels::user(1)])], 'alert.raised', $id, isUrgent: true, about: 'alert');
    DB::connection('mysql')->transaction(fn () => LiveBus::record($event));
    test()->waker->pokes = [];

    return $event;
}

function flushRecordPlain(string $id): void
{
    DB::connection('mysql')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::central(), [Channels::user(1)], $id)));
    test()->waker->pokes = [];
}

function flushRow(string $id): object
{
    return DB::connection('mysql')->table('live_events')->where('entity_id', $id)->sole();
}

it('publishes an urgent row inline while the relay is idle, sequences the rest, and leaves the rest to the relay', function () {
    flushRecordPlain('ordinary');
    flushRecordUrgent('panic');

    $started = hrtime(true);
    $accepted = Relay::flushNow(Stream::central(), urgentOnly: true, budget: PanicPublishBudget::start(2.0));
    $took = (hrtime(true) - $started) / 1e9;

    expect($accepted)->toBeTrue()
        ->and(collect($this->transport->envelopes(Channels::user(1)))->pluck('i')->all())->toBe(['panic'])
        ->and(flushRow('panic')->published_at)->not->toBeNull()
        // Sequenced with it, so seqs stay in commit order, but published by the relay, not here.
        ->and(flushRow('ordinary')->seq)->not->toBeNull()
        ->and(flushRow('ordinary')->published_at)->toBeNull()
        ->and($this->waker->pokes)->toBe([])
        ->and($took)->toBeLessThan(0.5);

    // Every publish it made was bounded by the budget: 0.5 s to connect at most, and no more than was left.
    foreach ($this->transport->bounds as $bound) {
        expect($bound['connect'])->toBeLessThanOrEqual(0.5)
            ->and($bound['total'])->toBeLessThanOrEqual(2.0);
    }
});

it('returns false within 120 ms when another pass holds the stream (NOWAIT), rings the relay, and the relay publishes it', function () {
    flushRecordUrgent('busy');

    $second = DB::connection(LiveLog::secondConnection());
    $second->beginTransaction();
    $second->selectOne("SELECT head_seq FROM live_streams WHERE stream = 'central' FOR UPDATE");

    $started = hrtime(true);
    $accepted = Relay::flushNow(Stream::central());
    $took = (hrtime(true) - $started) / 1e9;

    $second->rollBack();

    expect($accepted)->toBeFalse()
        ->and($took)->toBeLessThan(0.12)
        ->and($this->transport->calls)->toBe(0)
        ->and($this->waker->pokes)->toBe(['c']);

    // The relay's next pass does what the flush could not.
    Sequencer::run(Stream::central());
    app(Publisher::class)->run(Stream::central());

    expect(flushRow('busy')->published_at)->not->toBeNull();
});

it('does not try while the breaker is open, and only rings the relay', function () {
    flushRecordUrgent('resting');
    app(Breaker::class)->open();

    expect(Relay::flushNow(Stream::central()))->toBeFalse()
        ->and($this->transport->calls)->toBe(0)
        ->and($this->waker->pokes)->toBe(['c'])
        ->and(flushRow('resting')->seq)->toBeNull();
});

it('only rings the relay with 0.1 s of the budget left, or after the legacy publish on it failed', function () {
    flushRecordUrgent('late');

    expect(Relay::flushNow(Stream::central(), budget: PanicPublishBudget::start(0.1)))->toBeFalse()
        ->and($this->transport->calls)->toBe(0)
        ->and($this->waker->pokes)->toBe(['c']);

    $budget = PanicPublishBudget::start(2.0);
    $budget->recordLegacy(false);

    expect(Relay::flushNow(Stream::central(), budget: $budget))->toBeFalse()
        ->and($this->transport->calls)->toBe(0)
        ->and($this->waker->pokes)->toBe(['c', 'c'])
        ->and(flushRow('late')->published_at)->toBeNull();
});

it('never publishes an access event from a request: only the relay can evict 250 ms later', function () {
    DB::connection('mysql')->transaction(fn () => LiveBus::record(new ProbeEvent(Stream::central(), [new Audience([Channels::user(12)])], 'access.changed', 'u12')));
    flushRecordPlain('plain');

    expect(Relay::flushNow(Stream::central(), urgentOnly: false))->toBeTrue()
        ->and(collect($this->transport->envelopes(Channels::user(1)))->pluck('i')->all())->toBe(['plain'])
        ->and($this->transport->envelopes(Channels::user(12)))->toBe([])
        ->and(flushRow('u12')->published_at)->toBeNull();
});

it('returns within its budget against a Reverb that accepts the connection and never answers, and opens the breaker', function () {
    Exceptions::fake();

    // A real listening socket nobody ever reads from: the kernel completes the handshake, and the
    // request then waits for an answer that never comes, which is what a hung Reverb looks like.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->not->toBeFalse();
    $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);

    config([
        'broadcasting.connections.reverb_relay.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb_relay.options.port' => $port,
        'broadcasting.connections.reverb_relay.key' => 'hung-key',
        'broadcasting.connections.reverb_relay.secret' => 'hung-secret',
        'broadcasting.connections.reverb_relay.app_id' => 'hung-app',
    ]);

    app()->instance(Transport::class, new PusherTransport);
    app()->forgetInstance(Publisher::class);

    flushRecordUrgent('hung');

    $budget = PanicPublishBudget::start(0.8);
    $started = hrtime(true);
    $accepted = Relay::flushNow(Stream::central(), budget: $budget);
    $took = (hrtime(true) - $started) / 1e9;

    fclose($server);

    expect($accepted)->toBeFalse()
        ->and($took)->toBeLessThan(0.8 + 0.1)
        ->and($budget->failed())->toBeTrue()
        ->and(app(Breaker::class)->isOpen())->toBeTrue()
        ->and($this->waker->pokes)->toBe(['c'])
        ->and(flushRow('hung')->published_at)->toBeNull();
});
