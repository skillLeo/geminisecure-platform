<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\SiteTime;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The ESTATE's half of the one time rule's data migration — M4 §3.1, §4
|--------------------------------------------------------------------------
|
| `tenant/2026_09_20_161000_store_office_typed_times_in_utc` moves the digits
| already sitting in an estate's tables to the moment they meant: a meeting at
| 10:00, a technician's 2–4 PM window, a blocked afternoon at the pool, a
| booking, a poll that closes at 8 PM. Its central twin — the shifts one — is
| proved run-once-and-reversible in `OneTimeRuleTest`. This one moves far more
| rows and had no test at all.
|
| WHY IT NEEDS A DATABASE OF ITS OWN, and why this file builds one.
|
| `up()` walks EVERY row of five tables. Run against the shared `FacilitiesFixture`
| estate it would shift that estate's correctly stored seed data by five hours for
| every file that ran afterwards — the exact class of failure M4 §4 defect 3 is
| about. So this file provisions `<prefix>clockmigrationtest`, seeds it with the
| real seeders, and is the only thing that ever opens it.
|
| HOW THE ROUND TRIP IS PROVED WITHOUT NAMING A SINGLE VALUE. The estate is seeded
| the RIGHT way round first, so every office-typed column already holds the UTC
| moment it should. Those are remembered, then written back as the wall-clock
| digits a pre-rule estate held. `up()` then has to reproduce every remembered
| value exactly — one assertion per column per row, over whatever the seeders
| happen to make, rather than over three values somebody chose.
|
*/

/** The estate under test, built once per process, in a database nothing else opens. */
function clockMigrationDatabase(): string
{
    static $built = false;

    /*
     * THE CONFIGURED PREFIX, NOT THE LITERAL. `$estate->run()` below opens this estate
     * through tenancy, which names its database `prefix + key` — so a literal only
     * resolves while the prefix is the default. `FacilitiesFixture::database()` has the
     * rest of the reasoning.
     */
    $database = config('tenancy.database.prefix').'clockmigrationtest';

    if ($built) {
        return $database;
    }

    $owner = DB::connection('mysql_owner');
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    config([
        'database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => $database]),
    ]);

    DB::purge('tenant');

    Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--database' => 'tenant', '--force' => true]);

    $was = config('database.default');
    config(['database.default' => 'tenant']);

    try {
        (new EstateFinanceSeeder)->run();
    } finally {
        config(['database.default' => $was]);
    }

    $built = true;

    return $database;
}

/** The migration under test, freshly required each time so its state is never shared. */
function clockMigration(): object
{
    /** @var object $migration */
    $migration = require database_path('migrations/tenant/2026_09_20_161000_store_office_typed_times_in_utc.php');

    return $migration;
}

/**
 * Exactly the columns the migration claims to move.
 *
 * Repeated here ON PURPOSE rather than read off the migration: if somebody adds a table to
 * the migration and not to this list, the round trip below stops covering it and nothing
 * says so — so the list is the test's own statement of what is meant to move.
 *
 * @var array<string, list<string>>
 */
const CLOCK_MOVES = [
    'meetings' => ['starts_at'],
    'maintenance_tickets' => ['eta_starts_at', 'eta_ends_at'],
    'amenity_slots' => ['starts_at', 'ends_at'],
    'amenity_bookings' => ['starts_at', 'ends_at'],
    'ballots' => ['nominations_open_at', 'nominations_close_at', 'opens_at', 'closes_at'],
];

/**
 * Every office-typed value in the estate, as stored — `table:id:column => digits`.
 *
 * @return array<string, string>
 */
function clockValues(): array
{
    $out = [];

    foreach (CLOCK_MOVES as $table => $columns) {
        foreach (DB::connection('tenant')->table($table)->orderBy('id')->get(['id', ...$columns]) as $row) {
            foreach ($columns as $column) {
                if ($row->{$column} !== null) {
                    $out[$table.':'.$row->id.':'.$column] = (string) $row->{$column};
                }
            }
        }
    }

    return $out;
}

/** Write one stored value straight through the query builder, past every cast. */
function clockSet(string $table, int $id, string $column, string $digits): void
{
    DB::connection('tenant')->table($table)->where('id', $id)->update([$column => $digits]);
}

