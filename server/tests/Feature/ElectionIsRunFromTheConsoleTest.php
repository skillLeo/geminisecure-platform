<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
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
| An election is drafted, configured and run from board 9 (B042, B104, B043)
|--------------------------------------------------------------------------
|
| M10 §7: the Secretary "must be able to create an election and edit its name and
| description; add positions by typing them … edit or remove a position while the
| election is still in draft, and reorder them". The routes for most of that
| existed and nothing on the console posted to them. A ballot drafted through the
| route landed on "No ballot has been drafted" with its editor hidden, because the
| screen counted positions rather than ballots. A draft could never leave draft,
| and a ballot whose nominations were closed early could never reach a poll.
|
| AND THE WRITES WENT TO THE WRONG BALLOT (B043). They were addressed by year and
| resolved to the year's oldest paper, while the control room drew — and offered
| to configure — the least advanced one; nothing stopped a second election being
| created for a year that already had one, and it took the room over.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED, so every election here is in a
| year of this file's own, and every one is deleted afterwards.
|
*/

const CONSOLE_ELECTION_YEARS = [2041, 2049]; // 2046 is the undecided-nominations test's

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

        // Positions, options and nominations go with their ballot (cascade). A ballot somebody voted in stays: a cast
        // vote is never deleted, by trigger and by grant (the cancel test votes on purpose, in a year of its own).
        Ballot::query()->whereBetween('year', CONSOLE_ELECTION_YEARS)->whereDoesntHave('receipts')->delete();
    }
});

/** Board 9 for a year, as the Secretary sees it. */
function controlRoomFor(object $test, object $viewer, int $year): mixed
{
    return $test->actingAs($viewer)->get(FacilitiesFixture::url('/governance/elections/'.$year))->assertOk();
}

it('offers a year with no election a way to draft one, and nothing to configure', function () {
    controlRoomFor($this, $this->secretary, 2041)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Governance/ControlRoom')
            ->where('ballotId', null)
            ->where('canCreate', true)
            ->where('canConfigure', false));
});

it('drafts an election from the console and lands on its editor, with no position on it yet', function () {
    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/elections'), [
            'year' => 2042,
            'title' => '2042 Committee Election',
            'description' => 'The committee for 2042.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirectContains('/governance/elections/2042')
        ->assertSessionHas('success');

    $ballot = Ballot::query()->where('year', 2042)->sole();

    expect($ballot->stage)->toBe(Ballot::DRAFT)
        ->and($ballot->code)->toBe('A');

    // A drafted ballot with no position is not "no ballot": it is what the editor is for.
    controlRoomFor($this, $this->secretary, 2042)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ballotId', $ballot->id)
            ->where('positions', [])
            ->where('stage', Ballot::DRAFT)
            ->where('canConfigure', true)
            ->where('canAddPosition', true)
            ->where('canEditPositions', true)
            ->where('ballot.title', '2042 Committee Election')
            ->where('ballot.description', 'The committee for 2042.')
            ->where('years', fn ($years) => collect($years)->contains(2042)));
});

it('adds the first position, renames the election and reorders its positions, each posted to the ballot', function () {
    $ballot = $this->governance->createElection(2043, 'Working Title', null);

    foreach (['Chairman', 'Secretary', 'Treasurer'] as $name) {
        $this->actingAs($this->secretary)
            ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/positions'), ['name' => $name, 'seats' => 1, 'scope' => BallotPosition::ESTATE])
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/rename'), ['title' => '2043 Committee Election', 'description' => 'Three offices.'])
        ->assertSessionHasNoErrors();

    $ids = $ballot->positions()->pluck('id')->all();

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/positions/order'), ['order' => array_reverse($ids)])
        ->assertSessionHasNoErrors();

    $ballot->refresh();

    expect($ballot->title)->toBe('2043 Committee Election')
        ->and($ballot->description)->toBe('Three offices.')
        ->and($ballot->positions()->pluck('name')->all())->toBe(['Treasurer', 'Secretary', 'Chairman']);

    // The rows the screen draws say which ballot they are on, so only this one's are offered for edit.
    controlRoomFor($this, $this->secretary, 2043)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('positions', fn ($rows) => collect($rows)->pluck('ballot_id')->unique()->values()->all() === [$ballot->id]));
});

