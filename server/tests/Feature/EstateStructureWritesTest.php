<?php

declare(strict_types=1);

use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Estate\EstateStructure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Add phase and import CSV — board 3 (12 §2, Wave 1, items 9 and 10)
|--------------------------------------------------------------------------
|
| Both bring addresses into existence, and an address is what a household is
| filed at and dues are billed to — so both are all-or-nothing. The tests
| below are mostly about what does NOT get written: a range that overlaps
| the estate, a file with one bad row, a preview that went stale.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    /*
     * No contract figure unless a test sets one (D-176). A BillingSeeder run by another file gives this estate a
     * contract of a few dozen units, and a file that runs EstateFinanceSeeder in the same process grows the shared
     * estate to 450 lots and more: the phase and the imports below were then refused as over contract (M13, the
     * run after deploy 9). The ceiling test sets its own figure. Rolled back with the transaction.
     */
    Subscription::query()->where('tenant_id', FacilitiesFixture::ESTATE)->update(['unit_count' => 0]);

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/*
 * THE PHASE THIS FILE ADDS HAS A NAME OF ITS OWN (M4 §4, defect 3).
 *
 * It used to be "Phase 9", which is also the block `FacilitiesFixture::scratchUnit()` files
 * every test's own lot under — and `EstateStructure::addPhase` correctly refuses to claim a
 * block that already has units standing in it. The estate database is committed and shared,
 * so the moment any file that makes a scratch lot ran first (the deposits, activity-log and
 * amenities suites all do, and all sort before this one) the successful POST below was
 * refused and this test died three lines later on a Phase row that was never created. It
 * passed alone and failed together, which is precisely the defect.
 *
 * "Hillside" is a name the service's own refusal offers ("Phase 6, or Hillside") and one no
 * scratch lot can ever stand in.
 */
const STRUCTURE_NEW_PHASE = 'Hillside';

/*
 * THE CONTRACT'S UNITS ARE THE CEILING (the client, 26 Sep; D-176). An estate contracted for 2,000 units could add a
 * sixth phase of lots from its own console, and none of them was billed. Nothing below writes to the shared estate:
 * the subscription row is the platform's, rolled back with it, and every refused write is proven to leave the lots
 * as they were.
 */
it('adds lots only up to the contracted units, says so in the import preview, and holds no estate without a contract figure to one', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $units = Unit::query()->count();

    // A plan of its own, inside the transaction: a fresh platform database has none, and no other test's may be relied on.
    $plan = Plan::query()->firstOrCreate(
        ['key' => 'contract-ceiling-test'],
        ['name' => 'Contract Ceiling Test', 'price_per_unit_minor' => 340_00, 'currency' => 'JMD', 'min_units' => 1, 'is_active' => false, 'sort' => 99],
    );

    Subscription::query()->updateOrCreate(
        ['tenant_id' => FacilitiesFixture::ESTATE],
        ['plan_id' => $plan->id, 'unit_count' => $units + 3, 'status' => 'active'],
    );

    // The screen says where the estate stands.
    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/estate'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('contract.contracted', $units + 3)->where('contract.units', $units));

    FacilitiesFixture::boot();

    // Ten lots past a contract with room for three: refused whole, in words that say what to do, and nothing created.
    // Numbered from a range no other test uses (the fixture estate is shared, and the suite runs in any order).
    $base = random_int(60_000, 89_000);
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/phases'), ['name' => 'Contract Ceiling Test', 'block_count' => 1, 'lot_from' => $base, 'lot_to' => $base + 9])
        ->assertSessionHasErrors('name');

    // M78 §1: in plain words, without "contract" (it read "covers … units" and "Ask GeminiSecure to raise the contract").
    expect(session('errors')->first('name'))->toContain('allowed '.number_format($units + 3).' units')
        ->and(session('errors')->first('name'))->toContain('Please contact your service provider to add more.');

    FacilitiesFixture::boot();

    expect(Unit::query()->count())->toBe($units)
        ->and(Phase::query()->where('name', 'Contract Ceiling Test')->exists())->toBeFalse();

    // The import says so in its PREVIEW, before anybody presses Import.
    $csv = "reference,phase\n".implode("\n", array_map(static fn (int $i): string => 'Lot '.($base + 20 + $i).','.STRUCTURE_NEW_PHASE, range(1, 4)));

    $previewed = $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/preview'), ['file' => UploadedFile::fake()->createWithContent('lots.csv', $csv)])
        ->assertRedirect();

    FacilitiesFixture::boot();

    $this->actingAs($manager)
        ->get((string) $previewed->headers->get('Location'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preview.valid', false)
            ->where('preview.errors.0.message', fn (string $message): bool => str_contains($message, 'Please contact your service provider to add more.')));

    FacilitiesFixture::boot();

    // With no contract figure (an estate being set up), no ceiling.
    Subscription::query()->where('tenant_id', FacilitiesFixture::ESTATE)->update(['unit_count' => 0]);

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/estate'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('contract', null));
});

