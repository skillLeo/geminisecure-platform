<?php

declare(strict_types=1);

use App\Live\Audience;
use App\Live\Channels;
use App\Live\Events\TileLive;
use App\Live\Live;
use App\Live\LiveBus;
use App\Live\Relay\Signaller;
use App\Live\Relay\Waker;
use App\Live\Stream;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;

/*
|--------------------------------------------------------------------------
| The write side of the live log (M11 Part C, DESIGN.md §1.2, §1.3, §2.1)
|--------------------------------------------------------------------------
|
| `LiveBus::record()` is the one way anything becomes live. It writes a `live_events` row inside
| the change's own transaction (the outbox), so a rollback broadcasts nothing and a commit can
| never be lost between two systems. It is also where "an estate never receives another
| estate's events" (C2) is enforced: at the moment the event is written, by the channel names'
| tenant segment, before anything reaches a socket.
|
| NOT DatabaseTransactions: the log is committed and read back by the sequencer, and the
| after-commit wake is part of what is asserted. `LiveLog::reset()` empties the log instead.
|
*/

beforeEach(function () {
    LiveLog::reset();

    $this->waker = new SpyWaker;
    app()->instance(Waker::class, $this->waker);
    app()->instance(Signaller::class, $this->waker);
});

afterEach(function () {
    LiveLog::leave();
    DB::connection('mysql')->table('tenants')->where('id', 'livelogdemo')->delete();
});

/** Run `$work` in a transaction on the central connection. */
function liveBusCentral(Closure $work): mixed
{
    return DB::connection('mysql')->transaction($work);
}

/** A plain ref event to one user on the platform stream. */
function liveBusPlain(string $id = '1'): ProbeEvent
{
    return ProbeEvent::on(Stream::central(), [Channels::user(7)], $id);
}

