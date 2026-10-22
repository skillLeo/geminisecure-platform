<?php

declare(strict_types=1);

use App\Models\Estate\Employee;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\EstateAssignment;
use App\Models\StatutoryRateVersion;
use App\Services\Estate\Payroll;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| The two-person rule counts one act: the run's final calculation (Hassam, 12 Oct 2026; D-233)
|--------------------------------------------------------------------------
|
| The client's bug: "You resolved an exception on this run, so someone else approves it." The rule counted every hand
| on the run (starting it, settling an exception, excluding somebody), and in an estate with three staff nobody could
| approve. Only the person who performed the final calculation may not approve it. Nobody is exempt, the Super Admin
| included. What the others decided is shown to the approver to weigh, never counted against them.
|
| And the screen tells the truth (the client's three faults): every role sees a button or a sentence, the page says who
| calculated the run and who must approve it, by name and role, with the way out when nobody can, and Request changes
| refuses a reply that names nothing.
|
*/

function payTwoPersonEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('paytwoperson', 'PTP', 'Pay Two Person Test Estate');
}

function payTwoPersonRun(): PayrollRun
{
    return PayrollRun::query()->where('slug', 'sep-2026')->firstOrFail();
}

beforeEach(function (): void {
    payTwoPersonEstate()->boot();

    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);

    // This file's people only: whoever an earlier case put here no longer reaches the estate.
    EstateAssignment::query()->where('tenant_id', payTwoPersonEstate()->key)->update(['is_active' => false]);

    // Two Super Admins, who both hold Approve pay runs; the Treasurer calculates and sends back; the President reads.
    $this->decider = payTwoPersonEstate()->viewer('estate.community_super_admin');
    $this->calculator = payTwoPersonEstate()->viewer('estate.community_super_admin');
    $this->treasurer = payTwoPersonEstate()->viewer('estate.treasurer');
    $this->president = payTwoPersonEstate()->viewer('estate.president');

    // September: the Treasurer started it, one Super Admin settled both of its exceptions, the other calculated it.
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
        'calculated_by' => $this->calculator->getKey(),
        'calculated_by_name' => $this->calculator->name,
        'changes_requested_reason' => null,
    ])->save();

    $wayne = Employee::query()->firstOrCreate(['full_name' => 'Wayne Thomas'], ['job_title' => 'Groundsman', 'monthly_rate_minor' => 9_000_000, 'employed_since' => '2026-01-05']);
    $neil = Employee::query()->firstOrCreate(['full_name' => 'Neil Anderson'], ['job_title' => 'Gate attendant', 'monthly_rate_minor' => 8_200_000, 'employed_since' => '2026-02-02']);

    PayrollException::query()->updateOrCreate(['payroll_run_id' => $run->id, 'employee_id' => $wayne->id], [
        'type' => PayrollException::OVERTIME_ANOMALY, 'detail' => '22 overtime hours against a usual 6.', 'overtime_hours' => 22, 'overtime_baseline_hours' => 6,
        'status' => PayrollException::RESOLVED, 'resolution_note' => 'Hours accepted as logged.',
        'resolved_at' => now()->subHour(), 'resolved_by' => $this->decider->getKey(), 'resolved_by_name' => $this->decider->name,
    ]);
    PayrollException::query()->updateOrCreate(['payroll_run_id' => $run->id, 'employee_id' => $neil->id], [
        'type' => PayrollException::MISSING_TIMESHEET, 'detail' => 'No timesheet for Sep 21 to Sep 27.',
        'status' => PayrollException::EXCLUDED, 'resolution_note' => 'Excluded from this run at the manager’s decision.',
        'resolved_at' => now()->subMinutes(50), 'resolved_by' => $this->decider->getKey(), 'resolved_by_name' => $this->decider->name,
    ]);
});