it('adds a phase with its lot range in one transaction, and refuses a range that overlaps the estate', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    // The invariant this test needs, asserted where the reason is obvious rather than left to
    // surface as a missing Phase row: nobody else's lot stands in the block it is about to claim.
    expect(Unit::query()->where('block', STRUCTURE_NEW_PHASE)->exists())
        ->toBeFalse(STRUCTURE_NEW_PHASE.' already has units in it, so a phase cannot be added over it. Give this test a block name no other file uses.');

    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/estate'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false)->where('preview', null));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/estate/phases'), ['name' => STRUCTURE_NEW_PHASE, 'block_count' => 2])
        ->assertForbidden();

    $units = Unit::query()->count();

    // Lot 47 exists — the whole range is refused and nothing is created.
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/phases'), ['name' => STRUCTURE_NEW_PHASE, 'block_count' => 2, 'lot_from' => 45, 'lot_to' => 50])
        ->assertSessionHasErrors('name');

    FacilitiesFixture::boot();

    expect(Unit::query()->count())->toBe($units)
        ->and(Phase::query()->where('name', STRUCTURE_NEW_PHASE)->exists())->toBeFalse();

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/phases'), [
            'name' => STRUCTURE_NEW_PHASE,
            'block_count' => 2,
            'lot_from' => 901,
            'lot_to' => 910,
            'street' => 'Hillside Drive',
        ])
        ->assertRedirect(FacilitiesFixture::url('/estate'));

    FacilitiesFixture::boot();

    $phase = Phase::query()->where('name', STRUCTURE_NEW_PHASE)->firstOrFail();

    expect($phase->status)->toBe(Phase::NEW)
        ->and($phase->block_count)->toBe(2)
        ->and($phase->sequence)->toBe((int) Phase::query()->where('id', '!=', $phase->id)->max('sequence') + 1)
        ->and(Unit::query()->count())->toBe($units + 10)
        ->and(Unit::query()->where('block', STRUCTURE_NEW_PHASE)->where('status', 'vacant')->count())->toBe(10)
        ->and(Unit::query()->where('reference', 'Lot 905')->value('street'))->toBe('Hillside Drive');

    // The board reads the new phase from the same GROUP BY as the rest.
    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/estate'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('phases', fn ($phases) => collect($phases)->contains(fn ($p) => $p['name'] === STRUCTURE_NEW_PHASE && $p['unit_count'] === 10 && $p['vacant'] === 10)));

    expect(fn () => app(EstateStructure::class)->addPhase(strtolower(STRUCTURE_NEW_PHASE), 1, null, null, null, $manager))
        ->toThrow(DomainException::class, 'already exists');
});

it('previews a unit list, rejects a file with one bad row whole, and imports a clean one whole', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $units = Unit::query()->count();

    $bad = UploadedFile::fake()->createWithContent('units.csv', implode("\n", [
        'reference,phase,street,type,status',
        'Lot 701,Phase 7,Ridge Way,residential,vacant',
        'Lot 47,Phase 7,Ridge Way,residential,occupied',
        'Lot 702,Phase 7,Ridge Way,shop,vacant',
        'Lot 701,Phase 7,Ridge Way,residential,vacant',
    ]));

    $response = $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/preview'), ['file' => $bad])
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');

    // The preview names every problem against its line, and offers no commit.
    $this->actingAs($manager)
        ->get($location)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preview.valid', false)
            ->where('preview.row_count', 4)
            ->where('preview.new_phases', ['Phase 7'])
            ->where('preview.errors', fn ($errors) => collect($errors)->pluck('line')->sort()->values()->all() === [3, 4, 5]));

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $query['import']])
        ->assertSessionHasErrors('import');

    FacilitiesFixture::boot();

    expect(Unit::query()->count())->toBe($units)
        ->and(Phase::query()->where('name', 'Phase 7')->exists())->toBeFalse();

    // A clean file: previewed, then committed whole, with the phase it names.
    $good = UploadedFile::fake()->createWithContent('units.csv', implode("\n", [
        'reference,phase,street,type,status',
        'Lot 701,Phase 7,Ridge Way,residential,vacant',
        'Lot 702,phase 7,Ridge Way,,occupied',
        'Shop 1,Phase 7,Ridge Way,commercial,vacant',
    ]));

    $location = (string) $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/preview'), ['file' => $good])
        ->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $this->actingAs($manager)
        ->get($location)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preview.valid', true)->where('preview.errors', []));

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $query['import']])
        ->assertRedirect(FacilitiesFixture::url('/estate'));

    FacilitiesFixture::boot();

    expect(Unit::query()->count())->toBe($units + 3)
        ->and(Phase::query()->where('name', 'Phase 7')->exists())->toBeTrue()
        // "phase 7" in the file filed under the phase as the estate spells it.
        ->and(Unit::query()->where('reference', 'Lot 702')->value('block'))->toBe('Phase 7')
        ->and(Unit::query()->where('reference', 'Shop 1')->value('type'))->toBe('commercial');

    // The preview is spent; the same token imports nothing twice.
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $query['import']])
        ->assertSessionHasErrors('import');

    FacilitiesFixture::boot();

    expect(Unit::query()->count())->toBe($units + 3);
});

it('refuses a preview that went stale between the two presses', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $structure = app(EstateStructure::class);

    $parsed = $structure->parseUnits("reference,phase\nLot 801,Phase 8\n");

    expect($parsed['valid'])->toBeTrue();

    // Somebody else took Lot 801 in the meantime.
    Unit::create(['reference' => 'Lot 801', 'block' => 'Phase 8', 'type' => 'residential', 'status' => 'vacant']);

    expect(fn () => $structure->importUnits($parsed['rows'], $manager))
        ->toThrow(DomainException::class, 'already exists');
});
