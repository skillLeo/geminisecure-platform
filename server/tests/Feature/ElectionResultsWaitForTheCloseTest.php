<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Estate\Nomination;
use App\Models\Role;
use App\Services\Estate\Governance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| No count before the close (Hassam, 11 Oct 2026)
|--------------------------------------------------------------------------
|
| "Live vote counts during an open election change how people vote, which is exactly why the rule exists." The results
| page drew every candidate's count for the year's furthest ballot at any stage, so while voting was open a committee
| member could read the running score. While voting is open the server sends the turnout only, how many households
| have voted of how many may; after the close, the full result. The check is on the server, in the board and in the
| controller, so the numbers do not travel at all.
|
| No vote is cast here and no receipt is written: a ballot in a year of this file's own is drafted, opened and closed,
| and goes afterwards with its positions, options and nominations.
|
*/

const RESULTS_CLOSE_YEAR = 2071;

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->governance = app(Governance::class);
    $this->president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $this->secretary = FacilitiesFixture::viewer(Role::SECRETARY);
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        FacilitiesFixture::boot();
        Ballot::query()->where('year', RESULTS_CLOSE_YEAR)->whereDoesntHave('receipts')->delete();
    }
});

/**
 * Every key at any depth of what a page is sent, so "no count" means none anywhere in it.
 *
 * @param  array<mixed>  $value
 * @return list<string>
 */
function resultsKeysOf(array $value): array
{
    $keys = [];

    foreach ($value as $key => $child) {
        $keys[] = (string) $key;

        if (is_array($child)) {
            $keys = [...$keys, ...resultsKeysOf($child)];
        }
    }

    return $keys;
}

it('sends the turnout and no candidate\'s count while voting is open, and the full result once the poll has closed', function () {
    $ballot = $this->governance->createElection(RESULTS_CLOSE_YEAR, RESULTS_CLOSE_YEAR.' Election', null);
    $chair = $this->governance->addPosition($ballot, 'Chairman', 1);
    $this->governance->openNominations($ballot, SiteTime::now()->addWeeks(1));
    FacilitiesFixture::boot();
    $nomination = Nomination::query()->forceCreate(['ballot_id' => $ballot->id, 'ballot_position_id' => $chair->id, 'candidate_name' => 'Only Candidate', 'nominator_name' => 'P', 'seconder_name' => 'S', 'status' => Nomination::PENDING]);
    $this->governance->accept($nomination, $this->secretary);
    $this->governance->closeNominations($ballot->fresh());
    $this->governance->startVetting($ballot->fresh());
    $this->governance->open($ballot->fresh(), SiteTime::now()->addDays(7));

    $url = FacilitiesFixture::url('/governance/elections/'.RESULTS_CLOSE_YEAR.'/results');

    // While voting is open: the turnout, and no candidate and no count anywhere in what the page is sent.
    $open = $this->actingAs($this->president)->get($url)->assertOk();
    $open->assertInertia(fn (AssertableInertia $page) => $page
        ->where('ballot.stage', Ballot::VOTING_OPEN)
        ->where('cards', [])
        ->where('count_closed', false)
        ->has('turnout.cast')
        ->has('turnout.eligible'));
    expect(in_array('votes', resultsKeysOf($open->viewData('page')['props']), true))->toBeFalse();

    // The board itself, which the certificate and the turnout report read too: nothing per candidate.
    FacilitiesFixture::boot();
    $board = $this->governance->resultsBoard(RESULTS_CLOSE_YEAR);
    expect([$board['cards'], $board['count_closed']])->toBe([[], false]);

    // Once the poll has closed: the full result, every candidate with their count.
    $this->governance->close($ballot->fresh());

    $this->actingAs($this->president)->get($url)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('count_closed', true)
            ->has('cards', 1)
            ->where('cards.0.rows.0.name', 'Only Candidate')
            ->where('cards.0.rows.0.votes', 0));
});