it('lets a person who settled exceptions approve the run, and never the person who calculated it', function () {
    $payroll = app(Payroll::class);
    $run = payTwoPersonRun();

    // Settled both exceptions (accepted an officer's overtime, excluded another), did not calculate it: may approve.
    expect($payroll->approvalRefusal($run, $this->decider))->toBeNull()
        ->and($payroll->mayApprove($run, $this->decider))->toBeTrue();

    // Starting the run is not calculating it either.
    $run->forceFill(['prepared_by' => $this->decider->getKey(), 'prepared_by_name' => $this->decider->name])->save();

    expect($payroll->approvalRefusal($run->refresh(), $this->decider))->toBeNull();

    // Calculated it: may not, though a Super Admin. Nobody is exempt.
    expect($payroll->mayApprove($run, $this->calculator))->toBeFalse()
        ->and($payroll->approvalRefusal($run, $this->calculator))->toBe('You calculated this run, so you cannot approve it.');

    $this->actingAs($this->decider)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canApprove', true)->where('approvalReason', null));

    $this->actingAs($this->calculator)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canApprove', false)->where('approvalReason', 'You calculated this run, so you cannot approve it.'));

    // The server refuses the calculator's press whatever a page drew, and nothing moves.
    $this->actingAs($this->calculator)->post(payTwoPersonEstate()->url('/payroll/runs/sep-2026/approve'))
        ->assertSessionHasErrors(['run' => 'You calculated this run, so you cannot approve it.']);

    expect(payTwoPersonRun()->status)->toBe(PayrollRun::CALCULATED);
});

it('shows the approver what was decided on the run, plainly, instead of locking them out for it', function () {
    $decided = [
        $this->decider->name.' accepted Wayne Thomas\'s overtime as logged: 22 hours against a usual 6.',
        $this->decider->name.' excluded Neil Anderson from this run, missing timesheet.',
    ];

    foreach ([$this->calculator, $this->decider, $this->treasurer, $this->president] as $viewer) {
        $this->actingAs($viewer)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('decisions', $decided));
    }
});

it('says to every viewer, before any press, who approves the run and that nothing stops them', function () {
    // Hassam's first case (12 Oct 2026): "Only the Community Super Admin approves a pay run. Dahlia Reid can approve this one."
    $can = 'Only the Community Super Admin approves a pay run. '.$this->decider->name.' can approve this one.';

    // The Super Admin who can approve, and the one who calculated it, who reads the same line and their own refusal under it.
    $this->actingAs($this->decider)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('approvalNext', $can)->where('approvalBlock', null));

    $this->actingAs($this->calculator)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('approvalNext', $can)->where('approvalBlock', 'You calculated this run, so you cannot approve it.'));

    // Hassam's third case, to the President and the Treasurer, who can never approve: who does, by name.
    foreach ([$this->president, $this->treasurer] as $viewer) {
        $this->actingAs($viewer)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('approvalNext', fn (string $line): bool => str_starts_with($line, 'Only the Community Super Admin approves a pay run. Those are ')
                && str_contains($line, $this->decider->name) && str_contains($line, $this->calculator->name) && ! str_contains($line, "\n")));
    }
});

it('says, when the only approver calculated it, in three short lines that someone else must calculate it first', function () {
    // The decider leaves: the calculator is the estate's only approver.
    EstateAssignment::query()->where('user_id', $this->decider->getKey())->update(['is_active' => false]);

    // Hassam's second case, each person named once a line, never "she": the role-holders as the estate holds them.
    $lines = $this->calculator->name." calculated this run.\nWhoever calculates a run cannot approve it.\nAsk "
        .$this->treasurer->name.', Treasurer, to calculate it instead, then '.$this->calculator->name.' can approve.';

    $this->actingAs($this->calculator)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('approvalNext', $lines)
            // The rule is the line's: not said a second time under it, and still the greyed button's reason.
            ->where('approvalBlock', null)
            ->where('approvalReason', 'You calculated this run, so you cannot approve it.'));

    // The President and the Treasurer: who approves, then the same three lines, so the Treasurer reads it is theirs to do.
    foreach ([$this->president, $this->treasurer] as $viewer) {
        $this->actingAs($viewer)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('approvalNext', 'Only the Community Super Admin approves a pay run. That is '.$this->calculator->name.".\n".$lines));
    }
});