it('refuses a second election for a year that already has one, and names the one it has', function () {
    $this->governance->createElection(2044, 'The 2044 Election', null);

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/elections'), ['year' => 2044, 'title' => '2044 By-election'])
        ->assertSessionHasErrors('year');

    expect((string) session('errors')->first('year'))->toContain('The 2044 Election');

    expect(fn () => $this->governance->createElection(2044, 'Another', null))
        ->toThrow(DomainException::class, 'already has an election');

    expect(Ballot::query()->where('year', 2044)->pluck('title')->all())->toBe(['The 2044 Election']);
});

it('configures the ballot the control room shows, even when the year also holds an older paper', function () {
    $older = Ballot::query()->forceCreate(['year' => 2045, 'code' => 'A', 'title' => 'Older Paper', 'kind' => Ballot::ELECTION, 'stage' => Ballot::TALLY]);
    $draft = Ballot::query()->forceCreate(['year' => 2045, 'code' => 'B', 'title' => 'Newer Draft', 'kind' => Ballot::ELECTION, 'stage' => Ballot::DRAFT]);

    controlRoomFor($this, $this->secretary, 2045)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ballotId', $draft->id)
            ->where('canConfigure', true));

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$draft->id.'/positions'), ['name' => 'Treasurer', 'seats' => 1, 'scope' => BallotPosition::ESTATE])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$draft->id.'/rename'), ['title' => 'Newer Draft, Renamed'])
        ->assertSessionHasNoErrors();

    expect($draft->positions()->pluck('name')->all())->toBe(['Treasurer'])
        ->and($draft->fresh()->title)->toBe('Newer Draft, Renamed')
        ->and($older->positions()->count())->toBe(0)
        ->and($older->fresh()->title)->toBe('Older Paper');
});

it('opens nominations on a configured draft, and refuses one with no position or a close already past', function () {
    $ballot = $this->governance->createElection(2046, '2046 Election', null);
    $future = SiteTime::now()->addWeeks(3)->format('Y-m-d\TH:i');
    $past = SiteTime::now()->subDay()->format('Y-m-d\TH:i');

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/open-nominations'), ['closes_at' => $future])
        ->assertSessionHasErrors('closes_at');

    expect((string) session('errors')->first('closes_at'))->toContain('no position');

    $this->governance->addPosition($ballot, 'Chairman', 1);

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/open-nominations'), ['closes_at' => $past])
        ->assertSessionHasErrors('closes_at');

    expect($ballot->fresh()->stage)->toBe(Ballot::DRAFT);

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/open-nominations'), ['closes_at' => $future])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $open = $ballot->fresh();

    // The close was typed on the estate's clock and is kept as the moment it means.
    expect($open->stage)->toBe(Ballot::NOMINATIONS_OPEN)
        ->and(SiteTime::local($open->nominations_close_at)->format('Y-m-d\TH:i'))->toBe($future);
});

