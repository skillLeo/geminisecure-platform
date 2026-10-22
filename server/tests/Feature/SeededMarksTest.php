<?php

declare(strict_types=1);

use App\Models\Estate\Notice;
use App\Support\Seeded\MarkedSeedCommand;
use App\Support\Seeded\Retirements;
use App\Support\Seeded\SeededTables;
use App\Support\Seeded\Seeding;
use App\Support\Seeded\SeedsMarkedRows;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\EstateScaleFixture;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Every row a seeder writes says so, and no other row does (M33 §5.2, §5.5, KI-25)
|--------------------------------------------------------------------------
|
| `seeded_at` and `seeded_by` go on every tenant-scoped table a seeder writes to, set by the seeder and never by the
| application. This file is the build failing when that stops being true: a table nobody classified, a seeder whose
| rows come out unmarked, a seeder that writes past the builder that marks, an application write that sets a mark.
|
*/

it('classifies every table that belongs to an estate, and every marked one carries both columns', function () {
    FacilitiesFixture::boot();

    $estate = DB::connection('tenant');
    $tables = array_map(static fn (object $row): string => (string) $row->t, $estate->select(
        "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
        [$estate->getDatabaseName()],
    ));

    foreach ($tables as $table) {
        expect(in_array($table, SeededTables::ESTATE_MACHINERY, true) || SeededTables::marked('tenant', $table) || array_key_exists($table, SeededTables::ESTATE_THROUGH_PARENT))
            ->toBeTrue("The estate table `{$table}` is on none of SeededTables' lists. Mark it — a tenant migration adding seeded_at and seeded_by — or name it machinery.");
    }

    // A ballot's marks carry nothing but their option and their own id (BallotUnlinkabilityTest): never a mark.
    foreach (array_keys(SeededTables::ESTATE_THROUGH_PARENT) as $table) {
        expect(SeededTables::marked('tenant', $table))->toBeFalse("`{$table}` is retired with its parent and carries no mark of its own.")
            ->and(Schema::connection('tenant')->hasColumn($table, 'seeded_at'))->toBeFalse("`{$table}` has a seeded_at column it must not have.");
    }

    foreach (SeededTables::estateMarked() as $table) {
        expect(in_array($table, $tables, true))->toBeTrue("SeededTables marks `{$table}`, which no estate has.")
            ->and(Schema::connection('tenant')->hasColumns($table, SeededTables::MARKS))->toBeTrue("The estate table `{$table}` carries no mark.");
    }

    $central = DB::connection('mysql');
    $theirs = array_map(static fn (object $row): string => (string) $row->t, $central->select(
        "SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME IN ('tenant_id', 'site_id')",
        [$central->getDatabaseName()],
    ));

    foreach ($theirs as $table) {
        expect(array_key_exists($table, SeededTables::CENTRAL_MARKED) || in_array($table, SeededTables::CENTRAL_MACHINERY, true))
            ->toBeTrue("The platform table `{$table}` names an estate and is on none of SeededTables' lists. Mark it, or name it machinery.");
    }

    foreach (SeededTables::CENTRAL_MARKED as $table => $how) {
        expect(Schema::connection('mysql')->hasColumns($table, SeededTables::MARKS))->toBeTrue("The platform table `{$table}` carries no mark.");

        if ($how[0] === 'column') {
            expect(Schema::connection('mysql')->hasColumn($table, $how[1]))->toBeTrue("`{$table}` says which estate by `{$how[1]}`, which it does not have.");
        }

        if ($how[0] === 'parent') {
            expect(Schema::connection('mysql')->hasColumn($table, $how[1]))->toBeTrue("`{$table}` hangs from its parent by `{$how[1]}`, which it does not have.")
                ->and(Schema::connection('mysql')->hasColumn($how[2], 'tenant_id'))->toBeTrue("`{$table}`'s parent `{$how[2]}` says no estate.");
        }
    }

    expect(array_values(array_diff(SeededTables::CENTRAL_KEPT, array_keys(SeededTables::CENTRAL_MARKED))))->toBe([])
        ->and(array_values(array_intersect(SeededTables::ESTATE_KEPT, SeededTables::ESTATE_RETIRED)))->toBe([])
        ->and(array_values(array_intersect(SeededTables::ESTATE_MACHINERY, SeededTables::estateMarked())))->toBe([]);
});

