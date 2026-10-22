<?php

declare(strict_types=1);

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\Relay\Sequencer;
use App\Live\Relay\Waker;
use App\Live\Stream;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;

/*
|--------------------------------------------------------------------------
| live:prune: retention that never costs a client an event silently (DESIGN.md §1.8, §5.5)
|--------------------------------------------------------------------------
|
| The log is kept for `live.retention_days` (seven) and then pruned, and `floor_seq` records how
| far. What makes that safe is the contract with catch-up: EVERY SEQ ABOVE THE FLOOR IS STILL IN
| THE LOG. A cursor at or above the floor is answered exactly; one below it is told `reset`, and
| the screen resyncs. So the prune removes only a contiguous prefix of the stream, and it stops
| at the first row it must keep: one that was never published (the relay may still send it), or
| one still held. Rows it keeps past the cut-off are alarmed, because a row that old and still
| unpublished means the relay is not doing its job. A row scheduled for later is neither.
|
*/

beforeEach(function () {
    LiveLog::reset();
    app()->instance(Waker::class, new SpyWaker);
    config(['live.retention_days' => 7]);
});

afterEach(function () {
    LiveLog::restoreClock('mysql');

    if (tenancy()->initialized) {
        LiveLog::restoreClock('tenant');
    }

    LiveLog::leave();
    DB::connection('mysql')->table('tenants')->where('id', 'livelognodb')->delete();
});

function prunedRecord(string $id, bool $held = false, string $connection = 'mysql', ?Stream $stream = null): void
{
    $stream ??= Stream::central();
    $channel = $stream->isEstate() ? Channels::estateFeed((string) $stream->tenantId, 'facilities') : Channels::user(7);

    DB::connection($connection)->transaction(fn () => LiveBus::record(
        new ProbeEvent($stream, [new Audience([$channel])], id: $id, isHeld: $held),
    ));
}

function prunedPublish(string $id, string $connection = 'mysql'): void
{
    DB::connection($connection)->table('live_events')->where('entity_id', $id)->update(['published_at' => DB::raw('UTC_TIMESTAMP(6)')]);
}