it('takes a ballot whose nominations were closed early to vetting, and opens the poll with its approved candidates on the paper', function () {
    $ballot = $this->governance->createElection(2047, '2047 Election', null);
    $chair = $this->governance->addPosition($ballot, 'Chairman', 1);
    $treasurer = $this->governance->addPosition($ballot, 'Treasurer', 1);
    $this->governance->openNominations($ballot, SiteTime::now()->addWeeks(2));

    $lodge = static fn (BallotPosition $seat, string $name, ?int $unitId = null): Nomination => Nomination::query()->forceCreate([
        'ballot_id' => $ballot->id,
        'ballot_position_id' => $seat->id,
        'unit_id' => $unitId,
        'candidate_name' => $name,
        'nominator_name' => 'A Proposer',
        'seconder_name' => 'A Seconder',
        'status' => Nomination::PENDING,
    ]);

    $first = $lodge($chair, 'Andrea Fletcher', FacilitiesFixture::unit('Lot 47')->id);
    $second = $lodge($chair, 'Keith Walters');
    $refused = $lodge($treasurer, 'Refused Candidate');
    $waiting = $lodge($treasurer, 'Sonia Campbell');

    // The board's one live button, and then the step that used to have no door.
    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/close-nominations'))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/vetting'))
        ->assertSessionHasNoErrors();

    expect($ballot->fresh()->stage)->toBe(Ballot::CANDIDATE_VETTING);

    $this->governance->accept($first, $this->secretary);
    $this->governance->accept($second, $this->secretary);
    $this->governance->reject($refused, 'Not a member of the estate', $this->secretary);

    $closes = SiteTime::now()->addDays(10)->format('Y-m-d\TH:i');

    // One candidate is still waiting: opening now would leave them off the paper undecided.
    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/open'), ['closes_at' => $closes])
        ->assertSessionHasErrors('closes_at');

    expect($ballot->fresh()->stage)->toBe(Ballot::CANDIDATE_VETTING)
        ->and(BallotOption::query()->where('ballot_id', $ballot->id)->count())->toBe(0);

    // And the screen knew first (M53 §13.1): the count it draws Open voting disabled with.
    expect($this->governance->controlRoom($ballot->year)['undecided'])->toBe(1);

    $this->governance->accept($waiting, $this->secretary);

    expect($this->governance->controlRoom($ballot->year)['undecided'])->toBe(0);

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/open'), ['closes_at' => $closes])
        ->assertSessionHasNoErrors();

    expect($ballot->fresh()->stage)->toBe(Ballot::VOTING_OPEN);

    $paper = BallotOption::query()->where('ballot_id', $ballot->id)->orderBy('ballot_position_id')->orderBy('sort_order')->get();

    // Seat by seat, in the order they were lodged; the refused candidate is not on it.
    expect($paper->map(fn (BallotOption $o): array => [$o->ballot_position_id, $o->label, $o->sort_order])->all())->toBe([
        [$chair->id, 'Andrea Fletcher', 1],
        [$chair->id, 'Keith Walters', 2],
        [$treasurer->id, 'Sonia Campbell', 1],
    ])
        // A candidate's phase is read off their own property, as board 11 prints it.
        ->and($paper->first()->phase)->toBe('Phase 2');
});

it('adds a seat while nominations are open or paused, removes only one nobody stood for, and changes none once candidates are checked (M56 §3)', function () {
    $ballot = $this->governance->createElection(2041, '2041 Election', null);
    $chair = $this->governance->addPosition($ballot, 'Chairman', 1);
    $this->governance->openNominations($ballot, SiteTime::now()->addWeeks(2));

    // Open: the console still offers to add a seat, and adds it, audited.
    $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/elections/2041'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('positionsOpen', true)->where('canConfigure', false));
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/positions'), ['name' => 'Treasurer', 'seats' => 1, 'scope' => BallotPosition::ESTATE])->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    $treasurer = BallotPosition::query()->where('ballot_id', $ballot->id)->where('name', 'Treasurer')->sole();
    expect(App\Models\AuditEntry::query()->where('action', 'election.position_added')->where('entity_id', (string) $ballot->id)->exists())->toBeTrue();

    // Somebody stands for the Chairman: that seat stays; the empty one can go.
    Nomination::query()->forceCreate([
        'ballot_id' => $ballot->id, 'ballot_position_id' => $chair->id, 'candidate_name' => 'A Candidate',
        'nominator_name' => 'A Proposer', 'seconder_name' => 'A Seconder', 'status' => Nomination::PENDING,
    ]);
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/positions/'.$chair->id.'/remove'))->assertSessionHasErrors('position');
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/positions/'.$treasurer->id.'/remove'))->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(BallotPosition::query()->whereKey($treasurer->id)->exists())->toBeFalse()
        ->and(BallotPosition::query()->whereKey($chair->id)->exists())->toBeTrue();

    // Renaming still waits for a draft: somebody may have stood for it.
    expect(fn () => $this->governance->updatePosition($chair->fresh(), 'Chair', 1))->toThrow(DomainException::class);

    // Paused: still open to a new seat.
    $this->governance->closeNominations($ballot->fresh());
    $secretaryRole = $this->governance->addPosition($ballot->fresh(), 'Secretary', 1);
    expect($secretaryRole->exists)->toBeTrue();

    // Candidates being checked: the paper is set.
    Nomination::query()->forceCreate([
        'ballot_id' => $ballot->id, 'ballot_position_id' => $secretaryRole->id, 'candidate_name' => 'Another Candidate',
        'nominator_name' => 'A Proposer', 'seconder_name' => 'A Seconder', 'status' => Nomination::PENDING,
    ]);
    Nomination::query()->where('ballot_id', $ballot->id)->update(['status' => Nomination::APPROVED]);
    $this->governance->startVetting($ballot->fresh());
    expect(fn () => $this->governance->addPosition($ballot->fresh(), 'Patron', 1))->toThrow(DomainException::class)
        ->and(fn () => $this->governance->removePosition($secretaryRole->fresh()))->toThrow(DomainException::class);
});

