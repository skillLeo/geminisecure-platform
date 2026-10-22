<?php

declare(strict_types=1);

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\Relay\Sequencer;
use App\Live\Relay\Waker;
use App\Live\Stream;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;

/*
|--------------------------------------------------------------------------
| The sequencer: gap-free, monotonic, and exact per channel (DESIGN.md §1.4, C6)
|--------------------------------------------------------------------------
|
| C6 asks for a monotonic id on every broadcast event, so a client that remembers the last one
| it applied can ask for "everything after N" and be given exactly that. An auto-increment id
| cannot be that number: it is handed out at INSERT, not at COMMIT, so a slow transaction commits
| a LOWER id after a faster one has already been read, and "id > N" skips it for ever.
|
| So `seq` is given only to rows that have already committed, one serial section at a time (the
| `live_streams` row lock), and each section commits its seqs and deliveries together. What that
| buys is proved here with two real sessions: a row held open in one is invisible, is skipped,
| and is sequenced later with a HIGHER seq, and nothing between is lost.
|
| The clock these tests move is the database's own (`SET timestamp`), because it is the only
| clock the log reads.
|
*/

beforeEach(function () {
    LiveLog::reset();
    app()->instance(Waker::class, $this->waker = new SpyWaker);
});

afterEach(function () {
    LiveLog::restoreClock('mysql');

    if (tenancy()->initialized) {
        LiveLog::restoreClock('tenant');
    }

    LiveLog::leave();
    DB::purge('live_second');
});

/** Record one event on the central platform stream, in its own transaction. */
function sequencerRecord(ProbeEvent $event): ProbeEvent
{
    DB::connection('mysql')->transaction(fn () => LiveBus::record($event));

    return $event;
}

/**
 * The deliveries on one channel, as [seq, prev_seq] pairs.
 *
 * @return list<array{0: int, 1: int|null}>
 */
function sequencerChannel(string $channel, string $connection = 'mysql'): array
{
    return DB::connection($connection)->table('live_deliveries')
        ->where('channel', $channel)
        ->orderBy('seq')
        ->get()
        ->map(fn ($row) => [(int) $row->seq, $row->prev_seq === null ? null : (int) $row->prev_seq])
        ->all();
}

function sequencerSeqOf(string $entityId, string $connection = 'mysql'): ?int
{
    $seq = DB::connection($connection)->table('live_events')->where('entity_id', $entityId)->value('seq');

    return $seq === null ? null : (int) $seq;
}

it('gives committed rows gap-free seqs from the head, in id order, with one delivery per channel', function () {
    sequencerRecord(ProbeEvent::on(Stream::central(), [Channels::user(1)], 'a'));
    sequencerRecord(ProbeEvent::on(Stream::central(), [Channels::user(1), Channels::user(2)], 'b'));
    sequencerRecord(ProbeEvent::on(Stream::central(), [Channels::user(3)], 'c'));

    expect(Sequencer::run(Stream::central()))->toBe(3)
        ->and(Sequencer::run(Stream::central()))->toBe(0);

    $rows = LiveLog::events();

    expect($rows->pluck('seq')->map(fn ($seq) => (int) $seq)->all())->toBe([1, 2, 3])
        ->and($rows->pluck('sequenced_at')->filter()->count())->toBe(3)
        ->and(LiveLog::head())->toBe(3);

    expect(LiveLog::deliveries()->map(fn ($row) => [$row->channel, (int) $row->seq, (int) $row->event_id])->all())->toBe([
        ['user.1', 1, (int) $rows[0]->id],
        ['user.1', 2, (int) $rows[1]->id],
        ['user.2', 2, (int) $rows[1]->id],
        ['user.3', 3, (int) $rows[2]->id],
    ]);
});

