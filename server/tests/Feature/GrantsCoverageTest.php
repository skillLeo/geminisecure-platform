<?php

declare(strict_types=1);

use App\Console\Commands\ApplyCentralAppendOnlyGrants;
use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Listeners\ReapplyCentralGrantsAfterMigration;
use App\Support\GrantedTables;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| A migration that adds a table must not be able to slip past the grants
|--------------------------------------------------------------------------
|
| THE DEFECT THIS EXISTS TO CATCH, AND WHY NOTHING ELSE IN THIS SUITE CAN.
|
| The application connects to MySQL as `gs_app`, which is deliberately not the
| schema owner. UPDATE and DELETE are withheld from it at DATABASE level and
| handed back one table at a time by `grants:append-only` (central) and
| `grants:estates` (every estate) — see DECISIONS.md D-017. A table added by a
| migration therefore arrives with NO update and NO delete grant until one of
| those commands is run again.
|
| Every other test in this suite is blind to that, structurally and permanently:
| `phpunit.xml` sets `DB_USERNAME=gs_owner`, because RefreshDatabase has to build
| and drop the schema, which `gs_app` cannot and must not do. The suite therefore
| runs with every privilege on every table and can never observe a missing grant.
| Green here, privilege error on the server.
|
| It has happened three times. The estate payroll tables, the estate notices, and
| then the central supervisor-override tables — where the symptom would have been
| that a guard typing the WRONG supervisor PIN got a database error instead of a
| refusal, because recording the failed attempt is an UPDATE.
|
| The estate half is now self-healing: `tenants:migrate` fires DatabaseMigrated,
| which re-grants. The CENTRAL half has no such event, and this is what stands in
| for one — not by checking the server, which this suite cannot do honestly, but
| by refusing to let a table be created without somebody classifying it. That
| classification lives in `App\Support\GrantedTables`, and the act of adding a
| line to it is the moment a person reads "then run grants:append-only".
|
| Proven to bite: remove any one name from `GrantedTables::CENTRAL_MUTABLE` or
| `ESTATE_MUTABLE` and the first test below fails, naming it.
|
*/

/**
 * Every table name a migration creates with a literal string.
 *
 * Deliberately a text scan and not a migration run. Running them would need the
 * database, and would only ever describe the machine it ran on; the files are the
 * thing under review, and they are the thing a code review sees.
 *
 * @return list<string>
 */
function tablesCreatedByMigrationsIn(string $directory): array
{
    $tables = [];

    foreach (glob($directory.'/*.php') ?: [] as $file) {
        preg_match_all(
            '/Schema::create\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/',
            (string) file_get_contents($file),
            $matches,
        );

        foreach ($matches[1] as $table) {
            $tables[] = $table;
        }
    }

    $tables = array_values(array_unique($tables));
    sort($tables);

    return $tables;
}

it('declares every table the central migrations create', function () {
    $created = tablesCreatedByMigrationsIn(database_path('migrations'));

    expect($created)->not->toBeEmpty('the migration scan found nothing - the parser is broken, not the migrations');

    $undeclared = array_values(array_diff($created, GrantedTables::central()));

    expect($undeclared)->toBe([], implode("\n", [
        'These central tables are created by a migration and are unknown to the grants commands:',
        '    '.implode(', ', $undeclared),
        '',
        'The application user holds no UPDATE and no DELETE on a table until',
        '`php artisan grants:append-only` has been run since it was created, and this',
        'suite connects as the schema owner so it can never see that. Add each name to',
        'App\Support\GrantedTables::CENTRAL_MUTABLE (or to CENTRAL_APPEND_ONLY if the',
        'table must never be edited or deleted from), then run, through the lock wrapper:',
        '    php artisan grants:append-only',
    ]));
});

it('declares every table the estate migrations create', function () {
    $created = tablesCreatedByMigrationsIn(database_path('migrations/tenant'));

    expect($created)->not->toBeEmpty('the migration scan found nothing - the parser is broken, not the migrations');

    $undeclared = array_values(array_diff($created, GrantedTables::estate()));

    expect($undeclared)->toBe([], implode("\n", [
        'These estate tables are created by a migration and are unknown to the grants commands:',
        '    '.implode(', ', $undeclared),
        '',
        'Add each name to App\Support\GrantedTables::ESTATE_MUTABLE (or to',
        'ESTATE_APPEND_ONLY if the table must never be edited or deleted from), then run,',
        'through the lock wrapper:',
        '    php artisan grants:estates',
    ]));
});

