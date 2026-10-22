<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Role;
use App\Services\Estate\Governance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An election's acts are in the audit log — and a vote is never its own entry (M21 §1.3)
|--------------------------------------------------------------------------
|
| Nothing about an election was audited: who opened the poll, who closed it, who certified it. Now each act is, with
| who did it; the rule for who may vote and the denominator when the poll opens; and, when it closes, WHO VOTED — the
| lots and the day each voted, from the receipts, with no choice and no time. A single vote is never written as it is
| cast, because an audit entry carries the moment it was written and the estate keeps only the day.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED — and a cast vote is never removed (a trigger refuses it: thinning the
| record de-anonymises what is left), so the election stays, in a year of its own chosen for each run, as
| BallotUnlinkabilityTest's does.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->governance = app(Governance::class);
    $this->secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $this->unit = FacilitiesFixture::scratchUnit('Lot 985');
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    // This file's own lot's household and its resident, made for the audited poll (the estate database is shared and committed).
    $households = DB::connection('tenant')->table('households')->where('unit_id', $this->unit->id)->pluck('id');
    DB::connection('tenant')->table('residents')->whereIn('household_id', $households)->delete();
    DB::connection('tenant')->table('households')->whereIn('id', $households)->delete();
});

it('never opens a poll that would shut within half an hour, and the console starts its closing time a week out (M53 §4.11)', function () {
    FacilitiesFixture::boot();
    $year = random_int(2200, 9000);
    while (Ballot::query()->where('year', $year)->exists()) {
        $year++;
    }
    $ballot = $this->governance->createElection($year, 'Closing Time Test Election', null);
    $position = $this->governance->addPosition($ballot, 'Chairman', 1);
    BallotOption::query()->forceCreate(['ballot_id' => $ballot->id, 'ballot_position_id' => $position->id, 'label' => 'Candidate A', 'sort_order' => 1]);
    $ballot->forceFill(['stage' => Ballot::NOMINATIONS_CLOSED])->save();
    $this->governance->startVetting($ballot->refresh());

    foreach ([now(), now()->addMinutes(5), now()->subDay()] as $closes) {
        expect(fn () => $this->governance->open($ballot->refresh(), $closes))
            ->toThrow(DomainException::class, 'Set a closing time at least half an hour from now.');
    }

    expect($ballot->refresh()->stage)->not->toBe(Ballot::VOTING_OPEN);

    // The box starts a week from today at 6:00 PM on the estate's clock.
    $page = $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/elections/'.$year));
    FacilitiesFixture::boot();

    expect($page->viewData('page')['props']['defaultClosesAt'])
        ->toBe(SiteTime::now()->addDays(7)->setTime(18, 0)->format('Y-m-d\TH:i'));
});

it('records who opened, closed, certified and published the poll, and who voted — never how, never when in the day', function () {
    FacilitiesFixture::boot();
    // A year no other run has used: an estate has one election a year, and this one's votes are permanent.
    $year = random_int(2200, 9000);
    while (Ballot::query()->where('year', $year)->exists()) {
        $year++;
    }
    // A household lives on the lot that votes: who may vote is the households that exist (Hassam, 9 October 2026), each
    // with its resident (M87 §1.4: a household with nobody in it is not counted).
    $household = DB::connection('tenant')->table('households')->insertGetId(['unit_id' => $this->unit->id, 'name' => 'Lot 985 household', 'access_restricted' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::connection('tenant')->table('residents')->insert(['household_id' => $household, 'full_name' => 'Resident of Lot 985', 'relationship' => 'owner', 'is_primary' => true, 'status' => 'verified', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $ballot = $this->governance->createElection($year, 'Audited Test Election', null);
    $position = $this->governance->addPosition($ballot, 'Chairman', 1);
    // A settled paper, as a seeded or resolution ballot has: the poll opens from it.
    $option = BallotOption::query()->forceCreate(['ballot_id' => $ballot->id, 'ballot_position_id' => $position->id, 'label' => 'Candidate A', 'sort_order' => 1]);
    $ballot->forceFill(['stage' => Ballot::NOMINATIONS_CLOSED])->save();
    $this->governance->startVetting($ballot->refresh());

    $this->governance->open($ballot->refresh(), now()->addHour());
    $this->governance->castVote($ballot->refresh(), $this->unit, [$option->id]);
    $this->governance->close($ballot->refresh());
    $this->governance->certify($ballot->refresh(), $this->secretary);
    $this->governance->publish($ballot->refresh());

    $entries = AuditEntry::query()->where('entity_type', 'Ballot')->where('entity_id', (string) $ballot->id)->orderBy('id')->get();
    expect($entries->pluck('action')->all())->toBe(['election.opened', 'election.closed', 'election.certified', 'election.results_published']);

    $opened = $entries->firstWhere('action', 'election.opened');
    expect($opened->after['eligible_households'])->toBeGreaterThan(0)
        ->and($opened->after['rule'])->toContain('arrears')
        ->and($opened->after['positions'])->toBe(['Chairman']);

    $closed = $entries->firstWhere('action', 'election.closed');
    expect($closed->after['households_voted'])->toBe(1)
        ->and($closed->after['voted'])->toBe([['lot' => 'Lot 985', 'voted_on' => $closed->after['voted'][0]['voted_on']]])
        ->and($closed->after['voted'][0]['voted_on'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');

    // What is said about the voters names no choice: no option, no candidate, no mark. (The certified OUTCOME names
    // the winner — that is the declared result, published to every household, not anybody's paper.)
    $aboutVoters = json_encode($entries->whereIn('action', ['election.opened', 'election.closed'])->map(fn (AuditEntry $e): array => [$e->before, $e->after])->values()->all());
    expect($aboutVoters)->not->toContain('Candidate A')
        ->and($aboutVoters)->not->toContain('mark')
        ->and($aboutVoters)->not->toContain('"option');
    expect($entries->firstWhere('action', 'election.certified')->after['certified_by'])->toBe($this->secretary->name);
    expect(AuditEntry::query()->where('action', 'like', '%vote%')->where('entity_id', (string) $ballot->id)->count())->toBe(0);
});
