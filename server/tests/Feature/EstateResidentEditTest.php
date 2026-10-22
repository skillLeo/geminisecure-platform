<?php

declare(strict_types=1);

use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Role;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\Estate\ResidentsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| Correcting a resident's record — board 38 (12 §2, Wave 2)
|--------------------------------------------------------------------------
|
| IT EDITS WHO SOMEBODY IS, NOT WHETHER THEY ARE AUTHORISED. Verification is
| decided where a claim is reviewed, by somebody whose name and date go on the
| decision. Biometric consent is the person's and never the office's (D-022).
| This suite holds both lines.
|
| AN ESTATE OF ITS OWN (M11 B5). The register this file corrects is in
| `residentedittest`, which no other file uses. It used to be the shared
| facilities estate, which the seeder below truncated and rebuilt under every
| other facilities file, and in which this file then left a household's primary
| resident renamed and unticked. `Tests\Support\OwnEstate` has the whole account.
|
*/

/** This file's own estate: its subdomain, its tenant id and its database suffix. */
function residentEditEstate(): OwnEstate
{
    static $estate = null;

    return $estate ??= new OwnEstate('residentedittest', 'RET', 'Resident Edit Test Estate');
}

beforeEach(function () {
    residentEditEstate()->boot();

    // Households and their people live in these two, which an estate with nothing
    // in it does not have — this suite is about a household, so it runs them.
    (new EstateFinanceSeeder)->run();
    (new ResidentsSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('corrects a record without touching verification or consent, and moves the primary rather than dropping it', function () {
    $secretary = residentEditEstate()->viewer(Role::SECRETARY);
    $president = residentEditEstate()->viewer(Role::PRESIDENT);

    $unit = Unit::query()->whereHas('household')->orderBy('id')->firstOrFail();
    $slug = $unit->slug();

    $primary = Resident::query()
        ->whereHas('household', fn ($q) => $q->where('unit_id', $unit->id))
        ->orderByDesc('is_primary')
        ->firstOrFail();

    $before = [$primary->status, $primary->verified_at?->toDateTimeString(), (bool) $primary->biometric_consent];

    $this->actingAs($secretary)
        ->get(residentEditEstate()->url('/residents/'.$slug))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canEdit', true)
            ->has('people'));

    // A reader of the register corrects nobody.
    $this->actingAs($president)
        ->post(residentEditEstate()->url('/residents/'.$slug.'/people/'.$primary->id), [
            'full_name' => 'Nobody', 'relationship' => 'owner', 'is_primary' => true,
        ])
        ->assertForbidden();

    $this->actingAs($secretary)
        ->post(residentEditEstate()->url('/residents/'.$slug.'/people/'.$primary->id), [
            'full_name' => 'Andrea M. Fletcher',
            'email' => 'ANDREA@EXAMPLE.COM',
            'phone' => '(876) 555 0144',
            'relationship' => 'owner',
            'moved_in_on' => '2024-03-01',
            'is_primary' => true,
        ])
        ->assertRedirect(residentEditEstate()->url('/residents/'.$slug));

    residentEditEstate()->boot();

    $primary = Resident::query()->findOrFail($primary->id);

    expect($primary->full_name)->toBe('Andrea M. Fletcher')
        ->and($primary->email)->toBe('andrea@example.com')
        // In its one stored form (M18C §6), however the office typed it.
        ->and($primary->phone)->toBe('+1 876 555 0144')

        // UNTOUCHED: verification and consent are decided elsewhere.
        ->and([$primary->status, $primary->verified_at?->toDateTimeString(), (bool) $primary->biometric_consent])->toBe($before);

    // Neither an email nor a phone is a household nobody can reach: both are required now (M18C), said beside each field.
    $this->actingAs($secretary)
        ->post(residentEditEstate()->url('/residents/'.$slug.'/people/'.$primary->id), [
            'full_name' => 'Andrea M. Fletcher', 'email' => '', 'phone' => '', 'relationship' => 'owner', 'is_primary' => true,
        ])
        ->assertSessionHasErrors(['phone', 'email']);

    // A household cannot have NO primary: unticking the only one is refused.
    $this->actingAs($secretary)
        ->post(residentEditEstate()->url('/residents/'.$slug.'/people/'.$primary->id), [
            'full_name' => 'Andrea M. Fletcher', 'phone' => '(876) 555 0144', 'email' => 'andrea@example.com', 'relationship' => 'owner',
        ])
        ->assertSessionHasErrors('full_name');

    residentEditEstate()->boot();

    expect(Resident::query()->findOrFail($primary->id)->is_primary)->toBeTrue();

    // Making a second member primary MOVES it off the first.
    $second = Resident::query()->create([
        'household_id' => $primary->household_id,
        'full_name' => 'Dane Fletcher',
        'phone' => '(876) 555 0145',
        'relationship' => 'spouse',
        'is_primary' => false,
        'status' => Resident::VERIFIED,
        'biometric_consent' => false,
    ]);

    $this->actingAs($secretary)
        ->post(residentEditEstate()->url('/residents/'.$slug.'/people/'.$second->id), [
            'full_name' => 'Dane Fletcher', 'phone' => '(876) 555 0145', 'email' => 'dane.fletcher@example.com', 'relationship' => 'spouse', 'is_primary' => true,
        ])
        ->assertRedirect();

    residentEditEstate()->boot();

    expect(Resident::query()->findOrFail($second->id)->is_primary)->toBeTrue()
        ->and(Resident::query()->findOrFail($primary->id)->is_primary)->toBeFalse()
        ->and(Resident::query()->where('household_id', $primary->household_id)->where('is_primary', true)->count())->toBe(1);

    // Somebody else's household member is not reachable through this unit.
    $stranger = Resident::query()->where('household_id', '!=', $primary->household_id)->first();

    if ($stranger !== null) {
        $this->actingAs($secretary)
            ->post(residentEditEstate()->url('/residents/'.$slug.'/people/'.$stranger->id), [
                'full_name' => 'Renamed', 'phone' => '876 555 0199', 'email' => 'renamed@example.com', 'relationship' => 'owner', 'is_primary' => true,
            ])
            ->assertSessionHasErrors('full_name');

        residentEditEstate()->boot();

        expect(Resident::query()->findOrFail($stranger->id)->full_name)->not->toBe('Renamed');
    }

    $second->delete();
});
