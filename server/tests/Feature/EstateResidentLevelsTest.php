<?php

declare(strict_types=1);

use App\Api\AccessLevels;
use App\Api\AppMatrix;
use App\Models\AuditEntry;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The estate office sees and changes a household member's access level — board 38
|--------------------------------------------------------------------------
|
| "The primary resident chooses one when adding or inviting, and can change it later. The estate office
| can see it and change it too" (the feature, 24 September 2026). The household page lists everybody's
| level; Residents `update` changes any but the primary's, who is always a Member; every change is audited
| as the office's.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lot, and everybody it puts on it is taken back.
|
*/

function levelsPagePerson(Household $household, string $name, bool $primary, string $level = AppMatrix::MEMBER): Resident
{
    $person = new Resident;
    $person->forceFill([
        'household_id' => $household->id, 'full_name' => $name, 'relationship' => $primary ? 'owner' : 'child',
        'is_primary' => $primary, 'access_level' => $level, 'status' => Resident::VERIFIED,
    ])->save();

    return $person;
}

function levelsPageClear(Household $household): void
{
    DB::connection('tenant')->table('residents')->where('household_id', $household->id)->delete();
}

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    // Levels as they are once switched on (`AccessLevels::open`); the last case walks the page as it ships — closed.
    config(['services.resident_app.access_levels' => true]);

    $this->unit = FacilitiesFixture::scratchUnit('Lot 8886');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Page Levels household', 'access_restricted' => false]);
    levelsPageClear($this->household);

    $this->other = Household::query()->firstOrCreate(['unit_id' => FacilitiesFixture::scratchUnit('Lot 8887')->id], ['name' => 'Page Elsewhere household', 'access_restricted' => false]);
    levelsPageClear($this->other);

    $this->primary = levelsPagePerson($this->household, 'Andrea Page', true);
    $this->soft = levelsPagePerson($this->household, 'Soft Dev', false);
    $this->stranger = levelsPagePerson($this->other, 'Stranger Page', false);

    $this->slug = $this->unit->slug();
    $this->path = fn (Resident $person): string => FacilitiesFixture::url('/residents/'.$this->slug.'/people/'.$person->id.'/access-level');
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    levelsPageClear($this->household);
    levelsPageClear($this->other);
});

it('lists every person\'s access level on the household page, with the three levels and who may change them', function () {
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    DB::connection('tenant')->table('residents')->where('id', $this->primary->id)->update(['access_level' => AppMatrix::HELPER]);

    $this->actingAs($secretary)->get(FacilitiesFixture::url('/residents/'.$this->slug))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canChangeLevel', true)
            ->where('accessLevels', fn ($levels) => collect($levels)->pluck('value')->all() === AppMatrix::LEVELS_ALL
                && collect($levels)->firstWhere('value', AppMatrix::DEPENDENT)['may']['dues'] === false
                && collect($levels)->firstWhere('value', AppMatrix::MEMBER)['may']['dues'] === true)
            // The primary is a Member whatever the column says; everybody else as stored.
            ->where('people', fn ($people) => collect($people)->firstWhere('id', $this->primary->id)['access_level'] === AppMatrix::MEMBER
                && collect($people)->firstWhere('id', $this->soft->id)['access_level'] === AppMatrix::MEMBER
                && collect($people)->firstWhere('id', $this->soft->id)['access_level_label'] === 'Member'));

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/residents/'.$this->slug))->assertOk()
        // In one line since 07b07cef (M98: a refusal is one plain line), not the permission's name.
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canChangeLevel', false)->where('levelBlockedReason', 'Changing a level is not part of your role.'));
});

it('reads a register entry made in code as the Member the database makes it, and audits a change from what is stored', function () {
    // Made the way `Residents::add` and a claim approval make one: no level given.
    $added = Resident::query()->create([
        'household_id' => $this->household->id, 'full_name' => 'Added Page', 'relationship' => 'child', 'is_primary' => false, 'status' => Resident::PENDING,
    ]);

    expect(AccessLevels::of($added))->toBe(AppMatrix::MEMBER)
        ->and(Resident::query()->findOrFail($added->id)->access_level)->toBe(AppMatrix::MEMBER);

    // A copy held in memory while the stored level moved on: the audit says what was stored, not what the copy thought.
    $stale = Resident::query()->findOrFail($this->soft->id);
    DB::connection('tenant')->table('residents')->where('id', $this->soft->id)->update(['access_level' => AppMatrix::DEPENDENT]);

    expect(AccessLevels::set($stale, AppMatrix::HELPER, 'The estate office', null, 'office'))->toBeTrue();

    $entry = AuditEntry::query()->where('action', 'household.member_level_changed')->where('entity_id', (string) $this->soft->id)->latest('id')->firstOrFail();

    expect($entry->before)->toBe(['access_level' => AppMatrix::DEPENDENT])
        ->and(Resident::query()->findOrFail($this->soft->id)->access_level)->toBe(AppMatrix::HELPER);

    // And asking for the level that is already stored changes and records nothing.
    expect(AccessLevels::set(Resident::query()->findOrFail($this->soft->id), AppMatrix::HELPER, 'The estate office', null, 'office'))->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'household.member_level_changed')->where('entity_id', (string) $this->soft->id)->count())->toBe(1);
});

