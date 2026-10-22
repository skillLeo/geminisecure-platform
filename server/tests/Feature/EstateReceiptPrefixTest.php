<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Estate\Dues;
use App\Services\Estate\Receipts;
use App\Services\Tenancy\EstateProvisioner;
use App\Services\Tenancy\ReceiptPrefix;
use Brick\Money\Money;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The receipt prefix, on the estate record — 13 A1
|--------------------------------------------------------------------------
|
| `PPV-R-00001`, not `PHOENIXPARK-R-00001`. At most six characters, set at
| provisioning, unique across the platform, and fixed by the first receipt —
| because a number is never reused, and a prefix that has printed one has
| printed a series.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** An estate record with no database behind it — onboarding, not provisioned. */
function prefixEstate(string $id, string $name, ?string $prefix): Tenant
{
    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'receipt_prefix' => $prefix,
        'status' => 'onboarding',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Tenant::query()->findOrFail($id);
}

it('suggests the initials of the estate name, and takes a digit on a collision', function () {
    $prefixes = app(ReceiptPrefix::class);

    DB::connection('mysql')->table('tenants')->whereIn('receipt_prefix', ['PPV', 'PPV2', 'OVG'])->update(['receipt_prefix' => null]);

    expect($prefixes->suggest('Phoenix Park Village 1'))->toBe('PPV')
        ->and($prefixes->suggest('Ocean View Gardens'))->toBe('OVG')
        ->and($prefixes->suggest('The Palms at Long Mountain Heights, Phase Two'))->toBe('TPALMH');

    prefixEstate('prefixclashone', 'Phoenix Park Village', 'PPV');

    expect($prefixes->suggest('Palm Park View'))->toBe('PPV2');
});

it('refuses a prefix that is too long, starts with a digit, or is another estate\'s', function () {
    $prefixes = app(ReceiptPrefix::class);

    expect(fn () => $prefixes->assertAvailable('PHOENIX'))->toThrow(DomainException::class, 'one to six')
        ->and(fn () => $prefixes->assertAvailable('1PV'))->toThrow(DomainException::class, 'one to six')
        ->and(fn () => $prefixes->assertAvailable('ppv'))->toThrow(DomainException::class, 'one to six')
        ->and(fn () => $prefixes->assertAvailable(FacilitiesFixture::RECEIPT_PREFIX))->toThrow(DomainException::class, 'Another estate');

    // Its own prefix is not "taken" from itself.
    $prefixes->assertAvailable(FacilitiesFixture::RECEIPT_PREFIX, FacilitiesFixture::ESTATE);
});

it('refuses to provision an estate under a taken prefix before any database exists', function () {
    expect(fn () => app(EstateProvisioner::class)->provision('prefixclash', 'Prefix Clash', 'onboarding', FacilitiesFixture::RECEIPT_PREFIX))
        ->toThrow(DomainException::class, 'Another estate');

    $built = DB::connection('mysql_owner')->select(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
        [config('tenancy.database.prefix').'prefixclash'],
    );

    expect($built)->toBe([])
        ->and(Tenant::query()->find('prefixclash'))->toBeNull();
});

it('numbers receipts under the estate record\'s prefix', function () {
    $payment = app(Dues::class)->receive(FacilitiesFixture::unit('Lot 9'), Money::ofMinor(250_00, 'JMD'), 'cash', now());

    expect($payment->receipt_no)->toStartWith(FacilitiesFixture::RECEIPT_PREFIX.'-R-')
        ->and(app(Receipts::class)->prefix())->toBe(FacilitiesFixture::RECEIPT_PREFIX);
});

it('corrects a prefix before the first receipt, and records who did', function () {
    $estate = prefixEstate('prefixfresh', 'Prefix Fresh', 'PF');

    expect(app(ReceiptPrefix::class)->issued($estate))->toBeFalse();

    expect(Artisan::call('estate:receipt-prefix', ['estate' => 'prefixfresh', 'prefix' => 'pfr']))->toBe(0);

    expect(Tenant::query()->findOrFail('prefixfresh')->receipt_prefix)->toBe('PFR');

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'client.receipt_prefix_set')
        ->where('entity_id', 'prefixfresh')
        ->sole();

    expect(json_decode((string) $entry->before, true))->toBe(['receipt_prefix' => 'PF'])
        ->and(json_decode((string) $entry->after, true))->toBe(['receipt_prefix' => 'PFR']);
});

it('fixes the prefix from the first receipt, on every path that saves an estate', function () {
    app(Dues::class)->receive(FacilitiesFixture::unit('Lot 9'), Money::ofMinor(100_00, 'JMD'), 'cash', now());

    $estate = Tenant::query()->findOrFail(FacilitiesFixture::ESTATE);

    expect(app(ReceiptPrefix::class)->issued($estate))->toBeTrue();

    // The command refuses, and says why.
    expect(Artisan::call('estate:receipt-prefix', ['estate' => FacilitiesFixture::ESTATE, 'prefix' => 'NEW']))->toBe(1)
        ->and(Artisan::output())->toContain('never reused');

    // So does the model, for a seeder or a form that goes round the service.
    expect(fn () => $estate->forceFill(['receipt_prefix' => 'NEW'])->save())
        ->toThrow(DomainException::class, 'cannot change');

    expect(Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->receipt_prefix)->toBe(FacilitiesFixture::RECEIPT_PREFIX);
});

it('renames receipts issued under the subdomain once, keeping every number and the high-water mark', function () {
    /*
     * THE CONFIGURED PREFIX, NOT THE LITERAL. The migration under test finds the
     * estate by stripping `tenancy.database.prefix` off the database name, so a
     * literal `gs_estate_` here only agrees with it while the prefix is the
     * default — and see `FacilitiesFixture::database()` for the second reason.
     */
    $database = config('tenancy.database.prefix').'rekeytest';
    $owner = DB::connection('mysql_owner');

    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    try {
        $owner->statement("CREATE TABLE `{$database}`.payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, receipt_no VARCHAR(40) NOT NULL)");
        $owner->statement("CREATE TABLE `{$database}`.receipt_sequences (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, prefix VARCHAR(40) NOT NULL UNIQUE, first_no BIGINT UNSIGNED NOT NULL, last_no BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)");
        $owner->table($database.'.payments')->insert([
            ['receipt_no' => 'REKEYTEST-R-04001'],
            ['receipt_no' => 'REKEYTEST-R-04471'],
        ]);
        $owner->table($database.'.receipt_sequences')->insert(['prefix' => 'REKEYTEST', 'first_no' => 4001, 'last_no' => 4472]);

        prefixEstate('rekeytest', 'Rekey Test', 'RKT');

        config([
            'database.connections.tenant' => array_merge(config('database.connections.mysql_owner'), ['database' => $database]),
            'database.default' => 'tenant',
        ]);
        DB::purge('tenant');

        $migration = require database_path('migrations/tenant/2026_09_17_100000_rekey_receipts_to_the_estate_prefix.php');
        $migration->up();

        expect($owner->table($database.'.payments')->orderBy('id')->pluck('receipt_no')->all())->toBe(['RKT-R-04001', 'RKT-R-04471'])
            ->and($owner->table($database.'.receipt_sequences')->get(['prefix', 'first_no', 'last_no'])->map(fn ($r) => (array) $r)->all())
            ->toBe([['prefix' => 'RKT', 'first_no' => 4001, 'last_no' => 4472]]);
    } finally {
        $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
        FacilitiesFixture::boot();
    }
});