it('keeps seqs contiguous across two connections: an open transaction\'s row is skipped, then sequenced later with a higher seq, and nothing is lost', function () {
    $second = DB::connection(LiveLog::secondConnection());

    // Another request's change, still open: its row exists, uncommitted, with the LOWER id.
    $second->beginTransaction();
    $second->table('live_events')->insert([
        'tenant_id' => null,
        'type' => 'probe.changed',
        'entity' => 'probe',
        'entity_id' => 'slow',
        'mode' => 'ref',
        'channels' => json_encode(['user.7']),
        'available_at' => DB::raw('UTC_TIMESTAMP(6)'),
        'created_at' => DB::raw('UTC_TIMESTAMP(6)'),
    ]);

    sequencerRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'fast'));

    // The open row is invisible to the sequencer, and does not make it wait.
    expect(Sequencer::run(Stream::central()))->toBe(1)
        ->and(sequencerSeqOf('fast'))->toBe(1);

    $second->commit();

    expect(Sequencer::run(Stream::central()))->toBe(1)
        ->and(sequencerSeqOf('slow'))->toBe(2)
        ->and(LiveLog::head())->toBe(2);

    $rows = LiveLog::events();
    $slow = $rows->firstWhere('entity_id', 'slow');
    $fast = $rows->firstWhere('entity_id', 'fast');

    // The id order said slow came first; the seq order says what committed first. A reader that
    // saw seq 1 and asks for "after 1" is handed the slow row: nothing was skipped.
    expect((int) $slow->id)->toBeLessThan((int) $fast->id)
        ->and($rows->whereNull('seq'))->toHaveCount(0)
        ->and(sequencerChannel('user.7'))->toBe([[1, null], [2, 1]]);
});

it('gives each delivery the exact previous seq on its own channel across interleaved events', function () {
    [$x, $y, $z] = [Channels::user(1), Channels::user(2), Channels::user(3)];

    sequencerRecord(ProbeEvent::on(Stream::central(), [$x], 'e1'));
    sequencerRecord(ProbeEvent::on(Stream::central(), [$y], 'e2'));

    expect(Sequencer::run(Stream::central()))->toBe(2);

    // The second run must find the previous seq in the table, not in its own memory.
    sequencerRecord(ProbeEvent::on(Stream::central(), [$x, $y], 'e3'));
    sequencerRecord(ProbeEvent::on(Stream::central(), [$x], 'e4'));
    sequencerRecord(ProbeEvent::on(Stream::central(), [$y, $z], 'e5'));

    // Two audiences are two rows and two seqs, each on its own channels.
    sequencerRecord(new ProbeEvent(Stream::central(), [new Audience([$x]), new Audience([$z])], id: 'e6'));

    expect(Sequencer::run(Stream::central()))->toBe(5);

    expect(sequencerChannel($x))->toBe([[1, null], [3, 1], [4, 3], [6, 4]])
        ->and(sequencerChannel($y))->toBe([[2, null], [3, 2], [5, 3]])
        ->and(sequencerChannel($z))->toBe([[5, null], [7, 5]]);
});

it('does not sequence a held row until it is released, and releases it after 10 s by the backstop', function () {
    $held = sequencerRecord(new ProbeEvent(Stream::central(), [new Audience([Channels::user(7)])], id: 'held', isHeld: true));
    sequencerRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'plain'));

    $row = fn (string $id) => DB::connection('mysql')->table('live_events')->where('entity_id', $id)->first();

    expect($row('held')->available_at)->toBeNull()
        ->and(Sequencer::run(Stream::central()))->toBe(1)
        ->and(sequencerSeqOf('held'))->toBeNull();

    // Releasing an ordinary row, or one already sequenced, changes nothing.
    LiveBus::release(LiveBus::idsOf(ProbeEvent::on(Stream::central(), [Channels::user(7)])), Stream::central());
    LiveBus::release([(int) $row('plain')->id], Stream::central());

    expect(Sequencer::run(Stream::central()))->toBe(0);

    $this->waker->pokes = [];
    LiveBus::release(LiveBus::idsOf($held), Stream::central());

    expect($row('held')->available_at)->not->toBeNull()
        ->and($this->waker->pokes)->toBe(['c'])
        ->and(Sequencer::run(Stream::central()))->toBe(1)
        ->and(sequencerSeqOf('held'))->toBe(2);

    // A process that died between its commit and its release: the backstop, at 10 s.
    sequencerRecord(new ProbeEvent(Stream::central(), [new Audience([Channels::user(7)])], id: 'orphan', isHeld: true));

    LiveLog::moveClock('mysql', 5);
    expect(Sequencer::run(Stream::central()))->toBe(0);

    LiveLog::moveClock('mysql', 11);
    expect(Sequencer::run(Stream::central()))->toBe(1)
        ->and(sequencerSeqOf('orphan'))->toBe(3)
        // Stamped when released, so the prune never mistakes it for one still held.
        ->and($row('orphan')->available_at)->not->toBeNull();
});