it('lists only the nominations Open voting waits on, and lets one asked for more information be decided (M56 §3.4)', function () {
    $ballot = $this->governance->createElection(2046, '2046 Election', null);
    $chair = $this->governance->addPosition($ballot, 'Chairman', 1);
    $this->governance->openNominations($ballot, SiteTime::now()->addWeeks(2));

    $lodge = static fn (string $name, string $status): Nomination => Nomination::query()->forceCreate([
        'ballot_id' => $ballot->id, 'ballot_position_id' => $chair->id, 'candidate_name' => $name,
        'nominator_name' => 'A Proposer', 'seconder_name' => 'A Seconder', 'status' => $status,
    ]);

    $lodge('Pending Candidate', Nomination::PENDING);
    $asked = $lodge('Asked Candidate', Nomination::MORE_INFO);
    $lodge('Approved Candidate', Nomination::APPROVED);

    // Both undecided rows hold Open voting, and both can now be decided from the list: the one asked for more
    // information used to be drawn with no tick and no cross, so it blocked the poll with nothing to press.
    $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/elections/2046/nominations?status=undecided'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Governance/Nominations')
            ->where('undecidedOnly', true)
            ->where('rows', fn ($rows): bool => collect($rows)->pluck('name')->sort()->values()->all() === ['Asked Candidate', 'Pending Candidate']
                && collect($rows)->every(fn (array $row): bool => $row['decidable'] === true)));

    // The whole list, unfiltered, still shows the decided one, with no decision to take on it.
    $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/elections/2046/nominations'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('undecidedOnly', false)
            ->where('rows', fn ($rows): bool => collect($rows)->firstWhere('name', 'Approved Candidate')['decidable'] === false));

    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/nominations/'.$asked->id.'/accept'))->assertSessionHasNoErrors();

    expect($asked->fresh()->status)->toBe(Nomination::APPROVED)
        ->and($this->governance->controlRoom(2046)['undecided'])->toBe(1);
});

it('adds a candidate proposed and seconded by residents, lodged pending and decidable, only while nominations are taken (M56 §3.3)', function () {
    $ballot = $this->governance->createElection(2045, '2045 Election', null);
    $chair = $this->governance->addPosition($ballot, 'Chairman', 1);
    $this->governance->openNominations($ballot, SiteTime::now()->addWeeks(2));

    // Three verified residents of a lot of this file's own: a candidate, a proposer and a seconder.
    $unit = FacilitiesFixture::scratchUnit('Lot 9476');
    $household = App\Models\Estate\Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Candidates household', 'access_restricted' => false]);
    [$candidate, $nominator, $seconder] = array_map(static function (string $name) use ($household): App\Models\Estate\Resident {
        $resident = App\Models\Estate\Resident::query()->firstOrNew(['household_id' => $household->id, 'full_name' => $name]);
        $resident->forceFill(['email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'relationship' => 'owner', 'is_primary' => false, 'status' => App\Models\Estate\Resident::VERIFIED])->save();

        return $resident->fresh();
    }, ['Carla Candidate', 'Paul Proposer', 'Sam Seconder']);

    // The picker finds them by name.
    $found = $this->actingAs($this->secretary)->getJson(FacilitiesFixture::url('/governance/residents/search?q='.urlencode(mb_substr($candidate->full_name, 0, 5))))->assertOk()->json('items');
    expect(collect($found)->pluck('id'))->toContain($candidate->id);

    $form = ['position_id' => $chair->id, 'candidate_id' => $candidate->id, 'nominator_id' => $nominator->id, 'seconder_id' => $seconder->id, 'statement' => 'I will keep the gate open on time.'];

    // Three different people, or nothing is lodged.
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/nominations'), [...$form, 'seconder_id' => $candidate->id])->assertSessionHasErrors('nomination');

    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/nominations'), $form)->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $lodged = Nomination::query()->where('ballot_id', $ballot->id)->sole();
    expect([$lodged->status, $lodged->candidate_name, $lodged->nominator_name, $lodged->seconder_name, $lodged->statement])
        ->toBe([Nomination::PENDING, $candidate->full_name, $nominator->full_name, $seconder->full_name, 'I will keep the gate open on time.']);

    // Once, per seat.
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/nominations'), $form)->assertSessionHasErrors('nomination');

    // Decidable on the list, and the screen offers the form while nominations are taken.
    $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/elections/2045/nominations'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canAdd', true)
            ->where('addTo.0.positions.0.name', 'Chairman')
            ->where('rows.0.decidable', true));

    // After the paper is settled, nobody is added.
    $this->governance->accept($lodged, $this->secretary);
    $this->governance->closeNominations($ballot->fresh());
    $this->governance->startVetting($ballot->fresh());
    $this->governance->open($ballot->fresh(), SiteTime::now()->addDays(7));

    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/nominations'), [...$form, 'candidate_id' => $nominator->id, 'nominator_id' => $candidate->id])->assertSessionHasErrors('nomination');
});