/*
 * The converse, so the declared lists cannot rot into a wish list.
 *
 * A stale name is not harmless: both commands read information_schema for what to
 * grant, so a name here that no longer exists would never be caught at runtime,
 * and it would quietly absorb a future table of the same name into whichever
 * classification the stale entry happened to hold.
 */
it('declares nothing central that no migration creates', function () {
    $created = tablesCreatedByMigrationsIn(database_path('migrations'));

    $stale = array_values(array_diff(
        GrantedTables::central(),
        $created,
        GrantedTables::NOT_NAMED_LITERALLY_IN_A_MIGRATION,
    ));

    expect($stale)->toBe([], 'declared central but created by no migration: '.implode(', ', $stale));
});

it('declares nothing estate-side that no migration creates', function () {
    $created = tablesCreatedByMigrationsIn(database_path('migrations/tenant'));

    $stale = array_values(array_diff(
        GrantedTables::estate(),
        $created,
        GrantedTables::NOT_NAMED_LITERALLY_IN_A_MIGRATION,
    ));

    expect($stale)->toBe([], 'declared estate-side but created by no migration: '.implode(', ', $stale));
});

/*
 * THE HOLE UNDER THE HOLE.
 *
 * Every test above reads table names out of the migration text, so a migration
 * that does not write the name down defeats all of them in silence. One already
 * had: `store_office_typed_shift_times_in_utc.php` creates its ledger as
 * `Schema::create(self::LEDGER, ...)`, and `site_time_corrections` was live in
 * gs_platform and in every estate while being unknown to this file, to
 * `GrantedTables`, and to anybody reading either.
 *
 * So an indirect create is allowed - some of them are unavoidable, as the spatie
 * permission tables are - but only by name, one file at a time, with the tables it
 * creates declared by hand in NOT_NAMED_LITERALLY_IN_A_MIGRATION.
 */
it('lets no migration hide a table behind an indirect name', function () {
    $hiding = [];

    foreach (['migrations', 'migrations/tenant'] as $directory) {
        foreach (glob(database_path($directory).'/*.php') ?: [] as $file) {
            // `Schema::create(` NOT followed by a quote: a constant, a variable,
            // an array lookup - anything a static reader cannot resolve.
            if (preg_match('/Schema::create\(\s*(?![\'"])/', (string) file_get_contents($file))) {
                $hiding[] = basename($file);
            }
        }
    }

    $hiding = array_values(array_unique($hiding));
    sort($hiding);

    $unaccounted = array_values(array_diff($hiding, GrantedTables::MIGRATIONS_NAMING_TABLES_INDIRECTLY));

    expect($unaccounted)->toBe([], implode("\n", [
        'These migrations create a table under a name no static reader can resolve:',
        '    '.implode(', ', $unaccounted),
        '',
        'The table they create is invisible to every other test in this file, so it can',
        'reach the server without anybody deciding whether the application user may',
        'update it. Declare the table by hand in',
        'App\Support\GrantedTables::NOT_NAMED_LITERALLY_IN_A_MIGRATION and in the matching',
        'CENTRAL_/ESTATE_ list, then add the file name to MIGRATIONS_NAMING_TABLES_INDIRECTLY.',
    ]));
});

it('never classifies one table as both append-only and mutable', function () {
    expect(array_intersect(GrantedTables::CENTRAL_APPEND_ONLY, GrantedTables::CENTRAL_MUTABLE))
        ->toBe([], 'a central table is listed as both append-only and mutable')
        ->and(array_intersect(GrantedTables::ESTATE_APPEND_ONLY, GrantedTables::ESTATE_MUTABLE))
        ->toBe([], 'an estate table is listed as both append-only and mutable');
});