beforeEach(function () {
    clockMigrationDatabase();

    // The estate row is CENTRAL and rolls back with this transaction, so the platform's
    // list of estates is exactly what it was before this file ran.
    DB::connection('mysql')->beginTransaction();

    $this->estate = Tenant::withoutEvents(function (): Tenant {
        $estate = new Tenant;

        $estate->forceFill([
            'id' => 'clockmigrationtest',
            'name' => 'Clock Migration Estate',
            'receipt_prefix' => 'CMT',
            'status' => 'active',
            'timezone' => 'America/Jamaica',
        ])->save();

        return $estate;
    });

    SiteTime::flush();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
});

it('moves every office-typed time in the estate to the moment it meant, once, and puts the digits back on the way down', function () {
    $this->estate->run(function (): void {
        $ledger = 'site_time_corrections';
        $migration = clockMigration();

        /*
         * THE ESTATE AS THE SEEDERS BUILT IT — already right, because the seeders write
         * through the rule. These are the values `up()` has to reproduce.
         */
        $correct = clockValues();

        expect($correct)->not->toBeEmpty('the seeded estate has no office-typed times to move');

        // More than one table's worth, or this proves less than it looks like it proves.
        $tables = collect(array_keys($correct))->map(static fn (string $k): string => explode(':', $k)[0])->unique();
        expect($tables->count())->toBeGreaterThan(2);

        /*
         * NOW PUT THE ESTATE BACK THE WAY A PRE-RULE ONE SAT: the digits the office typed,
         * stored as if they were UTC. The ballots keep whatever seconds they carry, which
         * is what the migration's minute-exact narrowing is about.
         */
        foreach ($correct as $key => $utc) {
            [$table, $id, $column] = explode(':', $key);
            clockSet($table, (int) $id, $column, SiteTime::local(Carbon::parse($utc, 'UTC'))->format('Y-m-d H:i:s'));
        }

        $wallClock = clockValues();
        expect($wallClock)->not->toBe($correct);

        try {
            /* ── UP: every value becomes the moment it meant ─────────── */

            $migration->up();

            $moved = clockValues();
            $entries = DB::connection('tenant')->table($ledger)->get()
                ->mapWithKeys(static fn (object $e): array => [$e->table_name.':'.$e->row_id.':'.$e->column_name => $e])
                ->all();

            $skipped = 0;

            foreach ($correct as $key => $utc) {
                // A ballot stamp that carries seconds is deliberately left alone.
                if (str_starts_with($key, 'ballots:') && ! str_ends_with($wallClock[$key], ':00')) {
                    expect($moved[$key])->toBe($wallClock[$key], $key.' carries seconds and should not have been moved');
                    expect($entries)->not->toHaveKey($key);
                    $skipped++;

                    continue;
                }

                expect($moved[$key])->toBe($utc, $key.' did not come back to the moment it meant');

                // And every move is in the ledger, with the estate's zone BY NAME.
                expect($entries)->toHaveKey($key);
                expect($entries[$key]->wall_clock)->toBe($wallClock[$key])
                    ->and($entries[$key]->utc)->toBe($utc)
                    ->and($entries[$key]->zone)->toBe('America/Jamaica');
            }

            expect(count($entries))->toBe(count($correct) - $skipped);

            /* ── UP AGAIN: the ledger refuses a second shift ──────────── */

            $migration->up();
            $migration->up();

            expect(clockValues())->toBe($moved)
                ->and(DB::connection('tenant')->table($ledger)->count())->toBe(count($entries));

            /* ── DOWN: the digits come back, exactly ──────────────────── */

            $migration->down();

            expect(clockValues())->toBe($wallClock)
                ->and(Schema::connection('tenant')->hasTable($ledger))->toBeFalse();
        } finally {
            /*
             * However this test ends — and wherever it got to — every value is written
             * back to the moment the seeders meant, by hand rather than by running the
             * migration again. A `finally` that called `up()` would shift a correct value
             * a second time if the failure happened before the push-back, which is the
             * one way this file could damage the estate it owns.
             */
            foreach ($correct as $key => $utc) {
                [$table, $id, $column] = explode(':', $key);
                clockSet($table, (int) $id, $column, $utc);
            }

            if (Schema::connection('tenant')->hasTable($ledger)) {
                DB::connection('tenant')->table($ledger)->truncate();
            }
        }
    });
});

