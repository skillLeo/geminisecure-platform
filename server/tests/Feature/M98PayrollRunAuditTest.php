<?php

declare(strict_types=1);

use App\Models\Estate\Employee;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\PayrollRunLine;
use App\Models\Estate\StatutoryFiling;
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
| The payroll audit (Hassam, 11 Oct): a pay run's controls say their rule, and nothing errors for a rule it knows
|--------------------------------------------------------------------------
|
| The approver line's way out (M94 §1.1) carried three of Hassam's four points: Approve greyed with the words beside it, the way out
| on the same screen, and no error page. The fourth, that the reason NAMES THE RULE, was said only on hover: the screen
| printed the way out instead of the rule. Now a barred approver reads the rule ("You calculated this run, so someone
| else approves it.") and under it the way out, which no longer says the rule a second time. A run sent back says it
| waits to be calculated again, with what was asked, where it said there were no payslips and that it waited for an
| approver. A run withholding a pension with no account to post it to says so before the press, where Approve used to be
| refused only once pressed. A file asked of an unapproved run comes back to the page, never an error page.
|
*/

function payAuditEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('payaudittest', 'PAU', 'Pay Audit Test Estate');
}

beforeEach(function (): void {
    payAuditEstate()->boot();

    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);

    // This file's people only: whoever an earlier case put here no longer reaches the estate.
    EstateAssignment::query()->where('tenant_id', payAuditEstate()->key)->update(['is_active' => false]);

    $this->csa = payAuditEstate()->viewer('estate.community_super_admin');
    $this->treasurer = payAuditEstate()->viewer('estate.treasurer');
    $this->president = payAuditEstate()->viewer('estate.president');

    // September as live holds it: started by the Treasurer, calculated by the only Super Admin, never sent back.
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
        'changes_requested_reason' => null,
    ])->save();

    $this->run = $run;
});

