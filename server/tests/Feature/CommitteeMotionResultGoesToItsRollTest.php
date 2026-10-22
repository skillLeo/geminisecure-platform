<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Publishing a committee motion's result comes back to its card and names its roll (step 7 of Sunday's walk)
|--------------------------------------------------------------------------
|
| A committee motion is published from its own card on the elections page (M70 §1.2, M72 §1.3 step 7) through the same
| certify route as an election, which certifies and publishes in one press. Its result is told to the households on
| its roll and nobody else (`PushAnnouncer::electionResults`). The route used to send everybody to the year's results
| page, which shows the year's lead ballot and not the motion, and said "published estate-wide" of a result only the
| roll was told. A motion now comes back to the card it was published from, and the line names the roll.
|
| The estate's database is committed and shared, so the motion is in a year of this file's own, deleted afterwards
| (nobody votes on it here, so nothing stops that).
|
*/

const MOTION_RESULT_YEAR = 2061;

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        FacilitiesFixture::boot();
        Ballot::query()->where('year', MOTION_RESULT_YEAR)->whereDoesntHave('receipts')->delete();
    }
});

it('publishes a closed committee motion from its card, comes back to it, and names the households on its roll', function () {
    $motion = Ballot::query()->forceCreate([
        'year' => MOTION_RESULT_YEAR,
        'code' => 'M',
        'title' => 'Committee Ballot · Gate Lighting',
        'kind' => Ballot::RESOLUTION,
        'stage' => Ballot::TALLY,
        'roll' => [],
        'eligible_households' => 7,
    ]);

    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))
        ->post(FacilitiesFixture::url('/governance/ballots/'.$motion->id.'/certify'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(FacilitiesFixture::url('/governance/elections/'.MOTION_RESULT_YEAR))
        ->assertSessionHas('success', 'Committee Ballot · Gate Lighting: the result is certified and published to the 7 households on its roll.');

    $motion->refresh();

    expect($motion->stage)->toBe(Ballot::CERTIFIED)
        ->and($motion->published_at)->not->toBeNull();
});

it('still sends an election to its results page, as before', function () {
    $election = Ballot::query()->forceCreate([
        'year' => MOTION_RESULT_YEAR,
        'code' => 'E',
        'title' => 'Election Of Its Own',
        'kind' => Ballot::ELECTION,
        'stage' => Ballot::TALLY,
    ]);

    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))
        ->post(FacilitiesFixture::url('/governance/ballots/'.$election->id.'/certify'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(FacilitiesFixture::url('/governance/elections/'.MOTION_RESULT_YEAR.'/results'));
});
