<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| units:occupancy: the 443 against 441 drift, read and put right (M81, M85 A8)
|--------------------------------------------------------------------------
|
| "Two units flagged occupied with no household behind them. The query is written. Run it, fix what it finds, and
| report both numbers." The dashboard's "Occupied units" counts lots flagged occupied (443 on live); the register
| counts the households living on the lots (441). This prints the query's findings and both numbers, and with --confirm
| makes each lot's flag say what the register says: flagged occupied with nobody living there, vacant; somebody living
| there and not flagged, occupied. Dry run unless told --confirm.
|
| An estate database of this file's own, its five lots written fresh for each case.
|
*/

const M85_OCCUPANCY_ESTATE = 'occupancytest';

beforeEach(function (): void {
    static $built = false;

    $database = config('tenancy.database.prefix').M85_OCCUPANCY_ESTATE;

    if (! $built) {
        $owner = DB::connection('mysql_owner');
        $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
        $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
        DB::purge('tenant');
        Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--database' => 'tenant', '--force' => true]);

        $built = true;
    }

    config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
    DB::purge('tenant');

    // Six lots: lived in and flagged; flagged with nobody ever; flagged after its household was removed; lived in and
    // flagged vacant; vacant and empty; flagged with a household nobody lives in, which is not one of the estate's
    // households (M87 §1.4, `Household::counted()`).
    $tenant = DB::connection('tenant');
    $tenant->table('residents')->delete();
    $tenant->table('households')->delete();
    $tenant->table('units')->delete();

    $lot = static fn (string $reference, string $block, string $status): int => (int) $tenant->table('units')->insertGetId([
        'reference' => $reference, 'block' => $block, 'street' => $block.' Drive', 'type' => 'residential', 'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $household = static fn (int $unit, string $name, ?string $removedAt = null): int => (int) $tenant->table('households')->insertGetId([
        'unit_id' => $unit, 'name' => $name, 'access_restricted' => false, 'removed_at' => $removedAt,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $resident = static fn (int $household, string $name) => $tenant->table('residents')->insert([
        'household_id' => $household, 'full_name' => $name, 'relationship' => 'owner', 'is_primary' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $resident($household($lot('Lot 1', 'Phase 1', 'occupied'), 'Lot 1 household'), 'Lot 1 resident');
    $lot('Lot 2', 'Phase 1', 'occupied');
    $resident($household($lot('Lot 3', 'Phase 1', 'occupied'), 'Lot 3 household', '2026-10-05 14:00:00'), 'Lot 3 resident');
    $resident($household($lot('Lot 4', 'Phase 2', 'vacant'), 'Lot 4 household'), 'Lot 4 resident');
    $lot('Lot 5', 'Phase 2', 'vacant');
    $household($lot('Lot 6', 'Phase 2', 'occupied'), 'Lot 6 household');

    DB::connection('mysql')->beginTransaction();
    DB::connection('mysql')->table('tenants')->insert([
        'id' => M85_OCCUPANCY_ESTATE, 'name' => 'Occupancy Test Estate', 'receipt_prefix' => 'OCC', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
    tenancy()->end();
});

/** Each lot's flag, read straight from the table. @return array<string, string> */
function m85Flags(): array
{
    config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => config('tenancy.database.prefix').M85_OCCUPANCY_ESTATE])]);
    DB::purge('tenant');

    return DB::connection('tenant')->table('units')->orderBy('reference')->pluck('status', 'reference')->all();
}

it('prints what it finds and both numbers, and changes nothing without --confirm', function (): void {
    expect(Artisan::call('units:occupancy', ['--estate' => M85_OCCUPANCY_ESTATE]))->toBe(0);
    $dry = Artisan::output();

    foreach ([
        'Lots marked occupied: 4. Households on the lots: 2.',
        'FLAGGED OCCUPIED, NOBODY LIVING THERE: 3',
        'Lot 2',
        'Lot 3',
        'Lot 6',
        'a household removed here on Oct 5, 2026',
        'a household with nobody in it',
        'LIVED IN, NOT FLAGGED OCCUPIED: 1',
        'Lot 4',
        'After --confirm: lots marked occupied: 2. Households on the lots: 2.',
        'Nothing is written without --confirm.',
    ] as $said) {
        expect(str_contains($dry, $said))->toBeTrue('the dry run does not say: '.$said);
    }

    expect(m85Flags())->toBe(['Lot 1' => 'occupied', 'Lot 2' => 'occupied', 'Lot 3' => 'occupied', 'Lot 4' => 'vacant', 'Lot 5' => 'vacant', 'Lot 6' => 'occupied']);
});

it('makes each flag say what the register says with --confirm, audits it, and finds nothing the next time', function (): void {
    expect(Artisan::call('units:occupancy', ['--estate' => M85_OCCUPANCY_ESTATE, '--confirm' => true]))->toBe(0);
    $done = Artisan::output();

    expect(m85Flags())->toBe(['Lot 1' => 'occupied', 'Lot 2' => 'vacant', 'Lot 3' => 'vacant', 'Lot 4' => 'occupied', 'Lot 5' => 'vacant', 'Lot 6' => 'vacant'])
        ->and(str_contains($done, 'Done: 3 lots marked vacant, 1 marked occupied. Lots marked occupied: 2. Households on the lots: 2.'))->toBeTrue()
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'estate.unit_occupancy_corrected')->where('tenant_id', M85_OCCUPANCY_ESTATE)->exists())->toBeTrue();

    expect(Artisan::call('units:occupancy', ['--estate' => M85_OCCUPANCY_ESTATE, '--confirm' => true]))->toBe(0)
        ->and(str_contains(Artisan::output(), 'Every lot\'s flag says what the register says. Nothing was changed.'))->toBeTrue();
});

it('refuses an estate it does not know, and changes nothing', function (): void {
    expect(Artisan::call('units:occupancy', ['--estate' => 'no-such-estate', '--confirm' => true]))->not->toBe(0)
        ->and(str_contains(Artisan::output(), 'Nothing was changed'))->toBeTrue();
});
