<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Nomination;
use App\Models\Role;
use App\Services\Estate\Governance;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Positions are typed, not chosen from a list — M10 §7
|--------------------------------------------------------------------------
|
| `ballot_positions.name` has always been free text with its own seat count and
| sort order: nothing in this codebase ever hardcoded "Chairman". What was missing
| was any way to WRITE one. The only writer was a seeder — no route, no service
| method, no screen — so an estate whose committee is not the seeded one had no
| way to say so, and the election it ran would have been for somebody else's
| offices.
|
| THE SEAT COUNT IS NOT DECORATION. It is what makes a result mean something: one
| seat and the candidates' percentages sum to 100 because a voter marks one name;
| three seats and they sum past 100 because a voter marks up to three. So it is
| required rather than defaulted quietly.
|
| EVERYTHING IS CONFINED TO A DRAFT, and half these tests are about that. Once
| nominations open, somebody has stood for a named seat: renaming it changes what
| they stood for, changing its seats changes who wins, and deleting it discards
| their nomination. The refusal lives in the service so it holds whichever door
| a write arrives at.
|
*/

const ELECTION_TEST_TITLE = 'Typed Positions Test Election';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->governance = app(Governance::class);
    $this->secretary = FacilitiesFixture::viewer(Role::SECRETARY);

    $this->ballot = $this->governance->createElection(
        2031,
        ELECTION_TEST_TITLE,
        'The committee for 2031.',
    );
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    $ids = Ballot::query()->where('title', ELECTION_TEST_TITLE)->pluck('id');
    Nomination::query()->whereIn('ballot_id', $ids)->delete();
    BallotPosition::query()->whereIn('ballot_id', $ids)->delete();
    Ballot::query()->whereIn('id', $ids)->delete();
});

it('takes any position title the estate uses, not a fixed list', function () {
    /*
     * The point of the section. None of these is a seeded title, and one of them
     * is an office no template would ever have offered.
     */
    foreach ([['Chairman', 1], ['Vice Chairman', 3], ['Beach Access Warden', 2]] as [$name, $seats]) {
        $this->governance->addPosition($this->ballot, $name, $seats);
    }

    $positions = $this->ballot->positions()->orderBy('sort_order')->get();

    expect($positions->pluck('name')->all())->toBe(['Chairman', 'Vice Chairman', 'Beach Access Warden'])
        ->and($positions->pluck('seat_count')->all())->toBe([1, 3, 2])

        // Appended in the order they were typed, not re-sorted alphabetically.
        ->and($positions->pluck('sort_order')->all())->toBe([1, 2, 3]);
});

it('refuses a position with no title and one with no seats', function () {
    expect(fn () => $this->governance->addPosition($this->ballot, '   ', 1))
        ->toThrow(DomainException::class, 'needs a title');

    expect(fn () => $this->governance->addPosition($this->ballot, 'Treasurer', 0))
        ->toThrow(DomainException::class, 'cannot be won');
});

it('refuses the same office twice, however it is capitalised', function () {
    /*
     * Two positions with the same title would put a household in front of the
     * same office twice with different candidates under it, and no result could
     * say which "Chairman" anybody won.
     */
    $this->governance->addPosition($this->ballot, 'Chairman', 1);

    expect(fn () => $this->governance->addPosition($this->ballot, 'chairman', 1))
        ->toThrow(DomainException::class, 'already has a position called');
});

it('retitles a position and changes its seats while the election is a draft', function () {
    $position = $this->governance->addPosition($this->ballot, 'Vice Chair', 2);

    $this->governance->updatePosition($position, 'Vice Chairman', 3);

    expect($position->fresh()->name)->toBe('Vice Chairman')
        ->and($position->fresh()->seat_count)->toBe(3);
});

