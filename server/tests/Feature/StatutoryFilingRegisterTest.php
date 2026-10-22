<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\StatutoryFiling;
use App\Models\StatutoryRateVersion;
use App\Services\Payroll\StatutoryFilingRegister;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The statutory register, and the record that cannot be unmade
|--------------------------------------------------------------------------
|
| Board super-admin-30 lists the returns Gemini Security owes the Jamaican
| authorities. Two things about it are worth asserting rather than looking at.
|
| The first is the calendar. Whether a return is owed, late, or nobody's problem
| yet is entirely a question of dates, and the difference between "Due soon" and
| "Overdue" is the difference between a task and a penalty. Those rules are
| driven from a frozen clock here, because a test that passes in September and
| fails in October is worse than no test at all.
|
| The second is invariant 4. A FILED return is a posted record: the authority
| holds a copy of it, and editing ours would put the two out of step with
| nothing to show for it. That is defended three times over - no route, a model
| guard, and a database trigger - and all three are asserted, because the one
| that gets removed during a refactor is always the one nobody tested.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console and dropping the schema mid-run would take other
| tests with it. Each test runs inside a transaction and leaves nothing behind.
|
| AND THE TRANSACTION IS NAMED, WHICH IS THE WHOLE POINT (M4 §4, defect 3).
|
| This file used `DatabaseTransactions`, and that trait transacts `config('database.default')` —
| resolved TWICE, once when the transaction is opened in `setUp` and again when it is rolled back
| after the test. Between those two moments the test at the bottom of this file calls
| `FacilitiesFixture::geminiViewer()`, which boots the estate fixture, which sets
| `database.default` to `tenant`. So the transaction was opened on `mysql` and rolled back on
| `tenant`: a different connection, a different session, and the central one walked away STILL
| OPEN, holding row locks in `gs_platform_test`.
|
| What that looked like was not this file failing. It was the NEXT test to insert
| `PR-TEST-MAY2030` sitting for fifty seconds and then reporting
| `SQLSTATE[HY000] 1205 Lock wait timeout exceeded` — a database error, in a test that had done
| nothing wrong, with nothing in it to suggest where to look. Under random order the file that
| paid for it changed every run.
|
| So the connection is named here rather than inferred, which no fixture can redirect. NINE OTHER
| FILES still use `DatabaseTransactions`; any of them that boots a tenant fixture inside a test has
| the same trap waiting.
|
*/

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();

    // A fixed "today", in a year the seeded register knows nothing about, so
    // these rows can be reasoned about in isolation.
    Carbon::setTestNow('2030-06-10');

    $this->register = app(StatutoryFilingRegister::class);
});

