<?php

declare(strict_types=1);

use App\Console\Commands\MobileRestoreSeeded;
use App\Console\Commands\MobileTestAccounts;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\AmenitySlot;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\Tenant;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Estate\FacilitiesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| mobile:restore-seeded — the repair itself, run (M4 §4, defect 4)
|--------------------------------------------------------------------------
|
| Two seeded demonstration records were cancelled by mistake: visitor pass #1 by
| a write probe that expected "not yours" and was wrong, and the seeded Gazebo
| booking #1 by a live check of the Resident App. `MobileRestoreSeeded` puts them
| back. Until this file, NOTHING RAN IT. The only evidence the defect was closed
| was a paragraph in a document, and a repair nobody has executed is a repair
| nobody knows works.
|
| `SeededDemoRecordsTest` pins the INTENDED state — what the seeder makes, and
| that the command refuses to run off a developer's machine. This file does the
| other half: it arranges the exact damage, runs the command, and checks the
| result against a snapshot of the whole estate database taken before the damage.
|
| WHY IT CAN BE RUN HERE. The command is pinned to the estate id `phoenixpark`
| and to row ids 1 and 1 — but an estate id is not a database. stancl resolves a
| tenant's database from `tenancy_db_name` on the row before it falls back to
| prefix + id, so a tenant row whose id is `phoenixpark` and whose db name is
| this file's own database is, to the command, indistinguishable from the real
| thing and, to the real `gs_estate_phoenixpark`, nothing at all.
|
| THAT IS ALSO THE ONE WAY THIS FILE COULD DO REAL HARM, so it is not left to
| care: `onlyOurOwnDatabase()` is called before every write and before every run
| of the command, and throws rather than touch a database whose name is not this
| file's. A test for a mistaken cancellation must not be able to cause one.
|
| THE CENTRAL ROW IS ROLLED BACK; THE ESTATE DATABASE IS NOT — it is dropped and
| rebuilt once per process, and each test puts back what it damaged, because the
| order these run in is random (see `phpunit.xml`).
|
*/

/**
 * This file's own estate database. Never `gs_estate_phoenixpark`, which is a real one.
 *
 * THE CONFIGURED PREFIX, NOT A LITERAL. The tenant row below names this database
 * outright (`tenancy_db_name`), so resolution never depended on the prefix — but a
 * literal is one database shared by every checkout of the suite on the server, and
 * two running at once each drop it out from under the other. `FacilitiesFixture::
 * database()` has the full reasoning. It stays a different name from the estate it
 * stands in for under ANY prefix, which a test below asserts.
 */
function restoreDatabase(): string
{
    return config('tenancy.database.prefix').'restoreseededtest';
}

/** The lot the seeded Gazebo booking belongs to, and Andrea Fletcher's home. */
const RESTORE_LOT = 'Lot 47';

/**
 * Point the tenant connection at this file's database — and refuse to be anywhere else.
 *
 * Returns the database name so a caller can assert on it; throws if tenancy has
 * been left pointing somewhere real.
 */
function onlyOurOwnDatabase(): string
{
    config([
        'database.connections.tenant' => array_merge(
            config('database.connections.mysql'),
            ['database' => restoreDatabase()],
        ),
        'database.default' => 'tenant',
    ]);

    DB::purge('tenant');

    $name = DB::connection('tenant')->getDatabaseName();

    if ($name !== restoreDatabase()) {
        throw new RuntimeException(
            'REFUSING to continue: the tenant connection points at ['.$name.'] and this file writes only to '
            .restoreDatabase().'. Cancelling a record on a real estate is the defect this file tests, not a step in it.'
        );
    }

    return $name;
}

/**
 * The estate the command will find: id `phoenixpark`, database this file's own.
 *
 * Built once per process by the REAL tenant migrations and the REAL
 * `FacilitiesSeeder`, for the reason `FacilitiesFixture` gives — a hand-built
 * booking would prove nothing about the one the seeder makes, and the state the
 * restore puts a booking back to is the seeder's, not this file's.
 */
