<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Meeting;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\DashboardTiles;
use App\Services\Estate\Governance;
use App\Services\Estate\PhasesFromLots;
use App\Services\Estate\Residents;
use App\Services\Estate\Settings;
use App\Services\Push\PanicFanOut;
use App\Services\Push\PushAnnouncer;
use App\Services\Push\PushNotifier;
use App\Support\SiteTime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| THE ESTATE'S LOTS, NOT ITS REGISTER (M60 §C2: 1,999, not 2,000)
|--------------------------------------------------------------------------
|
| Phoenix Park's register holds 2,000 units: the site plan's 1,999 lots and "Lot 1999A", the 2,000th home the seed made
| before the plan was read, which the plan does not have and which stands under no phase. The dashboard's Total units,
| the Settings page's Total units, the residents' structure totals, an election's eligible households, a meeting's
| quorum and "This tells N households" counted the register and said 2,000. They count the lots now. A committee's own
| meeting counts the committee. On an estate of this file's own: six lots in two phases worked out from the lot
| number, and one unit off the plan.
|
*/

function lotsEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('lotcountstest', 'LCT', 'Lot Counts Test Estate');
}

/** Six lots in two phases worked out from the lot number, and one unit the plan does not have; once a process. */
function lotsEstateBuilt(): void
{
    static $built = false;

    lotsEstate()->boot();

    if ($built) {
        return;
    }

    $now = now();

    foreach ([['Phase 1', 1, 1, 3], ['Phase 2', 2, 4, 6]] as [$name, $sequence, $first, $last]) {
        DB::connection('tenant')->table('estate_phases')->insert([
            'name' => $name, 'sequence' => $sequence, 'block_count' => 1, 'officers_assigned' => null, 'status' => 'active',
            'first_lot' => $first, 'last_lot' => $last, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    foreach (['Lot 1' => 'occupied', 'Lot 2' => 'occupied', 'Lot 3' => 'vacant', 'Lot 4' => 'occupied', 'Lot 5' => 'vacant', 'Lot 6' => 'vacant', 'Lot 1999A' => 'vacant'] as $reference => $status) {
        $unitId = DB::connection('tenant')->table('units')->insertGetId([
            'reference' => $reference, 'block' => null, 'street' => 'Phoenix Drive', 'type' => 'residential', 'status' => $status,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // A household on each occupied lot, and one on the unit the plan does not have: three households on the lots. Each
        // has its resident, as the estate counts a household (M87 §1.4: one with nobody in it is not counted).
        if ($status === 'occupied' || $reference === 'Lot 1999A') {
            $householdId = DB::connection('tenant')->table('households')->insertGetId(['unit_id' => $unitId, 'name' => $reference.' household', 'access_restricted' => false, 'created_at' => $now, 'updated_at' => $now]);
            DB::connection('tenant')->table('residents')->insert([
                'household_id' => $householdId, 'full_name' => 'Resident of '.$reference, 'relationship' => 'owner', 'is_primary' => true,
                'status' => 'verified', 'verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    // The phase worked out from the lot number, as `estate:structure-from-plan` leaves Phoenix Park (M57.5 §4.2).
    $database = lotsEstate()->database();
    tenancy()->end();
    PhasesFromLots::derive($database, [['name' => 'Phase 1', 'first' => 1, 'last' => 3], ['name' => 'Phase 2', 'first' => 4, 'last' => 6]]);
    lotsEstate()->boot();
    Unit::forgetPhaseDerivation();

    $built = true;
}

/**
 * The first array anywhere in a board holding `$key` => `$value`.
 *
 * @param  array<mixed>  $board
 * @return array<mixed>|null
 */
function lotsFind(array $board, string $key, mixed $value): ?array
{
    if (($board[$key] ?? null) === $value) {
        return $board;
    }

    foreach ($board as $child) {
        if (is_array($child) && ($found = lotsFind($child, $key, $value)) !== null) {
            return $found;
        }
    }

    return null;
}

beforeEach(function () {
    lotsEstateBuilt();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    lotsEstate()->boot();
    // The default again, whatever a test set (the estate database is committed).
    DB::connection('tenant')->table('estate_settings')->update(['voting_eligibility' => EstateSetting::VOTES_BY_HOUSEHOLDS]);
});

it('counts the six lots on the dashboard, the Settings page and the structure totals, not the seven units', function () {
    expect(PhasesFromLots::derived('tenant'))->toBeTrue()
        ->and(Unit::query()->count())->toBe(7)
        ->and(Unit::lots()->count())->toBe(6)
        ->and(Unit::query()->where('reference', 'Lot 1999A')->value('block'))->toBeNull();

    $tiles = app(DashboardTiles::class);
    $tiles->forget();

    expect($tiles->structure())->toMatchArray(['units' => 6, 'occupied' => 3])
        ->and(app(Residents::class)->structureBoard()['totals'])->toMatchArray(['units' => 6, 'occupied' => 3])
        ->and(lotsFind(app(Settings::class)->profileBoard(), 'key', 'total_units')['value'] ?? null)->toBe('6')
        // The estate's own phases, as Estate structure draws them, not the client record's figure (M69 SET-1: 0 on live).
        ->and(lotsFind(app(Settings::class)->profileBoard(), 'key', 'phase_count')['value'] ?? null)->toBe('2');
});

it('counts the households living on the lots for an election and a general meeting, and the committee for its own meeting', function () {
    $president = lotsEstate()->viewer(Role::PRESIDENT);
    lotsEstate()->viewer(Role::SECRETARY);
    lotsEstate()->viewer(Role::TREASURER);

    // Inside the estate's tenancy, as a request on its own address runs it: the committee is this estate's.
    lotsEstate()->tenant()->run(static fn () => lotsGovernanceCounts($president));
});

function lotsGovernanceCounts(User $president): void
{
    $governance = app(Governance::class);

    $meeting = static fn (string $type, string $audience): Meeting => $governance->scheduleMeeting([
        'type' => $type, 'title' => 'Meeting of the '.$type, 'starts_at' => SiteTime::today()->addDays(40)->setTime(18, 0),
        'held_as' => Meeting::IN_PERSON, 'venue' => 'Community Centre', 'audience_scope' => $audience,
    ], [], $president);

    // Who may vote, by default (Hassam, 9 October 2026): the three households living on the lots, not the six lots, and
    // never the household on the unit the plan does not have. The form's readout says the same.
    expect($governance->schedulerDefaults())->toMatchArray(['eligibleHouseholds' => 3, 'committeeMembers' => 3]);

    // A general meeting: a proportion of those households, and it tells the three of them.
    $agm = $meeting(Meeting::AGM, Meeting::WHOLE_ESTATE);
    expect($agm->quorum_basis)->toBe(Meeting::HOUSEHOLDS)
        ->and($agm->eligible_households)->toBe(3)
        ->and($governance->meetingDetail($agm->id)['households_told'])->toBe(3);

    // A committee's own meeting: a head count of its three members, not 25% of the estate (the M58 report's S3).
    $committee = $meeting(Meeting::COMMITTEE, Meeting::COMMITTEE_ONLY);
    expect($committee->quorum_basis)->toBe(Meeting::MEMBERS)
        ->and($committee->quorum_required_total)->toBe(3)
        ->and($governance->quorumOf($committee->fresh())['label'])->toBe('Counted at the meeting');

    // Nobody has been told of a draft, so its quorum follows its type as edited, both ways.
    $general = $governance->editDraft($committee->fresh(), ['type' => Meeting::EGM], null, $president);
    expect($general->quorum_basis)->toBe(Meeting::HOUSEHOLDS)
        ->and($general->quorum_required_total)->toBeNull()
        ->and($general->eligible_households)->toBe(3);

    $back = $governance->editDraft($general->fresh(), ['type' => Meeting::COMMITTEE], null, $president);
    expect($back->quorum_basis)->toBe(Meeting::MEMBERS)->and($back->quorum_required_total)->toBe(3);

    // An election's eligible households, as its control room says them before voting opens.
    if (! Ballot::query()->where('year', 2031)->exists()) {
        $governance->createElection(2031, '2031 Election', null);
    }
    expect(lotsFind($governance->controlRoom(2031), 'label', 'ELIGIBLE HOUSEHOLDS')['value'] ?? null)->toBe('3');
}

it('takes a phase meeting\'s quorum from that phase\'s households, from its draft on, never the whole estate\'s (M69; Hassam, 10 October)', function () {
    $president = lotsEstate()->viewer(Role::PRESIDENT);

    lotsEstate()->tenant()->run(static function () use ($president): void {
        $governance = app(Governance::class);
        $meeting = $governance->scheduleMeeting([
            'type' => Meeting::PHASE, 'title' => 'Phase 2 residents\' meeting', 'starts_at' => SiteTime::today()->addDays(40)->setTime(18, 0),
            'held_as' => Meeting::IN_PERSON, 'venue' => 'Community Centre', 'audience_scope' => Meeting::PHASE_SUBSET, 'phase' => 'Phase 2',
        ], [], $president);

        // Phase 2 has one household on its lots (Lot 4); the estate has three. A Phase 2 resident is never bound by a
        // threshold that counts another phase: its quorum counts Phase 2's households, and so does what it tells.
        expect($meeting->quorum_basis)->toBe(Meeting::HOUSEHOLDS)
            ->and($meeting->eligible_households)->toBe(1)
            ->and($governance->meetingDetail($meeting->id)['households_told'])->toBe(1);

        // The same base when the draft is edited, so it cannot change between draft and scheduled.
        $edited = $governance->editDraft($meeting->fresh(), ['title' => 'Phase 2 residents\' meeting, moved'], null, $president);

        expect($edited->eligible_households)->toBe(1);
    });
});

it('gives every reader of an election one count of its households: the rule before voting opens, the frozen number after (M62 A9, B1)', function () {
    lotsEstate()->tenant()->run(static function (): void {
        $governance = app(Governance::class);
        $ballot = Ballot::query()->where('year', 2032)->first() ?? $governance->createElection(2032, '2032 Election', null);
        $ballot->forceFill(['stage' => Ballot::DRAFT, 'eligible_households' => 0])->save();

        // Before voting opens: the estate's rule as it stands, the three households living on the lots, and the control
        // room's tile prints the same number.
        expect($governance->eligibleHouseholdCount($ballot->fresh()))->toBe(3)
            ->and(lotsFind($governance->controlRoom(2032), 'label', 'ELIGIBLE HOUSEHOLDS')['value'] ?? null)->toBe('3');

        // The rule moves, and the count with it, until the poll opens.
        DB::connection('tenant')->table('estate_settings')->update(['voting_eligibility' => EstateSetting::VOTES_BY_LOTS]);
        expect($governance->eligibleHouseholdCount($ballot->fresh()))->toBe(6);

        // Once voting has opened: the denominator frozen on the ballot, whatever the rule says after it.
        $ballot->forceFill(['stage' => Ballot::VOTING_OPEN, 'eligible_households' => 6])->save();
        DB::connection('tenant')->table('estate_settings')->update(['voting_eligibility' => EstateSetting::VOTES_BY_HOUSEHOLDS]);
        expect($governance->eligibleHouseholdCount($ballot->fresh()))->toBe(6)
            ->and(lotsFind($governance->controlRoom(2032), 'label', 'ELIGIBLE HOUSEHOLDS')['value'] ?? null)->toBe('6');
    });
});

it('tells only the households on a ballot\'s roll that voting is open and the results are out, and every household when it has no roll (M62 A9)', function () {
    $sent = [];
    $notifier = Mockery::mock(PushNotifier::class);
    $notifier->shouldReceive('toEstateResidents')->andReturnUsing(function ($tenant, $message, $units = null) use (&$sent): int {
        $sent[] = $units;

        return 0;
    });
    $announcer = new PushAnnouncer($notifier, app(PanicFanOut::class));

    lotsEstate()->tenant()->run(static function () use ($announcer, &$sent): void {
        $governance = app(Governance::class);
        $roll = Household::query()->orderBy('id')->limit(2)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $units = Household::query()->whereIn('id', $roll)->orderBy('unit_id')->pluck('unit_id')->map(static fn ($id): int => (int) $id)->all();

        $motion = $governance->createCommitteeBallot(2033, 'Committee Ballot · Push '.bin2hex(random_bytes(2)), 'Approve the budget?', array_fill_keys($roll, 'on the roll'));
        Ballot::withoutEvents(static fn () => $motion->forceFill(['stage' => Ballot::VOTING_OPEN])->save());

        $announcer->election($motion->fresh(), 'opened', 'lotcountstest');
        $announcer->electionResults(tap($motion->fresh())->forceFill(['published_at' => now()]), 'lotcountstest');

        // A ballot without a roll is told to every household, as it always was.
        $election = Ballot::query()->where('year', 2034)->first() ?? $governance->createElection(2034, '2034 Election', null);
        Ballot::withoutEvents(static fn () => $election->forceFill(['stage' => Ballot::VOTING_OPEN])->save());
        $announcer->election($election->fresh(), 'opened', 'lotcountstest');

        expect(count($sent))->toBe(3)
            ->and(collect($sent[0])->sort()->values()->all())->toBe($units)
            ->and(collect($sent[1])->sort()->values()->all())->toBe($units)
            ->and($sent[2])->toBeNull();
    });
});

it('counts every lot only when the estate says so, by the command, with a reason on the audit log', function () {
    // The dry run says both counts and writes nothing.
    expect(Artisan::call('estate:voting-eligibility', ['--estate' => 'lotcountstest', '--by' => 'lots']))->toBe(0);
    $said = Artisan::output();
    lotsEstate()->boot();

    expect($said)->toContain('the households that exist: 3 eligible; a 25% quorum is 1.')
        ->and($said)->toContain('every lot, vacant ones included: 6 eligible; a 25% quorum is 2.')
        ->and($said)->toContain('Nothing is written without --confirm')
        ->and(EstateSetting::current()->voting_eligibility)->toBe(EstateSetting::VOTES_BY_HOUSEHOLDS);

    // A reason is required, and the change is audited.
    expect(Artisan::call('estate:voting-eligibility', ['--estate' => 'lotcountstest', '--by' => 'lots', '--confirm' => true]))->not->toBe(0);
    expect(Artisan::call('estate:voting-eligibility', ['--estate' => 'lotcountstest', '--by' => 'lots', '--reason' => 'The client says an absent owner votes (CONFIRM-01).', '--confirm' => true]))->toBe(0);
    lotsEstate()->boot();

    expect(EstateSetting::current()->voting_eligibility)->toBe(EstateSetting::VOTES_BY_LOTS)
        ->and(DB::connection('mysql')->table('audit_log')->where('tenant_id', 'lotcountstest')->where('action', 'estate.voting_eligibility_changed')->exists())->toBeTrue();

    lotsEstate()->tenant()->run(static fn () => expect(app(Governance::class)->schedulerDefaults()['eligibleHouseholds'])->toBe(6));
});

it('shows a committee motion\'s tally on its card once the poll has closed, and nothing while it is open (M73 §1, step 7)', function () {
    lotsEstate()->tenant()->run(static function (): void {
        $governance = app(Governance::class);
        $roll = Household::query()->orderBy('id')->limit(2)->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // The year's control room needs an election of its own; the motion is listed beside it, never folded in.
        Ballot::query()->where('year', 2036)->where('kind', Ballot::ELECTION)->first() ?? $governance->createElection(2036, '2036 Election', null);
        $motion = $governance->createCommitteeBallot(2036, 'Committee Ballot · Tally '.bin2hex(random_bytes(2)), 'Approve the budget?', array_fill_keys($roll, 'on the roll'));
        $card = static fn (): array => collect($governance->controlRoom(2036)['committee_ballots'])->firstWhere('id', $motion->id);

        $governance->open($motion, now()->addDay());
        expect($card()['tally'])->toBeNull();

        $for = $motion->options()->orderBy('sort_order')->first();
        $governance->castVote($motion->fresh(), Unit::query()->findOrFail(Household::query()->findOrFail($roll[0])->unit_id), [(int) $for->id]);

        // A count while the poll is open would be a running score: still nothing.
        expect($card()['tally'])->toBeNull();

        $governance->close($motion->fresh());

        expect($card()['tally'])->toBe([
            ['label' => 'For', 'votes' => 1],
            ['label' => 'Against', 'votes' => 0],
            ['label' => 'Abstain', 'votes' => 0],
        ]);
    });
});