it('reorders the whole set, and refuses a partial list', function () {
    $a = $this->governance->addPosition($this->ballot, 'Chairman', 1);
    $b = $this->governance->addPosition($this->ballot, 'Secretary', 1);
    $c = $this->governance->addPosition($this->ballot, 'Treasurer', 1);

    $this->governance->reorderPositions($this->ballot, [$c->id, $a->id, $b->id]);

    expect($this->ballot->positions()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Treasurer', 'Chairman', 'Secretary']);

    /*
     * A partial list would leave the positions it omitted holding stale sort
     * orders that collide with the new ones, and the paper's order would then
     * depend on the database's tie-break rather than anybody's decision.
     */
    expect(fn () => $this->governance->reorderPositions($this->ballot, [$a->id, $b->id]))
        ->toThrow(DomainException::class, 'every position on the ballot, once each');
});

it('refuses renaming and reordering once the election has left draft, and adding or removing once candidates are checked (M56 §3)', function () {
    $position = $this->governance->addPosition($this->ballot, 'Chairman', 1);

    $this->governance->openNominations($this->ballot, now()->addWeeks(2));

    $ballot = $this->ballot->fresh();

    expect($ballot->stage)->not->toBe(Ballot::DRAFT);

    // While nominations are open a seat can still be added (M56 §3): nobody has stood for it yet.
    $treasurer = $this->governance->addPosition($ballot, 'Treasurer', 1);

    expect(fn () => $this->governance->updatePosition($position->fresh(), 'Chair', 1))
        ->toThrow(DomainException::class, 'cannot be changed');

    expect(fn () => $this->governance->reorderPositions($ballot, [$treasurer->id, $position->id]))
        ->toThrow(DomainException::class, 'cannot be reordered');

    // Once candidates are being checked, the paper is set: nothing is added and nothing taken away.
    foreach ([$position, $treasurer] as $seat) {
        App\Models\Estate\Nomination::query()->forceCreate([
            'ballot_id' => $ballot->id, 'ballot_position_id' => $seat->id, 'candidate_name' => 'A Candidate',
            'nominator_name' => 'A Proposer', 'seconder_name' => 'A Seconder', 'status' => App\Models\Estate\Nomination::APPROVED,
        ]);
    }
    $this->governance->closeNominations($ballot->fresh());
    $this->governance->startVetting($ballot->fresh());

    expect(fn () => $this->governance->addPosition($ballot->fresh(), 'Patron', 1))
        ->toThrow(DomainException::class, 'cannot be added');

    expect(fn () => $this->governance->removePosition($treasurer->fresh()))
        ->toThrow(DomainException::class, 'cannot be removed');
});

it('will not remove a position somebody has already stood for', function () {
    $position = $this->governance->addPosition($this->ballot, 'Chairman', 1);

    Nomination::query()->forceCreate([
        'ballot_id' => $this->ballot->id,
        'ballot_position_id' => $position->id,
        'candidate_name' => 'A Candidate',

        // A nomination needs a proposer and a seconder — the table says so, and
        // that is the estate's own rule about who may stand, not this test's.
        'nominator_name' => 'A Proposer',
        'seconder_name' => 'A Seconder',
        'status' => 'pending',
    ]);

    /*
     * Reachable only on a draft, where nominations should not exist — but a
     * seeded or imported ballot can carry them, and a dangling nomination would
     * appear on board 10 under a position nothing can name.
     */
    expect(fn () => $this->governance->removePosition($position->fresh()))
        ->toThrow(DomainException::class, 'Withdraw the nomination first');
});

it('creates and renames an election through its routes', function () {
    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/elections'), [
            'year' => 2032,
            'title' => ELECTION_TEST_TITLE,
            'description' => 'Typed through the route.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $ballot = Ballot::query()->where('year', 2032)->firstOrFail();

    expect($ballot->title)->toBe(ELECTION_TEST_TITLE);

    // Addressed to the ballot, not the year (B043).
    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/positions'), [
            'name' => 'Beach Access Warden',
            'seats' => 2,
            'scope' => BallotPosition::ESTATE,
        ])
        ->assertSessionHasNoErrors();

    expect($ballot->positions()->where('name', 'Beach Access Warden')->value('seat_count'))->toBe(2);
});
