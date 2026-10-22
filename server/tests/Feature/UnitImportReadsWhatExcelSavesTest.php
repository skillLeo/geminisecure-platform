<?php

declare(strict_types=1);

use App\Models\Estate\Unit;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Estate\EstateStructure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The unit import reads the files Excel writes (B051)
|--------------------------------------------------------------------------
|
| Excel's "CSV UTF-8 (Comma delimited)" starts the file with a byte-order mark.
| The importer read the header's first cell as "\xEF\xBB\xBFreference" and told
| the office the file had no "reference" column — when it plainly had one. The
| platform's own exports write that mark on purpose (`Exporter`), so a unit list
| exported here and imported back was refused the same way.
|
| And Excel's plain "CSV (Comma delimited)" is not UTF-8 at all: it is the
| Windows code page, where "é" is one byte that UTF-8 does not allow. That file
| passed the header check and then could not be stored or even drawn. A unit
| list is read as UTF-8 when it is UTF-8, and as Windows-1252 — what Excel on an
| English Windows writes — when it is not.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    // No contract figure, so no ceiling on lots (D-176), as in EstateStructureFitsItsColumnsTest: another file's
    // BillingSeeder gives this estate a contract of a few dozen units, and once the shared estate holds more the
    // imports below were refused as over contract (M13). Rolled back with the transaction.
    Subscription::query()->where('tenant_id', FacilitiesFixture::ESTATE)->update(['unit_count' => 0]);

    $this->manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** Upload a file through board 3, preview it, and commit it if it is valid. */
function excelImport(object $test, object $manager, string $contents): array
{
    $location = (string) $test->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/preview'), [
            'file' => UploadedFile::fake()->createWithContent('units.csv', $contents),
        ])
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $preview = $test->actingAs($manager)
        ->get($location)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Structure/Index'))
        ->inertiaProps('preview');

    return [(string) ($query['import'] ?? ''), $preview];
}

it('reads a header that starts with the byte-order mark Excel writes', function () {
    $parsed = app(EstateStructure::class)->parseUnits("\xEF\xBB\xBFreference,phase\nXLS-1,Excel Imports\n");

    expect($parsed['valid'])->toBeTrue()
        ->and($parsed['errors'])->toBe([])
        ->and($parsed['rows'][0]['reference'])->toBe('XLS-1');
});

it('previews and imports a "CSV UTF-8" file from Excel, byte-order mark and all', function () {
    [$token, $preview] = excelImport($this, $this->manager, "\xEF\xBB\xBFreference,phase,street\r\nXLS-2,Excel Imports,Ridge Way\r\n");

    expect($preview['valid'])->toBeTrue()
        ->and($preview['errors'])->toBe([]);

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $token])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Unit::query()->where('reference', 'XLS-2')->value('street'))->toBe('Ridge Way');
});

it('reads a plain Excel "CSV" in the Windows code page, and stores its accents as the letters they are', function () {
    // "Café Road" as Windows-1252 writes it: é is the single byte E9.
    [$token, $preview] = excelImport($this, $this->manager, "reference,phase,street\r\nXLS-3,Excel Imports,Caf\xE9 Road\r\n");

    expect($preview['valid'])->toBeTrue()
        ->and($preview['rows'][0]['street'])->toBe('Café Road');

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $token])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Unit::query()->where('reference', 'XLS-3')->value('street'))->toBe('Café Road');
});

it('leaves a file that is already UTF-8 exactly as it is', function () {
    $parsed = app(EstateStructure::class)->parseUnits("reference,phase,street\nXLS-4,Excel Imports,Café Road\n");

    expect($parsed['valid'])->toBeTrue()
        ->and($parsed['rows'][0]['street'])->toBe('Café Road');
});
