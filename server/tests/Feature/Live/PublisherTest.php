<?php

declare(strict_types=1);

use App\Live\Access\Evictor;
use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\Registry;
use App\Live\Relay\Breaker;
use App\Live\Relay\Publisher;
use App\Live\Relay\Sequencer;
use App\Live\Relay\Waker;
use App\Live\Stream;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Live\Fixtures\FixtureHydrator;
use Tests\Live\Support\FakeClock;
use Tests\Live\Support\FakeTransport;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;

/*
|--------------------------------------------------------------------------
| The publisher: what the sequencer numbered, sent to Reverb (DESIGN.md §1.5, §2.2, §4.1)
|--------------------------------------------------------------------------
|
| Every row reaches every one of its channels, in batches Reverb will take, and is marked
| published only when Reverb took all of it. When Reverb is down nothing is given up, the breaker
| rests publishing, and sequencing carries on. When Reverb refuses one message, the others beside
| it and after it still go, the refused one is given up after five tries, and its channels are
| nudged so a client for whom it was the LAST event still recovers it. Inline data is read when
| published, one call per hydrator, and never stored.
|
*/

beforeEach(function () {
    LiveLog::reset();
    Carbon::setTestNow(Carbon::now());
    Registry::reset();
    FixtureHydrator::$calls = [];
    FixtureHydrator::$queries = 0;

    app()->instance(Waker::class, new SpyWaker);
    app(Breaker::class)->close();

    $this->clock = new FakeClock;
    $this->transport = new FakeTransport;
    $this->publisher = new Publisher($this->transport, app(Breaker::class), new Evictor($this->transport, $this->clock), $this->clock);
});

afterEach(function () {
    Carbon::setTestNow();
    Registry::reset();
    app(Breaker::class)->close();
});

function publisherRecord(ProbeEvent $event): ProbeEvent
{
    DB::connection('mysql')->transaction(fn () => LiveBus::record($event));

    return $event;
}

function publisherRow(string $entityId): object
{
    return DB::connection('mysql')->table('live_events')->where('entity_id', $entityId)->sole();
}

/** @return list<string> */
function publisherUsers(int $from, int $count): array
{
    return array_map(fn (int $u): string => Channels::user($u), range($from, $from + $count - 1));
}

it('publishes each event on every channel as the §2.2 envelope, and marks it published only when Reverb took it', function () {
    publisherRecord(new ProbeEvent(Stream::central(), [new Audience([Channels::user(1), Channels::user(2)], summary: ['status' => 'resolved'])], 'alert.resolved', 'e1', about: 'alert'));
    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(1)], 'e2'));

    Sequencer::run(Stream::central());
    $outcome = $this->publisher->run(Stream::central());

    expect($outcome->read)->toBe(2)
        ->and($outcome->published)->toBe(2)
        ->and($outcome->complete())->toBeTrue()
        ->and($this->transport->batches)->toHaveCount(1);

    $epoch = substr((string) DB::connection('mysql')->table('live_streams')->value('epoch'), 0, 8);
    $first = $this->transport->envelopes(Channels::user(2))[0];

    expect(array_keys($first))->toBe(['v', 's', 'ep', 'q', 'p', 't', 'e', 'i', 'm', 'sum', 'o', 'at', 'sim'])
        ->and($first)->toMatchArray(['v' => 1, 's' => 'c', 'ep' => $epoch, 'q' => 1, 'p' => null, 't' => 'alert.resolved', 'e' => 'alert', 'i' => 'e1', 'm' => 'ref', 'sum' => ['status' => 'resolved'], 'o' => null, 'sim' => false])
        // The API's one time format, so the catch-up that repeats this envelope passes the same rule.
        ->and($first['at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/');

    // Each channel's own previous seq: user.1 heard seq 1, so its seq 2 says so.
    expect(collect($this->transport->envelopes(Channels::user(1)))->map(fn (array $e) => [$e['q'], $e['p']])->all())->toBe([[1, null], [2, 1]])
        ->and(collect($this->transport->items())->pluck('name')->unique()->all())->toBe(['live']);

    foreach (['e1', 'e2'] as $id) {
        expect(publisherRow($id)->published_at)->not->toBeNull()
            ->and((int) publisherRow($id)->publish_attempts)->toBe(1);
    }

    // Nothing left: a second pass reads nothing and sends nothing.
    expect($this->publisher->run(Stream::central())->read)->toBe(0)
        ->and($this->transport->calls)->toBe(1);
});

