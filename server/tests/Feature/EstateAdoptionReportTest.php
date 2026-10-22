<?php

declare(strict_types=1);

use App\Models\ClientAdoption;
use App\Models\Estate\Ballot;
use App\Services\Estate\AdoptionReport;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| What an estate is allowed to say about itself to the platform
|--------------------------------------------------------------------------
|
| Board 07 is the report that tests this platform's hardest rule: a cross-client
| screen reads gs_platform and never opens an estate database. `AdoptionRollup`
| counts the two capabilities Gemini operates; `AdoptionReport` is the other
| half, running inside each estate and pushing five more outward.
|
| That push is the only place estate data crosses the boundary on purpose, so it
| is the place to assert what may cross. Two integers and a sentence. No name, no
| household, no unit, no money — and, for governance, no way back to a voter.
|
*/

function adoptionEstate(): string
{
    static $built = false;

    // The configured prefix, never the literal: see `FacilitiesFixture::database()`.
    $database = config('tenancy.database.prefix').'adoptiontest';

    config([
        'database.connections.tenant' => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        ),
        'database.default' => 'tenant',
    ]);

    DB::purge('tenant');

    if ($built) {
        return $database;
    }

    $owner = DB::connection('mysql_owner');
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::purge('tenant');

    /*
     * A real tenant row on the central side, because `client_adoption.tenant_id`
     * is a foreign key to it — which is itself worth knowing: the roll-up cannot
     * hold a figure for an estate the platform has never heard of, so a stale
     * report cannot outlive the client it describes. The row carries no database
     * of its own; this suite drives `AdoptionReport` directly rather than through
     * `$tenant->run()`, and the tenant connection is already pointed at the
     * fixture above.
     *
     * WRITTEN BEFORE THE ESTATE IS BUILT, AND WITH A RECEIPT PREFIX ON IT. Both
     * halves are load-bearing, and this file spent six failures proving it.
     *
     * The seeder below banks six months of receipts, and `Receipts::prefix()`
     * looks the prefix up on THIS row, found from the connection's database name.
     * It has one indulgence for a fixture — a database no estate record names at
     * all numbers from its own name under test — and this row took the fixture
     * out of that branch while giving it nothing to use: written AFTER the seed,
     * the first run of this file seeded happily against no record, then left a
     * record behind with `receipt_prefix` NULL. Nothing rolls it back (this suite
     * does not use `RefreshDatabase`), so every later run found a record that
     * existed and carried no prefix, and `Receipts` rightly refused to number a
     * receipt under a series nobody chose — taking all six tests down inside
     * `beforeEach`, in isolation, for a reason that had nothing to do with
     * adoption.
     *
     * So the record is written first and carries `ADO`, and the prefix is in the
     * UPDATE half of `updateOrInsert` as well as the insert, so a database still
     * holding the prefix-less row from an older run is repaired by the next run
     * rather than being poisoned by it for good.
     */
    DB::connection('mysql')->table('tenants')->updateOrInsert(
        ['id' => 'adoptiontest'],
        [
            'name' => 'Adoption Test Estate',
            'receipt_prefix' => 'ADO',
            'data' => json_encode(['name' => 'Adoption Test Estate']),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--database' => 'tenant',
        '--force' => true,
    ]);

    Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);

    $built = true;

    return $database;
}

/** The rows this estate pushed, read back off the central table. */
function adoptionRows(string $tenantId = 'adoptiontest')
{
    return ClientAdoption::on('mysql')->where('tenant_id', $tenantId)->get()->keyBy('module_key');
}

beforeEach(function () {
    adoptionEstate();

    ClientAdoption::on('mysql')->where('tenant_id', 'adoptiontest')->delete();

    app(AdoptionReport::class)->push('adoptiontest');
});

it('reports every capability the estate owns and none that Gemini owns', function () {
    $rows = adoptionRows();

    /*
     * The five the estate operates. `guard_app` and `visitor_passes` are
     * Gemini's — counted from platform data by `AdoptionRollup` — and this class
     * must not write them, or two writers would race over the same row and the
     * last one to run would win.
     */
    expect($rows->keys()->sort()->values()->all())->toBe([
        'dues_ledger',
        'facilities',
        'governance',
        'notices',
        'payroll',
    ]);

    foreach ($rows as $row) {
        expect($row->reported_by)->toBe(ClientAdoption::BY_ESTATE);
    }
});

