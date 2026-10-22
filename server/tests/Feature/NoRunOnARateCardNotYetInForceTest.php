<?php

declare(strict_types=1);

use App\Models\Estate\PayrollRun;
use App\Models\EstateAssignment;
use App\Models\PayrollRun as GuardPayrollRun;
use App\Models\Role;
use App\Models\StatutoryRateVersion;
use App\Models\User;
use App\Services\Estate\Payroll;
use App\Services\Payroll\GuardPayrollApproval;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| No run is calculated or approved against a rate card that has not taken effect (Hassam, 12 Oct 2026; D-234)
|--------------------------------------------------------------------------
|
| "A run must not be calculable or approvable against a rate version whose effective-from date is in the future." The
| live read that prompted it: a demonstration guard run for 7 to 20 September 2026 was approved on the 2027/28 card.
| Nothing in either engine compared a card's date with today: the guard engine's approval asked only whether the card
| was verified, the estate engine's the same, and neither calculation asked at all. So the card here is VERIFIED, and
| only its date can refuse it. Each case then moves past that date to show the date was the reason.
|
| The test's today is fixed (12 Oct 2026), so the 2027/28 card is half a year away whatever day the suite runs.
|
*/

function futureCardEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('payfuturecard', 'PFC', 'Pay Future Card Test Estate');
}

/** May 2027 on this file's estate, at a status, on the 2027/28 card; calculated by `$by` when calculated. */
function futureCardRun(string $status, StatutoryRateVersion $card, ?User $by = null): PayrollRun
{
    $run = PayrollRun::query()->firstOrNew(['slug' => 'may-2027']);
    $run->forceFill([
        'reference' => 'PR-MAY2027',
        'period_label' => 'May 2027',
        'period_start' => '2027-05-01',
        'period_end' => '2027-05-31',
        'periods_per_year' => 12,
        'statutory_rate_version_id' => $card->id,
        'status' => $status,
        'gross_minor' => 0,
        'net_minor' => 0,
        'currency' => 'JMD',
        'calculated_by' => $by?->getKey(),
        'calculated_by_name' => $by?->name,
        'changes_requested_reason' => null,
    ])->save();

    return $run;
}

beforeEach(function (): void {
    futureCardEstate()->boot();
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);

    // This file's people only: whoever an earlier case put here no longer reaches the estate.
    EstateAssignment::query()->where('tenant_id', futureCardEstate()->key)->update(['is_active' => false]);

    $this->travelTo(Carbon::parse('2026-10-12 15:00', 'UTC'));
    $this->withoutVite();

    // Everything central from here is rolled back: the seeded 2027/28 card verified for this case only.
    DB::connection('mysql')->beginTransaction();

    $this->card = StatutoryRateVersion::query()->whereDate('effective_from', '2027-04-01')->whereNull('superseded_at')->firstOrFail();
    $this->card->forceFill(['is_verified' => true])->save();

    $this->reason = 'This rate card takes effect on Apr 1, 2027, which has not come yet, so no run can be calculated or approved against it.';
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

it('will not calculate an estate run against a card that takes effect later, and says so before the press', function () {
    $treasurer = futureCardEstate()->viewer('estate.treasurer');
    futureCardRun(PayrollRun::DRAFT, $this->card);

    $this->actingAs($treasurer)->get(futureCardEstate()->url('/payroll/runs/may-2027/exceptions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCalculate', false)->where('calculateReason', $this->reason));

    $this->actingAs($treasurer)->post(futureCardEstate()->url('/payroll/runs/may-2027/calculate'))
        ->assertSessionHasErrors(['run' => $this->reason]);

    $run = PayrollRun::query()->where('slug', 'may-2027')->firstOrFail();

    expect($run->status)->toBe(PayrollRun::DRAFT)
        ->and($run->lines()->count())->toBe(0)
        ->and($run->calculated_by)->toBeNull();

    // The date was the reason: once the card is in force the same run may be calculated.
    $this->travelTo(Carbon::parse('2027-06-02 15:00', 'UTC'));

    $this->actingAs($treasurer)->get(futureCardEstate()->url('/payroll/runs/may-2027/exceptions'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCalculate', true)->where('calculateReason', null));
});

it('will not approve an estate run calculated against a card that takes effect later', function () {
    $csa = futureCardEstate()->viewer('estate.community_super_admin');
    $treasurer = futureCardEstate()->viewer('estate.treasurer');

    // Calculated by the Treasurer, so the two-person rule leaves the Super Admin free: only the card can refuse.
    $run = futureCardRun(PayrollRun::CALCULATED, $this->card, $treasurer);

    expect(app(Payroll::class)->approvalRefusal($run, $csa))->toBe($this->reason)
        ->and(app(Payroll::class)->mayApprove($run, $csa))->toBeFalse();

    $this->actingAs($csa)->post(futureCardEstate()->url('/payroll/runs/may-2027/approve'), ['reconciled' => true])
        ->assertSessionHasErrors(['run' => $this->reason]);

    expect($run->refresh()->status)->toBe(PayrollRun::CALCULATED)
        ->and($run->journal_ref)->toBeNull();

    // The date was the reason.
    $this->travelTo(Carbon::parse('2027-06-02 15:00', 'UTC'));

    expect(app(Payroll::class)->approvalRefusal($run, $csa))->toBeNull();
});

it('will not approve a guard pay run on a card that takes effect later, as the demonstration run was', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    // The shape of DEMO-PR-20260920: a September 2026 fortnight on the 2027/28 card.
    $run = GuardPayrollRun::query()->create([
        'reference' => 'PR-TEST-FUTURE-CARD',
        'period_label' => '7 Sep to 20 Sep 2026',
        'period_start' => '2026-09-07',
        'period_end' => '2026-09-20',
        'periods_per_year' => 26,
        'statutory_rate_version_id' => $this->card->id,
        'status' => 'calculated',
        'gross_minor' => 84_000_00,
        'net_minor' => 80_000_00,
        'currency' => 'JMD',
    ]);

    expect(app(GuardPayrollApproval::class)->refusal($run, $director))->toBe($this->reason);

    $this->actingAs($director)
        ->from('/payroll/'.$run->id)
        ->post('/payroll/'.$run->id.'/approve', ['reconciled' => true])
        ->assertSessionHasErrors(['run' => $this->reason]);

    expect($run->fresh()->status)->toBe('calculated');

    // The date was the reason.
    $this->travelTo(Carbon::parse('2027-04-02 15:00', 'UTC'));

    expect(app(GuardPayrollApproval::class)->refusal($run->fresh(), $director))->toBeNull();
});