it('writes one row per audience inside the change\'s own transaction, and sequences nothing', function () {
    $event = new ProbeEvent(Stream::central(LiveLog::ESTATE), [
        new Audience(
            [Channels::estateFeed(LiveLog::ESTATE, 'security'), Channels::estateFeed(LiveLog::ESTATE, 'security')],
            'inline',
            ['status' => 'open'],
            'probe.console',
        ),
        new Audience([Channels::user(7)]),
        // An audience nobody is in writes no row.
        new Audience([]),
    ], id: '55');

    liveBusCentral(fn () => LiveBus::record($event));

    $rows = LiveLog::events();

    expect($rows)->toHaveCount(2);

    [$inline, $ref] = [$rows[0], $rows[1]];

    expect($inline->tenant_id)->toBe(LiveLog::ESTATE)
        ->and($inline->type)->toBe('probe.changed')
        ->and($inline->entity)->toBe('probe')
        ->and($inline->entity_id)->toBe('55')
        ->and($inline->mode)->toBe('inline')
        ->and($inline->hydrate)->toBe('probe.console')
        ->and(json_decode((string) $inline->summary, true))->toBe(['status' => 'open'])
        // Deduplicated: the same feed named twice is one channel.
        ->and(json_decode((string) $inline->channels, true))->toBe(['estate.'.LiveLog::ESTATE.'.security'])
        ->and((int) $inline->urgent)->toBe(0)
        ->and((int) $inline->simulated)->toBe(0)
        ->and($inline->origin)->toBeNull()
        ->and($inline->seq)->toBeNull()
        ->and($inline->available_at)->not->toBeNull()
        ->and($inline->created_at)->not->toBeNull()
        ->and($inline->sequenced_at)->toBeNull()
        ->and($inline->published_at)->toBeNull()
        ->and((int) $inline->publish_attempts)->toBe(0);

    expect($ref->mode)->toBe('ref')
        ->and($ref->hydrate)->toBeNull()
        ->and($ref->summary)->toBeNull()
        ->and(json_decode((string) $ref->channels, true))->toBe(['user.7']);

    // The bus says which rows it wrote for an event (what a held event's release needs, §1.7),
    // and nothing has been given a seq: that is the sequencer's alone.
    expect(LiveBus::idsOf($event))->toBe($rows->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->and(LiveBus::idsOf(liveBusPlain()))->toBe([])
        ->and(LiveLog::head())->toBe(0);
});

it('leaves no live_events row and no poke when the change rolls back', function () {
    try {
        liveBusCentral(function () {
            LiveBus::record(liveBusPlain());

            throw new RuntimeException('the business write failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(LiveLog::events())->toHaveCount(0)
        ->and($this->waker->pokes)->toBe([]);
});

it('pokes the relay only after the commit, and names the stream it wrote to', function () {
    liveBusCentral(function () {
        LiveBus::record(liveBusPlain());

        // Inside the transaction the row is not committed, so a wake now would find nothing.
        expect($this->waker->pokes)->toBe([]);
    });

    expect($this->waker->pokes)->toBe(['c']);

    LiveLog::enter();
    $this->waker->pokes = [];

    DB::connection('tenant')->transaction(fn () => LiveBus::record(
        ProbeEvent::on(Stream::estate(LiveLog::ESTATE), [Channels::estateFeed(LiveLog::ESTATE, 'facilities')]),
    ));

    expect($this->waker->pokes)->toBe(['e:'.LiveLog::ESTATE]);
});

it('never lets a failing wake undo a committed change', function () {
    app()->instance(Waker::class, new class implements Waker
    {
        public function poke(Stream $stream): void
        {
            throw new RuntimeException('redis is down');
        }
    });

    Exceptions::fake();

    liveBusCentral(fn () => LiveBus::record(liveBusPlain()));

    // The row stands (the relay's sweep finds it) and the failure is reported, not thrown.
    expect(LiveLog::events())->toHaveCount(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'redis is down');
});

it('throws when record() is called outside a transaction in the testing environment', function () {
    expect(fn () => LiveBus::record(liveBusPlain()))
        ->toThrow(LogicException::class, 'transaction');

    expect(LiveLog::events())->toHaveCount(0);
});

it('in production, inserts an event recorded outside a transaction anyway and reports it', function () {
    $this->app['env'] = 'production';
    Exceptions::fake();

    LiveBus::record(liveBusPlain());

    expect(LiveLog::events())->toHaveCount(1)
        ->and($this->waker->pokes)->toBe(['c']);

    Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), 'transaction'));
});

it('writes nothing inside Live::muted(), and the mute is scoped and exception-safe', function () {
    liveBusCentral(fn () => Live::muted(fn () => LiveBus::record(liveBusPlain())));

    expect(LiveLog::events())->toHaveCount(0)
        ->and($this->waker->pokes)->toBe([])
        ->and(Live::isMuted())->toBeFalse()
        ->and(Live::muted(fn () => 42))->toBe(42);

    try {
        Live::muted(function () {
            Live::muted(fn () => null);

            expect(Live::isMuted())->toBeTrue();

            throw new RuntimeException('a seeder failed half way');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Live::isMuted())->toBeFalse();

    liveBusCentral(fn () => LiveBus::record(liveBusPlain()));

    expect(LiveLog::events())->toHaveCount(1);
});

it('throws when an estate stream event names another tenant\'s channel', function () {
    LiveLog::enter();

    $estate = Stream::estate(LiveLog::ESTATE);

    expect(fn () => DB::connection('tenant')->transaction(fn () => LiveBus::record(ProbeEvent::on($estate, [
        Channels::estateFeed(LiveLog::ESTATE, 'facilities'),
        Channels::household(LiveLog::OTHER_ESTATE, 5),
    ]))))->toThrow(LogicException::class, 'household.'.LiveLog::OTHER_ESTATE.'.5');

    // A platform channel is refused too: an estate write never reaches user.{u} directly (§1.3).
    expect(fn () => DB::connection('tenant')->transaction(fn () => LiveBus::record(ProbeEvent::on($estate, [
        Channels::user(7),
    ]))))->toThrow(LogicException::class, 'user.7');

    expect(LiveLog::events('tenant'))->toHaveCount(0);

    // Its own channels are written, to its own database and nowhere else.
    DB::connection('tenant')->transaction(fn () => LiveBus::record(ProbeEvent::on($estate, [
        Channels::estateFeed(LiveLog::ESTATE, 'facilities'),
        Channels::household(LiveLog::ESTATE, 5),
    ])));

    expect(LiveLog::events('tenant'))->toHaveCount(1)
        ->and(LiveLog::events('tenant')->first()->tenant_id)->toBe(LiveLog::ESTATE)
        ->and(LiveLog::events('mysql'))->toHaveCount(0);
});

it('throws when an estate stream event is recorded while that estate is not the one open', function () {
    $event = ProbeEvent::on(Stream::estate(LiveLog::ESTATE), [Channels::estateFeed(LiveLog::ESTATE, 'facilities')]);

    expect(fn () => liveBusCentral(fn () => LiveBus::record($event)))
        ->toThrow(LogicException::class, 'tenancy');

    LiveLog::enter(LiveLog::OTHER_ESTATE);

    expect(fn () => DB::connection('tenant')->transaction(fn () => LiveBus::record($event)))
        ->toThrow(LogicException::class, LiveLog::OTHER_ESTATE);

    expect(LiveLog::events('tenant'))->toHaveCount(0);
});

it('throws when a central event about tenant A names estate B\'s channel', function () {
    expect(fn () => liveBusCentral(fn () => LiveBus::record(ProbeEvent::on(Stream::central(LiveLog::ESTATE), [
        Channels::estateFeed(LiveLog::ESTATE, 'security'),
        Channels::estateFeed(LiveLog::OTHER_ESTATE, 'security'),
    ]))))->toThrow(LogicException::class, 'estate.'.LiveLog::OTHER_ESTATE.'.security');

    // A platform event (no estate) may name no estate's channel at all.
    expect(fn () => liveBusCentral(fn () => LiveBus::record(ProbeEvent::on(Stream::central(), [
        Channels::geminiFeed(LiveLog::ESTATE, 'dispatch'),
    ]))))->toThrow(LogicException::class, 'gemini.'.LiveLog::ESTATE.'.dispatch');

    expect(LiveLog::events())->toHaveCount(0);

    // A's own channels and the platform's are what a central event about A may name. Its Gemini
    // audience is named through Channels::gemini(), the way every event about an estate names it.
    liveBusCentral(fn () => LiveBus::record(ProbeEvent::on(Stream::central(LiveLog::ESTATE), [
        Channels::estateFeed(LiveLog::ESTATE, 'security'),
        ...Channels::gemini('dispatch', LiveLog::ESTATE),
        Channels::guard(LiveLog::ESTATE, 12),
        Channels::user(7),
    ])));

    expect(LiveLog::events())->toHaveCount(1)
        ->and(json_decode((string) LiveLog::events()->sole()->channels, true))->toBe([
            'estate.'.LiveLog::ESTATE.'.security',
            'gemini.dispatch',
            'gemini.'.LiveLog::ESTATE.'.dispatch',
            'guard.'.LiveLog::ESTATE.'.12',
            'user.7',
        ]);
});

/**
 * §3.1's Streams column, word for word. Pinned here so the table the bus and the sequencer enforce
 * cannot drift from the design without this file changing in the same commit.
 *
 * @return array<string, list<string>>
 */
function liveBusDesignStreams(): array
{
    return [
        'user.{u}' => ['c'],
        'gemini.{feed}' => ['c'],
        'gemini.{t}.{feed}' => ['c'],
        'gemini.{t}.{feed}.{type}.{id}' => ['c'],
        'estate.{t}.{feed}' => ['c', 'e'],
        'estate.{t}.{feed}.{type}.{id}' => ['c', 'e'],
        'guard.{t}.{g}' => ['c', 'e'],
        'estate.{t}.guards' => ['c', 'e'],
        'estate.{t}.passes' => ['e'],
        'approval.{t}.{id}' => ['e'],
        'household.{t}.{h}' => ['c', 'e'],
        'resident.{t}.{a}' => ['c', 'e'],
        'community.{t}' => ['e'],
        'phase.{t}.{k}' => ['e'],
        'claim.{t}.{c}' => ['e'],
    ];
}

/**
 * One name of every family the builder makes, keyed by its row of §3.1.
 *
 * @return array<string, string>
 */
function liveBusOneOfEach(string $t): array
{
    return [
        'user.{u}' => Channels::user(7),
        'gemini.{feed}' => Channels::geminiFanIn('dispatch'),
        'gemini.{t}.{feed}' => Channels::geminiFeed($t, 'dispatch'),
        'gemini.{t}.{feed}.{type}.{id}' => Channels::geminiRecord($t, 'dispatch', 'alert', 55),
        'estate.{t}.{feed}' => Channels::estateFeed($t, 'facilities'),
        'estate.{t}.{feed}.{type}.{id}' => Channels::estateRecord($t, 'facilities', 'ticket', 1039),
        'guard.{t}.{g}' => Channels::guard($t, 12),
        'estate.{t}.guards' => Channels::estateGuards($t),
        'estate.{t}.passes' => Channels::estatePasses($t),
        'approval.{t}.{id}' => Channels::approval($t, 3),
        'household.{t}.{h}' => Channels::household($t, 44),
        'resident.{t}.{a}' => Channels::resident($t, 9),
        'community.{t}' => Channels::community($t),
        'phase.{t}.{k}' => Channels::phase($t, 'Phase 2'),
        'claim.{t}.{c}' => Channels::claim($t, 21),
    ];
}

it('knows which streams carry each channel family, exactly as §3.1 says', function () {
    expect(Channels::STREAMS)->toBe(liveBusDesignStreams());

    foreach (liveBusOneOfEach(LiveLog::ESTATE) as $family => $channel) {
        expect(Channels::familyOf($channel))->toBe($family)
            ->and(Channels::streamsOf($channel))->toBe(liveBusDesignStreams()[$family]);
    }

    expect(fn () => Channels::streamsOf('live-pulse'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Channels::streamsOf('estate.'.LiveLog::ESTATE.'.alerts'))->toThrow(InvalidArgumentException::class);
});

it('names a channel only on a stream catch-up reads it from: an estate event never names a central-only channel', function () {
    LiveLog::enter();

    $t = LiveLog::ESTATE;
    $all = liveBusOneOfEach($t);

    foreach (['gemini.{t}.{feed}', 'gemini.{t}.{feed}.{type}.{id}'] as $family) {
        expect(fn () => DB::connection('tenant')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::estate($t), [
            Channels::estateFeed($t, 'facilities'),
            $all[$family],
        ]))))->toThrow(LogicException::class, $all[$family].', a channel only the central stream carries');
    }

    expect(LiveLog::events('tenant'))->toHaveCount(0);

    // Every family the estate stream carries is written there, in one event.
    $estateCarried = array_values(array_filter($all, fn (string $family) => in_array('e', liveBusDesignStreams()[$family], true), ARRAY_FILTER_USE_KEY));

    DB::connection('tenant')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::estate($t), $estateCarried)));

    expect(json_decode((string) LiveLog::events('tenant')->sole()->channels, true))->toBe($estateCarried)
        ->and($estateCarried)->toHaveCount(11);
});