function restoreEstateDatabase(): void
{
    static $built = false;

    if (! $built) {
        $built = true;

        $owner = DB::connection('mysql_owner');
        $owner->statement('DROP DATABASE IF EXISTS `'.restoreDatabase().'`');
        $owner->statement('CREATE DATABASE `'.restoreDatabase().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        onlyOurOwnDatabase();

        Artisan::call('migrate', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'tenant',
            '--force' => true,
        ]);

        (new ChartOfAccountsSeeder)->run();

        /*
         * The lots `FacilitiesSeeder::BOOKINGS` names, in the phases board 19 puts them in.
         * A booking whose lot does not exist is skipped by the seeder, and the Gazebo at
         * Lot 47 has to be the FIRST row it writes for the command's `BOOKING_ID = 1` to
         * mean what it says.
         */
        foreach ([[RESTORE_LOT, 'Phase 2'], ['Lot 88', 'Phase 4'], ['Lot 63', 'Phase 3'], ['Lot 3', 'Phase 1']] as [$reference, $phase]) {
            Unit::create([
                'reference' => $reference,
                'block' => $phase,
                'street' => $phase.' Drive',
                'type' => 'residential',
                'status' => 'occupied',
            ]);
        }

        (new FacilitiesSeeder)->run();

        /*
         * Andrea Fletcher, at the lot the boards give her. The command finds both records
         * through HER — it will not touch a row that is not her household's — so without
         * her on the register the restore refuses, correctly, and proves nothing.
         */
        $unit = Unit::query()->where('reference', RESTORE_LOT)->firstOrFail();

        $household = Household::query()->firstOrCreate(
            ['unit_id' => $unit->id],
            ['name' => 'Fletcher household', 'access_restricted' => false],
        );

        Resident::query()->firstOrCreate(
            ['household_id' => $household->id, 'email' => MobileTestAccounts::RESIDENT_EMAIL],
            ['full_name' => 'Andrea Fletcher', 'relationship' => 'owner', 'is_primary' => true],
        );

        /*
         * AND THE PASS. No seeder makes visitor passes — the one the probe cancelled was
         * issued by the Resident App — so this is a pass as the issue path leaves it:
         * active, unused, valid from an hour ago for a day. The restore reads its status,
         * its `cancelled_at` and whose unit it is on; the signature, nonce and key version
         * it never looks at, and this file asserts it never rewrites them either.
         */
        VisitorPass::query()->create([
            'pass_id' => (string) Str::uuid(),
            'unit_id' => $unit->id,
            'household_id' => $household->id,
            'issued_by_resident_id' => null,
            'issued_by_name' => 'Andrea Fletcher',
            'category' => 'single',
            'visitor_name' => 'Marcia James',
            'visitor_phone' => '876-555-0123',
            'purpose' => 'Family visit',
            'valid_from' => Carbon::now()->subHour(),
            'valid_to' => Carbon::now()->addDay(),
            'single_use' => true,
            'nonce' => str_repeat('a', 32),
            'key_version' => 1,
            'token' => 'restore-seeded-test-token',
            'code' => 'RSTS-2-0001',
            'status' => VisitorPass::ACTIVE,
        ]);
    }

    onlyOurOwnDatabase();
}

/**
 * The central row the command resolves: id `phoenixpark`, database this file's own.
 *
 * SEPARATE FROM BUILDING THE DATABASE, AND THAT MATTERS. This writes to `tenants` on the CENTRAL
 * connection and therefore holds a row lock there for as long as the test's transaction is open.
 * Building the estate — DROP DATABASE, CREATE DATABASE, migrate, seed — happens BEFORE the
 * transaction is opened, so a failure while building can never leave a lock behind for whatever
 * file runs next to time out against.
 */
function restoreEstate(): Tenant
{
    onlyOurOwnDatabase();

    /*
     * Any row already at this key goes first, through the QUERY BUILDER and not the model:
     * deleting a Tenant fires `TenantDeleted`, which DROPS ITS DATABASE. This is inside the
     * test's own transaction, so a committed row a neighbouring file left would come straight
     * back — and the estate that owns the real database is never in this database at all.
     */
    DB::connection('mysql')->table('tenants')->where('id', MobileTestAccounts::ESTATE)->delete();

    return Tenant::withoutEvents(static function (): Tenant {
        $estate = new Tenant;

        $estate->forceFill([
            'id' => MobileTestAccounts::ESTATE,
            'name' => 'Phoenix Park (restore-seeded test)',

            // Not `PPV`: a receipt prefix is unique across the platform and the seeded
            // Phoenix Park holds that one. Nothing here issues a receipt.
            'receipt_prefix' => 'RSD',
            'status' => 'active',
        ]);

        /*
         * THE WHOLE SAFETY OF THIS FILE IS THIS LINE. stancl reads `tenancy_db_name` off
         * the row before it composes prefix + id, so the command's `Tenant::find('phoenixpark')`
         * opens this file's database and never the estate of the same name.
         */
        $estate->tenancy_db_name = restoreDatabase();

        $estate->save();

        return $estate;
    });
}

