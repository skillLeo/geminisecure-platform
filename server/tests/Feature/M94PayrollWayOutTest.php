<?php

declare(strict_types=1);

use App\Models\Estate\PayrollRun;
use App\Models\EstateAssignment;
use App\Models\StatutoryRateVersion;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| M94 §1.1 and M96: the pay run names the way out, and each control is at its level
|--------------------------------------------------------------------------
|
| Phoenix Park's September, on an estate of this file's own: the Treasurer started the run, the only Community Super
| Admin calculated it, and nobody else holds Approve pay runs, so the two-person rule left nobody to approve it and
| October could not start behind it. Now the Super Admin sees Approve greyed with the way out beside it (level 2); the
| President, who can never approve, sees no Approve and no Request changes and reads what happens next (level 3); the
| run list says on the screen why October cannot start yet; the calculate step warns the Super Admin before the press;
| and a role without Payroll update has no Calculate button.
|
*/

function payWayOutEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('paywayouttest', 'PWO', 'Pay Way Out Test Estate');
}

beforeEach(function (): void {
    payWayOutEstate()->boot();

    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);

    // This file's people only: whoever an earlier case put here no longer reaches the estate.
    EstateAssignment::query()->where('tenant_id', payWayOutEstate()->key)->update(['is_active' => false]);

    $this->csa = payWayOutEstate()->viewer('estate.community_super_admin');
    $this->treasurer = payWayOutEstate()->viewer('estate.treasurer');
    $this->president = payWayOutEstate()->viewer('estate.president');

    // September as live holds it: started by the Treasurer, calculated by the only Super Admin.
    $run = PayrollRun::query()->firstOrNew(['slug' => 'sep-2026']);
    $run->forceFill([
        'reference' => 'PR-SEP2026',
        'period_label' => 'September 2026',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'periods_per_year' => 12,
        'statutory_rate_version_id' => StatutoryRateVersion::forPayDate(Carbon::parse('2026-09-30'))->id,
        'status' => PayrollRun::CALCULATED,
        'gross_minor' => 0,
        'net_minor' => 0,
        'currency' => 'JMD',
        'prepared_by' => $this->treasurer->getKey(),
        'prepared_by_name' => $this->treasurer->name,
        'calculated_by' => $this->csa->getKey(),
        'calculated_by_name' => $this->csa->name,
    ])->save();
});

it('shows the Super Admin who calculated it a greyed Approve with the way out beside it', function () {
    $this->actingAs($this->csa)->get(payWayOutEstate()->url('/payroll/runs/sep-2026'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Payroll/Run')
            ->where('holdsApproval', true)
            ->where('canApprove', false)
            ->where('canRequestChanges', true)
            // Hassam's second case (12 Oct 2026): three short lines, each person named once a line.
            ->where('approvalNext', $this->csa->name." calculated this run.\nWhoever calculates a run cannot approve it.\nAsk "
                .$this->treasurer->name.', Treasurer, to calculate it instead, then '.$this->csa->name.' can approve.'));
});

it('gives the President no Approve and no Request changes, and says what happens next', function () {
    $this->actingAs($this->president)->get(payWayOutEstate()->url('/payroll/runs/sep-2026'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('holdsApproval', false)
            ->where('canRequestChanges', false)
            // Hassam's third case, then the three lines: who approves, and why nobody can yet.
            ->where('approvalNext', 'Only the Community Super Admin approves a pay run. That is '.$this->csa->name.".\n".$this->csa->name." calculated this run.\nWhoever calculates a run cannot approve it.\nAsk "
                .$this->treasurer->name.', Treasurer, to calculate it instead, then '.$this->csa->name.' can approve.'));
});

it('says on the run list why the next month cannot start, and what the open one waits on', function () {
    $this->actingAs($this->treasurer)->get(payWayOutEstate()->url('/payroll'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCreate', true)
            ->where('calendar.blocked_by', 'September 2026 is still open, so October 2026 cannot start yet. '.$this->csa->name.' calculated September 2026. '
                .'Whoever calculates a run cannot approve it. Ask '.$this->treasurer->name.', Treasurer, to calculate it instead, then '.$this->csa->name.' can approve.'));
});

it('warns the only approver before they calculate, and gives no Calculate to a role that cannot', function () {
    $this->actingAs($this->csa)->get(payWayOutEstate()->url('/payroll/runs/sep-2026/exceptions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCalculateRuns', true)
            ->where('calculateWarning', 'If you calculate this run, you cannot approve it, and nobody else here can. Ask '.$this->treasurer->name.', Treasurer, to calculate it instead.'));

    // The Treasurer calculating frees the Super Admin: no warning.
    $this->actingAs($this->treasurer)->get(payWayOutEstate()->url('/payroll/runs/sep-2026/exceptions'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCalculateRuns', true)->where('calculateWarning', null));

    $this->actingAs($this->president)->get(payWayOutEstate()->url('/payroll/runs/sep-2026/exceptions'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCalculateRuns', false));
});

it('frees the Super Admin once the Treasurer has recalculated it', function () {
    PayrollRun::query()->where('slug', 'sep-2026')->update(['calculated_by' => $this->treasurer->getKey(), 'calculated_by_name' => $this->treasurer->name]);

    // Hassam's third case to the Treasurer, who can never approve: who does, by name.
    $this->actingAs($this->treasurer)->get(payWayOutEstate()->url('/payroll/runs/sep-2026'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('approvalNext', 'Only the Community Super Admin approves a pay run. That is '.$this->csa->name.'.'));

    // And his first case to the Super Admin, who now can.
    $this->actingAs($this->csa)->get(payWayOutEstate()->url('/payroll/runs/sep-2026'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('approvalNext', 'Only the Community Super Admin approves a pay run. '.$this->csa->name.' can approve this one.'));
});
