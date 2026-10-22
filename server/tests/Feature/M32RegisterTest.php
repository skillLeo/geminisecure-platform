<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Estate\Residents;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M32 F1: the register shows people (4 October 2026)
|--------------------------------------------------------------------------
|
| A resident added at Lot 413 was a member of a seeded household and had no row of his own on /residents, which
| listed households by their primary. THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lots, and everybody
| it adds is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    $this->scratch = [];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    foreach ($this->scratch as $reference) {
        $unitId = Unit::query()->where('reference', $reference)->value('id');
        $households = DB::connection('tenant')->table('households')->where('unit_id', $unitId)->pluck('id');
        $residents = Resident::withRemoved()->whereIn('household_id', $households)->pluck('id');

        DB::connection('tenant')->table('resident_invites')->whereIn('resident_id', $residents)->delete();
        DB::connection('tenant')->table('unit_claims')->where('unit_id', $unitId)->delete();
        DB::connection('tenant')->table('household_vehicles')->whereIn('household_id', $households)->delete();
        DB::connection('tenant')->table('residents')->whereIn('id', $residents)->delete();
        DB::connection('tenant')->table('households')->whereIn('id', $households)->delete();
    }
});

/** A lot of this file's own, with no household on it. */
function m32Lot(object $test, string $reference): Unit
{
    $unit = FacilitiesFixture::scratchUnit($reference);
    $test->scratch[] = $reference;

    return $unit;
}

/** A lot of this file's own holding a household the way the demonstration seeded every occupied lot. */
function m32SeededLot(object $test, string $reference): Household
{
    $unit = m32Lot($test, $reference);
    $number = preg_replace('/^Lot\s+/', '', $reference);
    $household = Household::query()->create(['unit_id' => $unit->id, 'name' => $reference.' household', 'access_restricted' => false]);

    $placeholder = new Resident;
    $placeholder->forceFill([
        'household_id' => $household->id, 'full_name' => $reference.' householder', 'email' => 'lot'.$number.'@phoenixpark.test',
        'phone' => '876-555-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED,
    ])->save();

    return $household;
}

/** Add resident, as the office's form sends it. */
function m32Add(object $test, Unit $unit, string $name, string $email, ?string $choice = null): TestResponse
{
    return $test->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))->post(FacilitiesFixture::url('/residents'), [
        'phase' => (string) $unit->block, 'lot' => (string) $unit->reference, 'full_name' => $name, 'email' => $email,
        'phone' => '876 555 0'.random_int(100, 999), 'verification' => 'manual',
        ...($choice === null ? [] : ['household_choice' => $choice]),
    ]);
}

/** The People tab's rows for a search, as the register serves them. */
function m32People(object $test, string $query): array
{
    $rows = [];
    $test->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))->get(FacilitiesFixture::url('/residents?q='.rawurlencode($query)))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$rows) {
            $page->where('view', 'people')->where('rows', function ($r) use (&$rows) {
                $rows = collect($r)->map(static fn ($row) => $row instanceof Collection ? $row->all() : (array) $row)->all();

                return true;
            });
        });
    FacilitiesFixture::boot();

    return $rows;
}

/**
 * Whether the People search for `$query` lists `$name` on whichever page it falls. A search as wide as a phase can
 * return more than one page: "Phase 9" is FacilitiesFixture's scratch block, where every file's lots are, and the
 * register's first page is 25 rows in name order. Read alone, this file found its member there; read after enough
 * files, on page 2.
 */
function m32Finds(object $test, string $query, string $name): bool
{
    for ($page = 1; $page <= 50; $page++) {
        $rows = [];
        $test->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))
            ->get(FacilitiesFixture::url('/residents?'.http_build_query(['q' => $query, 'per' => 200, 'page' => $page])))->assertOk()
            ->assertInertia(function (AssertableInertia $inertia) use (&$rows) {
                $inertia->where('view', 'people')->where('rows', function ($r) use (&$rows) {
                    $rows = collect($r)->map(static fn ($row) => $row instanceof Collection ? $row->all() : (array) $row)->all();

                    return true;
                });
            });
        FacilitiesFixture::boot();

        if (collect($rows)->contains(fn (array $row): bool => $row['name'] === $name)) {
            return true;
        }
        if (count($rows) < 200) {
            return false;
        }
    }

    return false;
}