it('keeps every batch at 9,000 bytes or less, splits a wide event across batches of its own, and sends every (event, channel) exactly once', function () {
    // Many small events, and one addressed to 120 people, far more than one batch holds.
    foreach (range(1, 60) as $i) {
        publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user($i)], 'small-'.$i));
    }

    publisherRecord(ProbeEvent::on(Stream::central(), publisherUsers(1000, 120), 'wide'));
    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'after'));

    Sequencer::run(Stream::central());
    $outcome = $this->publisher->run(Stream::central());

    expect($outcome->published)->toBe(62)
        ->and(count($this->transport->batches))->toBeGreaterThan(3);

    foreach ($this->transport->batches as $batch) {
        // Measured the way pusher-php-server encodes the body it sends.
        expect(strlen((string) json_encode(['batch' => $batch])))->toBeLessThanOrEqual(Publisher::BATCH_BYTES);
    }

    $sent = collect($this->transport->items())->map(fn (array $item) => $item['data']['i'].'@'.$item['channel']);
    expect($sent->count())->toBe(60 + 120 + 1)
        ->and($sent->unique()->count())->toBe($sent->count());

    // The wide event's batches hold nothing else, so a refusal of one never takes another event down with it.
    foreach ($this->transport->batches as $batch) {
        $ids = collect($batch)->map(fn (array $item) => json_decode($item['data'], true)['i'])->unique();

        expect($ids->contains('wide') ? $ids->all() : ['wide'])->toBe(['wide']);
    }

    expect(DB::connection('mysql')->table('live_events')->whereNull('published_at')->count())->toBe(0);
});

it('keeps a row unpublished through an outage, rests publishing for 10 s while sequencing carries on, then publishes it whole', function () {
    Exceptions::fake();
    $this->transport->answer = fn (): int => 0;

    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'down'));
    Sequencer::run(Stream::central());

    $outcome = $this->publisher->run(Stream::central());

    expect($outcome->outage)->toBeTrue()
        ->and($outcome->published)->toBe(0)
        ->and(publisherRow('down')->published_at)->toBeNull()
        ->and(publisherRow('down')->publish_failed_at)->toBeNull()
        ->and((int) publisherRow('down')->publish_attempts)->toBe(1)
        ->and(app(Breaker::class)->isOpen())->toBeTrue();

    // Reverb is back, but the breaker holds for its 10 s: nothing is tried, and new rows are still sequenced.
    $this->transport->answer = null;
    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'meanwhile'));

    expect(Sequencer::run(Stream::central()))->toBe(1)
        ->and($this->publisher->run(Stream::central())->outage)->toBeTrue()
        ->and($this->transport->calls)->toBe(1);

    Carbon::setTestNow(Carbon::now()->addSeconds(11));

    $outcome = $this->publisher->run(Stream::central());

    expect($outcome->published)->toBe(2)
        ->and(publisherRow('down')->published_at)->not->toBeNull()
        ->and((int) publisherRow('down')->publish_attempts)->toBe(2)
        ->and(collect($this->transport->envelopes(Channels::user(7)))->pluck('q')->all())->toBe([1, 2]);

    // Reported once, however long the outage: not a line a minute in the log.
    Exceptions::assertReportedCount(1);
});

it('never gives a row up for an outage, however many times Reverb fails to answer', function () {
    Exceptions::fake();
    $this->transport->answer = fn (): int => 503;

    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'patient'));
    Sequencer::run(Stream::central());

    foreach (range(1, 8) as $round) {
        $this->publisher->run(Stream::central());
        Carbon::setTestNow(Carbon::now()->addSeconds(11));
    }

    expect(publisherRow('patient')->publish_failed_at)->toBeNull()
        ->and((int) publisherRow('patient')->publish_attempts)->toBe(8);
});