it('pauses and reopens nominations, extends their close only later, and deletes only a draft nobody stood in (M56 §3)', function () {
    $draft = $this->governance->createElection(2044, '2044 Election', null);
    $chair = $this->governance->addPosition($draft, 'Chairman', 1);

    // A draft: deleted with its name typed, and only so.
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$draft->id.'/delete'), ['confirm_name' => 'wrong'])->assertSessionHasErrors('election');
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$draft->id.'/delete'), ['confirm_name' => '2044 election'])->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(Ballot::query()->whereKey($draft->id)->exists())->toBeFalse();

    $ballot = $this->governance->createElection(2044, '2044 Election', null);
    $this->governance->addPosition($ballot, 'Chairman', 1);
    $this->governance->openNominations($ballot, SiteTime::now()->addWeeks(1));
    $close = $ballot->fresh()->nominations_close_at->copy();

    // Extend: later only.
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/extend-nominations'), ['closes_at' => SiteTime::local($close->copy()->subDay())->format('Y-m-d\TH:i')])->assertSessionHasErrors('election');
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/extend-nominations'), ['closes_at' => SiteTime::local($close->copy()->addDays(3))->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();

    // Pause (the control room's close), then reopen with a new close.
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/close-nominations'))->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect($ballot->fresh()->stage)->toBe(Ballot::NOMINATIONS_CLOSED);

    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/reopen-nominations'), ['closes_at' => SiteTime::local(SiteTime::now()->addDays(5))->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect($ballot->fresh()->stage)->toBe(Ballot::NOMINATIONS_OPEN);

    // Past its draft, it is cancelled, never deleted.
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/delete'), ['confirm_name' => '2044 Election'])->assertSessionHasErrors('election');
});

it('cancels an election with a reason and its name, keeps every ballot cast, and nothing moves it after (M56 §3.1.4)', function () {
    $ballot = $this->governance->createElection(2049, '2049 Election', null);
    $chair = $this->governance->addPosition($ballot, 'Chairman', 1);
    $this->governance->openNominations($ballot, SiteTime::now()->addWeeks(1));
    FacilitiesFixture::boot();
    $nomination = Nomination::query()->forceCreate(['ballot_id' => $ballot->id, 'ballot_position_id' => $chair->id, 'candidate_name' => 'Only Candidate', 'nominator_name' => 'P', 'seconder_name' => 'S', 'status' => Nomination::PENDING]);
    $this->governance->accept($nomination, $this->secretary);
    $this->governance->closeNominations($ballot->fresh());
    $this->governance->startVetting($ballot->fresh());
    $this->governance->open($ballot->fresh(), SiteTime::now()->addDays(7));

    FacilitiesFixture::boot();
    $option = BallotOption::query()->where('ballot_id', $ballot->id)->sole();
    $this->governance->castVote($ballot->fresh(), FacilitiesFixture::unit('Lot 47'), [$option->id]);

    // The Secretary runs the election and does not cancel it: the certifying officers do.
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/cancel'), ['reason' => 'Irregular roll.', 'confirm_name' => '2049 Election'])->assertForbidden();

    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $this->actingAs($president)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/cancel'), ['reason' => 'Irregular roll.', 'confirm_name' => 'not it'])->assertSessionHasErrors('election');
    $this->actingAs($president)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/cancel'), ['reason' => 'Irregular roll.', 'confirm_name' => '2049 Election'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $ballot = $ballot->fresh();
    expect([$ballot->stage, $ballot->cancel_reason, $ballot->cancelled_by])->toBe([Ballot::CANCELLED, 'Irregular roll.', $president->id])
        ->and(App\Models\Estate\BallotReceipt::query()->where('ballot_id', $ballot->id)->count())->toBe(1)
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'election.cancelled')->where('entity_id', (string) $ballot->id)->exists())->toBeTrue();

    // Terminal: no vote, no reopening, no second cancel.
    expect(fn () => $this->governance->castVote($ballot, FacilitiesFixture::unit('Lot 3'), [$option->id]))->toThrow(DomainException::class);
    $this->actingAs($president)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/reopen-voting'), ['closes_at' => SiteTime::local(SiteTime::now()->addDays(3))->format('Y-m-d\TH:i'), 'reason' => 'Try again.'])->assertSessionHasErrors('election');

    // The control room shows it as cancelled, with who, why and when.
    $this->actingAs($president)->get(FacilitiesFixture::url('/governance/elections/2049'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('life.stage', Ballot::CANCELLED)->where('life.cancelled.reason', 'Irregular roll.'));
});

