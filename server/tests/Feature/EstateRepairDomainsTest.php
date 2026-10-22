<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| estate:repair-domains reads every estate as an estate
|--------------------------------------------------------------------------
|
| The command reads its estates through the builder's cursor rather than
| `get()`, whose `TenantCollection` declares no item type (the PHPStan pass of
| the bug hunt). This holds the behaviour steady across that change: it reports
| without writing, writes only with --fix, and refuses an estate that is not there.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    Tenant::withoutEvents(static function (): void {
        (new Tenant)->forceFill(['id' => 'repairprobe', 'name' => 'Repair Probe', 'receipt_prefix' => 'RPP', 'status' => 'active'])->save();
    });
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();
});

it('reports an estate with no domain row, and writes it only when told to', function () {
    $domains = static fn (): array => DB::connection('mysql')->table('domains')->where('tenant_id', 'repairprobe')->pluck('domain')->all();

    expect(Artisan::call('estate:repair-domains', ['subdomain' => 'repairprobe']))->toBe(0)
        ->and(Artisan::output())->toContain('NO CONSOLE')->toContain('Re-run with --fix')
        ->and($domains())->toBe([]);

    expect(Artisan::call('estate:repair-domains', ['subdomain' => 'repairprobe', '--fix' => true]))->toBe(0)
        ->and($domains())->toBe(['repairprobe']);

    // And once it has its row, it says so and writes nothing more.
    expect(Artisan::call('estate:repair-domains', ['subdomain' => 'repairprobe', '--fix' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Every estate has the domain row')
        ->and($domains())->toBe(['repairprobe']);
});

it('reads every estate when none is named, and refuses one that does not exist', function () {
    expect(Artisan::call('estate:repair-domains'))->toBe(0)
        ->and(Artisan::output())->toContain('repairprobe')->toContain(FacilitiesFixture::ESTATE);

    expect(Artisan::call('estate:repair-domains', ['subdomain' => 'no-such-estate']))->toBe(1)
        ->and(Artisan::output())->toContain('No estate [no-such-estate] on this platform.');
});