it('makes a resident added to an empty lot its household\'s primary, and the register shows them at once', function () {
    $unit = m32Lot($this, 'Lot 7701');
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $added = app(Residents::class)->add((string) $unit->block, (string) $unit->reference, 'Empty Lot Person', 'empty.lot@m32.test', '876 555 0171', 'manual', false, $manager);
    FacilitiesFixture::boot();

    expect($added->is_primary)->toBeTrue()
        ->and(Household::query()->where('unit_id', $unit->id)->count())->toBe(1);

    $this->actingAs($manager)->get(FacilitiesFixture::url('/residents?q=Empty+Lot+Person'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['name'] === 'Empty Lot Person')));
});

/* ── an occupied lot asks ────────────────────────────────────────────────────────────────────────────────────── */

it('refuses to add a resident to an occupied lot until the office says what the person is, and saves nobody', function () {
    $household = m32SeededLot($this, 'Lot 7702');
    $unit = Unit::query()->findOrFail($household->unit_id);

    m32Add($this, $unit, 'No Choice Made', 'no.choice@m32.test')->assertSessionHasErrors('household_choice');
    expect(session('errors')->first('household_choice'))->toContain('Lot 7702 already has a household (Lot 7702 household)');

    FacilitiesFixture::boot();
    expect(Resident::query()->where('full_name', 'No Choice Made')->exists())->toBeFalse();

    // The form is told before saving: who is there, that it is a sample, and the household's balance in words it may read.
    // (A sample is marked off demonstration mode only, M60 §C3.)
    config(['demo.enabled' => false]);
    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))->get(FacilitiesFixture::url('/residents/new?phase='.rawurlencode((string) $unit->block).'&lot='.rawurlencode((string) $unit->reference)))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lot.occupied.unit', 'Lot 7702')
            ->where('lot.occupied.households.0.primary.name', 'Lot 7702 householder')
            ->where('lot.occupied.households.0.sample', true)
            ->where('lot.occupied.households.0.others', 0)
            ->where('lot.occupied.balance_line', fn (string $line) => str_ends_with($line, 'Adding someone does not change it.'))
            ->where('household_choices', fn ($c) => collect($c)->pluck('value')->all() === ['member', 'primary', 'separate']));
});

it('adds a member to the household on the lot, and nothing else', function () {
    $household = m32SeededLot($this, 'Lot 7703');
    $unit = Unit::query()->findOrFail($household->unit_id);

    m32Add($this, $unit, 'Chose Member', 'chose.member@m32.test', 'member')->assertSessionHasNoErrors();
    FacilitiesFixture::boot();

    $added = Resident::query()->where('full_name', 'Chose Member')->firstOrFail();
    expect($added->household_id)->toBe($household->id)
        ->and($added->is_primary)->toBeFalse()
        ->and(Resident::query()->where('household_id', $household->id)->where('is_primary', true)->value('full_name'))->toBe('Lot 7703 householder')
        ->and(DB::connection('tenant')->table('households')->where('unit_id', $unit->id)->count())->toBe(1);
});

it('makes the added person the household\'s primary resident, the one on record a member, and audits the move', function () {
    $household = m32SeededLot($this, 'Lot 7704');
    $unit = Unit::query()->findOrFail($household->unit_id);

    m32Add($this, $unit, 'Chose Primary', 'chose.primary@m32.test', 'primary')->assertSessionHasNoErrors();
    FacilitiesFixture::boot();

    $added = Resident::query()->where('full_name', 'Chose Primary')->firstOrFail();
    $before = Resident::query()->where('full_name', 'Lot 7704 householder')->firstOrFail();
    expect($added->household_id)->toBe($household->id)
        ->and($added->is_primary)->toBeTrue()
        ->and($before->is_primary)->toBeFalse()
        ->and($before->household_id)->toBe($household->id)
        ->and(Resident::query()->where('household_id', $household->id)->where('is_primary', true)->count())->toBe(1)
        ->and(DB::connection('tenant')->table('households')->where('unit_id', $unit->id)->count())->toBe(1)
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'residents.primary_moved')->where('entity_id', (string) $added->id)->exists())->toBeTrue();
});

