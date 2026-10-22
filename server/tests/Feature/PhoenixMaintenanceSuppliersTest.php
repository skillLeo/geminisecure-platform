<?php

declare(strict_types=1);

use App\Console\Commands\PhoenixMaintenanceSuppliers;
use App\Models\Estate\Vendor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| phoenix:maintenance-suppliers: the electricity company off the maintenance list, kept for bills; Gate2 renamed (DR-035)
|--------------------------------------------------------------------------
|
| On an estate of this file's own, so renaming a supplier the shared fixture's tickets name cannot move another test.
|
*/

function suppliersEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('supplierstest', 'SPT', 'Suppliers Test Estate');
}

beforeEach(function (): void {
    suppliersEstate()->boot();
    DB::connection('mysql')->beginTransaction();

    Vendor::query()->whereIn('name', [PhoenixMaintenanceSuppliers::UTILITY, PhoenixMaintenanceSuppliers::OLD_NAME, PhoenixMaintenanceSuppliers::NEW_NAME, 'Island Electric Services', 'Unfiled Plumbing'])->delete();

    $this->utility = Vendor::query()->create(['name' => PhoenixMaintenanceSuppliers::UTILITY, 'category' => 'Electricity', 'status' => Vendor::ACTIVE]);
    $this->gate = Vendor::query()->create(['name' => PhoenixMaintenanceSuppliers::OLD_NAME, 'category' => 'Barrier & access systems', 'status' => Vendor::ACTIVE]);
    $this->other = Vendor::query()->create(['name' => 'Island Electric Services', 'category' => 'Electrical contractor', 'status' => Vendor::ACTIVE]);
    $this->unfiled = Vendor::query()->create(['name' => 'Unfiled Plumbing', 'category' => null, 'status' => Vendor::ACTIVE]);
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
    suppliersEstate()->boot();
});

it('offers a work order every active supplier but a utility, the unfiled included', function (): void {
    $this->utility->forceFill(['category' => Vendor::UTILITIES])->save();
    $offered = Vendor::query()->takesMaintenanceWork()->pluck('name')->all();

    expect($offered)->toContain(PhoenixMaintenanceSuppliers::OLD_NAME, 'Island Electric Services', 'Unfiled Plumbing')
        ->not->toContain(PhoenixMaintenanceSuppliers::UTILITY);

    $this->other->forceFill(['status' => Vendor::INACTIVE])->save();
    expect(Vendor::query()->takesMaintenanceWork()->pluck('name')->all())->not->toContain('Island Electric Services');
});

it('changes nothing without --confirm, and says what it would', function (): void {
    expect(Artisan::call('phoenix:maintenance-suppliers', ['--estate' => 'supplierstest']))->toBe(0);
    $said = Artisan::output();
    suppliersEstate()->boot();

    expect($said)->toContain(PhoenixMaintenanceSuppliers::NEW_NAME)->toContain('kept for bills')->toContain('Nothing is written without --confirm')
        ->and($this->gate->fresh()->name)->toBe(PhoenixMaintenanceSuppliers::OLD_NAME)
        ->and($this->utility->fresh()->category)->toBe('Electricity');
});

it('files the utility under Utilities and renames Gate2 on the same record, once, and audits it', function (): void {
    expect(Artisan::call('phoenix:maintenance-suppliers', ['--estate' => 'supplierstest', '--confirm' => true]))->toBe(0);
    suppliersEstate()->boot();

    $utility = $this->utility->fresh();
    $gate = Vendor::query()->findOrFail($this->gate->id);

    expect($utility->category)->toBe(Vendor::UTILITIES)
        ->and($utility->status)->toBe(Vendor::ACTIVE)
        ->and($gate->name)->toBe(PhoenixMaintenanceSuppliers::NEW_NAME)
        ->and($this->other->fresh()->name)->toBe('Island Electric Services')
        ->and(Vendor::query()->takesMaintenanceWork()->pluck('name')->all())->toContain(PhoenixMaintenanceSuppliers::NEW_NAME)->not->toContain(PhoenixMaintenanceSuppliers::UTILITY)
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'vendor.maintenance_suppliers_set')->where('tenant_id', 'supplierstest')->exists())->toBeTrue();

    expect(Artisan::call('phoenix:maintenance-suppliers', ['--estate' => 'supplierstest', '--confirm' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to change');
});

it('refuses an estate it does not know, and changes nothing', function (): void {
    expect(Artisan::call('phoenix:maintenance-suppliers', ['--estate' => 'no-such-estate', '--confirm' => true]))->not->toBe(0)
        ->and(Artisan::output())->toContain('Nothing was changed');
});
