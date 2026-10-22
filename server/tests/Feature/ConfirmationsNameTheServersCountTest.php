<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Role;
use App\Services\Estate\Governance;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A confirmation names the server's own number (M62 B1, DR-008; M64 §E2)
|--------------------------------------------------------------------------
|
| Open nominations asks before it acts, and the number it names is the one Governance::eligibleHouseholdCount()
| returns for that election: the screen never counts for itself. On the committee-only election that number is 7
| (M62 A9, the m56 session's), which is what proves the eligibility rule is real.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED, so the election here is in a year of this file's own, deleted after.
|
*/

const CONFIRM_COUNT_YEAR = 2057;
const CONFIRM_EMPTY_YEAR = 2058;

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->governance = app(Governance::class);
    $this->secretary = FacilitiesFixture::viewer(Role::SECRETARY);
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        FacilitiesFixture::boot();
        Ballot::query()->whereIn('year', [CONFIRM_COUNT_YEAR, CONFIRM_EMPTY_YEAR])->whereDoesntHave('receipts')->delete();
    }
});

it("gives Open nominations the election's own count of households, from Governance::eligibleHouseholdCount (DR-008)", function () {
    $ballot = Ballot::query()->where('year', CONFIRM_COUNT_YEAR)->first()
        ?? $this->governance->createElection(CONFIRM_COUNT_YEAR, CONFIRM_COUNT_YEAR.' Election', null);
    $expected = $this->governance->eligibleHouseholdCount($ballot->fresh());

    $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/elections/'.CONFIRM_COUNT_YEAR))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Governance/ControlRoom')
            ->where('ballotId', $ballot->id)
            ->where('eligibleHouseholds', $expected));
});

it('names no count for a year with no election to open', function () {
    $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/elections/'.CONFIRM_EMPTY_YEAR))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ballotId', null)
            ->where('eligibleHouseholds', null));
});