/*
 * A name written twice is harmless to the grants - both commands de-duplicate -
 * but it is the visible symptom of somebody adding a table to the wrong block and
 * not noticing, and the next person reading the list cannot tell which of the two
 * entries is the considered one. Caught the day this file was written.
 */
it('lists every table exactly once', function () {
    $lists = [
        'CENTRAL_APPEND_ONLY' => GrantedTables::CENTRAL_APPEND_ONLY,
        'CENTRAL_MUTABLE' => GrantedTables::CENTRAL_MUTABLE,
        'ESTATE_APPEND_ONLY' => GrantedTables::ESTATE_APPEND_ONLY,
        'ESTATE_MUTABLE' => GrantedTables::ESTATE_MUTABLE,
    ];

    foreach ($lists as $name => $tables) {
        $duplicates = array_values(array_unique(
            array_diff_assoc($tables, array_unique($tables))
        ));

        expect($duplicates)->toBe([], "{$name} lists ".implode(', ', $duplicates).' more than once');
    }
});

/*
 * And the commands must still be driven by the declared list rather than by a
 * second copy of it. A literal list re-introduced into either command would make
 * every test above true and meaningless.
 */
it('drives both grants commands from the declared lists', function () {
    expect(ApplyCentralAppendOnlyGrants::APPEND_ONLY_TABLES)->toBe(GrantedTables::CENTRAL_APPEND_ONLY)
        ->and(ApplyAppendOnlyGrants::APPEND_ONLY_TABLES)->toBe(GrantedTables::ESTATE_APPEND_ONLY);
});

/*
|--------------------------------------------------------------------------
| The central database re-grants itself, the way every estate already did
|--------------------------------------------------------------------------
|
| `ReapplyCentralGrantsAfterMigration` closes the asymmetry: estates re-granted
| on `DatabaseMigrated`, and `gs_platform` relied on somebody remembering a
| command. What is proven here is WHEN it runs and when it stands back, because
| that is the whole of its risk — it must not fire once per estate during
| `tenants:migrate`, must not churn MySQL's grant tables through every
| RefreshDatabase in this suite, and must not grant after a rollback.
|
| What is NOT proven here, and cannot be: the grant itself. This suite connects
| as `gs_owner` (phpunit.xml), who already holds every privilege, so there is no
| honest way to observe `gs_app` gaining one. That half is proven by running
| `php artisan grants:append-only`, which reports PASS or FAIL against the real
| server, and both halves issue the identical statement through
| `App\Support\CentralGrants`.
|
*/

it('re-grants the central database after a real migration up', function () {
    expect(ReapplyCentralGrantsAfterMigration::skipReason('up', [], 'production', false))->toBeNull()
        ->and(ReapplyCentralGrantsAfterMigration::skipReason('up', [], 'local', false))->toBeNull();
});

it('stands back where a re-grant would be wrong or wasted', function () {
    // Once per estate during `tenants:migrate`; the estates have their own event.
    expect(ReapplyCentralGrantsAfterMigration::skipReason('up', [], 'production', true))
        ->toContain('tenant migration')
        // Every RefreshDatabase in this suite, against a database only ever
        // opened as the schema owner.
        ->and(ReapplyCentralGrantsAfterMigration::skipReason('up', [], 'testing', false))
        ->toContain('schema owner')
        // A rollback removes tables; granting one that is gone is an error.
        ->and(ReapplyCentralGrantsAfterMigration::skipReason('down', [], 'production', false))
        ->toContain("'down'")
        // `--pretend` printed SQL and changed no schema.
        ->and(ReapplyCentralGrantsAfterMigration::skipReason('up', ['pretend' => true], 'production', false))
        ->toContain('pretend');
});

it('is actually listening for the end of a migration', function () {
    expect(Event::hasListeners(MigrationsEnded::class))->toBeTrue(
        'AppServiceProvider must register ReapplyCentralGrantsAfterMigration, or the central half is manual again'
    );

    $registered = collect(Event::getRawListeners()[MigrationsEnded::class] ?? [])
        ->map(fn ($listener) => is_string($listener) ? $listener : null)
        ->filter()
        ->all();

    expect($registered)->toContain(ReapplyCentralGrantsAfterMigration::class);
});
