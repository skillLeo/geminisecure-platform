<?php

declare(strict_types=1);

use App\Models\Estate\Phase;
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
| A phase name and a unit row are refused where the columns would refuse them (B046)
|--------------------------------------------------------------------------
|
| A phase's name is stored twice: on `estate_phases.name` (64 characters) and on
| every unit in it as `units.block` (32). The form and the import both checked the
| first and never the second, so a 33-to-64 character name passed, then failed
| inside the transaction as a MySQL "data too long" — a 500, after the import
| preview had already said the file was fine. A phase added with no lots got in,
| and every import into it afterwards failed the same way.
|
| The limits are CHARACTERS, as the columns count them, not bytes. The import
| counted bytes, so a 32-character reference with an accent in it was refused as
| too long; and it cut a long street at 120 BYTES, which can land in the middle
| of a character and hand MySQL a string that is not UTF-8 at all.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: every name and reference here is
| this file's own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    /*
     * No contract figure, so no ceiling on lots (D-176): the ceiling is EstateStructureWritesTest's subject, and a
     * BillingSeeder run by another file gives this estate one — 94 units against the hundreds the shared estate has
     * gathered — so the lots below were refused as over contract whenever that file ran first (M12 §5.4). Rolled back.
     */
    Subscription::query()->where('tenant_id', FacilitiesFixture::ESTATE)->update(['unit_count' => 0]);

    $this->manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** Preview a CSV through the screen and return the token and what the preview says. */
function structureLimitsPreview(object $test, object $manager, string $contents): array
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

it('refuses a phase name longer than a unit can carry, with its lots, against the field', function () {
    $name = 'Hillside Extension North Ridge Upper';   // 36 characters

    expect(mb_strlen($name))->toBeGreaterThan(32);

    $this->actingAs($this->manager)
        // Lots of this file's own: ReviewerModeTest keeps a Lot 9901 in the shared estate on purpose.
        ->post(FacilitiesFixture::url('/estate/phases'), ['name' => $name, 'block_count' => 1, 'lot_from' => 8861, 'lot_to' => 8862])
        ->assertStatus(302)
        ->assertSessionHasErrors('name');

    FacilitiesFixture::boot();

    expect(Phase::query()->where('name', $name)->exists())->toBeFalse()
        ->and(Unit::query()->whereIn('reference', ['Lot 8861', 'Lot 8862'])->exists())->toBeFalse();
});

it('refuses the same name with no lots, so no phase exists that no lot can ever be filed under', function () {
    $name = 'Hillside Extension North Ridge Lower';

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/estate/phases'), ['name' => $name, 'block_count' => 0])
        ->assertStatus(302)
        ->assertSessionHasErrors('name');

    FacilitiesFixture::boot();

    expect(Phase::query()->where('name', $name)->exists())->toBeFalse();

    // And the service refuses it too, whoever calls it.
    expect(fn () => app(EstateStructure::class)->addPhase($name, 0, null, null, null, $this->manager))
        ->toThrow(DomainException::class, '32');
});

it('takes a name of exactly 32 characters, counted as characters and not bytes', function () {
    $name = 'Ridge — '.str_repeat('N', 24);   // an em dash is one character and three bytes

    expect(mb_strlen($name))->toBe(32)
        ->and(strlen($name))->toBeGreaterThan(32);

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/estate/phases'), ['name' => $name, 'block_count' => 1, 'lot_from' => 9903, 'lot_to' => 9904])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Phase::query()->where('name', $name)->exists())->toBeTrue()
        ->and(Unit::query()->where('reference', 'Lot 9903')->value('block'))->toBe($name);
});

it('refuses an imported phase longer than a unit can carry in the preview, rather than 500 at the commit', function () {
    $long = 'Hillside Extension North Ridge Imported';

    [$token, $preview] = structureLimitsPreview($this, $this->manager, "reference,phase\nLIM-1,{$long}\n");

    expect($preview['valid'])->toBeFalse()
        ->and(collect($preview['errors'])->pluck('line')->all())->toBe([2])
        ->and($preview['errors'][0]['message'])->toContain('32');

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $token])
        ->assertStatus(302)
        ->assertSessionHasErrors('import');

    FacilitiesFixture::boot();

    expect(Unit::query()->where('reference', 'LIM-1')->exists())->toBeFalse()
        ->and(Phase::query()->where('name', $long)->exists())->toBeFalse();
});

it('counts a reference in characters, so a 32-character one with an accent is not called too long', function () {
    $reference = 'Cottage Ô-'.str_repeat('9', 22);

    expect(mb_strlen($reference))->toBe(32)
        ->and(strlen($reference))->toBe(33);

    [$token, $preview] = structureLimitsPreview($this, $this->manager, "reference,phase\n{$reference},Column Limits\n");

    expect($preview['valid'])->toBeTrue();

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $token])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    expect(Unit::query()->where('reference', $reference)->exists())->toBeTrue();
});

it('cuts a long street at 120 characters, never in the middle of one', function () {
    $street = str_repeat('a', 119).'éxyz';

    [$token, $preview] = structureLimitsPreview($this, $this->manager, "reference,phase,street\nLIM-2,Column Limits,{$street}\n");

    expect($preview['valid'])->toBeTrue();

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $token])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    FacilitiesFixture::boot();

    $stored = (string) Unit::query()->where('reference', 'LIM-2')->value('street');

    expect($stored)->toBe(str_repeat('a', 119).'é')
        ->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue();
});