it('gives a refused row up after five tries, publishing the rows beside and after it on its channel meanwhile', function () {
    Exceptions::fake();
    $poisoned = fn (array $batch): bool => collect($batch)->contains(fn (array $item) => (json_decode($item['data'], true)['i'] ?? null) === 'poison');
    $this->transport->answer = fn (array $batch): int => $poisoned($batch) ? 422 : 200;

    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'before'));
    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'poison'));
    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'beside'));
    Sequencer::run(Stream::central());

    // The batch of three is refused; each is then sent alone, and only the poison stays behind.
    $outcome = $this->publisher->run(Stream::central());

    expect($outcome->published)->toBe(2)
        ->and($outcome->waiting)->toBe(1)
        ->and($outcome->outage)->toBeFalse()
        ->and(app(Breaker::class)->isOpen())->toBeFalse()
        ->and(publisherRow('before')->published_at)->not->toBeNull()
        ->and(publisherRow('beside')->published_at)->not->toBeNull()
        ->and(publisherRow('poison')->published_at)->toBeNull();

    // A later event on the same channel is not held behind the refused one: its p reveals the gap.
    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'later'));
    Sequencer::run(Stream::central());

    expect($this->publisher->run(Stream::central())->published)->toBe(1)
        ->and(collect($this->transport->envelopes(Channels::user(7)))->map(fn (array $e) => [$e['i'], $e['q'], $e['p']])->last())->toBe(['later', 4, 3]);

    // Resting between tries: 0.5, 1, 2, 5 s. Not tried before its rest is over.
    $calls = $this->transport->calls;
    expect($this->publisher->run(Stream::central())->read)->toBe(0)
        ->and($this->transport->calls)->toBe($calls);

    foreach ([0.5, 1, 2, 5] as $rest) {
        $this->clock->advance($rest + 0.01);
        $this->publisher->run(Stream::central());
    }

    $poison = publisherRow('poison');

    expect($poison->publish_failed_at)->not->toBeNull()
        ->and($poison->published_at)->toBeNull()
        ->and((int) $poison->publish_attempts)->toBe(5);

    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "live_events row {$poison->id} (probe.changed) on the central stream was given up")
        && ! str_contains($e->getMessage(), 'user.7'));

    // Given up is finished: never read again.
    $calls = $this->transport->calls;
    $this->clock->advance(60);

    expect($this->publisher->run(Stream::central())->read)->toBe(0)
        ->and($this->transport->calls)->toBe($calls);
});

it('nudges every channel of a row it gives up, so a client for whom it was the LAST event still catches up', function () {
    Exceptions::fake();
    $this->transport->answer = fn (array $batch): int => collect($batch)->contains(fn (array $item) => (json_decode($item['data'], true)['q'] ?? null) !== null) ? 422 : 200;

    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(9), Channels::user(10)], 'last'));
    Sequencer::run(Stream::central());

    $this->publisher->run(Stream::central());

    foreach ([0.5, 1, 2, 5] as $rest) {
        $this->clock->advance($rest + 0.01);
        $this->publisher->run(Stream::central());
    }

    $epoch = substr((string) DB::connection('mysql')->table('live_streams')->value('epoch'), 0, 8);

    expect(publisherRow('last')->publish_failed_at)->not->toBeNull()
        ->and($this->transport->envelopes(Channels::user(9)))->toBe([['v' => 1, 's' => 'c', 'ep' => $epoch, 'q' => null, 't' => 'live.nudge']])
        ->and($this->transport->envelopes(Channels::user(10)))->toBe([['v' => 1, 's' => 'c', 'ep' => $epoch, 'q' => null, 't' => 'live.nudge']]);
});