it('names a channel only on a stream catch-up reads it from: a central event never names an estate-only channel', function () {
    $t = LiveLog::ESTATE;
    $all = liveBusOneOfEach($t);

    foreach (['estate.{t}.passes', 'approval.{t}.{id}', 'community.{t}', 'phase.{t}.{k}', 'claim.{t}.{c}'] as $family) {
        expect(fn () => liveBusCentral(fn () => LiveBus::record(ProbeEvent::on(Stream::central($t), [
            Channels::estateFeed($t, 'security'),
            $all[$family],
        ]))))->toThrow(LogicException::class, $all[$family].", a channel only estate {$t}'s own stream carries");
    }

    expect(LiveLog::events())->toHaveCount(0);

    // Every family the central stream carries is written there, in one event.
    $centralCarried = array_values(array_filter($all, fn (string $family) => in_array('c', liveBusDesignStreams()[$family], true), ARRAY_FILTER_USE_KEY));

    liveBusCentral(fn () => LiveBus::record(ProbeEvent::on(Stream::central($t), $centralCarried)));

    expect(json_decode((string) LiveLog::events()->sole()->channels, true))->toBe($centralCarried)
        ->and($centralCarried)->toHaveCount(10);
});

it('throws on a forbidden summary key', function (string $key) {
    expect(fn () => liveBusCentral(fn () => LiveBus::record(new ProbeEvent(Stream::central(), [
        new Audience([Channels::user(7)], 'ref', ['status' => 'open', $key => 'x']),
    ]))))->toThrow(LogicException::class, $key);

    expect(LiveLog::events())->toHaveCount(0);
})->with(['who', 'token', 'latitude', 'amount_minor', 'resolution_note']);