/** The seeded Gazebo booking the command names — id 1 on a freshly seeded estate. */
function restoreBookingRow(): AmenityBooking
{
    return AmenityBooking::query()->findOrFail(MobileRestoreSeeded::BOOKING_ID);
}

/** The visitor pass the probe cancelled — id 1. */
function restorePassRow(): VisitorPass
{
    return VisitorPass::query()->findOrFail(MobileRestoreSeeded::PASS_ID);
}

/**
 * Every row in the estate database, table by table, in a deterministic order.
 *
 * "Nothing else moved" is the half of this repair that is easy to get wrong — a
 * restore that also refunds a deposit, releases a diary slot or writes an audit
 * row has done damage of its own — so it is asserted against the WHOLE database
 * rather than against the handful of tables somebody thought to name.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function restoreSnapshot(): array
{
    $connection = DB::connection('tenant');
    $out = [];

    foreach ($connection->select('SHOW TABLES') as $row) {
        $table = (string) array_values((array) $row)[0];

        $rows = array_map(
            static fn (object $record): array => (array) $record,
            $connection->table($table)->get()->all(),
        );

        // Ordered by content, not by key: two runs must compare equal whatever
        // order MySQL happens to hand the rows back in.
        usort($rows, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

        $out[$table] = $rows;
    }

    return $out;
}

/**
 * The snapshot without the two tables the repair is allowed to touch.
 *
 * @param  array<string, list<array<string, mixed>>>  $snapshot
 * @return array<string, list<array<string, mixed>>>
 */
function restoreEverythingElse(array $snapshot): array
{
    /*
     * And the live log: putting the booking back TELLS the Facilities screens it changed (`booking.changed`, M18H
     * §7.2), which is a screen being kept right, not a record moving. It is working state the relay prunes after a week.
     */
    unset($snapshot['visitor_passes'], $snapshot['amenity_bookings'], $snapshot['live_events'], $snapshot['live_streams']);

    return $snapshot;
}

/**
 * One table's rows with `updated_at` dropped.
 *
 * A row put back is a row whose BUSINESS columns are the ones the seeder wrote.
 * `updated_at` is not one of them — a repair that rewrites a status necessarily
 * touches it — so it is dropped here and nowhere else, and every other column,
 * including `created_at`, is held to the byte.
 *
 * @param  array<string, list<array<string, mixed>>>  $snapshot
 * @return list<array<string, mixed>>
 */
function restoreRowsOf(array $snapshot, string $table): array
{
    return array_map(
        static function (array $row): array {
            unset($row['updated_at']);

            return $row;
        },
        $snapshot[$table] ?? [],
    );
}

/** Cancel both records exactly as the two mistakes did: a status and one timestamp. */
function restoreArrangeTheDamage(Carbon $at): void
{
    onlyOurOwnDatabase();

    restorePassRow()->forceFill(['status' => VisitorPass::CANCELLED, 'cancelled_at' => $at])->save();
    restoreBookingRow()->forceFill(['status' => AmenityBooking::CANCELLED, 'cancelled_at' => $at])->save();
}

/** Run the command; return [exit code, output]. */
function restoreRun(string ...$options): array
{
    onlyOurOwnDatabase();

    $code = Artisan::call('mobile:restore-seeded', array_fill_keys($options, true));
    $output = Artisan::output();

    // The command ends tenancy, which puts the connection back where it found it.
    onlyOurOwnDatabase();

    return [$code, $output];
}

beforeEach(function (): void {
    /*
     * THE ESTATE IS BUILT BEFORE THE TRANSACTION IS OPENED, and the order matters both ways.
     * Building it issues DDL and takes a while; opening the central transaction first would hold
     * a lock on `tenants` across all of it. The tenant ROW, on the other hand, must be inside the
     * transaction — it is committed nowhere, so it cannot collide with itself on the next test.
     */
    restoreEstateDatabase();

    DB::connection('mysql')->beginTransaction();

    restoreEstate();

    $this->clean = restoreSnapshot();
});