it('does not sequence a scheduled row (25 min ahead) before its time, sequences it after, and live:prune neither deletes nor alarms it meanwhile', function () {
    config(['live.retention_days' => 0]);

    sequencerRecord(new ProbeEvent(Stream::central(), [new Audience([Channels::user(7)])], id: 'due', at: Carbon::now()->addMinutes(25)));
    sequencerRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], 'now'));

    $ahead = (int) DB::connection('mysql')->table('live_events')->where('entity_id', 'due')
        ->value(DB::raw('TIMESTAMPDIFF(SECOND, created_at, available_at)'));

    expect($ahead)->toBeGreaterThanOrEqual(1499)->toBeLessThanOrEqual(1501)
        ->and(Sequencer::run(Stream::central()))->toBe(1)
        ->and(sequencerSeqOf('due'))->toBeNull();

    LiveLog::moveClock('mysql', 24 * 60);

    expect(Sequencer::run(Stream::central()))->toBe(0);

    // The prune, with nothing retained: the published row goes, the scheduled one stays, and
    // nothing is alarmed, because a row waiting for its own time is not a stuck row.
    DB::connection('mysql')->table('live_events')->where('entity_id', 'now')->update(['published_at' => DB::raw('UTC_TIMESTAMP(6)')]);

    $exit = Artisan::call('live:prune', ['--stream' => ['c']]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('ALARM')
        ->and(DB::connection('mysql')->table('live_events')->where('entity_id', 'due')->exists())->toBeTrue()
        ->and(DB::connection('mysql')->table('live_events')->where('entity_id', 'now')->exists())->toBeFalse();

    LiveLog::moveClock('mysql', 26 * 60);

    expect(Sequencer::run(Stream::central()))->toBe(1)
        ->and(sequencerSeqOf('due'))->toBe(2);
});

it('sequences an estate stream in the estate\'s own database, and only while that estate is open', function () {
    LiveLog::enter();

    DB::connection('tenant')->transaction(fn () => LiveBus::record(
        ProbeEvent::on(Stream::estate(LiveLog::ESTATE), [Channels::estateFeed(LiveLog::ESTATE, 'facilities')], 'estate-row'),
    ));

    expect(Sequencer::run(Stream::estate(LiveLog::ESTATE)))->toBe(1)
        ->and(sequencerSeqOf('estate-row', 'tenant'))->toBe(1)
        ->and(LiveLog::head('tenant'))->toBe(1)
        ->and(LiveLog::head('mysql'))->toBe(0)
        ->and(sequencerChannel('estate.'.LiveLog::ESTATE.'.facilities', 'tenant'))->toBe([[1, null]]);

    expect(fn () => Sequencer::run(Stream::estate(LiveLog::OTHER_ESTATE)))->toThrow(LogicException::class);

    LiveLog::leave();

    expect(fn () => Sequencer::run(Stream::estate(LiveLog::ESTATE)))->toThrow(LogicException::class);
});

/** A row written the way LiveBus never would: straight into the table, channels unchecked. */
function sequencerRawRow(string $connection, ?string $tenant, string $id, array $channels): void
{
    DB::connection($connection)->table('live_events')->insert([
        'tenant_id' => $tenant,
        'type' => 'probe.changed',
        'entity' => 'probe',
        'entity_id' => $id,
        'mode' => 'ref',
        'channels' => json_encode($channels),
        'available_at' => DB::raw('UTC_TIMESTAMP(6)'),
        'created_at' => DB::raw('UTC_TIMESTAMP(6)'),
    ]);
}