afterEach(function () {
    // The rollback comes FIRST, whatever else has to be undone: a central transaction left open
    // by a clean-up that threw is the very failure described above.
    DB::connection('mysql')->rollBack();

    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function statutoryFiling(array $attributes): StatutoryFiling
{
    return StatutoryFiling::create(array_merge([
        'form_code' => 'S01',
        'form_title' => 'Statutory Deduction Remittance',
        'status' => StatutoryFiling::DUE,
        'currency' => 'JMD',
    ], $attributes));
}

/** A rate version in force on the frozen date, signed off or not. */
function ratesInForce(bool $verified): StatutoryRateVersion
{
    return StatutoryRateVersion::create([
        'label' => 'Test rates '.($verified ? 'signed off' : 'draft'),
        'effective_from' => '2030-01-01',
        'nis_employee_bp' => 300,
        'nis_employer_bp' => 300,
        'nis_ceiling_annual_minor' => 5_000_000_00,
        'nht_employee_bp' => 200,
        'nht_employer_bp' => 300,
        'education_tax_employee_bp' => 225,
        'education_tax_employer_bp' => 350,
        'paye_bp' => 2500,
        'paye_threshold_annual_minor' => 1_800_000_00,
        'is_verified' => $verified,
    ]);
}

it('calls a closed period with a future deadline due soon', function () {
    statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    $row = $this->register->rows(2030)[0];

    // The one vocabulary's word since M96 and M99 (it read "Due soon"); the date says how soon.
    expect($row['badge_label'])->toBe('Action required')
        ->and($row['badge_class'])->toBe('pending')
        // The board draws the amber icon tile on exactly this state.
        ->and($row['warn'])->toBeTrue()
        ->and($row['when'])->toBe('Due Jun 14');
});

it('names a return with the console\'s separator, never an em dash (the payroll pass, 11 Oct)', function () {
    statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    $title = (string) $this->register->rows(2030)[0]['title'];

    // It read "S01 — Statutory Deduction Remittance".
    expect(str_starts_with($title, 'S01 · '))->toBeTrue()
        ->and(str_contains($title, '—'))->toBeFalse();
});

it('calls a missed deadline overdue, not due soon', function () {
    statutoryFiling([
        'period_label' => 'March 2030',
        'period_start' => '2030-03-01',
        'period_end' => '2030-03-31',
        'due_on' => '2030-04-14',
    ]);

    $row = $this->register->rows(2030)[0];

    // "Due soon" on a return that is eight weeks late would be a screen
    // reassuring somebody who is accruing a penalty.
    // Overdue as a flag beside the state (M99); still never the due-soon badge.
    expect($row['badge_label'])->toBe('Action required · Overdue')
        ->and($row['badge_class'])->toBe('exception');
});

it('owes nothing for a period that has not closed', function () {
    statutoryFiling([
        'form_code' => 'P24',
        'form_title' => 'Annual Employer Return',
        'period_label' => 'Tax year 2030',
        'period_start' => '2030-01-01',
        'period_end' => '2030-12-31',
        'due_on' => '2031-03-31',
        'status' => StatutoryFiling::NOT_STARTED,
    ]);

    $row = $this->register->rows(2030)[0];

    expect($row['badge_label'])->toBe('Pending')
        ->and($row['warn'])->toBeFalse()
        // Beyond this year, so the year has to be spelled out or it reads as
        // three weeks away rather than nine months.
        ->and($row['when'])->toBe('Due Mar 31, 2031');
});

it('reads outstanding first, then filed, then what is not owed yet', function () {
    statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    statutoryFiling([
        'form_code' => 'S02',
        'form_title' => 'Monthly Reconciliation',
        'period_label' => 'April 2030',
        'period_start' => '2030-04-01',
        'period_end' => '2030-04-30',
        'due_on' => '2030-05-14',
        'status' => StatutoryFiling::FILED,
        'filed_on' => '2030-05-11',
    ]);

    statutoryFiling([
        'period_label' => 'March 2030',
        'period_start' => '2030-03-01',
        'period_end' => '2030-03-31',
        'due_on' => '2030-04-14',
    ]);

    statutoryFiling([
        'form_code' => 'P24',
        'form_title' => 'Annual Employer Return',
        'period_label' => 'Tax year 2030',
        'period_start' => '2030-01-01',
        'period_end' => '2030-12-31',
        'due_on' => '2031-03-31',
        'status' => StatutoryFiling::NOT_STARTED,
    ]);

    $labels = array_map(
        static fn (array $row): string => (string) $row['badge_label'],
        $this->register->rows(2030),
    );

    expect($labels)->toBe(['Action required · Overdue', 'Action required', 'Completed', 'Pending']);
});

it('says what an outstanding return will cover and why it cannot be prepared', function () {
    statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    $row = $this->register->rows(2030)[0];

    expect($row['detail'])->toContain('May 2030')
        // The ruling's own list — the employer's share is on the same return.
        ->and($row['detail'])->toContain('PAYE, NIS, NHT, Education Tax, HEART')
        // The important half: the figures are not available, and why.
        ->and($row['detail'])->toContain('awaiting an approved pay run');
});

it('shows a filed return by its period alone', function () {
    statutoryFiling([
        'period_label' => 'April 2030',
        'period_start' => '2030-04-01',
        'period_end' => '2030-04-30',
        'due_on' => '2030-05-14',
        'status' => StatutoryFiling::FILED,
        'filed_on' => '2030-05-11',
    ]);

    $row = $this->register->rows(2030)[0];

    expect($row['detail'])->toBe('April 2030')
        ->and($row['when'])->toBe('Filed May 11');
});

it('blocks a new filing while the card in force carries no verified TAJ figures', function () {
    // A card nobody has verified means no run can be approved on it, which means
    // no return can be prepared from one. Q-002 is ruled; this case survives it.
    ratesInForce(verified: false);

    $reason = $this->register->blockedReason();

    expect($reason)->toContain('approved pay run')
        ->and($reason)->toContain('no verified TAJ periodic figures');
});

it('still blocks a new filing when signed-off rates have no unfiled run', function () {
    // The reason changes with the facts. Being up to date reads very
    // differently from being blocked by a decision, so it says so.
    ratesInForce(verified: true);

    expect($this->register->blockedReason())
        ->toContain('already been filed')
        ->not->toContain('no verified TAJ');
});

it('narrows to one year and names the years it does hold', function () {
    statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    expect($this->register->rows(2030))->toHaveCount(1)
        ->and($this->register->rows(2029))->toHaveCount(0)
        ->and($this->register->years())->toContain(2030);
});

it('refuses to edit a filed return in the model', function () {
    $filed = statutoryFiling([
        'period_label' => 'April 2030',
        'period_start' => '2030-04-01',
        'period_end' => '2030-04-30',
        'due_on' => '2030-05-14',
        'status' => StatutoryFiling::FILED,
        'filed_on' => '2030-05-11',
    ]);

    // A correction is an AMENDED RETURN for the same period, never an edit.
    expect(fn () => $filed->update(['confirmation_reference' => 'TAJ-REWRITTEN']))
        ->toThrow(LogicException::class, 'cannot be edited');
});

it('refuses to delete a filed return in the model', function () {
    $filed = statutoryFiling([
        'period_label' => 'April 2030',
        'period_start' => '2030-04-01',
        'period_end' => '2030-04-30',
        'due_on' => '2030-05-14',
        'status' => StatutoryFiling::FILED,
        'filed_on' => '2030-05-11',
    ]);

    expect(fn () => $filed->delete())->toThrow(LogicException::class);
});

it('keeps the append-only guard in the database, not only in PHP', function () {
    /*
     * The model guard protects code that goes through the model. The trigger
     * protects everything else - a raw query, a migration, a console command
     * written in a hurry - and it is the one that still holds after the others
     * have been refactored away.
     */
    $triggers = DB::connection('mysql')
        ->table('information_schema.TRIGGERS')
        ->where('EVENT_OBJECT_TABLE', 'statutory_filings')
        ->pluck('EVENT_MANIPULATION')
        ->all();

    expect($triggers)->toContain('UPDATE')
        ->and($triggers)->toContain('DELETE');
});

/* ------------------------------------------------------------------ */
/* preparing a return, and the payroll's Employees tab — 12 §2, Wave 4 */
/* ------------------------------------------------------------------ */

/** An approved May 2030 run with two payslips, on a verified card. */
function approvedRunWithSlips(): PayrollRun
{
    $card = ratesInForce(verified: true);

    $run = PayrollRun::create([
        'reference' => 'PR-TEST-MAY2030',
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'periods_per_year' => 12,
        'statutory_rate_version_id' => $card->id,
        'status' => 'approved',
        'gross_minor' => 300_000_00,
        'net_minor' => 240_000_00,
        'currency' => 'JMD',
    ]);

    foreach ([['Filing Officer One', 1], ['Filing Officer Two', 2]] as [$name, $n]) {
        $guard = Guard::create([
            'full_name' => $name,
            'employee_number' => 'GS-FIL-'.$n,
            'psra_number' => 'PSRA-FIL-'.$n,
            'psra_expires_on' => '2031-01-01',
            'employment_type' => 'full_time',
            'status' => 'active',
        ]);

        Payslip::create([
            'payroll_run_id' => $run->id,
            'guard_id' => $guard->id,
            'gross_minor' => 150_000_00,
            'nis_minor' => 4_500_00, 'nht_minor' => 3_000_00, 'education_tax_minor' => 3_375_00, 'paye_minor' => 19_125_00,
            'net_minor' => 120_000_00,
            'employer_nis_minor' => 4_500_00, 'employer_nht_minor' => 4_500_00,
            'employer_education_tax_minor' => 5_250_00, 'employer_heart_minor' => 4_500_00,
            'currency' => 'JMD',
        ]);
    }

    return $run;
}

it('prepares the S01 from an approved run, both halves, completing the owed row rather than adding one', function () {
    $run = approvedRunWithSlips();

    // The register already owes May, with no figures: no run could be approved for it.
    $owed = statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    expect($this->register->blockedReason())->toBeNull();

    $filing = $this->register->prepareNext();

    expect($filing->id)->toBe($owed->id)
        ->and(StatutoryFiling::query()->where('form_code', 'S01')->whereDate('period_start', '2030-05-01')->count())->toBe(1)
        ->and($filing->payroll_run_id)->toBe($run->id)
        ->and($filing->employees_covered)->toBe(2)
        ->and($filing->paye_minor)->toBe(38_250_00)
        ->and($filing->heart_minor)->toBe(9_000_00)
        // Employee deductions 60,000 + employer contributions 37,500.
        ->and($filing->total_minor)->toBe(97_500_00)
        ->and($filing->status)->toBe(StatutoryFiling::DUE)
        ->and($filing->due_on->toDateString())->toBe('2030-06-14');

    // Nothing approved is left to prepare, and it says so rather than preparing twice.
    expect($this->register->blockedReason())->toContain('already been filed');
    expect(fn () => $this->register->prepareNext())->toThrow(DomainException::class);
});

/*
 * NEXT YEAR'S CARD HAS NOTHING TO DO WITH THIS YEAR'S RETURN (B083).
 *
 * `blockedReason()` used to ask whether TODAY's card was verified before it asked
 * whether an approved run was waiting. From 1 April the card in force is the new
 * tax year's, seeded unverified until somebody records TAJ's periodic figures —
 * so the March S01, due 14 April and prepared from a run approved on March's own
 * verified card, could not be started, and the control said "no run can be
 * approved yet" with an approved run sitting there.
 */

/** Two tax years' cards: this one verified and closed on 31 March 2031, the next one not verified. */
function aTaxYearTurnsOver(): StatutoryRateVersion
{
    $closing = ratesInForce(verified: true);
    $closing->forceFill(['effective_to' => '2031-03-31'])->save();

    StatutoryRateVersion::create([
        'label' => 'Test rates 2031-04 draft',
        'effective_from' => '2031-04-01',
        'nis_employee_bp' => 300,
        'nis_employer_bp' => 300,
        'nis_ceiling_annual_minor' => 5_000_000_00,
        'nht_employee_bp' => 200,
        'nht_employer_bp' => 300,
        'education_tax_employee_bp' => 225,
        'education_tax_employer_bp' => 350,
        'paye_bp' => 2500,
        'paye_threshold_annual_minor' => 1_800_000_00,
        'is_verified' => false,
    ]);

    return $closing;
}

/** A March 2031 run on the closing card, with one payslip. */
function aMarchRun(StatutoryRateVersion $card, string $status): PayrollRun
{
    $run = PayrollRun::create([
        'reference' => 'PR-TEST-MAR2031',
        'period_label' => 'March 2031',
        'period_start' => '2031-03-01',
        'period_end' => '2031-03-31',
        'periods_per_year' => 12,
        'statutory_rate_version_id' => $card->id,
        'status' => $status,
        'gross_minor' => 150_000_00,
        'net_minor' => 120_000_00,
        'currency' => 'JMD',
    ]);

    $guard = Guard::create([
        'full_name' => 'Filing Officer March',
        'employee_number' => 'GS-FIL-MAR',
        'psra_number' => 'PSRA-FIL-MAR',
        'psra_expires_on' => '2032-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    Payslip::create([
        'payroll_run_id' => $run->id,
        'guard_id' => $guard->id,
        'gross_minor' => 150_000_00,
        'nis_minor' => 4_500_00, 'nht_minor' => 3_000_00, 'education_tax_minor' => 3_375_00, 'paye_minor' => 19_125_00,
        'net_minor' => 120_000_00,
        'employer_nis_minor' => 4_500_00, 'employer_nht_minor' => 4_500_00,
        'employer_education_tax_minor' => 5_250_00, 'employer_heart_minor' => 4_500_00,
        'currency' => 'JMD',
    ]);

    return $run;
}

it('prepares an approved run\'s return after next year\'s unverified card comes into force', function () {
    $run = aMarchRun(aTaxYearTurnsOver(), 'approved');

    Carbon::setTestNow('2031-04-02');

    expect($this->register->blockedReason())->toBeNull();

    $filing = $this->register->prepareNext();

    expect($filing->payroll_run_id)->toBe($run->id)
        ->and($filing->period_label)->toBe('March 2031')
        ->and($filing->due_on->toDateString())->toBe('2031-04-14')
        ->and($filing->total_minor)->toBe(48_750_00);
});

it('names the run waiting for approval rather than blaming a card it was not calculated on', function () {
    aMarchRun(aTaxYearTurnsOver(), 'calculated');

    Carbon::setTestNow('2031-04-02');

    // The March run is on March's verified card, so it CAN be approved: "no run can be approved yet" would be false.
    expect($this->register->blockedReason())
        ->not->toContain('no run can be approved yet')
        ->toContain('March 2031');

    expect(fn () => $this->register->prepareNext())->toThrow(DomainException::class);
});

it('lets only a role holding create prepare a return', function () {
    approvedRunWithSlips();

    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);
    $opsManager = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);

    $this->withoutVite();

    $this->actingAs($opsManager)->post('/payroll/filings')->assertForbidden();

    $this->actingAs($opsManager)
        ->get('/payroll/filings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canPrepare', false));

    $this->actingAs($accountant)->post('/payroll/filings')->assertRedirect();

    expect(StatutoryFiling::query()->whereDate('period_start', '2030-05-01')->value('total_minor'))->toBe(97_500_00);
});
