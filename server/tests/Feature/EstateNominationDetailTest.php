<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Nomination;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| One nomination and the record behind its decision — board 10's "View"
| (12 §2, Wave 4)
|--------------------------------------------------------------------------
|
| THE SNAPSHOT IS WHAT WAS DECIDED AGAINST, and it is shown as it was stored.
| THE ARREARS FIGURE IS A LEDGER FACT: the Secretary runs the election with
| Governance Full and holds no ledger access, so they see the ageing bucket
| board 10 already prints and not the balance.
|
*/

const NOMINATION_TEST_YEAR = 2091;

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();
    Ballot::query()->where('year', NOMINATION_TEST_YEAR)->delete();
});

it('shows a decision with its snapshot, and the balance only to a role that reads the ledger', function () {
    $ballot = Ballot::query()->forceCreate([
        'year' => NOMINATION_TEST_YEAR,
        'code' => 'Z',
        'title' => 'Ballot Z (Test Executive)',
        'stage' => 'nominations',
    ]);

    $position = BallotPosition::query()->forceCreate([
        'ballot_id' => $ballot->id,
        'name' => 'Chairman',
        'seat_count' => 1,
        'sort_order' => 1,
    ]);

    $nomination = Nomination::query()->forceCreate([
        'ballot_id' => $ballot->id,
        'ballot_position_id' => $position->id,
        'unit_id' => FacilitiesFixture::unit('Lot 63')->id,
        'candidate_name' => 'Keith Walters',
        'nominator_name' => 'Ricardo Hall',
        'seconder_name' => 'Tanya Simms',
        'status' => Nomination::REJECTED,
        'decision_reason' => 'arrears >90 days',
        'decided_at' => now()->subDays(3),
        'decided_by_name' => 'Delroy Samuels',
        'eligibility_checked_on' => now()->subDays(3)->toDateString(),
        'arrears_bucket_at_check' => 'd90',
        'arrears_minor_at_check' => 48_500_00,
        'tenure_months_at_check' => null,
    ]);

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $propertyManager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/governance/nominations/'.$nomination->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Governance/Nomination')
            ->where('nomination.name', 'Keith Walters')
            ->where('nomination.position', $position->fresh()->displayName())
            ->where('nomination.reason', 'arrears >90 days')
            ->where('nomination.decided_by', 'Delroy Samuels')
            ->where('nomination.snapshot.arrears_bucket', '90 days or more overdue')
            ->where('nomination.snapshot.arrears', '$48,500.00')
            ->where('nomination.amounts_hidden', false)
            ->where('nomination.year', NOMINATION_TEST_YEAR));

    // The Secretary runs the election and does not read the ledger.
    $this->actingAs($secretary)
        ->get(FacilitiesFixture::url('/governance/nominations/'.$nomination->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('nomination.snapshot.arrears_bucket', '90 days or more overdue')
            ->where('nomination.snapshot.arrears', null)
            ->where('nomination.amounts_hidden', true))
        ->assertDontSee('48,500');

    // Governance is not part of the Property Manager's access at all.
    $this->actingAs($propertyManager)
        ->get(FacilitiesFixture::url('/governance/nominations/'.$nomination->id))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/governance/nominations/999999'))
        ->assertNotFound();
});