it('makes a new, separate household at the lot, with the added person its primary, and leaves the other alone', function () {
    $household = m32SeededLot($this, 'Lot 7705');
    $unit = Unit::query()->findOrFail($household->unit_id);

    m32Add($this, $unit, 'Chose Separate', 'chose.separate@m32.test', 'separate')->assertSessionHasNoErrors();
    FacilitiesFixture::boot();

    $added = Resident::query()->where('full_name', 'Chose Separate')->firstOrFail();
    expect($added->household_id)->not->toBe($household->id)
        ->and($added->is_primary)->toBeTrue()
        ->and((int) DB::connection('tenant')->table('households')->where('id', $added->household_id)->value('unit_id'))->toBe($unit->id)
        ->and(DB::connection('tenant')->table('households')->where('unit_id', $unit->id)->count())->toBe(2)
        ->and(Resident::query()->where('household_id', $household->id)->pluck('full_name')->all())->toBe(['Lot 7705 householder']);
});

it('at a lot of two households, asks which one a member joins, shows each on the household page, and removes only the one shown', function () {
    $older = m32SeededLot($this, 'Lot 7712');
    $unit = Unit::query()->findOrFail($older->unit_id);
    m32Add($this, $unit, 'Second Family Head', 'second.family@m32.test', 'separate')->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    $newer = Household::query()->where('unit_id', $unit->id)->whereKeyNot($older->id)->firstOrFail();

    // A member, with no household said: refused, naming both, and nobody saved.
    m32Add($this, $unit, 'Which One Member', 'which.one@m32.test', 'member')->assertSessionHasErrors('household_choice');
    expect(session('errors')->first('household_choice'))->toContain('Lot 7712 has 2 households')->toContain('Lot 7712 household');
    FacilitiesFixture::boot();
    expect(Resident::query()->where('full_name', 'Which One Member')->exists())->toBeFalse();

    // Said: it joins the older one, which is not the lot's default (the latest), and the office lands on its page.
    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))->post(FacilitiesFixture::url('/residents'), [
        'phase' => (string) $unit->block, 'lot' => (string) $unit->reference, 'full_name' => 'Which One Member', 'email' => 'which.one@m32.test',
        'phone' => '876 555 0712', 'verification' => 'manual', 'household_choice' => 'member', 'household_id' => $older->id,
    ])->assertSessionHasNoErrors()->assertRedirect(FacilitiesFixture::url('/residents/'.$unit->slug().'?household='.$older->id));
    FacilitiesFixture::boot();
    expect(Resident::query()->where('full_name', 'Which One Member')->value('household_id'))->toBe($older->id)
        ->and(Resident::query()->where('household_id', $older->id)->where('is_primary', true)->value('full_name'))->toBe('Lot 7712 householder');

    // The page shows the latest by default and the older one by id, and the switcher names both, oldest first.
    $office = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $this->actingAs($office)->get(FacilitiesFixture::url('/residents/'.$unit->slug()))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('resident.household_id', $newer->id)
            ->where('households_here', fn ($here) => collect($here)->pluck('id')->all() === [$older->id, $newer->id]));
    FacilitiesFixture::boot();
    $this->actingAs($office)->get(FacilitiesFixture::url('/residents/'.$unit->slug().'?household='.$older->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('resident.household_id', $older->id)
            ->where('resident.name', 'Lot 7712 householder')
            ->where('households_here.0.shown', true)
            ->where('households_here.1.shown', false));
    FacilitiesFixture::boot();

    // The register's People row for the older household's member opens that household; the latest's needs no id.
    $member = collect(m32People($this, 'Which One Member'))->firstWhere('name', 'Which One Member');
    $head = collect(m32People($this, 'Second Family Head'))->firstWhere('name', 'Second Family Head');
    expect($member)->toHaveKey('household_param', $older->id)
        ->and($head)->toHaveKey('household_param')
        ->and($head['household_param'])->toBeNull();

    // Removing the household on the older one's page removes that one, and the other stays on the lot.
    $this->actingAs($office)->post(FacilitiesFixture::url('/residents/'.$unit->slug().'/household/remove'), [
        'confirm_lot' => (string) $unit->reference, 'reason' => 'The test removes the older household only.', 'household_id' => $older->id,
    ])->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(Household::withRemoved()->findOrFail($older->id)->isRemoved())->toBeTrue()
        ->and(Household::query()->whereKey($newer->id)->exists())->toBeTrue()
        ->and(Resident::query()->where('household_id', $newer->id)->pluck('full_name')->all())->toBe(['Second Family Head']);
});

