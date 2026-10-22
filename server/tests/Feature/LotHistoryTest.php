<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\Role;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A lot's page says who has lived there (M98, the Residents census: ownership and tenancy history)
|--------------------------------------------------------------------------
|
| The lot's page showed today's household and nothing of the ones before it. It now lists every household the register
| has held at the lot, the one living there first and then those that moved out, each with how its people were recorded
| (Owner or Tenant), when it moved in and when and why it moved out. Read from the register's own rows, removed ones
| included; nothing new is stored.
|
| Lot 9872 is this file's own scratch lot: the fixture estate is shared.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 9872');
    lotHistoryClear($this->unit->id);
});

afterEach(function () {
    FacilitiesFixture::boot();
    lotHistoryClear($this->unit->id);

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** This file's rows on its own scratch lot, gone: a household a charge points at stays, as the ledger is append-only. */
function lotHistoryClear(int $unitId): void
{
    $households = Household::withRemoved()->where('unit_id', $unitId)->pluck('id');
    VisitorPass::query()->whereIn('household_id', $households)->delete();
    Resident::withRemoved()->whereIn('household_id', $households)->delete();

    foreach ($households as $id) {
        try {
            Household::withRemoved()->whereKey($id)->delete();
        } catch (Throwable) {
        }
    }
}

/** One person on a household, as the register holds them. */
function lotHistoryPerson(Household $household, string $name, string $relationship, string $movedIn): void
{
    $person = new Resident;
    $person->forceFill([
        'household_id' => $household->id, 'full_name' => $name, 'email' => null, 'phone' => '+1 876 555 0987',
        'relationship' => $relationship, 'is_primary' => true, 'status' => Resident::VERIFIED, 'moved_in_on' => $movedIn,
    ])->save();
}

it('lists the household living at the lot first, then the one that moved out, with when and why', function () {
    $office = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $page = FacilitiesFixture::url('/residents/'.$this->unit->slug());

    // The first household: recorded as owners, moved in on 2 September 2024.
    $first = Household::query()->create(['unit_id' => $this->unit->id, 'name' => 'Brown household', 'access_restricted' => false]);
    lotHistoryPerson($first, 'Pat Brown', 'owner', '2024-09-02');

    // It moves out: the office removes it, with the reason.
    $this->actingAs($office)
        ->post(FacilitiesFixture::url('/residents/'.$this->unit->slug().'/household/remove'), ['confirm_lot' => 'Lot 9872', 'reason' => 'Sold the property to the Greens.'])
        ->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    $movedOut = SiteTime::now()->format('j M Y');

    // The next household: tenants, moved in on 1 October 2026.
    $second = Household::query()->create(['unit_id' => $this->unit->id, 'name' => 'Green household', 'access_restricted' => false]);
    lotHistoryPerson($second, 'Kim Green', 'tenant', '2026-10-01');

    $this->actingAs($office)->get($page)->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->has('history', 2)
        ->where('history.0.name', 'Green household')
        ->where('history.0.held_by', 'Kim Green')
        ->where('history.0.recorded_as', 'Tenant')
        ->where('history.0.moved_in', '1 Oct 2026')
        ->where('history.0.current', true)
        ->where('history.0.moved_out', null)
        ->where('history.1.name', 'Brown household')
        ->where('history.1.held_by', 'Pat Brown')
        ->where('history.1.recorded_as', 'Owner')
        ->where('history.1.moved_in', '2 Sep 2024')
        ->where('history.1.moved_out', $movedOut)
        ->where('history.1.reason', 'Sold the property to the Greens.')
        ->where('history.1.current', false));
});

it('says nothing has been recorded at a lot nobody has lived at', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))
        ->get(FacilitiesFixture::url('/residents/'.$this->unit->slug()))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('history', []));
});
