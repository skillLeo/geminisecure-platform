<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Nomination;
use App\Models\Role;
use App\Services\Estate\Governance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The elections page knows an empty paper before Open voting is pressed (M70 §1.2, E2E-M7)
|--------------------------------------------------------------------------
|
| The 2031 election sat at candidate vetting with one seat and nobody on the
| paper; Open voting was live and `Governance::open()` refused it with a sentence
| Hassam liked. The page now gets the names on the paper and that same sentence,
| and draws the button off with it. The sentence is the server's: proved equal to
| the refusal `open()` gives for the same ballot.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED, so the election here is in a year
| of this file's own, and it is deleted afterwards.
|
*/

const EMPTY_PAPER_YEAR = 2053;

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
        Ballot::query()->where('year', EMPTY_PAPER_YEAR)->whereDoesntHave('receipts')->delete();
    }
});

it('sends the names on the paper and the server\'s own sentence while nobody is on it', function () {
    $ballot = $this->governance->createElection(EMPTY_PAPER_YEAR, EMPTY_PAPER_YEAR.' Election', null);
    $seat = $this->governance->addPosition($ballot, 'Chair', 1);
    $this->governance->openNominations($ballot, SiteTime::now()->addWeeks(2));

    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/close-nominations'))->assertSessionHasNoErrors();
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/vetting'))->assertSessionHasNoErrors();

    // What the server says when Open voting is pressed on this paper.
    try {
        $this->governance->open($ballot->fresh(), SiteTime::now()->addDays(10));
        $refusal = null;
    } catch (DomainException $refused) {
        $refusal = $refused->getMessage();
    }

    expect($refusal)->toStartWith('Nobody is on the paper for '.EMPTY_PAPER_YEAR.' Election');

    $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/elections/'.EMPTY_PAPER_YEAR))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('onPaper', 0)
            ->where('emptyPaperReason', $refusal));

    // Somebody approved: on the paper, and no reason.
    $nomination = Nomination::query()->forceCreate([
        'ballot_id' => $ballot->id,
        'ballot_position_id' => $seat->id,
        'candidate_name' => 'Andrea Fletcher',
        'nominator_name' => 'A Proposer',
        'seconder_name' => 'A Seconder',
        'status' => Nomination::PENDING,
    ]);
    $this->governance->accept($nomination, $this->secretary);

    $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/elections/'.EMPTY_PAPER_YEAR))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('onPaper', 1)
            ->where('emptyPaperReason', null));

    expect($seat)->toBeInstanceOf(BallotPosition::class);
});
