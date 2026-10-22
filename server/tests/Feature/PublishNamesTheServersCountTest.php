<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Estate\Meeting;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\Governance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| The count a confirmation names is the server's own (M62 B1; Hassam, M65 §3, 10 October 2026)
|--------------------------------------------------------------------------
|
| On an estate of this file's own, built to Phoenix Park's shape where it matters: 443 households on 443 lots, and the
| seven console accounts that are its committee. Publishing a general meeting names the 443 households the push reaches;
| a committee's own meeting resolves to the committee's 7 and tells no household; opening the poll names the 443 the
| election counts. Every number is read off the page the server renders, never counted here in a way of the test's own.
|
*/

const COUNTS_ELECTION_YEAR = 2060;

function countsEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('confirmcounts', 'CFC', 'Confirm Counts Estate');
}

/** 443 lots with a household and its resident on each; once a process (the estate's database is committed, and is this file's alone). */
function countsEstateBuilt(): void
{
    static $built = false;

    countsEstate()->boot();

    if ($built) {
        return;
    }

    if (DB::connection('tenant')->table('units')->count() === 0) {
        $now = now();

        foreach (array_chunk(range(1, 443), 200) as $chunk) {
            DB::connection('tenant')->table('units')->insert(array_map(static fn (int $n): array => [
                'reference' => 'Lot '.$n, 'block' => null, 'street' => 'Phoenix Drive', 'type' => 'residential', 'status' => 'occupied',
                'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        foreach (DB::connection('tenant')->table('units')->pluck('id')->chunk(200) as $chunk) {
            DB::connection('tenant')->table('households')->insert($chunk->map(static fn ($id): array => [
                'unit_id' => $id, 'name' => 'Household '.$id, 'access_restricted' => false, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }

        // Each with its resident, as the estate counts a household (M87 §1.4: one with nobody in it is not counted).
        foreach (DB::connection('tenant')->table('households')->pluck('id')->chunk(200) as $chunk) {
            DB::connection('tenant')->table('residents')->insert($chunk->map(static fn ($id): array => [
                'household_id' => $id, 'full_name' => 'Resident '.$id, 'relationship' => 'owner', 'is_primary' => true,
                'status' => 'verified', 'verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }
    }

    $built = true;
}

/** The seven console accounts of the committee (any estate role counts, as `Settings::committee()` counts them). */
function countsCommittee(): User
{
    $president = countsEstate()->viewer(Role::PRESIDENT);

    foreach ([Role::SECRETARY, Role::TREASURER, Role::SECRETARY, Role::TREASURER, Role::SECRETARY, Role::TREASURER] as $role) {
        countsEstate()->viewer($role);
    }

    return $president;
}

beforeEach(function () {
    countsEstateBuilt();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    $this->president = countsCommittee();
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        countsEstate()->boot();
        Meeting::query()->delete();
        Ballot::query()->where('year', COUNTS_ELECTION_YEAR)->whereDoesntHave('receipts')->delete();
    }
});

/** A draft meeting of this type and audience, as the console schedules one; its id. */
function countsMeeting(User $by, string $type, string $audience): int
{
    return countsEstate()->tenant()->run(static fn (): int => app(Governance::class)->scheduleMeeting([
        'type' => $type, 'title' => 'Meeting of the '.$type, 'starts_at' => SiteTime::today()->addDays(40)->setTime(18, 0),
        'held_as' => Meeting::IN_PERSON, 'venue' => 'Community Centre', 'audience_scope' => $audience,
    ], [], $by)->id);
}

it('names the 443 households a general meeting tells, from the server, and no committee', function () {
    $agm = countsMeeting($this->president, Meeting::AGM, Meeting::WHOLE_ESTATE);

    $this->actingAs($this->president)->get(countsEstate()->url('/governance/meetings/'.$agm))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Governance/Meeting')
            ->where('meeting.households_told', 443)
            ->where('publishReach.committee', false)
            ->where('publishReach.members', null));
});

it("resolves a committee's own meeting to the committee's 7 members, from the server, and tells no household", function () {
    $committee = countsMeeting($this->president, Meeting::COMMITTEE, Meeting::COMMITTEE_ONLY);

    $this->actingAs($this->president)->get(countsEstate()->url('/governance/meetings/'.$committee))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('meeting.households_told', 0)
            ->where('publishReach.committee', true)
            ->where('publishReach.members', 7));
});

it('names the 443 households an election counts when its poll opens, from the server', function () {
    countsEstate()->tenant()->run(static fn () => app(Governance::class)->createElection(COUNTS_ELECTION_YEAR, COUNTS_ELECTION_YEAR.' Election', null));

    $this->actingAs($this->president)->get(countsEstate()->url('/governance/elections/'.COUNTS_ELECTION_YEAR))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('eligibleHouseholds', 443));
});