it('reopens a closed poll with a reason before certification, and records a challenge only against a published result (M56 §3)', function () {
    $ballot = $this->governance->createElection(2042, '2042 Election', null);
    $chair = $this->governance->addPosition($ballot, 'Chairman', 1);
    $this->governance->openNominations($ballot, SiteTime::now()->addWeeks(1));
    FacilitiesFixture::boot();
    $nomination = Nomination::query()->forceCreate(['ballot_id' => $ballot->id, 'ballot_position_id' => $chair->id, 'candidate_name' => 'Only Candidate', 'nominator_name' => 'P', 'seconder_name' => 'S', 'status' => Nomination::PENDING]);
    $this->governance->accept($nomination, $this->secretary);
    $this->governance->closeNominations($ballot->fresh());
    $this->governance->startVetting($ballot->fresh());
    $this->governance->open($ballot->fresh(), SiteTime::now()->addDays(7));
    $this->governance->close($ballot->fresh());

    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $url = FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/reopen-voting');

    $this->actingAs($president)->post($url, ['closes_at' => SiteTime::local(SiteTime::now()->addDays(3))->format('Y-m-d\TH:i'), 'reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($president)->post($url, ['closes_at' => SiteTime::local(SiteTime::now()->addDays(3))->format('Y-m-d\TH:i'), 'reason' => 'The gate tablet was down all afternoon.'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect($ballot->fresh()->stage)->toBe(Ballot::VOTING_OPEN)
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'election.reopened')->where('entity_id', (string) $ballot->id)->exists())->toBeTrue();

    // No published result: nothing to challenge.
    $challenge = FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/challenge');
    $this->actingAs($president)->post($challenge, ['grounds' => 'The count left out the late ballots.'])->assertSessionHasErrors('election');

    $this->governance->close($ballot->fresh());
    $this->governance->certify($ballot->fresh(), $president);
    $this->governance->publish($ballot->fresh());

    $this->actingAs($president)->post($challenge, ['grounds' => 'The count left out the late ballots.'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect($this->governance->challengesOf($ballot->fresh()))->toHaveCount(1)
        ->and($this->governance->challengesOf($ballot->fresh())[0]['grounds'])->toBe('The count left out the late ballots.');
});

it('will not open a poll that has nobody on its paper', function () {
    $ballot = $this->governance->createElection(2048, '2048 Election', null);
    $this->governance->addPosition($ballot, 'Chairman', 1);
    $ballot->forceFill(['stage' => Ballot::CANDIDATE_VETTING])->save();

    $this->actingAs($this->secretary)
        ->post(FacilitiesFixture::url('/governance/ballots/'.$ballot->id.'/open'), ['closes_at' => SiteTime::now()->addDays(10)->format('Y-m-d\TH:i')])
        ->assertSessionHasErrors('closes_at');

    expect((string) session('errors')->first('closes_at'))->toContain('Nobody is on the paper')
        ->and($ballot->fresh()->stage)->toBe(Ballot::CANDIDATE_VETTING);
});