it('says the rule, then the way out, to the Super Admin who calculated it, and the rule only once', function () {
    $this->actingAs($this->csa)->get(payAuditEstate()->url('/payroll/runs/sep-2026'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Payroll/Run')
            ->where('holdsApproval', true)
            ->where('canApprove', false)
            // The greyed button's reason, and on the screen Hassam's three lines (12 Oct 2026), which say the rule once.
            ->where('approvalReason', 'You calculated this run, so you cannot approve it.')
            ->where('approvalBlock', null)
            ->where('approvalNext', $this->csa->name." calculated this run.\nWhoever calculates a run cannot approve it.\nAsk "
                .$this->treasurer->name.', Treasurer, to calculate it instead, then '.$this->csa->name.' can approve.'));
});

it('gives a role that never approves no refusal of its own, only who approves and what stops them', function () {
    $this->actingAs($this->president)->get(payAuditEstate()->url('/payroll/runs/sep-2026'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('holdsApproval', false)
            ->where('approvalBlock', null)
            ->where('approvalNext', fn (string $line): bool => str_starts_with($line, 'Only the Community Super Admin approves a pay run. That is '.$this->csa->name.'.')));
});

it('says a run sent back waits to be calculated again, with what was asked, and never as an error page', function () {
    $asked = 'Wayne Thomas is missing his September overtime.';

    $this->actingAs($this->csa)->post(payAuditEstate()->url('/payroll/runs/sep-2026/changes'), ['reason' => $asked])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->run->refresh()->status)->toBe(PayrollRun::EXCEPTIONS);

    $this->actingAs($this->csa)->get(payAuditEstate()->url('/payroll/runs/sep-2026'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('run.sent_back_reason', $asked)
            ->where('canApprove', false)
            ->where('approvalReason', 'This run was sent back with Request changes, so it must be calculated again before it can be approved.')
            // Said on the screen under the line; an approver reads no who-approves line before the run is calculated again.
            ->where('approvalBlock', 'This run was sent back with Request changes, so it must be calculated again before it can be approved.')
            ->where('approvalNext', null));

    // A run never sent back says what comes first, as it always did (PayRunStopsInOneLineTest).
    expect(app(Payroll::class)->approvalRefusal((new PayrollRun)->forceFill(['status' => PayrollRun::EXCEPTIONS]), null))
        ->toBe('Calculate the run first; there are no payslips to approve yet.');
});

it('greys Approve with the pension rule before the press, and refuses it in the same words, never an error page', function () {
    // The Treasurer recalculated it, so the Super Admin is free to approve it.
    $this->run->forceFill(['calculated_by' => $this->treasurer->getKey(), 'calculated_by_name' => $this->treasurer->name])->save();

    $employee = Employee::query()->firstOrNew(['full_name' => 'Pension Audit Employee']);
    $employee->forceFill(['job_title' => 'Groundsman', 'monthly_rate_minor' => 90_000_00, 'employed_since' => '2026-01-05'])->save();

    $line = PayrollRunLine::query()->firstOrNew(['payroll_run_id' => $this->run->id, 'employee_id' => $employee->id]);
    $line->forceFill(['gross_minor' => 90_000_00, 'net_minor' => 80_000_00, 'pension_minor' => 0])->save();

    $rule = 'This run withholds approved pension contributions, which are owed to the scheme and not to TAJ, so they '
        .'post to '.Payroll::PENSION_PAYABLE.' Pension Contributions Payable. Add that liability account to the chart first.';

    try {
        // No pension withheld: nothing stands in the way.
        $this->actingAs($this->csa)->get(payAuditEstate()->url('/payroll/runs/sep-2026'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canApprove', true)->where('approvalReason', null));

        // A pension withheld, and this estate's chart has no account to post it to.
        $line->forceFill(['pension_minor' => 10_000_00])->save();

        $this->actingAs($this->csa)->get(payAuditEstate()->url('/payroll/runs/sep-2026'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canApprove', false)->where('approvalReason', $rule));

        $this->actingAs($this->csa)->post(payAuditEstate()->url('/payroll/runs/sep-2026/approve'), ['reconciled' => true])
            ->assertRedirect()
            ->assertSessionHasErrors(['run' => $rule]);

        expect($this->run->refresh()->status)->toBe(PayrollRun::CALCULATED);
    } finally {
        $line->forceFill(['pension_minor' => 0])->save();
    }
});

it('offers approving on the run list only to a role that ever approves, in plain words and the vocabulary', function () {
    $this->actingAs($this->president)->get(payAuditEstate()->url('/payroll'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Payroll/Runs')
            ->where('rows.0.status_label', 'Pending')
            ->where('rows.0.action', 'Review')
            ->where('kpis.2.label', 'Last run’s net pay')
            ->where('kpis.3.label', 'Waiting for approval')
            // The next run's days in words, as every other date on the screen (it read "2026-10-01 to 2026-10-31").
            ->where('calendar.runs', 'Oct 1 to Oct 31, 2026'));

    $this->actingAs($this->csa)->get(payAuditEstate()->url('/payroll'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.0.action', 'Review & approve'));
});

it('says on the exceptions page why Calculate waits, in a sentence, and what a run sent back was sent back for', function () {
    $employee = Employee::query()->firstOrNew(['full_name' => 'Exception Audit Employee']);
    $employee->forceFill(['job_title' => 'Gate attendant', 'monthly_rate_minor' => 82_000_00, 'employed_since' => '2026-01-05'])->save();

    $exception = PayrollException::query()->firstOrNew(['payroll_run_id' => $this->run->id, 'employee_id' => $employee->id, 'type' => PayrollException::MISSING_TIMESHEET]);
    $exception->forceFill(['detail' => 'No September timesheet.', 'status' => PayrollException::UNRESOLVED])->save();

    $this->run->forceFill(['status' => PayrollRun::EXCEPTIONS, 'changes_requested_reason' => 'Kemar Brown worked the long weekend.'])->save();

    try {
        $this->actingAs($this->treasurer)->get(payAuditEstate()->url('/payroll/runs/sep-2026/exceptions'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Estate/Payroll/Exceptions')
                ->where('canCalculate', false)
                // It read "Continue to calculation (resolve all first) — 1 item still unresolved."
                ->where('calculateReason', 'Settle the 1 exception above first. A missing timesheet calculated anyway pays somebody for hours nobody recorded.')
                ->where('run.sent_back_reason', 'Kemar Brown worked the long weekend.'));
    } finally {
        // Settled, never deleted: the next case and the next run of this file start from a run with nothing blocking.
        $exception->forceFill(['status' => PayrollException::RESOLVED])->save();
    }
});

it('names each employee\'s type in the form\'s own words, fixed term included', function () {
    $fixed = Employee::query()->firstOrNew(['full_name' => 'Fixed Term Audit Employee']);
    $fixed->forceFill(['job_title' => 'Relief gate attendant', 'employment_type' => 'contract', 'monthly_rate_minor' => 60_000_00, 'employed_since' => '2026-06-01'])->save();

    $this->actingAs($this->treasurer)->get(payAuditEstate()->url('/payroll/employees'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Payroll/Employees')
            // It read "Part-time": the register knew full time and anything else.
            ->where('rows', fn ($rows): bool => collect($rows)->firstWhere('name', 'Fixed Term Audit Employee')['type'] === 'Fixed term'));
});

it('names a return without an em dash, and says on its row why it cannot be filed yet', function () {
    $return = StatutoryFiling::query()->firstOrNew(['form_code' => StatutoryFiling::S01, 'period_label' => 'September 2026']);
    $return->forceFill([
        'form_title' => 'Statutory Deduction Remittance',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'due_on' => '2026-10-14',
        'status' => StatutoryFiling::NOT_STARTED,
        'payroll_run_id' => $this->run->id,
        'currency' => 'JMD',
    ])->save();

    $this->actingAs($this->treasurer)->get(payAuditEstate()->url('/payroll/filings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Payroll/Filings')
            ->where('rows', function ($rows): bool {
                $row = collect($rows)->firstWhere('period', 'September 2026');

                // It read "S01 — Statutory Deduction Remittance", and the reason was only on hover.
                return $row['title'] === 'S01 · Statutory Deduction Remittance'
                    && $row['file_blocked_short'] === 'Approve the September 2026 pay run first.';
            }));
});

it('names every return on the compliance calendar without an em dash', function () {
    $this->actingAs($this->treasurer)->get(payAuditEstate()->url('/payroll/filings/calendar'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Payroll/Calendar')
            ->where('months', function ($months): bool {
                $titles = collect($months)->flatMap(fn ($month) => collect($month['rows'])->pluck('title'));

                // It read "S01 — Statutory Deduction Remittance" on the register's rows and the projected ones alike.
                return $titles->isNotEmpty()
                    && $titles->every(fn (string $title): bool => str_contains($title, ' · ') && ! str_contains($title, '—'));
            }));
});

it('says when a paid run was approved in words, on the estate\'s own calendar', function () {
    // A paid run of its own, made once and never changed: a paid run's row is not rewritten.
    $paid = PayrollRun::query()->firstWhere('slug', 'aug-2026');

    if ($paid === null) {
        $paid = new PayrollRun;
        $paid->forceFill([
            'reference' => 'PR-AUG2026',
            'slug' => 'aug-2026',
            'period_label' => 'August 2026',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'periods_per_year' => 12,
            'statutory_rate_version_id' => StatutoryRateVersion::forPayDate(Carbon::parse('2026-08-31'))->id,
            'status' => PayrollRun::PAID,
            'gross_minor' => 0,
            'net_minor' => 0,
            'currency' => 'JMD',
            'prepared_by' => $this->treasurer->getKey(),
            'prepared_by_name' => $this->treasurer->name,
            'approved_by' => $this->csa->getKey(),
            'approved_by_name' => $this->csa->name,
            // 1:30 AM on Sep 3 by UTC is the evening of Sep 2 in Kingston.
            'approved_at' => Carbon::parse('2026-09-03 01:30:00', 'UTC'),
        ])->save();
    }

    $this->actingAs($this->treasurer)->get(payAuditEstate()->url('/payroll/runs/aug-2026'))
        ->assertOk()
        // It read "2026-09-03", the UTC calendar's date in the database's own form.
        ->assertInertia(fn (AssertableInertia $page) => $page->where('run.approved_at', 'Sep 2, 2026'));
});

it('sends a file asked of an unapproved run back to the page with the reason, never an error page', function () {
    $this->actingAs($this->csa)->get(payAuditEstate()->url('/payroll/runs/sep-2026/export?format=bank'))
        ->assertRedirect()
        ->assertSessionHasErrors(['format' => 'September 2026 has not been approved. A file built from a draft is an instruction to pay figures nobody has agreed.']);
});