it('marks every row the seeders wrote into an estate at its production size, with the command that wrote it', function () {
    EstateScaleFixture::boot();

    /*
     * Rows the estate's MIGRATIONS write, which no seeder did and which therefore carry no mark: the receipt counter's
     * first row (`2026_09_11_160000_number_receipts_per_estate`). Any other unmarked row is a seeder's that was missed.
     */
    $writtenByMigrations = ['receipt_sequences' => 1];

    $unmarked = [];
    foreach (SeededTables::estateMarked() as $table) {
        $count = (int) DB::connection('tenant')->table($table)->whereNull('seeded_at')->count() - ($writtenByMigrations[$table] ?? 0);

        if ($count > 0) {
            $unmarked[$table] = $count;
        }
    }

    expect($unmarked)->toBe([], 'Rows a seeder wrote with no mark: '.json_encode($unmarked))
        ->and(DB::connection('tenant')->table('charges')->count())->toBe(EstateScaleFixture::CHARGES)
        ->and(DB::connection('tenant')->table('units')->distinct()->orderBy('seeded_by')->pluck('seeded_by')->all())->toBe(['db:seed', 'phoenix:demo-seed'])
        ->and(DB::connection('tenant')->table('households')->whereNotNull('seeded_at')->count())->toBe(EstateScaleFixture::HOUSEHOLDS);
});

it('marks every platform row a seeding command writes for an estate, and leaves the platform\'s own catalogue unmarked', function () {
    EstateScaleFixture::boot();
    DB::connection('mysql')->beginTransaction();

    try {
        EstateScaleFixture::enter();

        // Every marked table with an id to say which rows are new (the estate's site row is keyed by the estate).
        $tables = array_values(array_filter(array_keys(SeededTables::CENTRAL_MARKED), static fn (string $t): bool => Schema::connection('mysql')->hasColumn($t, 'id')));

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = (int) DB::connection('mysql')->table($table)->max('id');
        }

        $this->artisan('company:demo-seed', ['--estate' => EstateScaleFixture::ESTATE])->assertSuccessful()->run();
        EstateScaleFixture::boot();

        $written = [];
        $unmarked = [];
        foreach ($tables as $table) {
            $new = DB::connection('mysql')->table($table)->where('id', '>', $before[$table]);
            $written[$table] = (int) (clone $new)->count();

            if ((int) (clone $new)->whereNull('seeded_at')->count() > 0) {
                $unmarked[] = $table;
            }
        }

        expect($unmarked)->toBe([], 'Platform rows a seeding command wrote with no mark: '.implode(', ', $unmarked))
            ->and($written['guards'])->toBeGreaterThan(0)
            ->and($written['invoices'])->toBeGreaterThan(0)
            ->and(DB::connection('mysql')->table('guards')->where('id', '>', $before['guards'])->distinct()->pluck('seeded_by')->all())->toBe(['company:demo-seed'])
            // The rate card and the tiers are the platform's, not an estate's: they are never marked.
            ->and(Schema::connection('mysql')->hasColumn('plans', 'seeded_at'))->toBeFalse();
    } finally {
        DB::connection('mysql')->rollBack();
    }
});

