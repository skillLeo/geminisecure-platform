<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Services\Estate\Governance;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HouseholdHolder;

/*
|--------------------------------------------------------------------------
| Turnout by phase divides by that phase's households (M85 A9)
|--------------------------------------------------------------------------
|
| The definitions adopted in M73 and M81: "turnout by phase, that phase's households · 1,999 and 2,000 are never divided
| by". The election's own count already followed them (`eligibleHouseholdCount()`); each phase's row divided by every
| unit in the phase, vacant lots too, so the phases' percentages could never add up to the election's. Each phase now
| counts by the election's rule: the households on its roll where it has one, every lot where the estate votes by
| lot, otherwise the households living on the phase's lots, counted as the estate counts them (M87 §1.4,
| `Household::counted()`: a household with a resident in it).
|
| One phase of this file's own, inside a transaction on the shared estate that is rolled back.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    DB::connection('tenant')->beginTransaction();

    // Five lots: two lived in, one whose household was removed, one vacant and empty, one whose household has nobody in it.
    $lot = static fn (string $n, string $status): Unit => Unit::query()->create([
        'reference' => 'M85 Turnout Lot '.$n, 'block' => 'M85 Turnout Phase', 'street' => 'M85 Turnout Drive',
        'type' => 'residential', 'status' => $status,
    ]);
    $household = static fn (Unit $unit, string $name): Household => Household::query()->create(['unit_id' => $unit->id, 'name' => $name, 'access_restricted' => false]);

    $this->households = [$household($lot('1', 'occupied'), 'M85 Turnout One'), $household($lot('2', 'occupied'), 'M85 Turnout Two')];

    foreach ($this->households as $i => $lived) {
        HouseholdHolder::of($lived, 'M85 Turnout Resident '.($i + 1));
    }

    $gone = $household($lot('3', 'occupied'), 'M85 Turnout Three');
    HouseholdHolder::of($gone, 'M85 Turnout Resident 3');
    DB::connection('tenant')->table('households')->where('id', $gone->id)->update(['removed_at' => now()]);

    $lot('4', 'vacant');

    $household($lot('5', 'occupied'), 'M85 Turnout Five');
});

afterEach(function (): void {
    DB::connection('tenant')->rollBack();
});

/** This file's phase as the results board and the turnout report read it. @return array<string, mixed> */
function m85TurnoutRow(Ballot $ballot): array
{
    return collect(app(Governance::class)->turnoutByPhase($ballot))->firstWhere('phase', 'M85 Turnout Phase');
}

/** A ballot with no receipts: only the eligible side is under test. */
function m85EmptyBallot(?array $roll = null): Ballot
{
    $ballot = new Ballot;
    $ballot->id = 0;
    $ballot->roll = $roll;

    return $ballot;
}

it('divides a phase\'s turnout by the households living on its lots, not by its units, and not by a household nobody lives in', function (): void {
    expect(m85TurnoutRow(m85EmptyBallot())['eligible'])->toBe(2);
});

it('counts the households on the roll where the ballot has one, and every lot where the estate votes by lot', function (): void {
    expect(m85TurnoutRow(m85EmptyBallot([$this->households[0]->id]))['eligible'])->toBe(1);

    EstateSetting::current()->forceFill(['voting_eligibility' => EstateSetting::VOTES_BY_LOTS])->save();

    expect(m85TurnoutRow(m85EmptyBallot())['eligible'])->toBe(5);
});