/* ── the register shows people ───────────────────────────────────────────────────────────────────────────────── */

it('lists a member on the register, not only a primary, with the household and their role', function () {
    $household = m32SeededLot($this, 'Lot 7706');
    $unit = Unit::query()->findOrFail($household->unit_id);
    m32Add($this, $unit, 'Member Shown Here', 'member.shown@m32.test', 'member')->assertSessionHasNoErrors();

    $row = collect(m32People($this, 'Member Shown Here'))->firstWhere('name', 'Member Shown Here');
    expect($row)->not->toBeNull()
        ->and($row['role'])->toBe('member')
        ->and($row['household'])->toBe('Lot 7706 household')
        ->and($row['state'])->toBe('active')
        ->and($row['unit'])->toContain('Lot 7706')
        ->and($row['sample'])->toBeFalse();
});

it('finds a member by each field the search reads (M32 F4)', function (string $query) {
    $household = m32SeededLot($this, 'Lot 7707');
    $member = new Resident;
    $member->forceFill(['household_id' => $household->id, 'full_name' => 'Searchable Member', 'email' => 'softdev.m32test@gmail.com', 'phone' => '658 555 0277', 'relationship' => 'spouse', 'is_primary' => false, 'status' => Resident::VERIFIED])->save();
    DB::connection('tenant')->table('household_vehicles')->insert(['household_id' => $household->id, 'plate' => 'PK 7707', 'created_at' => now(), 'updated_at' => now()]);

    expect(m32Finds($this, $query, 'Searchable Member'))->toBeTrue('searching "'.$query.'" did not find the member');
})->with([
    'name, any part, any case' => ['searchable'],
    'email, any part, any case' => ['SOFTDEV.M32'],
    // 658, so no seeded `876-555-0NNN` person shares it (M32SearchTest says why).
    'phone, spaced' => ['658 555 0277'],
    'phone, digits only' => ['6585550277'],
    'phone, with the country code' => ['+16585550277'],
    'lot, the number alone' => ['7707'],
    'lot, with "Lot"' => ['Lot 7707'],
    'phase' => ['Phase 9'],
    'household name' => ['Lot 7707 household'],
    'vehicle plate, run together' => ['PK7707'],
    'vehicle plate, spaced, lower case' => ['pk 7707'],
]);

it('reads "Lot N" as that lot — a unit called just N too — and not as every lot with N somewhere in it', function () {
    foreach (['Lot 7713' => 'At Lot 7713', 'Lot 17713' => 'At Lot 17713', '7714' => 'At Bare 7714'] as $reference => $name) {
        // A key of digits is an int to PHP.
        $unit = m32Lot($this, (string) $reference);
        $household = Household::query()->create(['unit_id' => $unit->id, 'name' => $name.' household', 'access_restricted' => false]);
        $person = new Resident;
        $person->forceFill(['household_id' => $household->id, 'full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@m32.test', 'phone' => '658 555 0713', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    }

    $names = static fn (array $rows): array => collect($rows)->pluck('name')->filter(static fn ($n) => str_starts_with((string) $n, 'At '))->values()->all();

    expect($names(m32People($this, 'Lot 7713')))->toBe(['At Lot 7713'])
        ->and($names(m32People($this, 'Lot 7714')))->toBe(['At Bare 7714']);
});

it('says what was searched and what the search reads when nothing matches', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))->get(FacilitiesFixture::url('/residents?q=zzqqxx'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows', [])
            ->where('empty_filtered.title', 'Nothing matches “zzqqxx”')
            ->where('empty_filtered.body', 'The search looks at names, emails, phone numbers, lots, phases and vehicles. Clear the search to see everybody again.'));
});