afterEach(function (): void {
    /*
     * THE CENTRAL TRANSACTION GOES BACK FIRST, WHATEVER HAPPENS NEXT.
     *
     * It holds a row lock on `tenants`, and every other suite's central writes queue behind that
     * lock. A transaction left open by a clean-up that threw is not this file failing — it is a
     * lock-wait timeout fifty seconds later, in a file that did nothing wrong. Rolling back first,
     * unconditionally, is the only ordering that cannot do that.
     */
    if (DB::connection('mysql')->transactionLevel() > 0) {
        DB::connection('mysql')->rollBack();
    }

    /*
     * THEN PUT THE ESTATE BACK BY HAND. The estate database is committed — it has to be, the
     * command opens it on a connection of tenancy's own making — so a test that left booking #1
     * cancelled would hand the next test a fixture that was already damaged. With the order
     * randomised that is not a theory.
     */
    onlyOurOwnDatabase();

    restorePassRow()->forceFill(['status' => VisitorPass::ACTIVE, 'cancelled_at' => null])->save();
    restoreBookingRow()->forceFill(['status' => AmenityBooking::CONFIRMED, 'cancelled_at' => null])->save();

    AmenityBooking::query()->whereKeyNot(MobileRestoreSeeded::BOOKING_ID)->where('reference', 'like', 'RST-%')->delete();
    AmenitySlot::query()->where('reason', 'like', 'restore-seeded test%')->delete();
});

it('is pointed at its own database and not at the estate of the same name', function (): void {
    expect(onlyOurOwnDatabase())->toBe(restoreDatabase())
        ->and(restoreDatabase())->not->toBe(config('tenancy.database.prefix').MobileTestAccounts::ESTATE);

    // And the command really does resolve to it, rather than to prefix + id.
    expect(Tenant::query()->findOrFail(MobileTestAccounts::ESTATE)->database()->getName())->toBe(restoreDatabase());
});

