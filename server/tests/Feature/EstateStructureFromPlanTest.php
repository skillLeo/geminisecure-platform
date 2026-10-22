<?php

declare(strict_types=1);

use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Estate\EstateStructure;
use App\Services\Estate\PhasesFromLots;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The real Phoenix Park: phases from the site plan, a phase worked out from the lot (M57.5 §4.2, §5)
|--------------------------------------------------------------------------
|
| `estate:structure-from-plan` on the shared facilities estate, with a plan of this file's own: five lots the boards
| name (Lot 3, 9, 47, 63, 88), filed by the fixture as Phases 1, 1, 2, 3 and 4. The plan puts Lot 47 in Phase 1, Lot 63
| in Phase 2 and Lot 88 in Phase 3, as the real plan moves Lot 47, 55 and 1202.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED, and turning `units.block` into a computed column changes it for every
| file after this one: every test that makes the change puts it back with the command's own --restore.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->planFile = storage_path('framework/testing/structure-plan-'.getmypid().'.json');
    File::ensureDirectoryExists(dirname($this->planFile));
    File::put($this->planFile, json_encode([
        'source' => 'A test plan',
        'phases' => [
            ['name' => 'Phase 1', 'first_lot' => 1, 'last_lot' => 50, 'count' => 50, 'blocks' => [
                ['letter' => 'A', 'units' => 25, 'lots' => [[1, 25]]],
                ['letter' => 'B', 'units' => 25, 'lots' => [[26, 50]]],
            ]],
            ['name' => 'Phase 2', 'first_lot' => 51, 'last_lot' => 70, 'count' => 20, 'blocks' => []],
            ['name' => 'Phase 3', 'first_lot' => 71, 'last_lot' => 90, 'count' => 20, 'blocks' => []],
            ['name' => 'Phase 4', 'first_lot' => 91, 'last_lot' => 9000, 'count' => 8910, 'blocks' => []],
            ['name' => 'Phase 5', 'first_lot' => 9001, 'last_lot' => 9999, 'count' => 999, 'blocks' => []],
        ],
        'boundary' => ['source' => 'A test outline', 'accuracy_m' => 50, 'points' => [[17.95, -76.94], [17.97, -76.94], [17.97, -76.92], [17.95, -76.92]]],
    ]));

    $this->restoreFrom = null;

    // The five phase rows a real estate has (the fixture files its lots under phase names with no rows); this file's own.
    $this->madePhases = [];
    foreach (['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5'] as $i => $name) {
        if (! Phase::query()->where('name', $name)->exists()) {
            $this->madePhases[] = (int) DB::connection('tenant')->table('estate_phases')->insertGetId([
                'name' => $name, 'sequence' => 900 + $i, 'block_count' => 6, 'officers_assigned' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
});

afterEach(function () {
    // Whatever a test changed in the shared estate goes back, for every file after this one.
    if ($this->restoreFrom !== null) {
        Artisan::call('estate:structure-from-plan', ['--estate' => FacilitiesFixture::ESTATE, '--restore' => $this->restoreFrom]);
        File::deleteDirectory($this->restoreFrom);
    }

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    Unit::forgetPhaseDerivation();
    File::delete($this->planFile);
    DB::connection('tenant')->table('estate_phases')->whereIn('id', $this->madePhases)->delete();

    expect(PhasesFromLots::derived('tenant'))->toBeFalse();
});

/** @param array<string, mixed> $options */
function structureFromPlan(array $options): array
{
    $code = Artisan::call('estate:structure-from-plan', ['--estate' => FacilitiesFixture::ESTATE, ...$options]);
    $output = Artisan::output();
    FacilitiesFixture::boot();
    Unit::forgetPhaseDerivation();

    return [$code, $output];
}

it('shows everything on the dry run and changes nothing', function () {
    $before = DB::connection('tenant')->table('units')->orderBy('id')->pluck('block', 'reference')->all();

    [$code, $out] = structureFromPlan(['--plan' => $this->planFile]);

    expect($code)->toBe(0)
        ->and($out)->toContain('DRY RUN, nothing is changed')
        ->and($out)->toContain('Proof:     SELECT DATABASE() on the connection every statement below runs on = '.FacilitiesFixture::database().'  (the same database)')
        ->and($out)->toContain('WHERE id = ')
        ->and($out)->toContain("AND name = 'Phase 1'")
        ->and($out)->toContain('virtual after `lot_number`')
        ->and($out)->toContain('Lot 2000: none in this estate.')
        ->and($out)->toContain('Phase 2 to Phase 1')
        ->and($out)->toContain('HOUSEHOLDS ON LOTS WHOSE PHASE CHANGES')
        ->and($out)->toContain('estate_sites')
        ->and($out)->toContain('<4 points>')
        ->and($out)->toContain('ballot_receipts (votes cast)')
        ->and($out)->toContain('BACKUP, WRITTEN BEFORE ANY STATEMENT ON THE REAL RUN')
        ->and($out)->toContain('DRY RUN: nothing was changed.');

    expect(PhasesFromLots::derived('tenant'))->toBeFalse()
        ->and(DB::connection('tenant')->table('units')->orderBy('id')->pluck('block', 'reference')->all())->toBe($before)
        ->and(Phase::query()->whereNotNull('first_lot')->count())->toBe(0);
});

it('works every phase out from the lot on the real run, keeps every unit, and the restore puts it all back', function () {
    $before = DB::connection('tenant')->table('units')->orderBy('id')->pluck('block', 'reference')->all();
    $units = DB::connection('tenant')->table('units')->count();

    [$code, $out] = structureFromPlan(['--plan' => $this->planFile, '--confirm' => true]);
    preg_match('/Backup written: (.+)$/m', $out, $m);
    $this->restoreFrom = trim($m[1] ?? '');

    expect($code)->toBe(0)
        ->and($this->restoreFrom)->not->toBe('')
        ->and(is_file($this->restoreFrom.'/units.jsonl'))->toBeTrue()
        ->and($out)->toContain('Untouched rows: the same before and after');

    // Worked out by MySQL from the lot number, for every unit, and nothing deleted.
    expect(PhasesFromLots::derived('tenant'))->toBeTrue()
        ->and(DB::connection('tenant')->table('units')->count())->toBe($units)
        ->and(Unit::query()->where('reference', 'Lot 47')->first()->addressLabel())->toBe('Phase 1 · Lot 47')
        ->and(Unit::query()->where('reference', 'Lot 63')->value('block'))->toBe('Phase 2')
        ->and(Unit::query()->where('reference', 'Lot 88')->value('block'))->toBe('Phase 3')
        ->and(Phase::query()->where('name', 'Phase 1')->first()->blockLetters())->toBe(['A', 'B'])
        // M78 §3: "2 blocks · …" and "No blocks drawn · …" (they read "… on the site plan · …").
        ->and(Phase::query()->where('name', 'Phase 1')->first()->footLine())->toStartWith('2 blocks · ')
        ->and(Phase::query()->where('name', 'Phase 2')->first()->footLine())->toStartWith('No blocks drawn · ');

    // The boundary on the platform's site row, and the real run's own audit entry.
    expect(json_decode((string) DB::connection('mysql')->table('estate_sites')->where('tenant_id', FacilitiesFixture::ESTATE)->value('boundary'), true))->toHaveCount(4)
        ->and(DB::connection('mysql')->table('audit_log')->where('tenant_id', FacilitiesFixture::ESTATE)->where('action', 'estate.structure_from_plan')->exists())->toBeTrue();

    // Nothing can put a phase on a unit again: a model write is dropped, a raw one refused by MySQL.
    $unit = Unit::query()->where('reference', 'Lot 47')->first();
    $unit->block = 'Phase 9';
    $unit->save();
    expect(Unit::query()->where('reference', 'Lot 47')->value('block'))->toBe('Phase 1');
    expect(fn () => DB::connection('tenant')->table('units')->where('reference', 'Lot 47')->update(['block' => 'Phase 9']))->toThrow(QueryException::class);

    // The restore: back to a stored phase holding exactly what it held.
    Artisan::call('estate:structure-from-plan', ['--estate' => FacilitiesFixture::ESTATE, '--restore' => $this->restoreFrom]);
    FacilitiesFixture::boot();
    Unit::forgetPhaseDerivation();

    expect(PhasesFromLots::derived('tenant'))->toBeFalse()
        ->and(DB::connection('tenant')->table('units')->orderBy('id')->pluck('block', 'reference')->all())->toBe($before)
        ->and(Phase::query()->whereNotNull('first_lot')->count())->toBe(0);

    File::deleteDirectory($this->restoreFrom);
    $this->restoreFrom = null;
});

it('refuses a plan whose blocks do not cover their phase, and changes nothing', function () {
    $plan = json_decode(File::get($this->planFile), true);
    $plan['phases'][0]['blocks'][1]['units'] = 24;
    File::put($this->planFile, json_encode($plan));

    [$code, $out] = structureFromPlan(['--plan' => $this->planFile, '--confirm' => true]);

    expect($code)->not->toBe(0)
        ->and($out)->toContain('Block B of Phase 1 draws 24 units and holds 25 lots. Nothing was changed.')
        ->and(PhasesFromLots::derived('tenant'))->toBeFalse();
});

it('keeps a plan-built estate to its plan: no new phase, and an import row is checked against the plan', function () {
    DB::connection('tenant')->table('estate_phases')->where('name', 'Phase 1')->update(['first_lot' => 1, 'last_lot' => 50]);
    DB::connection('tenant')->table('estate_phases')->where('name', 'Phase 2')->update(['first_lot' => 51, 'last_lot' => 70]);

    try {
        $structure = app(EstateStructure::class);

        expect(fn () => $structure->addPhase('Phase 9', 1, null, null, null, FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN)))
            ->toThrow(DomainException::class, EstateStructure::PLAN_REFUSAL);

        $parsed = $structure->parseUnits("reference,phase\nLot 52,Phase 1\nLot 300,Phase 2\nOV-1A,Phase 1\n");
        $messages = array_column($parsed['errors'], 'message');

        expect($parsed['valid'])->toBeFalse()
            ->and($messages)->toContain('Lot 52 is in Phase 2 on the site plan, not Phase 1.')
            ->and($messages)->toContain('Lot 300 is not on the site plan.')
            ->and($messages)->toContain('This estate\'s lots are numbered on its site plan, so a reference is "Lot" and a number, not "OV-1A".');

        $standing = $structure->standing();
        expect($standing['built'])->toBe(70)
            ->and($standing['plan_refusal'])->toBe(EstateStructure::PLAN_REFUSAL);
    } finally {
        DB::connection('tenant')->table('estate_phases')->update(['first_lot' => null, 'last_lot' => null]);
    }
});