/* ── a sample is marked everywhere, and a resident never sees one ─────────────────────────────────────────────── */

it('marks a sample household "sample" on the register, the household page and the claims queue, off demonstration mode', function () {
    config(['demo.enabled' => false]);
    $household = m32SeededLot($this, 'Lot 7708');
    $unit = Unit::query()->findOrFail($household->unit_id);
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    // The register, People and Households.
    expect(collect(m32People($this, 'Lot 7708 householder'))->firstWhere('name', 'Lot 7708 householder')['sample'] ?? null)->toBeTrue();
    $this->actingAs($manager)->get(FacilitiesFixture::url('/residents?view=households&q=Lot+7708'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'households')->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['unit_id'] === $unit->id && $r['sample'] === true)));
    FacilitiesFixture::boot();

    // The household page: the holder, and the member row, which no longer reads "Verified".
    $this->actingAs($manager)->get(FacilitiesFixture::url('/residents/'.$unit->slug()))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('resident.sample', true)
            ->where('members', fn ($m) => collect($m)->contains(fn ($r) => $r['sample'] === true && $r['value'] === 'Sample · seeded, never verified'))
            ->where('people', fn ($p) => collect($p)->contains(fn ($r) => $r['sample'] === true)));
    FacilitiesFixture::boot();

    // The claims queue.
    UnitClaim::query()->create(['unit_id' => $unit->id, 'household_id' => $household->id, 'claim_type' => UnitClaim::TYPE_UNIT, 'submitted_name' => 'Claimant 7708', 'match_result' => 'none', 'status' => UnitClaim::PENDING]);
    $this->actingAs($manager)->get(FacilitiesFixture::url('/residents/claims'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('claims', fn ($c) => collect($c)->contains(fn ($card) => $card['record_sample'] === true)));
});

it('marks nobody "sample" in demonstration mode: the register, the household page and the claims queue draw people (M60 §C3)', function () {
    config(['demo.enabled' => true]);
    $household = m32SeededLot($this, 'Lot 7715');
    $unit = Unit::query()->findOrFail($household->unit_id);
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    expect(collect(m32People($this, 'Lot 7715 householder'))->firstWhere('name', 'Lot 7715 householder')['sample'] ?? null)->toBeFalse();
    $this->actingAs($manager)->get(FacilitiesFixture::url('/residents/'.$unit->slug()))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('resident.sample', false)
            ->where('members', fn ($m) => collect($m)->every(fn ($r) => $r['sample'] === false && ! str_starts_with((string) $r['value'], 'Sample')))
            ->where('people', fn ($p) => collect($p)->every(fn ($r) => $r['sample'] === false)));
    FacilitiesFixture::boot();

    UnitClaim::query()->create(['unit_id' => $unit->id, 'household_id' => $household->id, 'claim_type' => UnitClaim::TYPE_UNIT, 'submitted_name' => 'Claimant 7715', 'match_result' => 'none', 'status' => UnitClaim::PENDING]);
    $this->actingAs($manager)->get(FacilitiesFixture::url('/residents/claims'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('claims', fn ($c) => collect($c)->every(fn ($card) => $card['record_sample'] === false)));
});

it('shows a resident no sample person in their own household list in the Resident App', function () {
    $household = m32SeededLot($this, 'Lot 7709');
    $unit = Unit::query()->findOrFail($household->unit_id);
    $real = new Resident;
    $real->forceFill(['household_id' => $household->id, 'full_name' => 'Real Member 7709', 'email' => 'real.7709@m32.test', 'relationship' => 'owner', 'is_primary' => false, 'status' => Resident::VERIFIED])->save();

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'real.7709@m32.test', 'full_name' => 'Real Member 7709',
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(), 'resident_id' => $real->id, 'unit_id' => $unit->id,
    ]);
    $token = $account->createToken('resident-app:m32', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    app('auth')->forgetGuards();
    $members = $this->withToken($token)->getJson('/api/v1/me/household')->assertOk()->json('members');
    FacilitiesFixture::boot();

    expect(collect($members)->pluck('full_name')->all())->toContain('Real Member 7709')
        ->and(collect($members)->pluck('full_name')->all())->not->toContain('Lot 7709 householder');
});