it('sends two integers and a sentence, and nothing that names anybody', function () {
    $rows = adoptionRows();

    /*
     * The whole point of the roll-up. What crosses the boundary is a numerator,
     * a denominator and a description — `gs_platform` ends up knowing "212 of
     * 450" and never whose 212. This scans the detail sentences for the
     * vocabulary of a person or a sum of money, against an estate that holds 450
     * units, 433 households, four employees on J$440,000 of payroll and a ledger
     * carrying J$1,840,000 of arrears.
     */
    $forbidden = ['Fletcher', 'Morgan', 'Anderson', 'Clarke', 'Thomas', 'Lot ', 'J$', '$'];

    foreach ($rows as $row) {
        expect($row->detail)->toBeString();

        foreach ($forbidden as $needle) {
            expect($row->detail)->not->toContain($needle, $row->module_key.' leaks '.$needle);
        }
    }

    // And the table itself has no column that could carry one.
    $columns = Schema::connection('mysql')->getColumnListing('client_adoption');

    expect($columns)->not->toContain('unit_id')
        ->and($columns)->not->toContain('resident_id')
        ->and($columns)->not->toContain('amount_minor');
});

it('counts governance turnout from receipts and never from marks', function () {
    $ballot = Ballot::query()->whereNotNull('opens_at')->orderByDesc('opens_at')->first();

    if ($ballot === null) {
        $this->markTestSkipped('This estate has no opened ballot to measure turnout against.');
    }

    $receipts = (int) DB::connection('tenant')
        ->table('ballot_receipts')
        ->where('ballot_id', $ballot->id)
        ->count();

    $row = adoptionRows()->get('governance');

    /*
     * TURNOUT IS RECEIPTS, AND RECEIPTS HOLD NO CHOICE. `ballot_marks` is the
     * table that knows what was voted for, and nothing in this path touches it —
     * a cross-client report is the last place a ballot should become linkable to
     * a voter. The denominator is the ballot's OWN snapshotted electorate rather
     * than today's household count, so a turnout figure does not move when a
     * unit is sold.
     */
    expect($row->adopted)->toBe($receipts)
        ->and($row->eligible)->toBe((int) $ballot->eligible_households);

    // The mark count is deliberately NOT what was reported. On a multi-position
    // ballot there are more marks than voters, and reporting those as turnout
    // would put an estate above 100% and invite somebody to "fix" it by
    // dividing marks by voters — which is the join that must never exist.
    $marks = (int) DB::connection('tenant')->table('ballot_marks')->count();

    if ($marks !== $receipts) {
        expect($row->adopted)->not->toBe($marks);
    }
});

it('reports nothing to measure rather than nought per cent', function () {
    /*
     * An estate that has never held an election has not failed at governance.
     * `eligible = 0` is what carries that, and `ClientAdoption::isMeasurable()`
     * is what stops board 07 drawing a bar at the bottom of a health list for a
     * client who has done nothing wrong.
     */
    $empty = new ClientAdoption(['adopted' => 0, 'eligible' => 0]);

    expect($empty->isMeasurable())->toBeFalse()
        ->and($empty->percentage())->toBe(0);

    foreach (adoptionRows() as $row) {
        if (! $row->isMeasurable()) {
            expect($row->adopted)->toBe(0);
        }
    }
});

it('never reports a capability as more adopted than it is eligible for', function () {
    /*
     * A percentage over 100 is the shape of a numerator and a denominator
     * counting different things — marks against voters, employees against runs.
     * `percentage()` clamps for display; this asserts the underlying figures do
     * not need clamping, because a clamp hides the mistake rather than catching
     * it.
     */
    foreach (adoptionRows() as $row) {
        expect($row->adopted)->toBeLessThanOrEqual(
            $row->eligible,
            $row->module_key.' reports '.$row->adopted.' adopted out of '.$row->eligible.' eligible.',
        );
    }
});

it('leaves the rows Gemini owns alone', function () {
    /*
     * Written by hand as if `adoption:rollup` had run, then the estate reports
     * again. Two writers, one table, and neither may overwrite the other — the
     * shape that would otherwise make client health depend on which command ran
     * last.
     */
    ClientAdoption::on('mysql')->updateOrCreate(
        ['tenant_id' => 'adoptiontest', 'module_key' => 'guard_app'],
        [
            'label' => 'Guard App coverage',
            'adopted' => 3,
            'eligible' => 4,
            'detail' => '3 of 4 active posts worked.',
            'reported_by' => ClientAdoption::BY_GEMINI,
            'measured_at' => now(),
        ],
    );

    app(AdoptionReport::class)->push('adoptiontest');

    $guardApp = adoptionRows()->get('guard_app');

    expect($guardApp)->not->toBeNull()
        ->and($guardApp->adopted)->toBe(3)
        ->and($guardApp->eligible)->toBe(4)
        ->and($guardApp->reported_by)->toBe(ClientAdoption::BY_GEMINI);
});