it('puts both cancelled records back exactly as they were, and moves nothing else', function (): void {
    $damagedAt = Carbon::now()->subMinutes(30);

    restoreArrangeTheDamage($damagedAt);

    // The damage is real: both records are cancelled before the command runs.
    expect(restorePassRow()->status)->toBe(VisitorPass::CANCELLED)
        ->and(restorePassRow()->cancelled_at)->not->toBeNull()
        ->and(restoreBookingRow()->status)->toBe(AmenityBooking::CANCELLED)
        ->and(restoreBookingRow()->cancelled_at)->not->toBeNull();

    [$code, $output] = restoreRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('restored');

    $after = restoreSnapshot();

    // 1 — the pass is back: active, no cancellation, every other column untouched.
    expect(restorePassRow()->status)->toBe(VisitorPass::ACTIVE)
        ->and(restorePassRow()->cancelled_at)->toBeNull()
        ->and(restoreRowsOf($after, 'visitor_passes'))->toEqual(restoreRowsOf($this->clean, 'visitor_passes'));

    // 2 — the booking is back: confirmed, no cancellation, the deposit still held,
    //     the reference, the terms and the journal reference all as the seeder left them.
    expect(restoreBookingRow()->status)->toBe(AmenityBooking::CONFIRMED)
        ->and(restoreBookingRow()->cancelled_at)->toBeNull()
        ->and(restoreBookingRow()->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and(restoreRowsOf($after, 'amenity_bookings'))->toEqual(restoreRowsOf($this->clean, 'amenity_bookings'));

    // 3 — AND NOTHING ELSE MOVED. Every other table in the estate database, row for
    //     row: no ledger entry written or undone, no diary slot taken or released, no
    //     audit row, no charge, no payment, no notice.
    expect(restoreEverythingElse($after))->toEqual(restoreEverythingElse($this->clean));
});

it('changes nothing at all on a dry run, and says what it would do', function (): void {
    restoreArrangeTheDamage(Carbon::now()->subHour());

    $damaged = restoreSnapshot();

    [$code, $output] = restoreRun('--dry-run');

    expect($code)->toBe(0)
        ->and($output)->toContain('Would be')
        ->and($output)->toContain('restored');

    // Not "the two rows are unchanged" — the whole database is byte for byte what it was.
    expect(restoreSnapshot())->toEqual($damaged);
});

it('is idempotent: a second run reports both as already right and writes nothing', function (): void {
    restoreArrangeTheDamage(Carbon::now()->subHour());

    [$first] = restoreRun();

    expect($first)->toBe(0);

    $afterFirst = restoreSnapshot();

    [$second, $output] = restoreRun();

    expect($second)->toBe(0)
        ->and($output)->toContain('unchanged')
        ->and($output)->toContain('Already active')
        ->and($output)->toContain('Already confirmed');

    /*
     * BYTE FOR BYTE, `updated_at` INCLUDED. The second run must not re-save a row it has
     * nothing to change — a repair that rewrites a record every time it is run is a repair
     * that cannot be left in anybody's start-up script.
     */
    expect(restoreSnapshot())->toEqual($afterFirst);

    // And nothing was doubled: still one pass, still four seeded bookings.
    expect(VisitorPass::query()->count())->toBe(count($this->clean['visitor_passes']))
        ->and(AmenityBooking::query()->count())->toBe(count($this->clean['amenity_bookings']));
});

it('refuses the booking, and fails, when somebody else has taken the period since', function (): void {
    restoreArrangeTheDamage(Carbon::now()->subHour());

    $booking = restoreBookingRow();

    /*
     * The side effect of the cancel: the Gazebo went back to the diary and another
     * household took the same hours. Two households told the same Sunday is theirs is
     * the one failure the diary exists to prevent, so the restore must NOT win here.
     */
    AmenityBooking::query()->create([
        'reference' => 'RST-'.$booking->starts_at->format('Y-m').'-0001',
        'amenity_id' => $booking->amenity_id,
        'unit_id' => Unit::query()->where('reference', 'Lot 88')->value('id'),
        'resident_name' => 'Sonia Campbell',
        'starts_at' => $booking->starts_at,
        'ends_at' => $booking->ends_at,
        'status' => AmenityBooking::CONFIRMED,
        'fee_minor' => 0,
        'deposit_minor' => 0,
        'currency' => $booking->currency,
        'deposit_state' => AmenityBooking::DEPOSIT_NONE,
    ]);

    [$code, $output] = restoreRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('The period has been booked since');

    // The booking stays cancelled — and the pass, which nothing is wrong with, is still put back.
    expect(restoreBookingRow()->status)->toBe(AmenityBooking::CANCELLED)
        ->and(restorePassRow()->status)->toBe(VisitorPass::ACTIVE);
});

it('refuses the booking when the estate has closed the period since', function (): void {
    restoreArrangeTheDamage(Carbon::now()->subHour());

    $booking = restoreBookingRow();

    AmenitySlot::query()->create([
        'amenity_id' => $booking->amenity_id,
        'kind' => AmenitySlot::BLOCKED,
        'starts_at' => $booking->starts_at,
        'ends_at' => $booking->ends_at,
        'reason' => 'restore-seeded test — the estate closed the Gazebo',
    ]);

    [$code, $output] = restoreRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('closed that period since');

    expect(restoreBookingRow()->status)->toBe(AmenityBooking::CANCELLED);
});

it('leaves a pass that was USED alone — an admission is a real event, not a mistake to undo', function (): void {
    onlyOurOwnDatabase();

    $usedAt = Carbon::now()->subMinutes(5);

    restorePassRow()->forceFill(['status' => VisitorPass::USED, 'used_at' => $usedAt])->save();

    [$code, $output] = restoreRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('a used pass is a real admission');

    expect(restorePassRow()->status)->toBe(VisitorPass::USED)
        ->and(restorePassRow()->used_at?->toIso8601String())->toBe($usedAt->toIso8601String());

    // Put it back for the next test, whatever order that is.
    restorePassRow()->forceFill(['status' => VisitorPass::ACTIVE, 'used_at' => null])->save();
});

it('CANNOT tell a cancellation made on purpose from the bug\'s, and puts a deliberate one back too', function (): void {
    /*
     * THIS TEST DESCRIBES A DEFECT, NOT A FEATURE, and it is written down here because a
     * repair command that is wrong in this particular way is dangerous in a way prose in a
     * document cannot keep anybody safe from.
     *
     * The command's whole test for "was this the bug?" is `status === cancelled`. Neither
     * `visitor_passes` nor `amenity_bookings` records WHO or WHAT cancelled a row — there is
     * no `cancelled_by`, no reason, and (the command's own docblock says so) no audit entry —
     * so a cancellation a resident or the office made deliberately, at any time, looks
     * exactly like the probe's to this command and is silently undone.
     *
     * The safe use of the command is therefore ONCE, by hand, on the dev database the two
     * mistakes were made on. It must not go into a start-up script, a seeder or CI.
     *
     * WHAT WOULD SETTLE IT: provenance on the cancellation. Either a `cancelled_by` /
     * `cancelled_reason` column on both tables, written by the cancel paths, or an audit row
     * per cancellation — and then the command restores only a cancellation whose provenance
     * is the probe's. Until one of those exists no amount of care in this command can tell
     * the two apart, because the information simply is not in the database.
     */
    restoreArrangeTheDamage(Carbon::now()->subHour());

    [$first] = restoreRun();

    expect($first)->toBe(0)
        ->and(restorePassRow()->status)->toBe(VisitorPass::ACTIVE);

    // A PERSON now cancels the pass on purpose, after the repair, for their own reasons.
    $deliberatelyAt = Carbon::now();

    restorePassRow()->forceFill(['status' => VisitorPass::CANCELLED, 'cancelled_at' => $deliberatelyAt])->save();

    [$second] = restoreRun();

    expect($second)->toBe(0);

    // It is back. That is what the command does today, and it is the defect described above.
    expect(restorePassRow()->status)->toBe(VisitorPass::ACTIVE)
        ->and(restorePassRow()->cancelled_at)->toBeNull();
});

it('refuses when Andrea Fletcher is not on the register, and touches neither record', function (): void {
    restoreArrangeTheDamage(Carbon::now()->subHour());

    /*
     * She is taken off the register by changing the address the command looks her up by,
     * rather than by deleting her: the point is that the command cannot tell whose the two
     * records are, and a delete would also have to move her household and her booking.
     */
    $resident = Resident::query()->where('email', MobileTestAccounts::RESIDENT_EMAIL)->firstOrFail();

    $resident->forceFill(['email' => 'not.on.the.register@example.test'])->save();

    // Taken AFTER she is taken off, so the one row this test moved on purpose is inside the
    // baseline and the comparison below is about what the COMMAND did, not what the test did.
    $damaged = restoreSnapshot();

    try {
        [$code, $output] = restoreRun();

        expect($code)->toBe(1)
            ->and($output)->toContain('is not on the register');

        // Nothing at all was written while it could not tell whose the records were.
        expect(restoreSnapshot())->toEqual($damaged);
    } finally {
        onlyOurOwnDatabase();
        $resident->forceFill(['email' => MobileTestAccounts::RESIDENT_EMAIL])->save();
    }
});

it('leaves a pass that is not hers alone', function (): void {
    restoreArrangeTheDamage(Carbon::now()->subHour());

    $pass = restorePassRow();
    $hers = (int) $pass->unit_id;
    $theirs = (int) Unit::query()->where('reference', 'Lot 88')->value('id');

    $pass->forceFill(['unit_id' => $theirs])->save();

    try {
        [$code, $output] = restoreRun();

        expect($code)->toBe(1)
            ->and($output)->toContain('It belongs to another household');

        expect(restorePassRow()->status)->toBe(VisitorPass::CANCELLED);
    } finally {
        onlyOurOwnDatabase();
        restorePassRow()->forceFill(['unit_id' => $hers])->save();
    }
});

it('names the amenity the seeder actually books, so the record it restores is the right one', function (): void {
    expect(MobileRestoreSeeded::BOOKING_AMENITY)->toBe('Gazebo')
        ->and(restoreBookingRow()->amenity->name)->toBe(MobileRestoreSeeded::BOOKING_AMENITY)
        ->and((int) restoreBookingRow()->unit_id)->toBe((int) Unit::query()->where('reference', RESTORE_LOT)->value('id'))
        ->and(Amenity::query()->where('name', MobileRestoreSeeded::BOOKING_AMENITY)->exists())->toBeTrue();
});