it('delivers a central row that bypassed LiveBus nowhere when it names another estate\'s channel, a channel the stream does not carry, or no channel at all', function () {
    Exceptions::fake();

    [$a, $b] = [LiveLog::ESTATE, LiveLog::OTHER_ESTATE];

    sequencerRawRow('mysql', $a, 'good', ["estate.{$a}.security", 'user.7', 'gemini.dispatch']);
    sequencerRawRow('mysql', $a, 'other-estate', ["estate.{$a}.security", "household.{$b}.5"]);
    sequencerRawRow('mysql', null, 'platform-naming-an-estate', ["estate.{$a}.security"]);
    sequencerRawRow('mysql', $a, 'estate-only', ["estate.{$a}.passes"]);
    sequencerRawRow('mysql', $a, 'not-canonical', ['User.7']);
    sequencerRawRow('mysql', $a, 'the-pulse', ['live-pulse']);
    sequencerRawRow('mysql', $a, 'after', ["estate.{$a}.security"]);

    expect(Sequencer::run(Stream::central()))->toBe(7)
        ->and(LiveLog::head())->toBe(7);

    // Every row keeps its seq, so the stream stays contiguous; only the admissible ones go anywhere.
    expect(LiveLog::deliveries()->map(fn ($row) => [$row->channel, (int) $row->seq])->all())->toBe([
        ['estate.'.$a.'.security', 1],
        ['gemini.dispatch', 1],
        ['user.7', 1],
        ['estate.'.$a.'.security', 7],
    ])->and(sequencerChannel("estate.{$a}.security"))->toBe([[1, null], [7, 1]]);

    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "the central stream (an event about estate {$a}) names a channel of estate {$b} (household.{t}.{h})"));
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "the central stream (a platform event) names a channel of estate {$a} (estate.{t}.{feed})"));
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "names a channel of estate {$a} (estate.{t}.passes), which that stream does not carry"));
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'not one App\\Live\\Channels can produce'));

    // The report says which kind of channel, never which record.
    Exceptions::assertNotReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "household.{$b}.5"));
});

it('delivers an estate row that bypassed LiveBus nowhere when it names another estate\'s channel or a central-only one', function () {
    Exceptions::fake();
    LiveLog::enter();

    [$a, $b] = [LiveLog::ESTATE, LiveLog::OTHER_ESTATE];

    sequencerRawRow('tenant', $a, 'other-estate', ["household.{$b}.5"]);
    sequencerRawRow('tenant', $a, 'central-only', ["gemini.{$a}.dispatch"]);
    sequencerRawRow('tenant', $a, 'a-platform-channel', ['user.7']);
    sequencerRawRow('tenant', $a, 'good', ["estate.{$a}.facilities", "estate.{$a}.passes"]);

    expect(Sequencer::run(Stream::estate($a)))->toBe(4)
        ->and(LiveLog::deliveries('tenant')->map(fn ($row) => [$row->channel, (int) $row->seq])->all())->toBe([
            ["estate.{$a}.facilities", 4],
            ["estate.{$a}.passes", 4],
        ]);

    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "estate {$a}'s stream names a channel of estate {$b} (household.{t}.{h})"));
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "names a channel of estate {$a} (gemini.{t}.{feed}), which that stream does not carry"));
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "estate {$a}'s stream names a platform channel (user.{u})"));
});

it('refuses to run inside a transaction that is already open', function () {
    expect(fn () => DB::connection('mysql')->transaction(fn () => Sequencer::run(Stream::central())))
        ->toThrow(LogicException::class, 'transaction');
});

it('takes at most its limit per pass and leaves the rest for the next, still in order', function () {
    foreach (['r1', 'r2', 'r3', 'r4', 'r5'] as $id) {
        sequencerRecord(ProbeEvent::on(Stream::central(), [Channels::user(7)], $id));
    }

    expect(Sequencer::run(Stream::central(), 2))->toBe(2)
        ->and(Sequencer::run(Stream::central(), 2))->toBe(2)
        ->and(Sequencer::run(Stream::central(), 2))->toBe(1)
        ->and(LiveLog::events()->pluck('seq')->map(fn ($seq) => (int) $seq)->all())->toBe([1, 2, 3, 4, 5])
        ->and(sequencerChannel('user.7'))->toBe([[1, null], [2, 1], [3, 2], [4, 3], [5, 4]]);
});
