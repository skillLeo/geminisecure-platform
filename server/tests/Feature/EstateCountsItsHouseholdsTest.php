<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Meeting;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\DashboardTiles;
use App\Services\Estate\Governance;
use App\Services\Estate\PhasesFromLots;
use App\Services\Estate\Residents;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| ONE COUNT OF THE ESTATE'S HOUSEHOLDS, EVERYWHERE (M87 §1.4; Sunday step 3)
|--------------------------------------------------------------------------
|
| Hassam, 11 October: "441 is the estate's number and it always has been." The agreed definition: households with a
| resident, not on Lot 1999A (a unit the site plan does not have), and never a unit flagged occupied with no household
| behind it. Live read three numbers for it: the dashboard 443 (units flagged occupied), the register 444 (every
| household), the AGM 441 (its own snapshot); a meeting made today counted every household on a lot, resident or not.
| Every reader now asks one rule: the dashboard's occupied tile, the register's Households tab, the structure page's
| occupancy, and a new meeting's or ballot's eligible households (a phase meeting, its phase's). The count changes, the
| data does not: nothing here deletes a unit, a household or a resident.
|
| On an estate of this file's own: six lots in two phases worked out from the lot number, and one unit off the plan.
|
*/

function householdsEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('householdcountstest', 'HCT', 'Household Counts Test Estate');
}

/**
 * Lot by lot, what counts and why:
 *   Lot 1  flagged occupied, a household with a resident                         counts (Phase 1)
 *   Lot 2  flagged occupied, a household with nobody in it                       does not
 *   Lot 3  flagged occupied, no household behind the flag                        does not
 *   Lot 4  flagged vacant, a household with a resident (the flag is wrong)       counts (Phase 2)
 *   Lot 5  a removed household, its resident still on the row                    does not
 *   Lot 6  vacant, nothing                                                       does not
 *   Lot 1999A  off the site plan, a household with a resident                    does not
 */
function householdsEstateBuilt(): void
{
    static $built = false;

    householdsEstate()->boot();

    if ($built) {
        return;
    }

    $db = DB::connection('tenant');
    $now = now();

    foreach ([['Phase 1', 1, 1, 3], ['Phase 2', 2, 4, 6]] as [$name, $sequence, $first, $last]) {
        $db->table('estate_phases')->insert([
            'name' => $name, 'sequence' => $sequence, 'block_count' => 1, 'officers_assigned' => null, 'status' => 'active',
            'first_lot' => $first, 'last_lot' => $last, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $lots = [
        'Lot 1' => ['occupied', 'resident'],
        'Lot 2' => ['occupied', 'empty'],
        'Lot 3' => ['occupied', null],
        'Lot 4' => ['vacant', 'resident'],
        'Lot 5' => ['vacant', 'removed'],
        'Lot 6' => ['vacant', null],
        'Lot 1999A' => ['vacant', 'resident'],
    ];

    foreach ($lots as $reference => [$status, $household]) {
        $unitId = $db->table('units')->insertGetId([
            'reference' => $reference, 'block' => null, 'street' => 'Phoenix Drive', 'type' => 'residential', 'status' => $status,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($household === null) {
            continue;
        }

        $householdId = $db->table('households')->insertGetId([
            'unit_id' => $unitId, 'name' => $reference.' household', 'access_restricted' => false,
            'removed_at' => $household === 'removed' ? $now : null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($household !== 'empty') {
            $db->table('residents')->insert([
                'household_id' => $householdId, 'full_name' => 'Resident of '.$reference, 'relationship' => 'owner', 'is_primary' => true,
                'status' => 'verified', 'verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    // The phase worked out from the lot number, as `estate:structure-from-plan` leaves Phoenix Park (M57.5 §4.2).
    $database = householdsEstate()->database();
    tenancy()->end();
    PhasesFromLots::derive($database, [['name' => 'Phase 1', 'first' => 1, 'last' => 3], ['name' => 'Phase 2', 'first' => 4, 'last' => 6]]);
    householdsEstate()->boot();
    Unit::forgetPhaseDerivation();

    $built = true;
}

beforeEach(function () {
    householdsEstateBuilt();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    householdsEstate()->boot();
});

it('counts two households: a resident in each, on the lots, flags and removed rows aside', function () {
    expect(PhasesFromLots::derived('tenant'))->toBeTrue()
        ->and(Household::query()->count())->toBe(4)
        ->and(Household::query()->counted()->count())->toBe(2)
        ->and(Household::query()->counted()->with('unit')->get()->map(static fn (Household $h): string => (string) $h->unit->reference)->sort()->values()->all())->toBe(['Lot 1', 'Lot 4']);
});

it('says the same number on the dashboard, the structure page and the register', function () {
    $tiles = app(DashboardTiles::class);
    $tiles->forget();

    $structure = app(Residents::class)->structureBoard();
    $register = app(Residents::class)->listBoard(false);

    expect($tiles->structure()['occupied'])->toBe(2)
        ->and($structure['totals']['occupied'])->toBe(2)
        ->and(collect($structure['phases'])->pluck('occupied', 'name')->all())->toBe(['Phase 1' => 1, 'Phase 2' => 1])
        ->and($register['total'])->toBe(2)
        ->and(collect($register['rows'])->pluck('unit')->map(static fn (string $u): string => (string) preg_replace('/^.*· /', '', $u))->sort()->values()->all())->toBe(['Lot 1', 'Lot 4']);
});

it('moves the dashboard\'s number when a household is added, without waiting for its cache', function () {
    $tiles = app(DashboardTiles::class);
    $tiles->forget();
    expect($tiles->structure()['occupied'])->toBe(2);

    // Lot 2's empty household gains a resident: it is a household with a resident now.
    $lot2 = (int) Household::query()->whereHas('unit', static fn ($u) => $u->where('reference', 'Lot 2'))->value('id');
    $id = DB::connection('tenant')->table('residents')->insertGetId([
        'household_id' => $lot2, 'full_name' => 'Joined Lot 2', 'relationship' => 'owner', 'is_primary' => true,
        'status' => 'verified', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        expect(app(DashboardTiles::class)->structure()['occupied'])->toBe(3);
    } finally {
        DB::connection('tenant')->table('residents')->where('id', $id)->delete();
    }
});

it('gives a meeting made today the same count, and a phase meeting its own phase\'s', function () {
    $president = householdsEstate()->viewer(Role::PRESIDENT);

    householdsEstate()->tenant()->run(static fn () => householdsMeetings($president));
});

function householdsMeetings(User $president): void
{
    $governance = app(Governance::class);
    $meeting = static fn (array $over): Meeting => $governance->scheduleMeeting([
        'title' => 'A meeting', 'starts_at' => SiteTime::today()->addDays(40)->setTime(18, 0),
        'held_as' => Meeting::IN_PERSON, 'venue' => 'Community Centre', ...$over,
    ], [], $president);

    expect($governance->schedulerDefaults()['eligibleHouseholds'])->toBe(2);

    $agm = $meeting(['type' => Meeting::AGM, 'audience_scope' => Meeting::WHOLE_ESTATE]);
    expect($agm->eligible_households)->toBe(2)
        ->and($governance->meetingDetail($agm->id)['households_told'])->toBe(2);

    $phase1 = $meeting(['type' => Meeting::PHASE, 'audience_scope' => Meeting::PHASE_SUBSET, 'phase' => 'Phase 1']);
    expect($phase1->eligible_households)->toBe(1);
}