it('never marks a row the application writes, never changes a mark, and never hands one back unasked', function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $ids = [];

    try {
        expect(Seeding::active())->toBeFalse();

        // A request that tries to set them is ignored, on both databases, through Eloquent and the query builder alike.
        $method = DB::connection('mysql')->table('payment_methods')->insertGetId([
            'tenant_id' => FacilitiesFixture::ESTATE, 'institution' => 'M33 test bank', 'last_four' => '0033', 'is_default' => false,
            'seeded_at' => now(), 'seeded_by' => 'a request', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $notice = new Notice;
        $notice->forceFill(['title' => 'M33 a person wrote this', 'body' => 'Written by the application.', 'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'M33', 'kind' => Notice::GENERAL, 'seeded_at' => now(), 'seeded_by' => 'a model'])->save();
        $ids[] = $notice->id;

        expect(DB::connection('mysql')->table('payment_methods')->where('id', $method)->value('seeded_by'))->toBeNull()
            ->and(DB::connection('tenant')->table('notices')->where('id', $notice->id)->value('seeded_at'))->toBeNull();

        // A seeding command's row is marked as it is written, and with what wrote it.
        $seeded = Seeding::run('phoenix:demo-seed', static fn (): int => DB::connection('tenant')->table('notices')->insertGetId([
            'title' => 'M33 a seeder wrote this', 'body' => 'Written by a seeder.', 'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'M33', 'kind' => Notice::GENERAL, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $ids[] = $seeded;
        $mark = DB::connection('tenant')->table('notices')->where('id', $seeded)->first(['seeded_at', 'seeded_by']);

        expect($mark->seeded_by)->toBe('phoenix:demo-seed')->and($mark->seeded_at)->not->toBeNull();

        // An update never changes a mark, whoever makes it, a re-seed included; an upsert in a re-seed never marks a real row.
        DB::connection('tenant')->table('notices')->where('id', $seeded)->update(['title' => 'M33 edited', 'seeded_at' => null, 'seeded_by' => 'an edit']);
        Seeding::run('db:seed', static fn () => DB::connection('tenant')->table('notices')->where('id', $notice->id)->update(['body' => 'Re-seeded.', 'seeded_by' => 'db:seed']));
        Seeding::run('db:seed', static fn () => DB::connection('mysql')->table('payment_methods')->upsert(
            [['id' => $method, 'tenant_id' => FacilitiesFixture::ESTATE, 'institution' => 'M33 test bank', 'last_four' => '0034', 'is_default' => false]],
            ['id'],
        ));

        expect(DB::connection('tenant')->table('notices')->where('id', $seeded)->value('seeded_by'))->toBe('phoenix:demo-seed')
            ->and(DB::connection('tenant')->table('notices')->where('id', $notice->id)->value('seeded_by'))->toBeNull()
            ->and(DB::connection('mysql')->table('payment_methods')->where('id', $method)->value('last_four'))->toBe('0034')
            ->and(DB::connection('mysql')->table('payment_methods')->where('id', $method)->value('seeded_at'))->toBeNull();

        // Asked for by name, the mark is read; a `*` never hands it back, so a model or an API response is as it was.
        expect((array) DB::connection('tenant')->table('notices')->where('id', $seeded)->first())->not->toHaveKey('seeded_at')
            ->and(Notice::query()->findOrFail($seeded)->getAttributes())->not->toHaveKey('seeded_by')
            ->and(DB::connection('tenant')->table('notices')->where('id', $seeded)->value('seeded_by'))->toBe('phoenix:demo-seed');
    } finally {
        DB::connection('mysql')->rollBack();
        DB::connection('tenant')->table('notices')->whereIn('id', $ids)->delete();
    }
});

it('writes every seeded row through the builder that marks it, and only the seeding commands seed', function () {
    $sources = [
        ...glob(database_path('seeders/*.php')),
        ...glob(database_path('seeders/Estate/*.php')),
        app_path('Console/Commands/DemoSeed.php'),
        app_path('Console/Commands/PhoenixDemoSeed.php'),
        app_path('Console/Commands/CompanyDemoSeed.php'),
    ];

    foreach ($sources as $file) {
        $code = (string) file_get_contents($file);
        $name = basename($file);

        // A raw INSERT, an INSERT … SELECT or the owner's connection would write a row the mark never sees.
        expect(preg_match('/\binsert\s+(ignore\s+)?into\b/i', $code))->toBe(0, "{$name} writes a raw INSERT, which no mark reaches.")
            ->and(preg_match('/insert(OrIgnore)?Using\s*\(/', $code))->toBe(0, "{$name} inserts from a sub-select, which no mark reaches.")
            ->and(preg_match('/(mysql_owner\'\)|\$owner)\s*->\s*table\s*\(/', $code))->toBe(0, "{$name} writes through the owner's connection, which no mark reaches.");
    }

    // Every command that seeds marks its rows on the command itself, and says so; or says why it does not seed.
    $notSeeding = [
        'mobile:restore-seeded' => 'puts two seeded demo records back to their seeded state; it inserts nothing',
        'estate:rewrite-seeded-contacts' => 'moves seeded people\'s addresses and numbers to ones nobody has (M35 §5.3); it inserts nothing',
        'phoenix:demo-purge' => 'removes what a seed wrote; it writes no row of an estate',
    ];

    foreach (glob(app_path('Console/Commands/*.php')) as $file) {
        if (preg_match('/protected \$signature = \'([a-z0-9:-]+)/', (string) file_get_contents($file), $m) !== 1 || ! str_contains($m[1], 'seed') || array_key_exists($m[1], $notSeeding)) {
            continue;
        }

        $class = 'App\\Console\\Commands\\'.basename($file, '.php');

        expect(in_array($m[1], Seeding::SEEDING_COMMANDS, true))->toBeTrue("`{$m[1]}` seeds, and is not in Seeding::SEEDING_COMMANDS.")
            ->and(in_array(SeedsMarkedRows::class, class_uses($class), true))->toBeTrue("`{$m[1]}` seeds without SeedsMarkedRows: its rows would carry no mark.");
    }

    // `db:seed` is Laravel's own command with the mark, wherever it is run from.
    expect(Artisan::all()['db:seed'])->toBeInstanceOf(MarkedSeedCommand::class);

    // A seeder is reached only through `db:seed`: nothing in the application runs one by hand.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            expect(preg_match('/new\s+\\\\?(Database\\\\Seeders\\\\)?[A-Za-z]+Seeder\b|Seeder::class\)\s*->\s*(run|__invoke)\(/', (string) file_get_contents($file->getPathname())))
                ->toBe(0, $file->getFilename().' runs a seeder outside `db:seed`, where no mark is switched on.');
        }
    }

    expect(Retirements::blindCommand('phoenix:demo-seed'))->toBeTrue('a seeding run sees every row it writes over');
});
