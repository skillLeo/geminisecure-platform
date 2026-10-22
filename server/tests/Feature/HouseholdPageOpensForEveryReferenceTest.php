<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Board 38 opens for a unit whatever the estate calls it (B040)
|--------------------------------------------------------------------------
|
| Phoenix Park writes its addresses "Lot 47". Ocean View's are "OV-1A", and the
| estate's own unit import takes any reference up to 32 characters — "A-12",
| "14B", "Block 3.1".
|
| Board 38 is addressed by a slug of the reference, and the slug was undone by
| turning every "-" back into a space. "OV-1A" became "ov-1a", was looked up as
| "ov 1a", and every household on every estate but Phoenix Park answered 404 —
| along with the edit and app-access routes keyed on the same segment. A "/" or a
| "." did not even reach the lookup: the route refused the character.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED, so every unit here is a scratch
| lot of this file's own, in the fixture's scratch block.
|
*/

const HOUSEHOLD_PAGE_PHASE = 'Phase 9';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** A scratch unit with somebody living at it. */
function householdPageUnit(string $reference): array
{
    $unit = FacilitiesFixture::scratchUnit($reference, HOUSEHOLD_PAGE_PHASE);

    $household = Household::query()->firstOrCreate(
        ['unit_id' => $unit->id],
        ['name' => 'Reference test household', 'access_restricted' => false],
    );

    $person = Resident::query()->firstOrCreate(
        ['household_id' => $household->id, 'full_name' => 'Reference Tester'],
        ['relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED, 'phone' => '876 555 0101'],
    );

    return [$unit, $person];
}

it('opens board 38 for a unit whose reference carries a hyphen, a slash, a dot or an underscore', function (string $reference) {
    [$unit, $person] = householdPageUnit($reference);

    $slug = $unit->slug();

    // Nothing but characters a URL carries as they are, and the route accepts.
    expect($slug)->toMatch('/^[a-z0-9_~-]+$/');

    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/residents/'.$slug))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Residents/Show')
            ->where('resident.unit_id', $unit->id)
            ->where('resident.unit_slug', $slug));

    // The acts keyed on the same segment reach the same household, and come back to it.
    $this->actingAs($this->admin)
        ->post(FacilitiesFixture::url('/residents/'.$slug.'/people/'.$person->id), [
            'full_name' => 'Reference Tester',
            'phone' => '876 555 0101',
            'email' => 'reference.tester@example.com',
            'relationship' => 'owner',
            'is_primary' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(FacilitiesFixture::url('/residents/'.$slug));
})->with([
    'a hyphen, as Ocean View writes every unit' => ['OV-1A'],
    'a slash' => ['A/12'],
    'a dot' => ['Block 3.1'],
    'an underscore' => ['Unit_7'],
    'a hyphen between spaces' => ['Villa - 2'],
]);

it('keeps the lot slug Phoenix Park has always had', function () {
    expect(FacilitiesFixture::unit('Lot 47')->slug())->toBe('lot-47');

    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/residents/lot-47'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('resident.unit_id', FacilitiesFixture::unit('Lot 47')->id));
});

it('gives two references that differ only by a space and a hyphen two different addresses', function () {
    [$hyphen] = householdPageUnit('RF-2B');
    [$space] = householdPageUnit('RF 2B');

    expect($hyphen->slug())->not->toBe($space->slug());

    foreach ([$hyphen, $space] as $unit) {
        $this->actingAs($this->admin)
            ->get(FacilitiesFixture::url('/residents/'.$unit->slug()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('resident.unit_id', $unit->id));
    }
});

it('does not let a unit named "New" hide behind the screen of the same name', function () {
    [$new] = householdPageUnit('New');

    expect($new->slug())->not->toBe('new');

    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/residents/'.$new->slug()))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('resident.unit_id', $new->id));

    // And the screens themselves are still the screens.
    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/residents/new'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Residents/New'));

    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/residents/claims'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Residents/Claims'));
});

it('answers 404 for a segment that is not the address of any unit', function (string $segment) {
    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/residents/'.$segment))
        ->assertNotFound();
})->with([
    'no such unit' => ['zz-no-such-unit'],
    'a broken escape' => ['ov~zz1a'],
    'an upper-case escape, which is not the spelling' => ['a~2F12'],
    'the old hyphen-for-space guess at a hyphenated reference' => ['ov-1a'],
]);

it('encodes a reference into a slug and back without losing or confusing a single character', function (string $reference) {
    $slug = Unit::slugFor($reference);

    expect($slug)->toMatch('/^[a-z0-9_~-]+$/')
        ->and(Unit::referenceFromSlug($slug))->toBe(strtolower($reference));
})->with(['Lot 47', 'OV-1A', 'A/12', 'Block 3.1', 'Unit_7', 'Villa - 2', '14B', 'Lot  47', 'Café 3', '~odd~', 'claims', 'NEW']);