it('never lets "second approver", "she" or "he" reach the run page', function () {
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Payroll/Run.vue'));

    expect(str_contains(strtolower($page), 'second approver'))->toBeFalse();

    // Every viewer, with a second Super Admin free and then with the calculator the only approver.
    foreach ([false, true] as $alone) {
        if ($alone) {
            EstateAssignment::query()->where('user_id', $this->decider->getKey())->update(['is_active' => false]);
        }

        foreach ([$this->decider, $this->calculator, $this->treasurer, $this->president] as $viewer) {
            $this->actingAs($viewer)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
                ->assertInertia(function (AssertableInertia $page): void {
                    $said = ' '.strtolower(implode(' ', array_filter([$page->toArray()['props']['approvalNext'] ?? null, $page->toArray()['props']['approvalBlock'] ?? null, $page->toArray()['props']['approvalReason'] ?? null]))).' ';

                    expect(str_contains($said, 'second approver'))->toBeFalse()
                        ->and(str_contains($said, 'committee'))->toBeFalse()
                        ->and(preg_match('/\b(she|her|hers|he|him|his)\b/', $said))->toBe(0);
                });
        }
    }
});

it('warns before Calculate only when nobody else could approve, and an approver who settled exceptions still could', function () {
    // The decider settled both exceptions and is free to approve: the Super Admin calculating leaves them free, so no warning.
    $this->actingAs($this->calculator)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026/exceptions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCalculateRuns', true)->where('calculateWarning', null));

    // With the decider gone the Super Admin is the only approver: warned before the press, told whom to ask by name and role.
    EstateAssignment::query()->where('user_id', $this->decider->getKey())->update(['is_active' => false]);

    $this->actingAs($this->calculator)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026/exceptions'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('calculateWarning', 'If you calculate this run, you cannot approve it, and nobody else here can. Ask '
            .$this->treasurer->name.', Treasurer, to calculate it instead.'));
});

it('gives every role on the page a button or a sentence, never neither', function () {
    $who = fn (string $line): bool => str_starts_with($line, 'Only the Community Super Admin approves a pay run.');

    // The President: no control, so the badge (the page's View only) and the sentence saying who approves.
    $this->actingAs($this->president)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('holdsApproval', false)->where('canRequestChanges', false)->where('approvalNext', $who));

    // The Treasurer: Request changes, and the same sentence.
    $this->actingAs($this->treasurer)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('holdsApproval', false)->where('canRequestChanges', true)->where('approvalNext', $who));

    // The Super Admins: Approve, live for one and greyed with its reason for the other, and the sentence.
    foreach ([$this->decider, $this->calculator] as $viewer) {
        $this->actingAs($viewer)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('holdsApproval', true)->where('approvalNext', $who));
    }

    // And the page draws the badge for a role with no control at all.
    expect((string) file_get_contents(resource_path('js/Pages/Estate/Payroll/Run.vue')))->toContain('<span v-if="viewOnly" class="view-badge">View only</span>');
});

it('refuses a request for changes that names nothing, as it refuses an empty one, and shows the reason it was sent back', function () {
    foreach (['all done', 'OK', 'Looks good.', 'please check'] as $nothing) {
        $this->actingAs($this->treasurer)->post(payTwoPersonEstate()->url('/payroll/runs/sep-2026/changes'), ['reason' => $nothing])
            ->assertSessionHasErrors('run');

        expect(payTwoPersonRun()->status)->toBe(PayrollRun::CALCULATED);
    }

    $this->actingAs($this->treasurer)->post(payTwoPersonEstate()->url('/payroll/runs/sep-2026/changes'), ['reason' => 'Wayne Thomas is missing his September overtime.'])
        ->assertSessionHasNoErrors();

    expect(payTwoPersonRun()->status)->toBe(PayrollRun::EXCEPTIONS);

    // The next person reads what was asked, on the exceptions page and on the run.
    $this->actingAs($this->calculator)->get(payTwoPersonEstate()->url('/payroll/runs/sep-2026/exceptions'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('run.sent_back_reason', 'Wayne Thomas is missing his September overtime.'));
});