/** @return list<string> */
function prunedLeft(string $connection = 'mysql'): array
{
    return DB::connection($connection)->table('live_events')->orderBy('id')->pluck('entity_id')->all();
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function prune(array $arguments = ['--stream' => ['c']]): array
{
    $exit = Artisan::call('live:prune', $arguments);

    return [$exit, Artisan::output()];
}

it('advances floor_seq and keeps unpublished and held rows', function () {
    // Eight days ago: a1..a4 are sequenced; a3 is never published; h1 is held and never released.
    LiveLog::moveClock('mysql', -8 * 86400);

    foreach (['a1', 'a2', 'a3', 'a4'] as $id) {
        prunedRecord($id);
    }

    prunedRecord('h1', held: true);

    expect(Sequencer::run(Stream::central()))->toBe(4);

    foreach (['a1', 'a2', 'a4'] as $id) {
        prunedPublish($id);
    }

    LiveLog::restoreClock('mysql');

    // Today: b1 is recent and not yet sequenced.
    prunedRecord('b1');

    [$exit, $output] = prune();

    // a1 and a2 go. a3 stops the prune: it was never published, so the relay may still send it,
    // and a4 is kept behind it, because deleting past a kept row would leave a hole above the floor.
    expect(prunedLeft())->toBe(['a3', 'a4', 'h1', 'b1'])
        ->and(LiveLog::floor())->toBe(2)
        ->and(LiveLog::deliveries()->pluck('seq')->map(fn ($seq) => (int) $seq)->all())->toBe([3, 4]);

    // The contract catch-up relies on: every seq above the floor is still in the log.
    $retained = DB::connection('mysql')->table('live_events')->whereNotNull('seq')->orderBy('seq')->pluck('seq')->map(fn ($seq) => (int) $seq)->all();
    expect($retained)->toBe(range(LiveLog::floor() + 1, LiveLog::head()));

    // Kept past the cut-off means the relay is not doing its job: said loudly, and the run fails.
    expect($exit)->toBe(1)
        ->and($output)->toContain('ALARM')
        ->and($output)->toContain('seq 3')
        ->and($output)->toContain('held');
});

it('prunes a row the relay gave up on (publish_failed_at) like a published one', function () {
    LiveLog::moveClock('mysql', -8 * 86400);

    prunedRecord('p1');
    prunedRecord('p2');
    Sequencer::run(Stream::central());

    prunedPublish('p1');
    DB::connection('mysql')->table('live_events')->where('entity_id', 'p2')->update(['publish_failed_at' => DB::raw('UTC_TIMESTAMP(6)')]);

    LiveLog::restoreClock('mysql');

    [$exit, $output] = prune();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('ALARM')
        ->and(prunedLeft())->toBe([])
        ->and(LiveLog::floor())->toBe(2);
});

it('deletes in batches until the prefix is gone', function () {
    LiveLog::moveClock('mysql', -8 * 86400);

    foreach (range(1, 7) as $n) {
        prunedRecord('x'.$n);
    }

    Sequencer::run(Stream::central());
    DB::connection('mysql')->table('live_events')->update(['published_at' => DB::raw('UTC_TIMESTAMP(6)')]);
    LiveLog::restoreClock('mysql');

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements) {
        if (str_starts_with($query->sql, 'delete from `live_') || str_starts_with($query->sql, 'SET TRANSACTION')) {
            $statements[] = $query->sql;
        }
    });

    [$exit] = prune(['--stream' => ['c'], '--batch' => 3]);

    $deletes = array_values(array_filter($statements, fn (string $sql) => str_starts_with($sql, 'delete from `live_events`')));

    expect($exit)->toBe(0)
        ->and(prunedLeft())->toBe([])
        ->and(LiveLog::floor())->toBe(7)
        ->and(count($deletes))->toBeGreaterThanOrEqual(3);

    // Every batch runs READ COMMITTED, so no range delete holds the gap the sequencer writes into.
    foreach ($statements as $index => $sql) {
        if (str_starts_with($sql, 'delete from')) {
            expect($statements[$index - 1] ?? null)->toBe('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
    }
});

it('leaves the floor where it is when nothing is old enough', function () {
    prunedRecord('fresh');
    Sequencer::run(Stream::central());
    prunedPublish('fresh');

    [$exit, $output] = prune();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('ALARM')
        ->and(prunedLeft())->toBe(['fresh'])
        ->and(LiveLog::floor())->toBe(0);
});

it('alarms, and keeps, an old row that is due but was never sequenced', function () {
    LiveLog::moveClock('mysql', -8 * 86400);
    prunedRecord('stuck');
    LiveLog::restoreClock('mysql');

    [$exit, $output] = prune();

    expect($exit)->toBe(1)
        ->and($output)->toContain('ALARM')
        ->and($output)->toContain('never sequenced')
        ->and(prunedLeft())->toBe(['stuck']);
});

it('prunes each estate in its own database, and one estate that cannot be opened stops no other stream', function () {
    LiveLog::enter();
    LiveLog::moveClock('tenant', -8 * 86400);
    prunedRecord('e1', connection: 'tenant', stream: Stream::estate(LiveLog::ESTATE));
    prunedRecord('e2', connection: 'tenant', stream: Stream::estate(LiveLog::ESTATE));
    Sequencer::run(Stream::estate(LiveLog::ESTATE));
    prunedPublish('e1', 'tenant');
    prunedPublish('e2', 'tenant');
    LiveLog::restoreClock('tenant');
    LiveLog::leave();

    LiveLog::moveClock('mysql', -8 * 86400);
    prunedRecord('c1');
    Sequencer::run(Stream::central());
    prunedPublish('c1');
    LiveLog::restoreClock('mysql');

    // An estate row with no database behind it, and a key that is no estate at all.
    DB::connection('mysql')->table('tenants')->insert(['id' => 'livelognodb', 'name' => 'No database', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    [$exit, $output] = prune(['--stream' => ['e:livelognodb', 'e:nosuchestate', 'c', 'e:'.LiveLog::ESTATE]]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('livelognodb')
        ->and($output)->toContain('nosuchestate')
        ->and(prunedLeft())->toBe([])
        ->and(LiveLog::floor())->toBe(1)
        ->and(tenancy()->initialized)->toBeFalse();

    LiveLog::estate();
    tenancy()->initialize(LiveLog::estate());

    expect(prunedLeft('tenant'))->toBe([])
        ->and(LiveLog::floor('tenant'))->toBe(2);
});

it('is scheduled daily at 03:50, after the other prunes', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'live:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('50 3 * * *');
});