it('throws on a summary over 1 KB', function () {
    $ids = range(1_000_000, 1_000_200);

    expect(fn () => liveBusCentral(fn () => LiveBus::record(new ProbeEvent(Stream::central(), [
        new Audience([Channels::user(7)], 'ref', ['ids' => $ids]),
    ]))))->toThrow(LogicException::class, '1024');

    expect(LiveLog::events())->toHaveCount(0);
});

it('refuses a channel name the builder cannot produce', function (string $channel) {
    expect(fn () => liveBusCentral(fn () => LiveBus::record(ProbeEvent::on(Stream::central(LiveLog::ESTATE), [$channel]))))
        ->toThrow(LogicException::class);

    expect(LiveLog::events())->toHaveCount(0);
})->with([
    'an unknown feed' => 'estate.livelogone.nosuchfeed',
    'the public pulse' => 'live-pulse',
    'a presence alias' => 'presence-live.user.7',
    'the wire prefix' => 'private-live.user.7',
    'uppercase' => 'User.7',
    'a leading zero' => 'user.07',
    'an uppercase tenant' => 'estate.LiveLogOne.security',
    'a record type the feed has no page for' => 'estate.livelogone.facilities.unit.5',
    'a per-estate copy of a platform-only feed' => 'gemini.livelogone.settings',
    'a legacy channel' => 'estate.livelogone.alerts',
    'nothing' => '',
]);

it('refuses one channel named by two audiences of the same event', function () {
    expect(fn () => liveBusCentral(fn () => LiveBus::record(new ProbeEvent(Stream::central(), [
        new Audience([Channels::user(7)]),
        new Audience([Channels::user(8), Channels::user(7)]),
    ]))))->toThrow(LogicException::class, 'user.7');
});

it('requires an inline audience to name its hydrator, and a ref audience not to', function () {
    expect(fn () => liveBusCentral(fn () => LiveBus::record(new ProbeEvent(Stream::central(), [
        new Audience([Channels::user(7)], 'inline'),
    ]))))->toThrow(LogicException::class, 'hydrat');

    expect(fn () => liveBusCentral(fn () => LiveBus::record(new ProbeEvent(Stream::central(), [
        new Audience([Channels::user(7)], 'ref', [], 'probe.console'),
    ]))))->toThrow(LogicException::class, 'hydrat');

    expect(fn () => liveBusCentral(fn () => LiveBus::record(new ProbeEvent(Stream::central(), [
        new Audience([Channels::user(7)], 'full'),
    ]))))->toThrow(LogicException::class, 'mode');

    expect(LiveLog::events())->toHaveCount(0);
});

it('marks only alert.raised urgent, and only on the central stream', function () {
    expect(fn () => liveBusCentral(fn () => LiveBus::record(new ProbeEvent(
        Stream::central(LiveLog::ESTATE),
        [new Audience(Channels::gemini('dispatch', LiveLog::ESTATE))],
        kind: 'alert.acknowledged',
        isUrgent: true,
        about: 'alert',
    ))))->toThrow(LogicException::class, 'urgent');

    liveBusCentral(fn () => LiveBus::record(new ProbeEvent(
        Stream::central(LiveLog::ESTATE),
        [new Audience(Channels::gemini('dispatch', LiveLog::ESTATE))],
        kind: 'alert.raised',
        isUrgent: true,
        about: 'alert',
        isSimulated: true,
    )));

    $row = LiveLog::events()->sole();

    expect((int) $row->urgent)->toBe(1)
        ->and((int) $row->simulated)->toBe(1);
});