/* ── the placeholder is retired, by a command a person runs ───────────────────────────────────────────────────── */

it('retires a placeholder once a real person holds its household: primary moved, placeholder hidden, ledger untouched', function () {
    $household = m32SeededLot($this, 'Lot 7710');
    $real = new Resident;
    $real->forceFill(['household_id' => $household->id, 'full_name' => 'Real Holder 7710', 'email' => 'real.7710@m32.test', 'relationship' => 'owner', 'is_primary' => false, 'status' => Resident::VERIFIED, 'verified_at' => now()])->save();
    $placeholderId = (int) Resident::query()->where('full_name', 'Lot 7710 householder')->value('id');

    $ledger = static fn (): array => [
        DB::connection('tenant')->table('charges')->count(), DB::connection('tenant')->table('payments')->count(), DB::connection('tenant')->table('journal_lines')->count(),
    ];
    $before = $ledger();

    // Said first, changing nothing.
    expect(Artisan::call('residents:retire-samples', ['--estate' => FacilitiesFixture::ESTATE, '--household' => (string) $household->id]))->toBe(0);
    expect(Artisan::output())->toContain('Real Holder 7710 would be the primary resident')->toContain('Nothing is changed without --confirm');
    FacilitiesFixture::boot();
    expect(Resident::query()->whereKey($placeholderId)->exists())->toBeTrue();

    expect(Artisan::call('residents:retire-samples', ['--estate' => FacilitiesFixture::ESTATE, '--household' => (string) $household->id, '--confirm' => true]))->toBe(0);
    FacilitiesFixture::boot();

    $retired = Resident::withRemoved()->findOrFail($placeholderId);
    expect($real->refresh()->is_primary)->toBeTrue()
        ->and(Resident::query()->whereKey($placeholderId)->exists())->toBeFalse()
        ->and($retired->retired_at)->not->toBeNull()
        ->and($retired->retired_reason)->toContain('Real Holder 7710')
        ->and($retired->is_primary)->toBeFalse()
        ->and($ledger())->toBe($before)
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'residents.sample_retired')->where('entity_id', (string) $placeholderId)->exists())->toBeTrue();

    // Hidden from the register.
    expect(collect(m32People($this, 'Lot 7710'))->pluck('name')->all())->not->toContain('Lot 7710 householder');

    // And a second run finds nothing to do at that household.
    Artisan::call('residents:retire-samples', ['--estate' => FacilitiesFixture::ESTATE, '--household' => (string) $household->id, '--confirm' => true]);
    expect(Artisan::output())->toContain('Nothing to change');
});

/* ── F8: claims, newest first ────────────────────────────────────────────────────────────────────────────────── */

it('orders the claims queue newest first, by when the claim was made', function () {
    $unit = m32Lot($this, 'Lot 7711');
    $claim = static function (string $name, ?CarbonInterface $madeAt) use ($unit): void {
        $row = UnitClaim::query()->create(['unit_id' => $unit->id, 'claim_type' => UnitClaim::TYPE_UNIT, 'submitted_name' => $name, 'match_result' => 'none', 'status' => UnitClaim::PENDING]);

        if ($madeAt !== null) {
            $row->forceFill(['created_at' => $madeAt])->save();
        }
    };

    // Made in this order, so the id order (either way) is not the time order: the last one made is the oldest claim.
    $claim('Middle Claimant M32', now()->subDay());
    $claim('Newest Claimant M32', null);
    $claim('Oldest Claimant M32', now()->subDays(3));

    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))->get(FacilitiesFixture::url('/residents/claims'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('claims', function ($claims) {
            $order = collect($claims)->map(fn ($c) => preg_match('/(Newest|Middle|Oldest) Claimant M32/', json_encode($c), $m) === 1 ? $m[1] : null)->filter()->values()->all();

            return $order === ['Newest', 'Middle', 'Oldest'];
        }));
});
