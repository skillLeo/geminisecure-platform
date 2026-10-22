<?php

declare(strict_types=1);

use App\Live\Registry;
use App\Live\Testing\EmitterCoverage;

/*
|--------------------------------------------------------------------------
| Every writer of a tracked table is a known emitter site (DESIGN.md §2.15, the static twin)
|--------------------------------------------------------------------------
|
| EmitterCoverage catches a forgotten LiveBus::record() at runtime, but only on a path some test
| walks. This is its static twin: it reads app/ for every write to a table an event class tracks
| — a query-builder insert, update, upsert or delete, which never fires a model event and so is
| invisible to anything listening for one (PanicFanOut, MessagesController, IncidentsController,
| CommunityController, Dues, RequestInbox and GateController all write this way), and a quiet or
| forced save on a tracked model — and fails when a site is not on the list below.
|
| THE LIST STARTS EMPTY, because nothing is tracked yet. A wiring unit that makes a table live
| adds the table to its event class's `tracked()` and each (file, table) site that writes it,
| having made that site record in the same transaction.
|
*/

/**
 * Sites known to write a tracked table AND to record the matching event in the same transaction.
 *
 * @return list<array{0: string, 1: string}> [path relative to the project, table]
 */
function liveKnownEmitterSites(): array
{
    return [];
}

it('finds no write to a tracked table in app/ outside the known emitter sites', function () {
    $known = liveKnownEmitterSites();

    $unknown = array_values(array_filter(
        EmitterCoverage::writeSites(Registry::tracked(), app_path()),
        static fn (array $site): bool => ! in_array([$site['file'], $site['table']], $known, true),
    ));

    expect($unknown)->toBe([], "These write a tracked table and are not known emitter sites. Make each one record its event in the same transaction, then add it to liveKnownEmitterSites():\n"
        .implode("\n", array_map(static fn (array $site): string => "    {$site['file']}:{$site['line']}  {$site['table']}  ({$site['kind']})", $unknown)));
});

it('lists no known site that no longer writes its table', function () {
    $sites = EmitterCoverage::writeSites(Registry::tracked(), app_path());

    $stale = array_values(array_filter(
        liveKnownEmitterSites(),
        static fn (array $known): bool => ! collect($sites)->contains(static fn (array $site): bool => $site['file'] === $known[0] && $site['table'] === $known[1]),
    ));

    expect($stale)->toBe([], 'known emitter sites that no longer write their table: '.json_encode($stale));
});

it('finds a query-builder write, a quiet save and a forced save of a tracked table, and no read', function () {
    $file = base_path('tests/Live/Fixtures/Writers/FixtureWriter.php');
    $lines = file($file) ?: [];

    // Worked out from the fixture's own text, so moving a line cannot make this pass or fail.
    $expected = [];

    foreach ($lines as $index => $line) {
        $writes = str_contains($line, "table('live_fixture_tickets')") && preg_match('/->(insert|insertGetId|update)\(/', $line);

        if ($writes || str_contains($line, '->saveQuietly(') || str_contains($line, '->forceFill(')) {
            $expected[] = $index + 1;
        }
    }

    $sites = EmitterCoverage::writeSites(
        ['live_fixture_tickets'],
        base_path('tests/Live/Fixtures/Writers'),
        ['FixtureTicket' => 'live_fixture_tickets'],
    );

    expect($expected)->toHaveCount(11)
        ->and(array_column($sites, 'line'))->toBe($expected)
        ->and(array_unique(array_column($sites, 'table')))->toBe(['live_fixture_tickets'])
        ->and(array_unique(array_column($sites, 'file')))->toBe(['tests/Live/Fixtures/Writers/FixtureWriter.php'])
        ->and(array_count_values(array_column($sites, 'kind')))->toBe(['query' => 9, 'model' => 2]);
});

it('reads a column-tracked table by its table name', function () {
    $sites = EmitterCoverage::writeSites(['live_fixture_bookings.status'], base_path('tests/Live/Fixtures/Writers'), []);

    // The static reader cannot tell which columns an update sets; the runtime half can.
    expect($sites)->toHaveCount(3)
        ->and(array_unique(array_column($sites, 'table')))->toBe(['live_fixture_bookings']);
});

/**
 * Every place under app/, database/ and routes/ that writes the live log's own tables: through
 * the query builder on the table's name, in raw SQL, or through a builder on a table passed in by
 * a file that names the table (LivePrune's batched delete).
 *
 * @return list<array{0: string, 1: string}> [path relative to the project, table]
 */
function liveLogWriters(): array
{
    $tables = ['live_events', 'live_deliveries'];
    $writers = [];

    foreach (['app', 'database', 'routes'] as $root) {
        foreach (EmitterCoverage::writeSites($tables, base_path($root), []) as $site) {
            $writers[] = [$site['file'], $site['table']];
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

            foreach ($tables as $table) {
                $raw = preg_match('/\b(?:insert\s+(?:ignore\s+)?into|replace\s+into|update|delete\s+from|truncate(?:\s+table)?)\s+`?'.$table.'\b/i', $source) === 1;
                $passedIn = preg_match('/[\'"]'.$table.'[\'"]/', $source) === 1
                    && preg_match('/->table\(\s*\$\w+\s*\)[^;]*->(?:insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|increment|decrement|truncate)\s*\(/s', $source) === 1;

                if ($raw || $passedIn) {
                    $writers[] = [$path, $table];
                }
            }
        }
    }

    $writers = array_values(array_unique($writers, SORT_REGULAR));
    sort($writers);

    return $writers;
}

it('lets only LiveBus, the relay and live:prune write live_events, and only the Sequencer and live:prune write live_deliveries', function () {
    // The tenant rule is enforced where an event is written and again where it is delivered; a
    // writer that went round both would be the one hole. The relay's files live in app/Live/Relay.
    $allowed = static fn (string $file, string $table): bool => match ($table) {
        'live_events' => $file === 'app/Live/LiveBus.php'
            || str_starts_with($file, 'app/Live/Relay/')
            || $file === 'app/Console/Commands/LivePrune.php',
        'live_deliveries' => $file === 'app/Live/Relay/Sequencer.php'
            || $file === 'app/Console/Commands/LivePrune.php',
        default => false,
    };

    $writers = liveLogWriters();
    $strays = array_values(array_filter($writers, static fn (array $writer): bool => ! $allowed(...$writer)));

    expect($strays)->toBe([], 'These write the live log and are not its writers: '.json_encode($strays));

    // The scan itself is proved on the writers it must find, each by a different route.
    expect($writers)->toContain(['app/Live/LiveBus.php', 'live_events'])
        ->toContain(['app/Live/Relay/Sequencer.php', 'live_events'])
        ->toContain(['app/Live/Relay/Sequencer.php', 'live_deliveries'])
        ->toContain(['app/Console/Commands/LivePrune.php', 'live_events'])
        ->toContain(['app/Console/Commands/LivePrune.php', 'live_deliveries']);
});

it('maps each application model to the table it writes', function () {
    $models = EmitterCoverage::modelTables();

    expect($models['User'] ?? null)->toBe('users')
        ->and($models['GateEvent'] ?? null)->toBe('gate_events')
        ->and($models['DuressAlert'] ?? null)->toBe('duress_alerts');
});