it('in production, drops an event that breaks the contract, reports it, and lets the change commit', function () {
    $this->app['env'] = 'production';
    Exceptions::fake();

    $key = 'live-bus-test-'.bin2hex(random_bytes(4));

    liveBusCentral(function () use ($key) {
        DB::connection('mysql')->table('cache')->insert(['key' => $key, 'value' => 'kept', 'expiration' => time() + 60]);

        LiveBus::record(ProbeEvent::on(Stream::central(LiveLog::ESTATE), [Channels::estateFeed(LiveLog::OTHER_ESTATE, 'security')]));
    });

    try {
        expect(DB::connection('mysql')->table('cache')->where('key', $key)->value('value'))->toBe('kept')
            ->and(LiveLog::events())->toHaveCount(0)
            ->and($this->waker->pokes)->toBe([]);

        Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), LiveLog::OTHER_ESTATE));
    } finally {
        DB::connection('mysql')->table('cache')->where('key', $key)->delete();
    }
});

it('refuses, by trigger, an update of summary or channels, and a change of seq once it is set', function () {
    liveBusCentral(fn () => LiveBus::record(new ProbeEvent(Stream::central(), [
        new Audience([Channels::user(7)], 'ref', ['status' => 'open']),
    ])));

    $id = LiveLog::events()->sole()->id;
    $table = fn () => DB::connection('mysql')->table('live_events')->where('id', $id);

    expect(fn () => $table()->update(['summary' => json_encode(['status' => 'closed'])]))
        ->toThrow(QueryException::class, 'written once');

    expect(fn () => $table()->update(['channels' => json_encode(['user.8'])]))
        ->toThrow(QueryException::class, 'written once');

    // Compared as bytes, so not even a change of case gets through.
    expect(fn () => $table()->update(['type' => 'PROBE.CHANGED']))
        ->toThrow(QueryException::class, 'written once');

    // The sequencer's one write is allowed: seq from NULL to a value...
    $table()->update(['seq' => 999]);

    // ...and then never again.
    expect(fn () => $table()->update(['seq' => 1000]))
        ->toThrow(QueryException::class, 'written once');

    // The relay's bookkeeping columns stay writable.
    $table()->update(['published_at' => now(), 'publish_attempts' => 1]);

    expect((int) $table()->value('seq'))->toBe(999)
        ->and(json_decode((string) $table()->value('summary'), true))->toBe(['status' => 'open']);

    // A delivery is never updated at all.
    DB::connection('mysql')->table('live_deliveries')->insert(['channel' => 'user.7', 'seq' => 999, 'prev_seq' => null, 'event_id' => $id]);

    expect(fn () => DB::connection('mysql')->table('live_deliveries')->where('seq', 999)->update(['prev_seq' => 5]))
        ->toThrow(QueryException::class, 'written once');
});

