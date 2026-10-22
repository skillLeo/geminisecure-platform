<?php

declare(strict_types=1);

use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Estate\EstateStructure;
use App\Services\Estate\LotRangeFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| An estate's homes, phase by phase — M25 §2, M27 §1
|--------------------------------------------------------------------------
|
| The client: 2,000 homes, "1999 plus one additional one"; "phase one goes up
| to 336 or something like that"; "phase one has two sides of it… but it's all
| the phase one". So phases are unequal, a phase may be several blocks of lot
| numbers, and the 2,000th home may not be lot 2000.
|
| The boundaries below are an EXAMPLE shaped like that, and nothing here is
| Phoenix Park's: the real ones come from his map, confirmed by him in writing
| (M27 §2). What is proven is the path they will take: the file is refused
| unless every home is in exactly one phase and they add up to 2,000, the console
| shows each phase's blocks before anything is written, and the import makes
| 2,000 homes, each in its phase, filling a 2,000-unit contract exactly.
|
| AN EMPTY ESTATE OF ITS OWN, because the real import goes into an empty
| estate (after the demo's lots are cleared) and refuses any lot that
| already exists. Its units and phases are cleared before every test.
|
*/

/** This file's own estate: its subdomain, its tenant id and its database suffix. */
function lotRangeEstate(): OwnEstate
{
    static $estate = null;

    return $estate ??= new OwnEstate('lotrangetest', 'LRT', 'Lot Range Test Estate');
}

/** The example: five unequal phases, two of them in two blocks, the 2,000th home numbered 2000. Not the client's. */
const LOT_RANGE_EXAMPLE = [
    'Phase 1: 1-150, 200-336',
    'Phase 2: 151-199, 337-700',
    'Phase 3: 701-1100',
    'Phase 4: 1101-1749',
    'Phase 5: 1750-1999, 2000',
];

/** Which phase the example puts a lot in. */
function lotRangeExamplePhase(int $lot): string
{
    return match (true) {
        $lot <= 150, $lot >= 200 && $lot <= 336 => 'Phase 1',
        $lot <= 700 => 'Phase 2',
        $lot <= 1100 => 'Phase 3',
        $lot <= 1749 => 'Phase 4',
        default => 'Phase 5',
    };
}

/** A contract of this many units for the test estate, inside the central transaction. */
function lotRangeContract(int $units): void
{
    $plan = Plan::query()->firstOrCreate(
        ['key' => 'lot-range-test'],
        ['name' => 'Lot Range Test', 'price_per_unit_minor' => 340_00, 'currency' => 'JMD', 'min_units' => 1, 'is_active' => false, 'sort' => 99],
    );

    Subscription::query()->updateOrCreate(
        ['tenant_id' => lotRangeEstate()->key],
        ['plan_id' => $plan->id, 'unit_count' => $units, 'status' => 'active'],
    );
}

beforeEach(function () {
    lotRangeEstate()->boot();

    // Committed in the estate's database, so cleared here: every test starts from an estate with no lots and no phases.
    $owner = DB::connection('mysql_owner');
    $owner->statement('DELETE FROM `'.lotRangeEstate()->database().'`.units');
    $owner->statement('DELETE FROM `'.lotRangeEstate()->database().'`.estate_phases');

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/* ------------------------------------------------------------------ */
/* the phases */
/* ------------------------------------------------------------------ */

it('reads unequal phases, each one or more blocks of lots, in the order given', function () {
    $phases = LotRangeFile::phases(LOT_RANGE_EXAMPLE, 2000, 2000);

    expect(array_column($phases, 'phase'))->toBe(['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5'])
        ->and(array_column($phases, 'count'))->toBe([287, 413, 400, 649, 251])
        ->and($phases[0]['ranges'])->toBe([[1, 150], [200, 336]])
        ->and($phases[0]['written'])->toBe('1-150, 200-336')
        ->and($phases[4]['ranges'])->toBe([[1750, 1999], [2000, 2000]]);

    // The old one-range shape still reads, "=" or ":", "to" or a dash, "and" between blocks.
    expect(LotRangeFile::phases(['Phase 1=1-1000', 'Phase 2: lots 1001 to 1500 and 1501-2000'], 2000, 2000))->toHaveCount(2);
});

it('takes a 2,000th home that is not a plain number, by its name', function () {
    $phases = LotRangeFile::phases(['Phase 1: 1-999', 'Phase 2: 1000-1999, 1999A'], 2000, 2000);

    expect($phases[1]['named'])->toBe(['Lot 1999A'])
        ->and($phases[1]['count'])->toBe(1001);

    expect(LotRangeFile::phases(['Phase 1: 1-999', 'Phase 2: 1000-1999, Club House'], 2000, 2000)[1]['named'])->toBe(['Club House']);
});

it('refuses, naming the lot, any lot in two phases, in no phase, or outside the estate, and a total that is not 2,000', function (array $specs, string $says) {
    expect(fn () => LotRangeFile::phases($specs, 2000, 2000))->toThrow(DomainException::class, $says);
})->with([
    'a lot in two phases' => [['Phase 1: 1-150, 200-336', 'Phase 2: 150-199, 337-2000'], 'Lot 150 is in two phases, Phase 1 and Phase 2.'],
    'a lot in no phase' => [['Phase 1: 1-150, 200-336', 'Phase 2: 152-199, 337-2000'], 'No phase has lot 151.'],
    'a block in no phase' => [['Phase 1: 1-150', 'Phase 2: 200-2000'], 'No phase has lots 151 to 199.'],
    'a lot past the estate' => [['Phase 1: 1-1000', 'Phase 2: 1001-2001'], 'Phase 2 has lot 2001, outside the estate\'s lots 1 to 2000.'],
    'the 2,000th home missing' => [['Phase 1: 1-1000', 'Phase 2: 1001-1999'], 'The phases hold 1,999 homes; the estate has 2,000. Add the missing one'],
    'a named home in two phases' => [['Phase 1: 1-1000, 1999A', 'Phase 2: 1001-1999, 1999A'], 'Lot 1999A is in two phases, Phase 1 and Phase 2.'],
    'a named home that is a number already' => [['Phase 1: 1-1000', 'Phase 2: 1001-1999, Lot 1000'], 'Lot 1000 is in two phases'],
    'a phase twice' => [['Phase 1: 1-1000', 'phase 1: 1001-2000'], 'phase 1 is given twice.'],
    'written backwards' => [['Phase 1: 1000-1', 'Phase 2: 1001-2000'], 'Write the lower lot first.'],
    'a phase with no lots' => [['Phase 1: 1-2000', 'Phase 2: ,'], 'Phase 2 has no lots.'],
    'a name too long for a lot' => [['A phase name far too long to carry: 1-2000'], 'longer than a phase name may be (32 characters)'],
    'not a phase and its lots' => [['Phase 1'], 'is not a phase and its lots.'],
    'no phases at all' => [[], 'Give each phase its lots'],
]);

it('writes the importer\'s own file, phase by phase, and the list the client confirms against the map', function () {
    $phases = LotRangeFile::phases(LOT_RANGE_EXAMPLE, 2000, 2000);
    $lines = explode("\n", rtrim(LotRangeFile::csv($phases), "\n"));

    expect($lines)->toHaveCount(2001)
        ->and($lines[0])->toBe('reference,phase,street,type,status')
        ->and($lines[1])->toBe('Lot 1,"Phase 1",,residential,vacant')
        ->and($lines[150])->toBe('Lot 150,"Phase 1",,residential,vacant')
        ->and($lines[151])->toBe('Lot 200,"Phase 1",,residential,vacant')
        ->and($lines[2000])->toBe('Lot 2000,"Phase 5",,residential,vacant');

    expect(LotRangeFile::confirmation($phases))->toBe([
        'Phase 1: lots 1 to 150, and 200 to 336 (287 homes)',
        'Phase 2: lots 151 to 199, and 337 to 700 (413 homes)',
        'Phase 3: lots 701 to 1100 (400 homes)',
        'Phase 4: lots 1101 to 1749 (649 homes)',
        'Phase 5: lots 1750 to 1999, and 2000 (251 homes)',
        'Total 2,000',
    ]);

    // A named home is its own cell, quoted.
    expect(LotRangeFile::csv(LotRangeFile::phases(['Phase 1: 1-1999, Club House'], 2000, 2000)))->toContain('"Club House","Phase 1",,residential,vacant');
});

it('is a command that writes the file and prints the list to confirm, and writes nothing when the phases do not add up', function () {
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lot-range-'.bin2hex(random_bytes(4)).'.csv';

    try {
        $this->artisan('estate:lot-file', ['--phase' => LOT_RANGE_EXAMPLE, '--out' => $out])
            ->expectsOutputToContain('2,000 homes in 5 phases written to')
            ->expectsOutputToContain('Phase 1: lots 1 to 150, and 200 to 336 (287 homes)')
            ->expectsOutputToContain('Total 2,000')
            ->assertSuccessful();

        expect(file_get_contents($out))->toBe(LotRangeFile::csv(LotRangeFile::phases(LOT_RANGE_EXAMPLE, 2000, 2000)));

        unlink($out);

        $this->artisan('estate:lot-file', ['--phase' => ['Phase 1: 1-1000', 'Phase 2: 1001-1999'], '--out' => $out])
            ->expectsOutputToContain('The phases hold 1,999 homes; the estate has 2,000.')
            ->assertFailed();

        $this->artisan('estate:lot-file', ['--phase' => LOT_RANGE_EXAMPLE])
            ->expectsOutputToContain('Say where to write the file')
            ->assertFailed();

        expect(file_exists($out))->toBeFalse();
    } finally {
        @unlink($out);
    }
});

/* ------------------------------------------------------------------ */
/* the preview's phase by phase table */
/* ------------------------------------------------------------------ */

it('tells each phase\'s lots as every block they run in, names any other reference, and marks the new phases', function () {
    $rows = array_map(
        static fn (array $r): array => ['reference' => $r[0], 'phase' => $r[1]],
        [['Lot 3', 'North'], ['Lot 1', 'North'], ['Lot 2', 'North'], ['Lot 5', 'North'], ['Lot 7', 'North'], ['Lot 9', 'North'], ['Lot 11', 'North'], ['Lot 12', 'North'], ['Lot 1999A', 'South'], ['Clubhouse', 'South']],
    );

    expect(EstateStructure::summarise($rows, ['North']))->toBe([
        ['phase' => 'North', 'count' => 8, 'lots' => 'Lot 1 to Lot 3, Lot 5, Lot 7, Lot 9, Lot 11 to Lot 12', 'new' => true],
        ['phase' => 'South', 'count' => 2, 'lots' => 'Lot 1999A, Clubhouse', 'new' => false],
    ]);
});

/* ------------------------------------------------------------------ */
/* the import, end to end */
/* ------------------------------------------------------------------ */

it('imports 2,000 homes in unequal phases into an empty estate on a 2,000-unit contract: every block shown first, nothing written until Import, then every home once in its phase', function () {
    lotRangeContract(2000);
    $manager = lotRangeEstate()->viewer(Role::PROPERTY_MANAGER);
    $csv = LotRangeFile::csv(LotRangeFile::phases(LOT_RANGE_EXAMPLE, 2000, 2000));

    $previewed = $this->actingAs($manager)
        ->post(lotRangeEstate()->url('/estate/import/preview'), ['file' => UploadedFile::fake()->createWithContent('phoenix-homes.csv', $csv)])
        ->assertRedirect();

    lotRangeEstate()->boot();

    // THE PREVIEW SAYS EXACTLY WHAT WILL CHANGE: 2,000 homes, five new phases, every block of each, and the contract.
    $this->actingAs($manager)
        ->get((string) $previewed->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('contract', ['contracted' => 2000, 'units' => 0])
            ->where('preview.valid', true)
            ->where('preview.errors', [])
            ->where('preview.row_count', 2000)
            ->where('preview.new_phases', ['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5'])
            ->where('preview.by_phase', [
                ['phase' => 'Phase 1', 'count' => 287, 'lots' => 'Lot 1 to Lot 150, Lot 200 to Lot 336', 'new' => true],
                ['phase' => 'Phase 2', 'count' => 413, 'lots' => 'Lot 151 to Lot 199, Lot 337 to Lot 700', 'new' => true],
                ['phase' => 'Phase 3', 'count' => 400, 'lots' => 'Lot 701 to Lot 1100', 'new' => true],
                ['phase' => 'Phase 4', 'count' => 649, 'lots' => 'Lot 1101 to Lot 1749', 'new' => true],
                ['phase' => 'Phase 5', 'count' => 251, 'lots' => 'Lot 1750 to Lot 2000', 'new' => true],
            ]));

    lotRangeEstate()->boot();

    // NOTHING WRITTEN by the preview.
    expect(Unit::query()->count())->toBe(0)
        ->and(Phase::query()->count())->toBe(0);

    parse_str((string) parse_url((string) $previewed->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->actingAs($manager)
        ->post(lotRangeEstate()->url('/estate/import/commit'), ['token' => $query['import']])
        ->assertRedirect(lotRangeEstate()->url('/estate'))
        ->assertSessionHas('success', '2000 units imported, and 5 new phases created.');

    lotRangeEstate()->boot();

    // 2,000 HOMES, NO DUPLICATE, NO GAP, EACH IN ITS PHASE.
    $blocks = Unit::query()->pluck('block', 'reference')->all();

    expect(Unit::query()->count())->toBe(2000)
        ->and(Unit::query()->distinct()->count('reference'))->toBe(2000)
        ->and(array_keys($blocks))->toEqualCanonicalizing(array_map(static fn (int $n): string => 'Lot '.$n, range(1, 2000)));

    foreach (range(1, 2000) as $lot) {
        expect($blocks['Lot '.$lot])->toBe(lotRangeExamplePhase($lot));
    }

    expect(Phase::query()->orderBy('sequence')->pluck('name')->all())->toBe(['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5'])
        ->and(Unit::query()->where('type', 'residential')->where('status', 'vacant')->count())->toBe(2000);

    // The contract is full, exactly, and the screen says so.
    $this->actingAs($manager)
        ->get(lotRangeEstate()->url('/estate'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('contract', ['contracted' => 2000, 'units' => 2000]));

    lotRangeEstate()->boot();

    // RUN AGAIN ON THE LOADED ESTATE, IT IS REFUSED WHOLE: the importer adds homes, it never moves one.
    $again = $this->actingAs($manager)
        ->post(lotRangeEstate()->url('/estate/import/preview'), ['file' => UploadedFile::fake()->createWithContent('phoenix-homes.csv', $csv)]);

    lotRangeEstate()->boot();

    $this->actingAs($manager)
        ->get((string) $again->headers->get('Location'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preview.valid', false)
            ->where('preview.errors.0.message', 'Lot 1 already exists in this estate.'));

    lotRangeEstate()->boot();

    expect(Unit::query()->count())->toBe(2000);
});

it('imports a 2,000th home that has a name, not a number, into its phase', function () {
    lotRangeContract(2000);
    $manager = lotRangeEstate()->viewer(Role::PROPERTY_MANAGER);
    $csv = LotRangeFile::csv(LotRangeFile::phases(['Phase 1: 1-999', 'Phase 2: 1000-1999, 1999A'], 2000, 2000));

    $previewed = $this->actingAs($manager)
        ->post(lotRangeEstate()->url('/estate/import/preview'), ['file' => UploadedFile::fake()->createWithContent('homes.csv', $csv)]);

    lotRangeEstate()->boot();

    $this->actingAs($manager)
        ->get((string) $previewed->headers->get('Location'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preview.valid', true)
            ->where('preview.row_count', 2000)
            ->where('preview.by_phase.1', ['phase' => 'Phase 2', 'count' => 1001, 'lots' => 'Lot 1000 to Lot 1999, Lot 1999A', 'new' => true]));

    lotRangeEstate()->boot();

    parse_str((string) parse_url((string) $previewed->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->actingAs($manager)->post(lotRangeEstate()->url('/estate/import/commit'), ['token' => $query['import']])->assertRedirect();

    lotRangeEstate()->boot();

    expect(Unit::query()->count())->toBe(2000)
        ->and(Unit::query()->where('reference', 'Lot 1999A')->value('block'))->toBe('Phase 2');
});

it('refuses a list past the contract in its preview, before anything is written', function () {
    lotRangeContract(2000);
    $manager = lotRangeEstate()->viewer(Role::PROPERTY_MANAGER);
    $csv = LotRangeFile::csv(LotRangeFile::phases(['Phase 1: 1-1000', 'Phase 2: 1001-2001'], 2001, 2001));

    $previewed = $this->actingAs($manager)
        ->post(lotRangeEstate()->url('/estate/import/preview'), ['file' => UploadedFile::fake()->createWithContent('lots-1-2001.csv', $csv)]);

    lotRangeEstate()->boot();

    $this->actingAs($manager)
        ->get((string) $previewed->headers->get('Location'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preview.valid', false)
            // M32 F6: the sentence that says what is used and what is left; M78 §1, in plain words without "contract".
            ->where('preview.errors.0.message', 'Your estate is allowed 2,000 units; 0 are in use and 2,000 are left. Adding 2,001 would make 2,001. Please contact your service provider to add more.'));

    lotRangeEstate()->boot();

    parse_str((string) parse_url((string) $previewed->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->actingAs($manager)
        ->post(lotRangeEstate()->url('/estate/import/commit'), ['token' => $query['import']])
        ->assertSessionHasErrors('import');

    lotRangeEstate()->boot();

    expect(Unit::query()->count())->toBe(0)
        ->and(Phase::query()->count())->toBe(0);
});