it('lets Residents update change a member\'s level, audited as the office, and refuses the Treasurer, the primary and another household', function () {
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    // A reader of the register changes nobody.
    $this->actingAs($treasurer)->post(($this->path)($this->soft), ['access_level' => AppMatrix::HELPER])->assertForbidden();
    FacilitiesFixture::boot();
    expect(Resident::query()->findOrFail($this->soft->id)->access_level)->toBe(AppMatrix::MEMBER);

    $this->actingAs($secretary)->post(($this->path)($this->soft), ['access_level' => AppMatrix::DEPENDENT])
        ->assertRedirect(FacilitiesFixture::url('/residents/'.$this->slug))
        ->assertSessionHas('success', fn (string $s): bool => str_contains($s, 'Soft Dev now has Dependent access in the Resident App'));
    FacilitiesFixture::boot();

    expect(Resident::query()->findOrFail($this->soft->id)->access_level)->toBe(AppMatrix::DEPENDENT);

    $entry = AuditEntry::query()->where('action', 'household.member_level_changed')->where('entity_id', (string) $this->soft->id)->latest('id')->firstOrFail();

    expect($entry->actor_name)->toBe($secretary->name)
        ->and($entry->actor_id)->toBe($secretary->id)
        ->and($entry->before)->toBe(['access_level' => AppMatrix::MEMBER])
        ->and($entry->after)->toMatchArray(['access_level' => AppMatrix::DEPENDENT, 'by' => $secretary->name, 'via' => 'office']);

    // The same level again changes nothing, and says so.
    $this->actingAs($secretary)->post(($this->path)($this->soft), ['access_level' => AppMatrix::DEPENDENT])
        ->assertSessionHas('success', fn (string $s): bool => str_contains($s, 'already has Dependent access; nothing changed'));
    FacilitiesFixture::boot();
    expect(AuditEntry::query()->where('action', 'household.member_level_changed')->where('entity_id', (string) $this->soft->id)->count())->toBe(1);

    // The primary is always a Member, said in words.
    $this->actingAs($secretary)->post(($this->path)($this->primary), ['access_level' => AppMatrix::HELPER])
        ->assertSessionHasErrors(['access_level' => 'The primary resident always has Member access.']);

    // Somebody on another household is not reachable through this one.
    $this->actingAs($secretary)->post(($this->path)($this->stranger), ['access_level' => AppMatrix::HELPER])
        ->assertSessionHasErrors(['access_level' => 'That person is not on this household.']);

    // And a level that does not exist is refused.
    $this->actingAs($secretary)->post(($this->path)($this->soft), ['access_level' => 'boss'])->assertSessionHasErrors('access_level');

    FacilitiesFixture::boot();

    expect(Resident::query()->findOrFail($this->primary->id)->access_level)->toBe(AppMatrix::MEMBER)
        ->and(Resident::query()->findOrFail($this->stranger->id)->access_level)->toBe(AppMatrix::MEMBER)
        ->and(Resident::query()->findOrFail($this->soft->id)->access_level)->toBe(AppMatrix::DEPENDENT);
});

it('ships with levels closed: the page says why nobody can be made a Dependent or a Helper yet, and the office cannot, but can always give Member back', function () {
    config(['services.resident_app.access_levels' => false]);

    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);

    $this->actingAs($secretary)->get(FacilitiesFixture::url('/residents/'.$this->slug))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('levelsOpen', false)
            ->where('levelsClosedReason', AccessLevels::NOT_OPEN)
            // The levels are still listed — what each means is still true — and the office still may change them in principle.
            ->where('canChangeLevel', true)
            ->where('accessLevels', fn ($levels) => collect($levels)->pluck('value')->all() === AppMatrix::LEVELS_ALL));

    foreach ([AppMatrix::DEPENDENT, AppMatrix::HELPER] as $level) {
        $this->actingAs($secretary)->post(($this->path)($this->soft), ['access_level' => $level])
            ->assertSessionHasErrors(['access_level' => AccessLevels::NOT_OPEN]);
        FacilitiesFixture::boot();
    }

    expect(Resident::query()->findOrFail($this->soft->id)->access_level)->toBe(AppMatrix::MEMBER)
        ->and(AuditEntry::query()->where('action', 'household.member_level_changed')->where('entity_id', (string) $this->soft->id)->count())->toBe(0);

    // A level held from before the switch went off can always be taken back to Member.
    DB::connection('tenant')->table('residents')->where('id', $this->soft->id)->update(['access_level' => AppMatrix::HELPER]);

    $this->actingAs($secretary)->post(($this->path)($this->soft), ['access_level' => AppMatrix::MEMBER])
        ->assertSessionHas('success', fn (string $s): bool => str_contains($s, 'Soft Dev now has Member access in the Resident App'));
    FacilitiesFixture::boot();

    expect(Resident::query()->findOrFail($this->soft->id)->access_level)->toBe(AppMatrix::MEMBER);
});