it('hydrates the inline rows with one call per hydrator for every id, merges d, and stores none of it', function () {
    Registry::useDirectory(base_path('tests/Live/Fixtures/Events'), 'Tests\\Live\\Fixtures\\Events');

    foreach (['11', '12', '404'] as $id) {
        publisherRecord(new ProbeEvent(Stream::central(), [new Audience([Channels::user(3)], 'inline', ['status' => 'open'], 'fixture.ticket')], id: $id));
    }

    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(3)], 'plain'));
    Sequencer::run(Stream::central());

    expect($this->publisher->run(Stream::central())->published)->toBe(4)
        ->and(FixtureHydrator::$calls)->toBe([['11', '12', '404']])
        ->and(FixtureHydrator::$queries)->toBe(1);

    $sent = collect($this->transport->envelopes(Channels::user(3)))->keyBy('i');

    expect($sent['11']['m'])->toBe('inline')
        ->and($sent['11']['d'])->toBe(['number' => '11', 'lot' => 'Lot 11', 'status' => 'open'])
        ->and($sent['12']['d'])->toBe(['number' => '12', 'lot' => 'Lot 12', 'status' => 'open'])
        // A record that has gone hydrates to null, which a screen reads as "remove it".
        ->and(array_key_exists('d', $sent['404']))->toBeTrue()
        ->and($sent['404']['d'])->toBeNull()
        // A ref event carries no data at all.
        ->and(array_key_exists('d', $sent['plain']))->toBeFalse();

    // The log keeps only the safe summary: the hydrated lot is nowhere in it.
    $stored = json_encode(LiveLog::events()->all());

    expect($stored)->not->toContain('Lot 11')
        ->and(json_decode((string) publisherRow('11')->summary, true))->toBe(['status' => 'open']);
});

it('holds an inline row back, and never sends it without its data, when its hydrator fails', function () {
    Exceptions::fake();

    publisherRecord(new ProbeEvent(Stream::central(), [new Audience([Channels::user(3)], 'inline', hydrate: 'nobody.declares')], id: 'orphan'));
    publisherRecord(ProbeEvent::on(Stream::central(), [Channels::user(3)], 'fine'));
    Sequencer::run(Stream::central());

    $outcome = $this->publisher->run(Stream::central());

    expect($outcome->published)->toBe(1)
        ->and(collect($this->transport->envelopes(Channels::user(3)))->pluck('i')->all())->toBe(['fine'])
        ->and(publisherRow('orphan')->published_at)->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "hydrator 'nobody.declares' failed"));
});

it('marks a row delivered nowhere as published without sending anything', function () {
    Exceptions::fake();

    DB::connection('mysql')->table('live_events')->insert([
        'tenant_id' => null, 'type' => 'probe.changed', 'entity' => 'probe', 'entity_id' => 'nowhere', 'mode' => 'ref',
        'channels' => json_encode(['Not.A.Channel']), 'available_at' => DB::raw('UTC_TIMESTAMP(6)'), 'created_at' => DB::raw('UTC_TIMESTAMP(6)'),
    ]);

    Sequencer::run(Stream::central());

    expect($this->publisher->run(Stream::central())->published)->toBe(1)
        ->and($this->transport->calls)->toBe(0)
        ->and(publisherRow('nowhere')->published_at)->not->toBeNull();
});

it('sends the pulse as {at, build} on live-pulse and nothing else, and not while the breaker is open', function () {
    config(['live.build' => 'a342c56']);

    expect($this->publisher->pulse())->toBeTrue();

    $pulse = $this->transport->items()[0];

    expect($pulse['name'])->toBe('pulse')
        ->and($pulse['channel'])->toBe('live-pulse')
        ->and(array_keys($pulse['data']))->toBe(['at', 'build'])
        ->and($pulse['data']['build'])->toBe('a342c56');

    app(Breaker::class)->open();

    expect($this->publisher->pulse())->toBeFalse()
        ->and($this->transport->calls)->toBe(1);
});

it('sends an ephemeral signal with no seq to each channel, and nothing while the breaker is open', function () {
    $this->publisher->signal([Channels::community('livelogone')], 'ballot.turnout', ['ballot_id' => 4]);

    expect($this->transport->envelopes('community.livelogone'))->toBe([['v' => 1, 'q' => null, 't' => 'ballot.turnout', 'd' => ['ballot_id' => 4]]]);

    app(Breaker::class)->open();
    $this->publisher->signal([Channels::community('livelogone')], 'ballot.turnout', ['ballot_id' => 4]);

    expect($this->transport->calls)->toBe(1);
});