it('reads the estate\'s zone by NAME, so the same typed hour is a different moment in July and in January', function () {
    /*
     * The half a fixed offset would pass and a zone name would not. Jamaica keeps no
     * daylight saving, so the estate is moved to one that does — the migration must read
     * `tenants.timezone` and never a number of hours.
     */
    $this->estate->forceFill(['timezone' => 'America/New_York'])->save();
    SiteTime::flush();

    $this->estate->run(function (): void {
        $migration = clockMigration();

        $meeting = DB::connection('tenant')->table('meetings')->orderBy('id')->first(['id', 'starts_at']);

        expect($meeting)->not->toBeNull();

        $restore = (string) $meeting->starts_at;

        try {
            foreach ([['2026-07-15 07:00:00', '2026-07-15 11:00:00'], ['2026-01-15 07:00:00', '2026-01-15 12:00:00']] as [$typed, $utc]) {
                clockSet('meetings', (int) $meeting->id, 'starts_at', $typed);

                $migration->up();

                expect(DB::connection('tenant')->table('meetings')->where('id', $meeting->id)->value('starts_at'))
                    ->toBe($utc, "07:00 in America/New_York on {$typed} is {$utc}, not five hours from anywhere");

                // And the ledger names the zone rather than an offset.
                expect(DB::connection('tenant')->table('site_time_corrections')
                    ->where('table_name', 'meetings')->where('row_id', $meeting->id)->value('zone'))
                    ->toBe('America/New_York');

                $migration->down();
            }
        } finally {
            /*
             * `up()` moves every row in the estate, not only the meeting under test, so
             * whatever it did is undone before anything is put back by hand.
             */
            if (Schema::connection('tenant')->hasTable('site_time_corrections')) {
                $migration->down();
            }

            clockSet('meetings', (int) $meeting->id, 'starts_at', $restore);

            if (Schema::connection('tenant')->hasTable('site_time_corrections')) {
                DB::connection('tenant')->table('site_time_corrections')->truncate();
            }
        }
    });
});

it('leaves a booking a resident made after the rule went live exactly where it is', function () {
    /*
     * THE SECOND NARROWING. A phone sends a moment with its offset, and from the moment
     * the new writers went live that was stored as true UTC — so a resident's own booking
     * made after `LIVE_FROM` must NOT be moved again. Moving it would put a household's
     * Saturday afternoon five hours out in the one direction nobody would think to check.
     */
    $this->estate->run(function (): void {
        $migration = clockMigration();

        $booking = DB::connection('tenant')->table('amenity_bookings')->orderBy('id')->first(['id', 'starts_at', 'resident_id', 'created_at']);

        expect($booking)->not->toBeNull();

        $alreadyUtc = '2026-10-03 18:00:00';

        DB::connection('tenant')->table('amenity_bookings')->where('id', $booking->id)->update([
            'starts_at' => $alreadyUtc,

            // A resident's own booking, made after the rule went live.
            'resident_id' => 1,
            'created_at' => '2026-09-21 09:00:00',
        ]);

        try {
            $migration->up();

            expect(DB::connection('tenant')->table('amenity_bookings')->where('id', $booking->id)->value('starts_at'))
                ->toBe($alreadyUtc, 'a booking already stored in UTC was moved a second time');

            expect(DB::connection('tenant')->table('site_time_corrections')
                ->where('table_name', 'amenity_bookings')->where('row_id', $booking->id)->exists())
                ->toBeFalse();
        } finally {
            // `up()` moved every other row in the estate; that is undone first.
            if (Schema::connection('tenant')->hasTable('site_time_corrections')) {
                $migration->down();
            }

            DB::connection('tenant')->table('amenity_bookings')->where('id', $booking->id)->update([
                'starts_at' => $booking->starts_at,
                'resident_id' => $booking->resident_id,
                'created_at' => $booking->created_at,
            ]);

            if (Schema::connection('tenant')->hasTable('site_time_corrections')) {
                DB::connection('tenant')->table('site_time_corrections')->truncate();
            }
        }
    });
});