it('writes one event per tenant in one transaction with recordAll, and throws for one event naming two tenants\' channels', function () {
    liveBusCentral(fn () => LiveBus::recordAll([
        ProbeEvent::on(Stream::central(LiveLog::ESTATE), [Channels::guard(LiveLog::ESTATE, 5)], '9'),
        ProbeEvent::on(Stream::central(LiveLog::OTHER_ESTATE), [Channels::guard(LiveLog::OTHER_ESTATE, 6)], '9'),
    ]));

    expect(LiveLog::events()->pluck('tenant_id')->all())->toBe([LiveLog::ESTATE, LiveLog::OTHER_ESTATE]);

    // Nothing of a batch is written when any one of its events breaks the rule.
    expect(fn () => liveBusCentral(fn () => LiveBus::recordAll([
        ProbeEvent::on(Stream::central(LiveLog::ESTATE), [Channels::guard(LiveLog::ESTATE, 5)], '10'),
        ProbeEvent::on(Stream::central(LiveLog::ESTATE), [Channels::guard(LiveLog::ESTATE, 5), Channels::guard(LiveLog::OTHER_ESTATE, 6)], '10'),
    ])))->toThrow(LogicException::class, 'guard.'.LiveLog::OTHER_ESTATE.'.6');

    expect(LiveLog::events())->toHaveCount(2);

    // And the pair shares the change's transaction: a rollback takes both.
    try {
        liveBusCentral(function () {
            LiveBus::recordAll([
                ProbeEvent::on(Stream::central(LiveLog::ESTATE), [Channels::guard(LiveLog::ESTATE, 5)], '11'),
                ProbeEvent::on(Stream::central(LiveLog::OTHER_ESTATE), [Channels::guard(LiveLog::OTHER_ESTATE, 6)], '11'),
            ]);

            throw new RuntimeException('the act failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(LiveLog::events())->toHaveCount(2);
});

it('carries no record id on the wire for a TileLive event', function () {
    liveBusCentral(fn () => LiveBus::record(
        TileLive::changed('duress_open', Stream::central(LiveLog::ESTATE), Channels::gemini('workforce', LiveLog::ESTATE)),
    ));

    $row = LiveLog::events()->sole();

    // The envelope is built from these columns: t, e, i, m, sum and the channel names.
    expect($row->type)->toBe(TileLive::TYPE)
        ->and($row->entity)->toBe('tile')
        ->and($row->entity_id)->toBe('duress_open')
        ->and($row->mode)->toBe('ref')
        ->and($row->hydrate)->toBeNull()
        ->and($row->summary)->toBeNull()
        ->and(json_decode((string) $row->channels, true))->toBe(['gemini.workforce', 'gemini.'.LiveLog::ESTATE.'.workforce']);

    // A key shaped like a record id, or a record channel, would say which record moved it.
    foreach (['55', 'alert:55', 'Duress_Open'] as $key) {
        expect(fn () => liveBusCentral(fn () => LiveBus::record(
            TileLive::changed($key, Stream::central(LiveLog::ESTATE), Channels::gemini('workforce', LiveLog::ESTATE)),
        )))->toThrow(LogicException::class);
    }

    expect(fn () => liveBusCentral(fn () => LiveBus::record(
        TileLive::changed('duress_open', Stream::central(LiveLog::ESTATE), [Channels::geminiRecord(LiveLog::ESTATE, 'dispatch', 'alert', 55)]),
    )))->toThrow(LogicException::class, 'record');

    expect(LiveLog::events())->toHaveCount(1)
        ->and(TileLive::tracked())->toBe([])
        ->and(TileLive::hydrators())->toBe([]);
});

it('stores the request\'s origin nonce only when it is sixteen lowercase hex characters', function () {
    $nonces = [
        '3fa1c09e5b2d7a10' => '3fa1c09e5b2d7a10',
        '3FA1C09E5B2D7A10' => null,
        '3fa1c09e5b2d7a1' => null,
        '3fa1c09e5b2d7a100' => null,
        'not-a-nonce-at-all' => null,
    ];

    foreach ($nonces as $header => $stored) {
        LiveLog::reset();
        app('request')->headers->set(Live::ORIGIN_HEADER, $header);

        expect(Live::origin())->toBe($stored);

        liveBusCentral(fn () => LiveBus::record(liveBusPlain()));

        expect(LiveLog::events()->sole()->origin)->toBe($stored);
    }

    app('request')->headers->remove(Live::ORIGIN_HEADER);

    expect(Live::origin())->toBeNull();
});

it('hands an ephemeral signal over after the commit, validated, and logs nothing', function () {
    liveBusCentral(function () {
        LiveBus::signal([Channels::estateFeed(LiveLog::ESTATE, 'governance')], 'election.turnout', ['ballot_id' => 4]);

        expect($this->waker->signals)->toBe([]);
    });

    expect($this->waker->signals)->toBe([[
        'channels' => ['estate.'.LiveLog::ESTATE.'.governance'],
        'type' => 'election.turnout',
        'data' => ['ballot_id' => 4],
    ]])->and(LiveLog::events())->toHaveCount(0);

    expect(fn () => LiveBus::signal([Channels::estateFeed(LiveLog::ESTATE, 'governance')], 'election.turnout', ['who' => 'x']))
        ->toThrow(LogicException::class, 'who');

    expect(fn () => LiveBus::signal([
        Channels::estateFeed(LiveLog::ESTATE, 'governance'),
        Channels::estateFeed(LiveLog::OTHER_ESTATE, 'governance'),
    ], 'election.turnout', ['ballot_id' => 4]))->toThrow(LogicException::class, LiveLog::OTHER_ESTATE);

    Live::muted(fn () => LiveBus::signal([Channels::estateFeed(LiveLog::ESTATE, 'governance')], 'election.turnout', ['ballot_id' => 5]));

    expect($this->waker->signals)->toHaveCount(1);
});

it('builds every channel family of §3.1, and nothing that is not one', function () {
    $t = LiveLog::ESTATE;

    expect(Channels::user(7))->toBe('user.7')
        ->and(Channels::geminiFanIn('dispatch'))->toBe('gemini.dispatch')
        ->and(Channels::geminiFeed($t, 'workforce'))->toBe("gemini.{$t}.workforce")
        ->and(Channels::geminiRecord($t, 'dispatch', 'alert', 55))->toBe("gemini.{$t}.dispatch.alert.55")
        ->and(Channels::geminiRecord($t, 'workforce', 'orders', '3'))->toBe("gemini.{$t}.workforce.orders.3")
        ->and(Channels::estateFeed($t, 'payroll'))->toBe("estate.{$t}.payroll")
        ->and(Channels::estateFeed($t, 'structure'))->toBe("estate.{$t}.structure")
        ->and(Channels::estateRecord($t, 'facilities', 'ticket', 1039))->toBe("estate.{$t}.facilities.ticket.1039")
        ->and(Channels::estateRecord($t, 'governance', 'nomination', 4))->toBe("estate.{$t}.governance.nomination.4")
        ->and(Channels::guard($t, 12))->toBe("guard.{$t}.12")
        ->and(Channels::estateGuards($t))->toBe("estate.{$t}.guards")
        ->and(Channels::estatePasses($t))->toBe("estate.{$t}.passes")
        ->and(Channels::approval($t, 3))->toBe("approval.{$t}.3")
        ->and(Channels::household($t, 44))->toBe("household.{$t}.44")
        ->and(Channels::resident($t, 9))->toBe("resident.{$t}.9")
        ->and(Channels::community($t))->toBe("community.{$t}")
        ->and(Channels::phase($t, '  Phase 2 '))->toBe("phase.{$t}.".substr(sha1('phase 2'), 0, 10))
        ->and(Channels::claim($t, 21))->toBe("claim.{$t}.21")
        ->and(Channels::pulse())->toBe('live-pulse');

    // Every name the builder makes, it can also place: the tenant segment, or none for the platform.
    expect(Channels::tenantOf("estate.{$t}.security"))->toBe($t)
        ->and(Channels::tenantOf("phase.{$t}.".substr(sha1('phase 2'), 0, 10)))->toBe($t)
        ->and(Channels::tenantOf("gemini.{$t}.dispatch.alert.55"))->toBe($t)
        ->and(Channels::tenantOf('gemini.dispatch'))->toBeNull()
        ->and(Channels::tenantOf('user.7'))->toBeNull();

    $refused = [
        fn () => Channels::user(0),
        fn () => Channels::user('07'),
        fn () => Channels::estateFeed('LiveLogOne', 'security'),
        fn () => Channels::estateFeed('ab', 'security'),
        fn () => Channels::estateFeed($t, 'alerts'),
        fn () => Channels::estateRecord($t, 'facilities', 'unit', 5),
        fn () => Channels::geminiFeed($t, 'settings'),
        fn () => Channels::geminiFeed($t, 'dashboard'),
        fn () => Channels::geminiFanIn('nosuchfeed'),
        fn () => Channels::household($t, -4),
        fn () => Channels::tenantOf('live-pulse'),
    ];

    foreach ($refused as $build) {
        expect($build)->toThrow(InvalidArgumentException::class);
    }
});

/** The app-store reviewers' estate, committed for one test (afterEach removes it). */
function liveBusDemonstrationEstate(): string
{
    DB::connection('mysql')->table('tenants')->insert([
        'id' => 'livelogdemo',
        'name' => 'Live log demonstration',
        'status' => 'active',
        'demo' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return 'livelogdemo';
}

it('refuses any Gemini channel on an event about the demonstration estate, however it was built', function () {
    $demo = liveBusDemonstrationEstate();

    // The fan-in reaches every control room, so an event about the reviewers' estate naming it
    // directly would put their panic on every dispatcher's console. Written nowhere, in testing.
    foreach ([
        Channels::geminiFanIn('dispatch'),
        Channels::geminiFanIn('dashboard'),
        Channels::geminiFeed($demo, 'dispatch'),
        Channels::geminiRecord($demo, 'dispatch', 'alert', 55),
    ] as $gemini) {
        expect(fn () => liveBusCentral(fn () => LiveBus::record(ProbeEvent::on(Stream::central($demo), [
            Channels::estateFeed($demo, 'security'),
            $gemini,
        ]))))->toThrow(LogicException::class, "demonstration estate {$demo} and names {$gemini}");
    }

    expect(LiveLog::events())->toHaveCount(0);

    // Named the way an event about an estate names its Gemini audience, it reaches its own
    // estate's channels and no Gemini one.
    liveBusCentral(fn () => LiveBus::record(ProbeEvent::on(Stream::central($demo), [
        Channels::estateFeed($demo, 'security'),
        ...Channels::gemini('dispatch', $demo),
    ])));

    expect(json_decode((string) LiveLog::events()->sole()->channels, true))->toBe(["estate.{$demo}.security"]);

    // Any other estate, and the platform itself, still reach the fan-in.
    LiveLog::reset();

    liveBusCentral(fn () => LiveBus::recordAll([
        ProbeEvent::on(Stream::central(LiveLog::ESTATE), [Channels::geminiFanIn('dispatch'), Channels::geminiFeed(LiveLog::ESTATE, 'dispatch')]),
        ProbeEvent::on(Stream::central(), [Channels::geminiFanIn('settings')]),
    ]));

    expect(LiveLog::events()->pluck('channels')->map(fn ($json) => json_decode((string) $json, true))->all())->toBe([
        ['gemini.dispatch', 'gemini.'.LiveLog::ESTATE.'.dispatch'],
        ['gemini.settings'],
    ]);
});

it('in production, writes a demonstration-estate event without its Gemini channels, and reports them', function () {
    $demo = liveBusDemonstrationEstate();
    $this->app['env'] = 'production';
    Exceptions::fake();

    liveBusCentral(fn () => LiveBus::record(new ProbeEvent(Stream::central($demo), [
        new Audience([Channels::estateFeed($demo, 'security'), Channels::geminiFanIn('dispatch')]),
        new Audience([Channels::geminiFeed($demo, 'workforce')]),
    ])));

    // Exactly what Channels::gemini() would have addressed: the estate's own feed, and nothing
    // for an audience that was all Gemini.
    expect(LiveLog::events())->toHaveCount(1)
        ->and(json_decode((string) LiveLog::events()->sole()->channels, true))->toBe(["estate.{$demo}.security"]);

    Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), "demonstration estate {$demo}")
        && str_contains($e->getMessage(), 'gemini.dispatch')
        && str_contains($e->getMessage(), "gemini.{$demo}.workforce"));
});

it('asks the database whether an estate is the demonstration one once per request, however many times the panic path asks', function () {
    $asked = 0;

    DB::listen(function ($query) use (&$asked) {
        if (str_contains($query->sql, '`tenants`') && str_contains($query->sql, '`demo`')) {
            $asked++;
        }
    });

    liveBusCentral(function () {
        // A panic names its Gemini audience through gemini(), then LiveBus checks each Gemini channel.
        LiveBus::record(ProbeEvent::on(Stream::central(LiveLog::ESTATE), [
            Channels::estateFeed(LiveLog::ESTATE, 'security'),
            ...Channels::gemini('dispatch', LiveLog::ESTATE),
        ]));
        LiveBus::record(ProbeEvent::on(Stream::central(LiveLog::ESTATE), Channels::gemini('workforce', LiveLog::ESTATE), '2'));
    });

    expect($asked)->toBe(1)
        ->and(LiveLog::events())->toHaveCount(2);

    // An event that names no Gemini channel does not ask at all.
    $asked = 0;
    liveBusCentral(fn () => LiveBus::record(ProbeEvent::on(Stream::central(LiveLog::OTHER_ESTATE), [Channels::estateFeed(LiveLog::OTHER_ESTATE, 'security')])));

    expect($asked)->toBe(0);
});

it('leaves the demonstration estate out of the Gemini feeds, fan-in included', function () {
    liveBusDemonstrationEstate();

    expect(Channels::gemini('dispatch', 'livelogdemo'))->toBe([])
        ->and(Channels::gemini('dispatch', LiveLog::ESTATE))->toBe(['gemini.dispatch', 'gemini.'.LiveLog::ESTATE.'.dispatch'])
        ->and(Channels::gemini('dispatch', null))->toBe(['gemini.dispatch'])
        // A platform-only feed has a fan-in and no per-estate copy (§3.1).
        ->and(Channels::gemini('dashboard', LiveLog::ESTATE))->toBe(['gemini.dashboard'])
        ->and(Channels::gemini('dashboard', 'livelogdemo'))->toBe([])
        ->and(Channels::gemini('settings', null))->toBe(['gemini.settings']);
});

it('names each stream the way the relay, the envelope and live_streams do', function () {
    expect(Stream::central()->key())->toBe('c')
        ->and(Stream::central(LiveLog::ESTATE)->key())->toBe('c')
        ->and(Stream::central(LiveLog::ESTATE)->tenantId)->toBe(LiveLog::ESTATE)
        ->and(Stream::central()->code())->toBe('c')
        ->and(Stream::central()->name())->toBe('central')
        ->and(Stream::central()->connection())->toBe(config('tenancy.database.central_connection'))
        ->and(Stream::estate(LiveLog::ESTATE)->key())->toBe('e:'.LiveLog::ESTATE)
        ->and(Stream::estate(LiveLog::ESTATE)->code())->toBe('e')
        ->and(Stream::estate(LiveLog::ESTATE)->name())->toBe('estate')
        ->and(Stream::estate(LiveLog::ESTATE)->connection())->toBe('tenant')
        ->and(Stream::fromKey('e:'.LiveLog::ESTATE)->tenantId)->toBe(LiveLog::ESTATE)
        ->and(Stream::fromKey('c')->isCentral())->toBeTrue();

    expect(fn () => Stream::estate('Not An Estate'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Stream::fromKey('x'))->toThrow(InvalidArgumentException::class);
});

it('builds the same log in the central database and in every estate\'s, each with its one stream row', function () {
    LiveLog::estate();

    $shape = function (string $connection): array {
        $db = DB::connection($connection);
        $shapes = [];

        foreach (['live_events', 'live_deliveries', 'live_streams'] as $table) {
            $create = (array) $db->selectOne("SHOW CREATE TABLE `{$table}`");
            $shapes[$table] = preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) array_values($create)[1]);
        }

        $shapes['triggers'] = collect($db->select('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE ? ORDER BY TRIGGER_NAME', ['live\_%']))
            ->map(fn ($row) => (array) $row)
            ->all();

        return $shapes;
    };

    config(['database.connections.live_estate_read' => array_merge(
        config('database.connections.mysql_owner'),
        ['database' => LiveLog::database(LiveLog::ESTATE)],
    )]);

    $central = $shape('mysql');
    $estate = $shape('live_estate_read');

    expect($estate)->toBe($central)
        ->and($central['triggers'])->toHaveCount(2);

    $centralStream = DB::connection('mysql')->table('live_streams')->get();
    $estateStream = DB::connection('live_estate_read')->table('live_streams')->get();

    expect($centralStream->pluck('stream')->all())->toBe(['central'])
        ->and($estateStream->pluck('stream')->all())->toBe(['estate'])
        ->and(strlen((string) $centralStream[0]->epoch))->toBe(26)
        ->and($estateStream[0]->epoch)->not->toBe($centralStream[0]->epoch);

    DB::purge('live_estate_read');
});
