<?php

declare(strict_types=1);

use App\Models\Estate\Employee;
use App\Models\Estate\Journal;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\StatutoryFiling;
use App\Models\StatutoryRateVersion;
use App\Models\User;
use App\Services\Estate\Ledger;
use App\Services\Estate\Payroll;
use App\Services\Payroll\PayrollFileFormats;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The estate's own payroll, traced to the lines it is made of
|--------------------------------------------------------------------------
|
| The money-module rule, on the third set of books: a screen figure is not
| proven by rendering, it is proven by equalling the sum of specific posted
| journal lines. Every total board 15 draws is summed here in raw SQL written
| out longhand, so the expectation and the application reach the same number by
| two different routes.
|
| THE PAYE COLUMN IS THE ONE FIGURE THIS FILE DELIBERATELY DOES NOT MATCH TO
| ITS BOARD, and the client has now ruled why (Q-002, D-082). Board 15 draws
| PAYE as 25% of gross-less-all-three-deductions, charged on the whole once TAJ's
| FORTNIGHTLY threshold is passed. The ruling: 25% of the amount above the
| MONTHLY threshold, a band on the excess. Board 15 would withhold J$85,392 a
| month where the ruling asks J$5,230. `it computes PAYE on statutory income
| above the threshold, not on the whole` stops anybody changing it back.
|
| EMPLOYER CONTRIBUTIONS ARE POSTED AND REMITTED (D-083), so every tie below
| carries them: 5010 holds the employer's share, 2100 holds both halves, and the
| S01 remits both.
|
| APPROVAL IS OPEN, AND ITS FIRST USE ASKS FOR A TICK. One test here approves a
| fresh run on the verified 2026-04 card — refused without the first-live-run
| acknowledgement, paid in four balanced lines with it.
|
| EVERY PERSON THIS FILE READS IS ONE IT CREATED (M6 3.1). The lookups used to be
| "the first Treasurer in the central database" and "the first user anywhere who
| can approve" — over `gs_platform_test`, which the whole run shares and which no
| suite here rolls back. Which person came back was therefore decided by which
| suites had run first. They are now scoped to this file's own three fixture
| people, still found by PERMISSION rather than by title, through
| `payrollFixtureUser()`.
|
| NO TEST HERE IS "LAST ON PURPOSE" ANY MORE, and two comments used to say one
| was. The ties are kept honest by the per-test transaction below, not by
| declaration order, which `phpunit.xml` shuffles. A comment claiming an order
| that is no longer enforced is worse than no comment: it is the reason the
| "never asked again" assertion below sat in the wrong test for so long, reading
| a paid-and-acknowledged run that the test above it had left behind.
|
*/

/**
 * The estate these tests read, built once per process, in a database of its own.
 *
 * Built by the same seeder that builds Phoenix Park, because what is being
 * proven is that the seeder's arithmetic and the ledger's agree.
 */
function payrollEstate(): string
{
    static $built = false;

    // The configured prefix, never the literal: see `FacilitiesFixture::database()`.
    $database = config('tenancy.database.prefix').'payrolltest';

    /*
     * ALREADY POINTED AT IT? RETURN, AND DO NOT PURGE. `DB::purge` disconnects, and a disconnect
     * throws away the per-test transaction opened in `beforeEach` below along with everything the
     * test has written inside it.
     */
    if (config('database.default') === 'tenant' && config('database.connections.tenant.database') === $database) {
        return $database;
    }

    config([
        'database.connections.tenant' => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        ),
        'database.default' => 'tenant',
    ]);

    DB::purge('tenant');

    if ($built) {
        return $database;
    }

    $owner = DB::connection('mysql_owner');
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::purge('tenant');

    /*
     * The rate card is CENTRAL. The Jamaica-wide TAJ cards are not a fact about
     * one estate — the tenant migration deliberately does not duplicate the
     * table — so a payroll run points at a row in `gs_platform`.
     *
     * Seeded from the real seeder, and ALWAYS rather than only when the table is
     * empty: it is idempotent, and a test database left holding the provisional
     * card from before the ruling would otherwise quietly compute every payslip
     * here on the wrong threshold.
     */
    Artisan::call('db:seed', [
        '--class' => StatutoryRatesSeeder::class,
        '--force' => true,
    ]);

    payrollRoles();

    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--database' => 'tenant',
        '--force' => true,
    ]);

    Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);

    $built = true;

    return $database;
}

/**
 * The estate roles, and one signed-up person holding each one this file needs.
 *
 * SEEDED HERE RATHER THAN ASSUMED, because the central test database is not
 * this suite's to rely on. `AuthenticationTest` uses `RefreshDatabase`, which
 * migrates `gs_platform_test` fresh and leaves it empty for everything that runs
 * after it — so a payroll test that looked for "whoever is already the
 * Treasurer" passed on its own and failed in the full suite, which is the worst
 * shape a test can have. Nothing here depends on demo data existing.
 */
function payrollRoles(): void
{
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (payrollFixtureRoles() as $role) {
        $email = payrollFixtureEmail($role);

        $user = User::on('mysql')->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => 'Payroll fixture — '.$role,
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'status' => 'active',
        ])->save();

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * The three estate roles this file needs somebody to be holding.
 *
 * @return list<string>
 */
function payrollFixtureRoles(): array
{
    return ['estate.community_super_admin', 'estate.property_manager', 'estate.treasurer'];
}

function payrollFixtureEmail(string $role): string
{
    return str_replace('.', '-', $role).'@payrolltest.local';
}

/**
 * The person THIS FILE created to hold one role — never whoever else is in the
 * central database.
 *
 * M6 §3.1. Every lookup in this file used to be "the first user with the
 * Treasurer role" or "the first user who can approve", over the whole of
 * `gs_platform_test` — a database every other suite in the run also writes to,
 * and one that no suite here rolls back. Which person came back was therefore
 * decided by which suites had run first, and the day one of them seeds a
 * treasurer with a lower id these tests start exercising a stranger. Scoping the
 * lookup to the three rows this file seeds itself is the same repair as scoping
 * a count to the rows a test created: it is the only version that cannot be
 * changed by somebody else's fixture.
 *
 * IT ALSO RE-SEEDS RATHER THAN FAILING. `payrollRoles()` runs once per process,
 * when the estate is built. A suite using `RefreshDatabase` rebuilds the central
 * database from scratch, so a person seeded before it ran is simply gone; the
 * honest answer is to put the fixture back and carry on, not to report a payroll
 * defect that is really a missing row.
 */
function payrollFixtureUser(string $role): User
{
    $email = payrollFixtureEmail($role);

    $user = User::on('mysql')->where('email', $email)->first();

    if ($user === null) {
        payrollRoles();

        $user = User::on('mysql')->where('email', $email)->first();
    }

    expect($user)->not->toBeNull(
        "This file's own fixture user for {$role} ({$email}) could not be seeded into the central ".
        'database. Check RbacMatrixSeeder still defines that role.'
    );

    return $user;
}

/**
 * What one account holds, summed in raw SQL from the lines themselves.
 *
 * Written out longhand and signed by account type: an expense is debits less
 * credits, a liability is credits less debits, an asset is debits less credits.
 * This is the second, independent route to every payroll figure the screens
 * show, and it must not share an implementation with the first.
 */
function payrollAccountMinor(string $code, bool $creditNormal = false): int
{
    $sql = $creditNormal
        ? 'SUM(l.credit_minor - l.debit_minor)'
        : 'SUM(l.debit_minor - l.credit_minor)';

    return (int) DB::connection('tenant')->selectOne("
        SELECT COALESCE({$sql}, 0) AS total
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ", [$code])->total;
}

/** Every payslip figure in one run, summed straight out of the lines table. */
function payrollRunTotals(string $slug): object
{
    return DB::connection('tenant')->selectOne('
        SELECT COUNT(*) AS staff,
               COALESCE(SUM(l.gross_minor), 0) AS gross,
               COALESCE(SUM(l.nis_minor), 0) AS nis,
               COALESCE(SUM(l.nht_minor), 0) AS nht,
               COALESCE(SUM(l.education_tax_minor), 0) AS edu,
               COALESCE(SUM(l.paye_minor), 0) AS paye,
               COALESCE(SUM(l.net_minor), 0) AS net,
               COALESCE(SUM(l.employer_nis_minor + l.employer_nht_minor
                          + l.employer_education_tax_minor + l.employer_heart_minor), 0) AS employer
          FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE r.slug = ?
    ', [$slug]);
}

/**
 * Somebody who can actually approve a run, found by permission not by title.
 *
 * NOT HARDCODED TO A ROLE NAME, and that is the point. The estate matrix gives
 * Payroll Full · Approver to exactly one role, and which one it is is a ruling
 * that has already moved once — board 24 draws the row with no Approver tag at
 * all, which made board 15's own approval flow unreachable. A test that named
 * the role would have to be edited every time the matrix is corrected, and a
 * test that has to be edited to keep passing is a test nobody trusts.
 */
function payrollApprover(): User
{
    /*
     * ON THE CENTRAL CONNECTION EXPLICITLY. This fixture points
     * `database.default` at the estate under test, and users, roles and
     * permissions all live in `gs_platform` — pinned there by D-012 precisely so
     * an estate cannot hold its own copy of who may do what. Reading them off
     * the default connection passes on its own and fails in the full suite,
     * where whichever estate fixture ran last decides what "default" means.
     *
     * AND OVER THIS FILE'S OWN THREE PEOPLE (M6 §3.1), not over every user in a
     * central database the whole run shares. Still by permission and not by
     * title — the point above stands — but among rows this file seeded, so no
     * other suite's fixture can decide who these tests approve a pay run as.
     */
    $user = collect(payrollFixtureRoles())
        ->map(fn (string $role): User => payrollFixtureUser($role))
        ->first(fn (User $candidate): bool => $candidate->can('estate.payroll.approve_runs'));

    expect($user)->not->toBeNull(
        'No role on this platform can approve a pay run. Board 15 draws an approval flow, so the '.
        'Payroll row of the matrix needs a Full · Approver cell — see RbacMatrixSeeder.'
    );

    return $user;
}

/**
 * EVERY TIE BELOW READS THE LEDGER AS THE SEEDER LEFT IT, whatever order the tests run in.
 *
 * M4 §4 defect 3, fixed at the place that caused it. The last test in this file approves and PAYS
 * a fresh run, which posts to 2100 and 5010 — the very accounts the ties above sum. It used to be
 * declared last for that reason, and a header comment said so. `phpunit.xml` now runs the suite in
 * random order, so declaration order guarantees nothing: on seed 4242 the statutory payable came
 * back J$91,371.01 short of what the paid runs withheld, because the remittance test had already
 * run.
 *
 * Every test now runs inside a transaction on the estate connection and none of it is kept. There
 * is no HTTP request and no `$tenant->run()` anywhere in this file, so there is no second
 * connection to this database that could miss the transaction.
 */
beforeEach(function () {
    payrollEstate();

    DB::connection('tenant')->beginTransaction();
});

afterEach(function () {
    DB::connection('tenant')->rollBack();
});

/*
 * AND THE WRAPPER ABOVE IS ITSELF UNDER TEST (M4 §4, defect 3).
 *
 * Every tie in this file is against the ledger as the seeder left it, and what keeps it
 * that way is one `beginTransaction` that no individual test mentions. Take it out and the
 * approve-and-pay test posts to 2100 and 5010 for good: the ties then pass or fail on which
 * test ran first, and the failure surfaces as a J$91,371.01 discrepancy three tests away
 * with nothing pointing back here. So its absence is a failure of its own, stated plainly.
 */
it('runs every test in this file inside a transaction on the estate, so nothing it writes outlives it', function () {
    expect(DB::connection('tenant')->transactionLevel())
        ->toBeGreaterThan(0, 'The per-test transaction in beforeEach is gone. Without it a run approved and paid by one test is still paid when the next one sums 2100, and every tie in this file becomes order-dependent.')
        ->and(config('database.connections.tenant.database'))
        ->toBe(payrollEstate(), 'This file must read its own estate, not whichever fixture booted last.');
});

/* ------------------------------------------------------------------ */
/* the arithmetic every payslip has to hold */
/* ------------------------------------------------------------------ */

it('leaves every payslip adding up to its own net', function () {
    $lines = DB::connection('tenant')->select('
        SELECT l.*, e.full_name
          FROM payroll_run_lines l
          JOIN employees e ON e.id = l.employee_id
    ');

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        $deductions = $line->nis_minor + $line->nht_minor + $line->education_tax_minor + $line->paye_minor;

        expect($line->gross_minor - $deductions)
            ->toBe($line->net_minor, $line->full_name.'\'s payslip does not add up to its own net.');
    }
});

it('computes PAYE on statutory income above the threshold, not on the whole', function () {
    /*
     * The one assertion that stops board 15's own arithmetic being copied back
     * in. Patricia Morgan is the only employee on this payroll who pays any
     * PAYE at all: her statutory income — gross less NIS, and NOT less NHT and
     * Education Tax — is the only one above the monthly threshold.
     */
    $morgan = DB::connection('tenant')->selectOne('
        SELECT l.*, r.statutory_rate_version_id FROM payroll_run_lines l
          JOIN employees e ON e.id = l.employee_id
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE e.full_name = ? AND r.slug = ?
    ', ['Patricia Morgan', 'aug-2026']);

    // The card the run was actually calculated on, re-fetched centrally — and it
    // must be the 2026-04 card, because August's pay date falls after 1 April.
    $rates = StatutoryRateVersion::on('mysql')->findOrFail($morgan->statutory_rate_version_id);

    expect($rates->effective_from->format('Y-m'))->toBe('2026-04');

    $statutoryIncome = $morgan->gross_minor - $morgan->nis_minor;

    // TAJ's PUBLISHED monthly threshold — never the annual figure divided.
    $threshold = $rates->thresholdPerPeriod(Payroll::PERIODS_PER_YEAR);

    expect($threshold)->toBe(158_530_00);
    $expected = intdiv(max(0, $statutoryIncome - $threshold) * $rates->paye_bp + 5_000, 10_000);

    expect($morgan->paye_minor)->toBe($expected);

    // And the shape board 15 draws — 25% of gross less all three deductions,
    // charged on the whole — is NOT what came out. If this ever passes, the
    // calculation has been changed to match the picture.
    $boardsFormula = intdiv(
        ($morgan->gross_minor - $morgan->nis_minor - $morgan->nht_minor - $morgan->education_tax_minor)
        * $rates->paye_bp + 5_000,
        10_000,
    );

    expect($morgan->paye_minor)->not->toBe($boardsFormula);
});

it('pays nobody for a month before they were employed', function () {
    $thomas = Employee::query()->where('full_name', 'Wayne Thomas')->firstOrFail();

    expect($thomas->employed_since->format('Y-m'))->toBe('2026-06');

    $may = payrollRunTotals('may-2026');
    $june = payrollRunTotals('jun-2026');

    expect((int) $may->staff)->toBe(3)
        ->and((int) $june->staff)->toBe(4);

    // Board 13's own variance: the two months differ by exactly one person's
    // gross and one person's net, not by a rounding.
    $line = DB::connection('tenant')->selectOne('
        SELECT l.* FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE l.employee_id = ? AND r.slug = ?
    ', [$thomas->id, 'jun-2026']);

    expect((int) $june->gross - (int) $may->gross)->toBe($line->gross_minor)
        ->and((int) $june->net - (int) $may->net)->toBe($line->net_minor);
});

/* ------------------------------------------------------------------ */
/* every figure board 15 draws, traced to the lines it is made of */
/* ------------------------------------------------------------------ */

it('draws board 15 from the payslips rather than from its own header', function () {
    $run = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();
    $board = app(Payroll::class)->runBoard($run);
    $totals = payrollRunTotals('aug-2026');

    $box = fn (string $key) => collect($board['summary'])->firstWhere('key', $key);

    expect($box('employees')['value'])->toBe((string) $totals->staff)
        ->and($box('gross')['value_minor'])->toBe((int) $totals->gross)
        ->and($box('net')['value_minor'])->toBe((int) $totals->net)
        ->and($box('deductions')['value_minor'])
        ->toBe((int) $totals->nis + (int) $totals->nht + (int) $totals->edu + (int) $totals->paye);

    // And the run header agrees with its own lines, which is what makes board
    // 13's Gross and Net columns the same figures as board 15's summary boxes.
    expect($run->gross_minor)->toBe((int) $totals->gross)
        ->and($run->net_minor)->toBe((int) $totals->net);
});

it('ties the payroll expense account to the gross of every run it has paid', function () {
    $paid = DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.gross_minor), 0) AS gross,
               COALESCE(SUM(l.net_minor), 0) AS net
          FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE r.status = ?
    ', [PayrollRun::PAID]);

    expect(payrollAccountMinor(Payroll::EXPENSE))->toBe((int) $paid->gross);
});

it('ties the statutory payable to what every paid run withheld and contributed, and nothing else', function () {
    $paid = DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.gross_minor - l.net_minor), 0) AS withheld,
               COALESCE(SUM(l.employer_nis_minor + l.employer_nht_minor
                          + l.employer_education_tax_minor + l.employer_heart_minor), 0) AS employer
          FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE r.status = ?
    ', [PayrollRun::PAID]);

    /*
     * The control account, read from its own posted lines, against the payslips
     * that produced it. The liability the estate reports is exactly what it took
     * off four people PLUS what it owes on top as their employer (D-083) — both
     * halves the S01 remits — and nothing has drifted between them.
     */
    $owed = (int) $paid->withheld + (int) $paid->employer;

    expect((int) $paid->employer)->toBeGreaterThan(0)
        ->and(payrollAccountMinor(Payroll::STATUTORY_PAYABLE, creditNormal: true))->toBe($owed)
        ->and(app(Payroll::class)->outstandingStatutoryMinor())->toBe($owed);
});

it('ties employer contributions expense to what every paid run owed as employer', function () {
    $paid = DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.employer_nis_minor + l.employer_nht_minor
                          + l.employer_education_tax_minor + l.employer_heart_minor), 0) AS employer
          FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE r.status = ?
    ', [PayrollRun::PAID]);

    // D-072 found three employer rates nothing read. D-083 posts them: 5010 is
    // the estate's real cost of employment beyond gross, to the cent.
    expect(payrollAccountMinor(Payroll::EMPLOYER_CONTRIBUTIONS))->toBe((int) $paid->employer);
});

it('posts one balanced entry per paid run and nothing outside it', function () {
    $runs = PayrollRun::query()->where('status', PayrollRun::PAID)->get();

    expect($runs)->not->toBeEmpty();

    foreach ($runs as $run) {
        expect($run->journal_ref)->not->toBeNull();

        $lines = DB::connection('tenant')->select('
            SELECT a.code, l.debit_minor, l.credit_minor
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE l.entry_ref = ?
        ', [$run->journal_ref]);

        // Four lines: gross, the employer's share, the payable for both halves,
        // and net pay out of the bank (D-083).
        expect($lines)->toHaveCount(4);

        $debits = array_sum(array_map(static fn ($l) => $l->debit_minor, $lines));
        $credits = array_sum(array_map(static fn ($l) => $l->credit_minor, $lines));

        expect($debits)->toBe($credits, $run->period_label.' does not balance.');

        $by = [];

        foreach ($lines as $line) {
            $by[$line->code] = $line;
        }

        $totals = payrollRunTotals($run->slug);

        expect($by[Payroll::EXPENSE]->debit_minor)->toBe((int) $totals->gross)
            ->and($by[Payroll::EMPLOYER_CONTRIBUTIONS]->debit_minor)->toBe((int) $totals->employer)
            ->and($by[Payroll::BANK]->credit_minor)->toBe((int) $totals->net)
            ->and($by[Payroll::STATUTORY_PAYABLE]->credit_minor)
            ->toBe((int) $totals->gross - (int) $totals->net + (int) $totals->employer);
    }
});

it('remits on the S01 exactly what the run it names withheld', function () {
    $filing = StatutoryFiling::query()
        ->where('form_code', StatutoryFiling::S01)
        ->where('period_label', 'August 2026')
        ->firstOrFail();

    $totals = payrollRunTotals('aug-2026');

    expect($filing->nis_minor)->toBe((int) $totals->nis)
        ->and($filing->nht_minor)->toBe((int) $totals->nht)
        ->and($filing->education_tax_minor)->toBe((int) $totals->edu)
        ->and($filing->paye_minor)->toBe((int) $totals->paye)
        ->and($filing->deductionsMinor())->toBe((int) $totals->gross - (int) $totals->net);

    // And the employer's share on the same return — the ruling's own list, PAYE,
    // NIS, NHT, Education Tax AND HEART — so filing clears 2100 to nil (D-083).
    expect($filing->employerContributionsMinor())->toBe((int) $totals->employer)
        ->and((int) $filing->heart_minor)->toBeGreaterThan(0)
        ->and($filing->remittanceMinor())
        ->toBe((int) $totals->gross - (int) $totals->net + (int) $totals->employer)
        ->and((int) $filing->total_minor)->toBe($filing->remittanceMinor());
});

/* ------------------------------------------------------------------ */
/* the refusals, each proven rather than assumed */
/* ------------------------------------------------------------------ */

it('refuses to approve a run calculated on a card with no verified TAJ figures', function () {
    /*
     * THIS TEST USED TO ASSERT THAT EVERY RUN WAS BLOCKED, and it was written to
     * fail the day the rates were verified — which it did, when Q-002 was ruled.
     *
     * The block survives for the one case it still guards. The 2027-04 card was
     * ruled on its annual threshold alone, with no TAJ periodic figures, so it is
     * seeded unverified; a run calculated on it must stop at `calculated` with a
     * sentence that says what is missing. Built in memory, so nothing here moves
     * the estate every other test in this file reads.
     */
    $unverified = StatutoryRateVersion::on('mysql')
        ->whereNull('superseded_at')
        ->where('is_verified', false)
        ->orderByDesc('effective_from')
        ->firstOrFail();

    $run = new PayrollRun([
        'status' => PayrollRun::CALCULATED,
        'statutory_rate_version_id' => $unverified->id,
        'period_label' => 'April 2027',
    ]);

    $approver = payrollApprover();

    // Before the card takes effect its date stops the run first (D-234).
    $this->travelTo(Carbon::parse('2026-10-12 15:00', 'UTC'));

    expect(app(Payroll::class)->approvalRefusal($run, $approver))->toStartWith('This rate card takes effect on Apr 1, 2027');

    // Once it has, what stops it is its missing figures. M78 §2: one line (it went on to say how to record and verify the card).
    $this->travelTo(Carbon::parse('2027-05-01 15:00', 'UTC'));

    expect(app(Payroll::class)->approvalRefusal($run, $approver))->toEndWith('rate card has no verified TAJ figures yet, so this run cannot be approved.')
        ->and(app(Payroll::class)->mayApprove($run, $approver))->toBeFalse();
});

it('refuses to let whoever calculated a run also approve it, and never counts having started it (D-233)', function () {
    $run = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();

    /*
     * The second-person rule is only reachable by somebody who HOLDS the
     * approval, and that is the whole point of it: the estate's own seeded
     * preparer is the Treasurer, who has Payroll Full and no Approver tag, so
     * they are already refused on permission alone. Stacking a second reason on
     * a person who is blocked anyway would prove nothing.
     *
     * The case that matters is the administrator who calculates a run and then
     * approves it: one person, both ends, nobody checking. So the approver is
     * made the calculator here and the refusal must still stand. Starting the
     * run is not that act (Hassam, 12 Oct 2026: only the final calculation
     * disqualifies anyone), so the same approver made only its starter is not
     * refused for it.
     */
    $approver = payrollApprover();
    $original = $run->only(['prepared_by', 'prepared_by_name', 'calculated_by', 'calculated_by_name']);

    $run->forceFill(['calculated_by' => $approver->getKey(), 'calculated_by_name' => $approver->name])->save();

    try {
        $refusal = app(Payroll::class)->approvalRefusal($run->refresh(), $approver);

        // One line (M53 Law 2).
        expect($refusal)->toBe('You calculated this run, so you cannot approve it.')
            ->and(app(Payroll::class)->mayApprove($run, $approver))->toBeFalse();

        expect(fn () => app(Payroll::class)->approve($run, $approver))
            ->toThrow(DomainException::class);

        // And it is checked BEFORE the draft-rates block, because the two
        // refusals are different sentences and a screen prints the one that
        // applies. Being told the rates are draft, when the real problem is
        // that you are approving your own work, sends somebody to the wrong
        // person.
        expect($refusal)->not->toContain('still marked draft');

        // Started it, calculated by nobody on record: whatever else holds the run, never a person bar.
        $run->forceFill(['prepared_by' => $approver->getKey(), 'prepared_by_name' => $approver->name, 'calculated_by' => null, 'calculated_by_name' => null])->save();

        expect(str_contains((string) app(Payroll::class)->approvalRefusal($run->refresh(), $approver), 'so you cannot approve it'))->toBeFalse();
    } finally {
        $run->forceFill($original)->save();
    }

    expect($run->refresh()->status)->toBe(PayrollRun::CALCULATED);
});

it('refuses to approve a run for a role that does not hold payroll approval', function () {
    $run = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();

    $manager = payrollFixtureUser('estate.property_manager');

    // M78 §2: one line naming the permission (it went on "…which this role does not hold").
    expect(app(Payroll::class)->approvalRefusal($run, $manager))
        ->toBe('Approving a pay run needs the Approve pay runs permission on Payroll & HR.')
        ->and(app(Payroll::class)->mayApprove($run, $manager))->toBeFalse();
});

it('refuses to calculate a run while an exception is unresolved', function () {
    $run = PayrollRun::query()->where('slug', 'sep-2026')->firstOrFail();

    expect($run->hasUnresolvedExceptions())->toBeTrue()
        ->and($run->lines()->count())->toBe(0);

    expect(fn () => app(Payroll::class)->calculate($run))
        ->toThrow(DomainException::class);

    // Still nothing calculated, so nothing could have been paid.
    expect($run->refresh()->lines()->count())->toBe(0);
});

it('refuses to recalculate or re-approve a run that has already been paid', function () {
    $run = PayrollRun::query()->where('slug', 'jul-2026')->firstOrFail();

    expect($run->isPosted())->toBeTrue();

    expect(fn () => app(Payroll::class)->calculate($run))->toThrow(DomainException::class);

    $approver = payrollApprover();

    expect(app(Payroll::class)->approvalRefusal($run, $approver))->toContain('already been approved');
});

it('refuses to file a return twice', function () {
    $filed = StatutoryFiling::query()->where('status', StatutoryFiling::FILED)->firstOrFail();
    $approver = payrollApprover();

    expect(fn () => app(Payroll::class)->fileReturn($filed, $approver))
        ->toThrow(DomainException::class);
});

/*
 * TWO REQUESTS, ONE RUN (bug hunt B020, B075). A double-click on Approve, or two
 * approvers in two tabs, loads the run twice while it is still `calculated`. Each
 * copy passes the refusals it was loaded with; what must stop the second is the
 * row as it stands when the second one reaches it. Without that the salaries,
 * 2100 and the bank were all posted twice, and the ledger cannot unpost either.
 */
it('refuses to approve a run a second time from a copy loaded before the first approval posted', function () {
    $approver = payrollApprover();
    $payroll = app(Payroll::class);

    $first = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();
    $second = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();

    $payroll->approve($first, $approver, reconciled: true);

    expect(fn () => $payroll->approve($second, $approver, reconciled: true))
        ->toThrow(DomainException::class, 'already been approved');

    expect(Journal::query()->where('source', Ledger::SOURCE_PAYROLL)->where('source_id', $first->id)->count())->toBe(1);
});

it('refuses to file a return a second time from a copy loaded before the first filing posted', function () {
    $approver = payrollApprover();
    $payroll = app(Payroll::class);

    $payroll->approve(PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail(), $approver, reconciled: true);

    $first = StatutoryFiling::query()->where('form_code', StatutoryFiling::S01)->where('period_label', 'August 2026')->firstOrFail();
    $second = StatutoryFiling::query()->whereKey($first->id)->firstOrFail();

    $payroll->fileReturn($first, $approver);

    expect(fn () => $payroll->fileReturn($second, $approver))
        ->toThrow(DomainException::class, 'already been filed');

    expect(Journal::query()->where('source', Ledger::SOURCE_PAYROLL_REMITTANCE)->where('source_id', $first->id)->count())->toBe(1);
});

it('refuses to send back or recalculate a run from a copy loaded before it was paid', function () {
    /*
     * The same stale copy, on the two acts that come before approval. Sending a
     * paid run back marked it `exceptions`; recalculating it then deleted the
     * payslips of a run whose money had already left the bank, and left it open
     * to be approved — and posted — again.
     */
    $approver = payrollApprover();
    $payroll = app(Payroll::class);

    $stale = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();
    $staleAgain = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();

    $paid = $payroll->approve(PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail(), $approver, reconciled: true);
    $payslips = $paid->lines()->count();

    expect(fn () => $payroll->requestChanges($stale, 'PAYE looks high on one payslip.', $approver))
        ->toThrow(DomainException::class, 'already been paid');

    expect(fn () => $payroll->calculate($staleAgain))
        ->toThrow(DomainException::class, 'already been paid');

    $after = PayrollRun::query()->findOrFail($paid->id);

    expect($after->status)->toBe(PayrollRun::PAID)
        ->and($after->lines()->count())->toBe($payslips)
        ->and($after->journal_ref)->toBe($paid->journal_ref);
});

/*
 * THE SECOND PERSON IS SECOND TO THE FIGURES, NOT TO THE DRAFT (bug hunt B025),
 * AND TO THE FINAL CALCULATION ALONE (Hassam, 12 Oct 2026; D-233). Starting a run
 * creates an empty draft. The control once looked only at who started it, so the
 * administrator could accept 22 overtime hours, calculate, and release the pay
 * alone while the banner still named the Treasurer. Whoever calculated it last
 * may not approve it. Accepting the overtime is a judgement the approver is shown
 * on the run's page, and it bars nobody: counting it locked a committee of three
 * out of payroll ("You resolved an exception on this run").
 */
it('refuses approval by whoever calculated the run last, and counts no other act against anybody (D-233)', function () {
    $treasurer = payrollFixtureUser('estate.treasurer');
    $admin = payrollApprover();
    $payroll = app(Payroll::class);

    $run = PayrollRun::query()->where('slug', 'sep-2026')->firstOrFail();
    $run->forceFill(['prepared_by' => $treasurer->getKey(), 'prepared_by_name' => $treasurer->name])->save();

    foreach ($run->exceptions()->get() as $exception) {
        $payroll->resolveException($exception, PayrollException::RESOLVED, 'Accepted as logged.', $admin);
    }

    // The administrator resolved the exceptions and calculated: both ends are theirs.
    $payroll->calculate($run->refresh(), $admin);

    expect($payroll->approvalRefusal($run->refresh(), $admin))->toBeString()->toContain('calculated this run')
        ->and(fn () => $payroll->approve($run, $admin, reconciled: true))->toThrow(DomainException::class, 'calculated this run')
        ->and($payroll->runBoard($run, $admin)['run']['calculated_by'])->toBe($admin->name);

    // The Treasurer recalculates it. The final calculation is the Treasurer's now, so the administrator, who accepted
    // the overtime, is not barred: they read what they decided on the run's page, as any approver does.
    $payroll->calculate($run->refresh(), $treasurer);

    $decisions = $payroll->runBoard($run->refresh(), $admin)['decisions'];

    expect($payroll->approvalRefusal($run, $admin))->toBeNull()
        ->and($payroll->mayApprove($run, $admin))->toBeTrue()
        ->and(collect($decisions)->contains(fn (string $line): bool => str_starts_with($line, $admin->name.' accepted Wayne Thomas\'s overtime as logged')))->toBeTrue();

    expect($run->refresh()->status)->toBe(PayrollRun::CALCULATED)
        ->and($run->journal_ref)->toBeNull();
});

it('lets the second person approve a run that somebody else resolved and calculated', function () {
    $treasurer = payrollFixtureUser('estate.treasurer');
    $admin = payrollApprover();
    $payroll = app(Payroll::class);

    $run = PayrollRun::query()->where('slug', 'sep-2026')->firstOrFail();
    $run->forceFill(['prepared_by' => $treasurer->getKey(), 'prepared_by_name' => $treasurer->name])->save();

    foreach ($run->exceptions()->get() as $exception) {
        $payroll->resolveException($exception, PayrollException::RESOLVED, 'Accepted as logged.', $treasurer);
    }

    $payroll->calculate($run->refresh(), $treasurer);

    expect($payroll->approvalRefusal($run->refresh(), $admin))->toBeNull();
});

/*
 * ACCEPTED OVERTIME IS PAID (EST-B-07, M85 A2). Wayne Thomas's 22 overtime hours were accepted on the September run
 * and his gross came out 88,000.00, the same as August: the calculation took the monthly rate and ignored the hours.
 * "A payroll system that accepts overtime and then does not pay it is worse than one that refuses it."
 */
it('pays accepted overtime: the monthly rate plus the accepted hours at time and a half (EST-B-07, M85 A2)', function () {
    $treasurer = payrollFixtureUser('estate.treasurer');
    $payroll = app(Payroll::class);

    $run = PayrollRun::query()->where('slug', 'sep-2026')->firstOrFail();
    $thomas = Employee::query()->where('full_name', 'Wayne Thomas')->firstOrFail();
    $overtime = $run->exceptions()->where('type', PayrollException::OVERTIME_ANOMALY)->where('employee_id', $thomas->id)->firstOrFail();

    // The seeded row carries the 22 hours its own words state, as a number the calculation can read.
    expect($overtime->overtime_hours)->toBe(22);

    foreach ($run->exceptions()->get() as $exception) {
        $payroll->resolveException($exception, PayrollException::RESOLVED, 'Accepted as logged.', $treasurer);
    }

    $payroll->calculate($run->refresh(), $treasurer);

    // 88,000.00 a month is 507.69 an hour on a 40-hour week (2,080 hours a year); time and a half is 761.54;
    // 22 hours of it is 16,753.88.
    $line = $run->lines()->where('employee_id', $thomas->id)->firstOrFail();

    expect($line->gross_minor)->toBe(88_000_00 + 16_753_88)
        ->and($run->refresh()->gross_minor)->toBe((int) $run->lines()->sum('gross_minor'));
});

it('pays no overtime for an exception left out of the run or for hours nobody accepted', function () {
    $treasurer = payrollFixtureUser('estate.treasurer');
    $payroll = app(Payroll::class);

    $run = PayrollRun::query()->where('slug', 'sep-2026')->firstOrFail();
    $thomas = Employee::query()->where('full_name', 'Wayne Thomas')->firstOrFail();

    foreach ($run->exceptions()->get() as $exception) {
        // Excluding the overtime row leaves Wayne Thomas out of the run altogether (board 14's rule), never paid for it.
        $payroll->resolveException($exception, $exception->employee_id === $thomas->id ? PayrollException::EXCLUDED : PayrollException::RESOLVED, 'Decided.', $treasurer);
    }

    $payroll->calculate($run->refresh(), $treasurer);

    expect($run->lines()->where('employee_id', $thomas->id)->exists())->toBeFalse();
});

/*
 * THE RETURN GOES ON THE REGISTER WHEN THE MONEY IS WITHHELD (bug hunt B026).
 * Approving a run credits 2100 with the month's deductions, and nothing ever put
 * that month's S01 where it could be filed — so 2100 could never be cleared.
 */
it('puts the period\'s S01 and S02 on the register when a run is approved, carrying the run\'s own figures', function () {
    $treasurer = payrollFixtureUser('estate.treasurer');
    $approver = payrollApprover();
    $payroll = app(Payroll::class);

    $run = PayrollRun::create([
        'reference' => 'PR-OCT2026',
        'slug' => 'oct-2026',
        'period_label' => 'October 2026',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'periods_per_year' => Payroll::PERIODS_PER_YEAR,
        'statutory_rate_version_id' => StatutoryRateVersion::forPayDate('2026-10-31')->id,
        'currency' => 'JMD',
        'prepared_by' => $treasurer->getKey(),
        'prepared_by_name' => $treasurer->name,
    ]);

    $payroll->calculate($run);

    expect(StatutoryFiling::query()->where('period_label', 'October 2026')->exists())->toBeFalse();

    $payroll->approve($run->refresh(), $approver, reconciled: true);

    $totals = payrollRunTotals('oct-2026');

    $s01 = StatutoryFiling::query()->where('form_code', StatutoryFiling::S01)->where('period_label', 'October 2026')->first();
    $s02 = StatutoryFiling::query()->where('form_code', StatutoryFiling::S02)->where('period_label', 'October 2026')->first();

    expect($s01)->not->toBeNull()
        ->and($s01->payroll_run_id)->toBe($run->id)
        ->and($s01->status)->not->toBe(StatutoryFiling::FILED)
        ->and($s01->due_on->toDateString())->toBe('2026-11-14')
        ->and($s01->employees_covered)->toBe((int) $totals->staff)
        ->and($s01->nis_minor)->toBe((int) $totals->nis)
        ->and($s01->nht_minor)->toBe((int) $totals->nht)
        ->and($s01->education_tax_minor)->toBe((int) $totals->edu)
        ->and($s01->paye_minor)->toBe((int) $totals->paye)
        ->and($s01->employerContributionsMinor())->toBe((int) $totals->employer)
        ->and((int) $s01->total_minor)->toBe($s01->remittanceMinor())
        ->and($s02)->not->toBeNull()
        ->and($s02->payroll_run_id)->toBe($run->id)
        ->and($s02->remittanceMinor())->toBe(0);

    // And filing it clears exactly what approving the run credited to 2100.
    $before = $payroll->outstandingStatutoryMinor();

    $payroll->fileReturn($s01, $approver);

    expect($payroll->outstandingStatutoryMinor())->toBe($before - ((int) $totals->gross - (int) $totals->net + (int) $totals->employer));
});

it('refuses to file a return whose pay run has not been approved, because 2100 holds nothing for it', function () {
    $approver = payrollApprover();

    $august = StatutoryFiling::query()->where('form_code', StatutoryFiling::S01)->where('period_label', 'August 2026')->firstOrFail();

    expect(PayrollRun::query()->findOrFail($august->payroll_run_id)->status)->toBe(PayrollRun::CALCULATED);

    $before = app(Payroll::class)->outstandingStatutoryMinor();

    expect(fn () => app(Payroll::class)->fileReturn($august, $approver))
        ->toThrow(DomainException::class, 'not been approved');

    expect($august->refresh()->status)->not->toBe(StatutoryFiling::FILED)
        ->and(app(Payroll::class)->outstandingStatutoryMinor())->toBe($before);
});

/*
 * A PENSION THE PAYROLL COULD NEVER PAY (bug hunt B032). The register accepted
 * one up to the whole salary; the calculator refuses anything above gross less
 * NIS, so every later Calculate returned a 500 — and just below that, the payslip
 * came out with a negative net.
 */
it('refuses a pension no payslip can bear, and names the employee when a stored one cannot be calculated', function () {
    $treasurer = payrollFixtureUser('estate.treasurer');
    $payroll = app(Payroll::class);

    $person = [
        'full_name' => 'Delroy Simms', 'job_title' => 'Groundsman', 'employment_type' => 'full_time',
        'monthly_rate' => '88000.00', 'consent' => true,
    ];

    // The whole salary, and just under what is left after NIS: neither can be paid.
    foreach (['88000.00', '85000.00'] as $pension) {
        expect(fn () => $payroll->addEmployee([...$person, 'approved_pension' => $pension], $treasurer))
            ->toThrow(DomainException::class, 'pension');
    }

    expect(Employee::query()->where('full_name', 'Delroy Simms')->exists())->toBeFalse();

    // A modest one is fine.
    $payroll->addEmployee([...$person, 'approved_pension' => '4000.00'], $treasurer);

    expect(Employee::query()->where('full_name', 'Delroy Simms')->value('approved_pension_minor'))->toBe(4_000_00);

    // One already stored that no payslip can bear is named, not a 500.
    $simone = Employee::query()->where('full_name', 'Simone Clarke')->firstOrFail();
    $simone->forceFill(['approved_pension_minor' => $simone->monthly_rate_minor])->save();

    $run = PayrollRun::create([
        'reference' => 'PR-OCT2026',
        'slug' => 'oct-2026',
        'period_label' => 'October 2026',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'periods_per_year' => Payroll::PERIODS_PER_YEAR,
        'statutory_rate_version_id' => StatutoryRateVersion::forPayDate('2026-10-31')->id,
        'currency' => 'JMD',
        'prepared_by' => $treasurer->getKey(),
        'prepared_by_name' => $treasurer->name,
    ]);

    expect(fn () => $payroll->calculate($run))->toThrow(DomainException::class, 'Simone Clarke');

    expect($run->refresh()->status)->not->toBe(PayrollRun::CALCULATED)
        ->and($run->lines()->count())->toBe(0);
});

it('answers a rate with more than two decimals as a refusal, not as an arithmetic exception', function () {
    // B022, B081, at the service: the controller rule is the first line, this the second.
    $treasurer = payrollFixtureUser('estate.treasurer');

    expect(fn () => app(Payroll::class)->addEmployee([
        'full_name' => 'Delroy Simms', 'job_title' => 'Groundsman', 'employment_type' => 'full_time',
        'monthly_rate' => '185000.005', 'consent' => true,
    ], $treasurer))->toThrow(DomainException::class, 'dollars and cents');

    expect(fn () => app(Payroll::class)->addEmployee([
        'full_name' => 'Delroy Simms', 'job_title' => 'Groundsman', 'employment_type' => 'full_time',
        'monthly_rate' => '85000.00', 'approved_pension' => '0.001', 'consent' => true,
    ], $treasurer))->toThrow(DomainException::class, 'dollars and cents');
});

/*
 * THE FILINGS BOARD TELLS THE DATE (bug hunt B037). The badge was the stored
 * status, set once by the seeder, so ten days after the August S01 fell due the
 * board still said "Due soon" while the compliance calendar said "Overdue".
 */
it('labels each return on board 16 from its dates against the estate\'s today', function () {
    $payroll = app(Payroll::class);

    $label = fn (string $code, string $period): array => collect($payroll->filingsBoard()['rows'])
        ->first(fn (array $row): bool => $row['code'] === $code && str_starts_with($row['subtitle'], $period));

    /*
     * The words are the one vocabulary since M96 and M99 (11 Oct): Completed, Action required (with Overdue as a flag
     * beside it once late), Pending. They were Filed, Overdue, Due soon and Not started. Each date state is still
     * asserted, so the badge still follows the dates exactly as B037 asked.
     */

    // Ten days after the August S01 fell due.
    $this->travelTo(Carbon::parse('2026-09-24 15:00:00', 'UTC'));

    expect($label('S01', 'August 2026'))->toMatchArray(['state' => 'overdue', 'status_label' => 'Action required', 'flag' => 'Overdue', 'warn' => true])
        ->and($label('S01', 'July 2026'))->toMatchArray(['state' => 'filed', 'status_label' => 'Completed', 'flag' => null])
        ->and($label('P24', 'Tax year 2025'))->toMatchArray(['state' => 'not_started', 'status_label' => 'Pending', 'flag' => null]);

    // Four days before it fell due, with its period closed.
    $this->travelTo(Carbon::parse('2026-09-10 15:00:00', 'UTC'));

    expect($label('S01', 'August 2026'))->toMatchArray(['state' => 'due_soon', 'status_label' => 'Action required', 'flag' => null, 'warn' => true]);

    // On the evening of its due day in Kingston it is still due, not overdue.
    $this->travelTo(Carbon::parse('2026-09-15 01:30:00', 'UTC'));

    expect($label('S01', 'August 2026'))->toMatchArray(['state' => 'due_soon', 'status_label' => 'Action required', 'flag' => null]);
});

/*
 * THE ESTATE'S DAY, NOT UTC'S (bug hunt B023, B082). At 8:30 PM on Sep 30 in
 * Kingston it is already Oct 1 in UTC. A return filed that evening is filed on
 * the 30th, and an employee added then started on the 30th.
 */
it('dates a filing and a new employee on the estate\'s calendar in the evening', function () {
    $treasurer = payrollFixtureUser('estate.treasurer');
    $approver = payrollApprover();
    $payroll = app(Payroll::class);

    $this->travelTo(Carbon::parse('2026-10-01 01:30:00', 'UTC'));

    $payroll->approve(PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail(), $approver, reconciled: true);

    $august = StatutoryFiling::query()->where('form_code', StatutoryFiling::S01)->where('period_label', 'August 2026')->firstOrFail();
    $filed = $payroll->fileReturn($august, $approver);

    expect($filed->filed_on->toDateString())->toBe('2026-09-30')
        ->and(Journal::query()->where('reference', $filed->journal_ref)->firstOrFail()->posted_on->toDateString())->toBe('2026-09-30')
        ->and($filed->journal_ref)->toStartWith('JV-2026-09-');

    $employee = $payroll->addEmployee([
        'full_name' => 'Delroy Simms', 'job_title' => 'Groundsman', 'employment_type' => 'full_time',
        'monthly_rate' => '85000.00', 'consent' => true,
    ], $treasurer);

    expect($employee->employed_since->toDateString())->toBe('2026-09-30');
});

it('refuses to send a run back without saying what is wrong with it', function () {
    $run = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();
    $approver = payrollApprover();

    expect(fn () => app(Payroll::class)->requestChanges($run, '   ', $approver))
        ->toThrow(DomainException::class);

    expect($run->refresh()->status)->toBe(PayrollRun::CALCULATED);
});

/* ------------------------------------------------------------------ */
/* what a payroll screen may show about a person */
/* ------------------------------------------------------------------ */

it('never sends a whole bank account number or NIS number to a screen', function () {
    $board = app(Payroll::class)->employeesBoard();
    $payload = json_encode($board, JSON_THROW_ON_ERROR);

    expect($board['rows'])->not->toBeEmpty();

    foreach (Employee::query()->get() as $employee) {
        expect($board['rows'])->toContain(
            ...array_filter($board['rows'], static fn (array $row): bool => $row['name'] === $employee->full_name),
        );
    }

    /*
     * The masked forms are what the board draws — "NCB •••• 3315". What must
     * never appear is a number a bank would accept, so the assertion is on the
     * SHAPE: every row's bank field carries the mask, and no row carries a bare
     * run of digits long enough to be an account.
     */
    foreach ($board['rows'] as $row) {
        expect($row['bank'])->toContain('••••')
            ->and($row['nis'])->toContain('•••');
    }

    // And no field on any row carries a bare run of digits long enough to be
    // an account number. Checked on the identity fields only: a monthly rate in
    // minor units is legitimately eight digits, and asserting over the whole
    // payload would fail on the one figure the board is supposed to print.
    foreach ($board['rows'] as $row) {
        expect(preg_match('/\d{5,}/', $row['bank'].' '.$row['nis']))->toBe(0);
    }
});

it('records an excluded employee as excluded rather than resolved', function () {
    /*
     * The distinction is a person's wages. An excluded employee is left out of
     * the run entirely — no payslip, no gross, no net — and a run that treated
     * exclusion as resolution would pay somebody nothing and report itself
     * complete.
     */
    $exception = PayrollException::query()
        ->where('status', PayrollException::UNRESOLVED)
        ->firstOrFail();

    expect($exception->blocksCalculation())->toBeTrue();

    $unexcluded = new PayrollException(['status' => PayrollException::EXCLUDED]);
    $resolved = new PayrollException(['status' => PayrollException::RESOLVED]);

    expect($unexcluded->blocksCalculation())->toBeFalse()
        ->and($resolved->blocksCalculation())->toBeFalse()
        ->and(PayrollException::EXCLUDED)->not->toBe(PayrollException::RESOLVED);
});

/* ------------------------------------------------------------------ */
/* the first live approval — LAST IN THIS FILE, because it pays a run */
/* ------------------------------------------------------------------ */

it('withholds an approved pension to its own payable, never to 2100, and refuses without the account — Q-017', function () {
    /*
     * Ruled (13 §0): nobody has an approved scheme, so every employee's field is
     * zero. This is the day one is set. Run inside a transaction and rolled back,
     * because the ties above and the approval below read this estate's ledger as
     * the seeder left it.
     */
    $connection = DB::connection('tenant');
    $connection->beginTransaction();

    try {
        $treasurer = payrollFixtureUser('estate.treasurer');
        $approver = payrollApprover();
        $employee = Employee::query()->where('status', Employee::ACTIVE)->orderBy('id')->firstOrFail();

        $employee->forceFill(['approved_pension_minor' => 10_000_00])->save();

        $run = PayrollRun::create([
            'reference' => 'PR-PENSIONTEST',
            'slug' => 'pension-test',
            'period_label' => 'October 2026',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'periods_per_year' => Payroll::PERIODS_PER_YEAR,
            'statutory_rate_version_id' => StatutoryRateVersion::forPayDate('2026-10-31')->id,
            'currency' => 'JMD',
            'prepared_by' => $treasurer->getKey(),
            'prepared_by_name' => $treasurer->name,
        ]);

        $payroll = app(Payroll::class);
        $payroll->calculate($run);

        $line = $run->lines()->where('employee_id', $employee->id)->sole();

        expect($line->pension_minor)->toBe(10_000_00);

        // No 2150 in the chart: refused, and nothing posted.
        expect(fn () => $payroll->approve($run->refresh(), $approver, reconciled: true))
            ->toThrow(DomainException::class, Payroll::PENSION_PAYABLE);

        $connection->table('accounts')->insert([
            'code' => Payroll::PENSION_PAYABLE,
            'name' => 'Pension Contributions Payable',
            'type' => 'liability',
            'is_control' => false,
            'subsidiary' => null,
            'is_bank_account' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paid = $payroll->approve($run->refresh(), $approver, reconciled: true);
        $totals = payrollRunTotals('pension-test');

        $by = collect($connection->select('
            SELECT a.code, l.debit_minor, l.credit_minor
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE l.entry_ref = ?
        ', [$paid->journal_ref]))->keyBy('code');

        // The pension goes to 2150; 2100 carries only what the S01 will clear.
        expect((int) $by[Payroll::PENSION_PAYABLE]->credit_minor)->toBe(10_000_00)
            ->and((int) $by[Payroll::STATUTORY_PAYABLE]->credit_minor)
            ->toBe((int) $totals->gross - (int) $totals->net - 10_000_00 + (int) $totals->employer)
            ->and((int) $by[Payroll::BANK]->credit_minor)->toBe((int) $totals->net);
    } finally {
        $connection->rollBack();
    }
});

it('approves a run on a verified card, asking for the acknowledgement on the first live one', function () {
    /*
     * Q-002 is ruled and payroll is unblocked. This pays a fresh October run end to end.
     * It used to be declared last, because what it posts to 2100 and 5010 would otherwise
     * have been read by the ties above; the per-test transaction is what keeps them apart
     * now, and nothing here depends on where it sits in the file.
     */
    $treasurer = payrollFixtureUser('estate.treasurer');
    $approver = payrollApprover();

    $run = PayrollRun::create([
        'reference' => 'PR-OCT2026',
        'slug' => 'oct-2026',
        'period_label' => 'October 2026',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'periods_per_year' => Payroll::PERIODS_PER_YEAR,
        'statutory_rate_version_id' => StatutoryRateVersion::forPayDate('2026-10-31')->id,
        'currency' => 'JMD',
        'prepared_by' => $treasurer->getKey(),
        'prepared_by_name' => $treasurer->name,
    ]);

    $payroll = app(Payroll::class);
    $payroll->calculate($run);

    expect($payroll->approvalRefusal($run->refresh(), $approver))->toBeNull()
        ->and($payroll->needsReconciliationAcknowledgement())->toBeTrue();

    // Without the tick: refused, naming the card — and nothing posted.
    $refusal = null;

    try {
        $payroll->approve($run, $approver);
    } catch (DomainException $e) {
        $refusal = $e->getMessage();
    }

    expect($refusal)->toContain('first live pay run')
        ->and($refusal)->toContain('TAJ 2026/27 (2026-04)')
        ->and($run->refresh()->status)->toBe(PayrollRun::CALCULATED)
        ->and($run->journal_ref)->toBeNull();

    // With it: paid, in four balanced lines, with the acknowledgement on the record.
    $paid = $payroll->approve($run, $approver, reconciled: true);

    expect($paid->status)->toBe(PayrollRun::PAID)
        ->and($paid->reconciliation_acknowledged_by_name)->toBe($approver->name)
        ->and($paid->reconciliation_rate_version)->toBe('TAJ 2026/27 (2026-04)')

        /*
         * AND IT IS NEVER ASKED AGAIN — asserted here, in the test that makes it true.
         *
         * This line used to live at the end of the calendar test, which acknowledges
         * nothing: it passed only while this test leaked its paid run into the next one,
         * and it went red the moment every test in this file was given a transaction of
         * its own. "Never asked again" is a fact about having acknowledged once, so it
         * belongs beside the acknowledgement.
         */
        ->and($payroll->needsReconciliationAcknowledgement())->toBeFalse();

    $totals = payrollRunTotals('oct-2026');

    $lines = DB::connection('tenant')->select('
        SELECT a.code, l.debit_minor, l.credit_minor
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE l.entry_ref = ?
    ', [$paid->journal_ref]);

    $by = [];

    foreach ($lines as $line) {
        $by[$line->code] = $line;
    }

    expect($lines)->toHaveCount(4)
        ->and($by[Payroll::EXPENSE]->debit_minor)->toBe((int) $totals->gross)
        ->and($by[Payroll::EMPLOYER_CONTRIBUTIONS]->debit_minor)->toBe((int) $totals->employer)
        ->and($by[Payroll::STATUTORY_PAYABLE]->credit_minor)
        ->toBe((int) $totals->gross - (int) $totals->net + (int) $totals->employer)
        ->and($by[Payroll::BANK]->credit_minor)->toBe((int) $totals->net);
});

/* ------------------------------------------------------------------ */
/* the payroll calendar — AFTER the approval, because it closes months */
/* ------------------------------------------------------------------ */

it('starts the next month on the calendar, one open run at a time', function () {
    /*
     * 12 §2, Wave 1: "Start new payroll run — payroll calendar of periods and
     * close dates." The calendar is calendar months, one open at a time, read
     * from the runs themselves: the next period is the month after the latest
     * run, whatever its state — and August is still open, so November cannot
     * start yet.
     *
     * OCTOBER IS MADE HERE (M4 §4, defect 3). It used to be whatever the test
     * above had left behind, which made this test's whole subject — which month
     * comes next — a fact about the order the file happened to run in. Every
     * test in this file now rolls back, so it makes its own: a DRAFT is enough,
     * because "whatever its state" is exactly the claim.
     */
    $treasurer = payrollFixtureUser('estate.treasurer');

    PayrollRun::query()->firstOrCreate(
        ['slug' => 'oct-2026'],
        [
            'reference' => 'PR-OCT2026',
            'period_label' => 'October 2026',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'periods_per_year' => Payroll::PERIODS_PER_YEAR,
            'statutory_rate_version_id' => StatutoryRateVersion::forPayDate('2026-10-31')->id,
            'currency' => 'JMD',
            'prepared_by' => $treasurer->getKey(),
            'prepared_by_name' => $treasurer->name,
        ],
    );

    $payroll = app(Payroll::class);
    $calendar = $payroll->calendar();

    expect($calendar['slug'])->toBe('nov-2026')
        ->and($calendar['label'])->toBe('November 2026')
        ->and($calendar['period_start'])->toBe('2026-11-01')
        ->and($calendar['period_end'])->toBe('2026-11-30')
        ->and($calendar['rate_card'])->toBe('2026-04')
        ->and($calendar['blocked_by'])->toContain('still open');

    expect(fn () => $payroll->startRun($treasurer))->toThrow(DomainException::class, 'still open');

    /*
     * Close the two open months by hand — this is the end of the file and the
     * estate is rebuilt per process, so the ledger ties above have already
     * been read. With nothing open, November starts as a draft prepared by
     * whoever pressed the button, on the card in force on its pay date, with
     * nothing calculated and nothing posted.
     */
    PayrollRun::query()->where('status', '!=', PayrollRun::PAID)->update(['status' => PayrollRun::PAID]);

    expect($payroll->calendar()['blocked_by'])->toBeNull();

    $run = $payroll->startRun($treasurer);

    expect($run->slug)->toBe('nov-2026')
        ->and($run->reference)->toBe('PR-NOV2026')
        ->and($run->status)->toBe(PayrollRun::DRAFT)
        ->and($run->prepared_by_name)->toBe($treasurer->name)
        ->and($run->period_end->toDateString())->toBe('2026-11-30')
        ->and($run->statutory_rate_version_id)->toBe(StatutoryRateVersion::forPayDate('2026-11-30')->id)
        ->and($run->lines()->count())->toBe(0)
        ->and($run->journal_ref)->toBeNull();

    // A month is paid once: starting again is refused, and the calendar has
    // moved on to December — blocked, because November is now the open one.
    expect(fn () => $payroll->startRun($treasurer))->toThrow(DomainException::class, 'still open')
        ->and($payroll->calendar()['slug'])->toBe('dec-2026');

    /*
     * AND THE TICK IS STILL OUTSTANDING, because nothing in THIS test ever approved
     * anything: it closed the open months by hand and started a draft. The estate's
     * seeded history never carried the acknowledgement — that is what makes the first
     * live approval ask for it — so the November run will be asked in its turn.
     *
     * This read `toBeFalse()` and was a sentence about a different test's paid run,
     * which is only ever true when that test has run first and been kept. It is now
     * asserted where the acknowledgement is actually given.
     */
    expect($payroll->needsReconciliationAcknowledgement())->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* the two files a run leaves in — 12 §1, Wave 3 */
/* ------------------------------------------------------------------ */

it('exports an approved run as two different documents, and refuses a draft', function () {
    /*
     * "Payroll export XLSX + bank CSV behind a PaymentGateway-style adapter."
     * They are DIFFERENT DOCUMENTS: one is an instruction to move money and
     * carries account numbers, the other a record of what was paid and carries
     * deductions. Neither is a view of the other.
     */
    $formats = app(PayrollFileFormats::class);

    expect(array_column($formats->catalogue(), 'key'))->toBe(['summary', 'bank']);
    expect(fn () => $formats->find('whatever'))->toThrow(DomainException::class);

    $paid = PayrollRun::query()->where('status', PayrollRun::PAID)->orderByDesc('period_start')->firstOrFail();
    $lines = $paid->lines()->with('employee')->orderByDesc('gross_minor')->get();

    expect($lines)->not->toBeEmpty();

    // THE BANK FILE: net only, and an employee with no account is IN it with a
    // note — dropping them would hand the bank a file that pays fewer people
    // than the run approved.
    $bank = $formats->find('bank')->build($paid, $lines);

    expect(str_starts_with($bank, "\xEF\xBB\xBF"))->toBeTrue();

    $bankRows = array_values(array_filter(explode("\n", trim($bank))));

    expect(count($bankRows) - 1)->toBe($lines->count())
        ->and($bankRows[0])->toContain('Account number')
        ->and($bankRows[0])->not->toContain('PAYE')
        ->and($bank)->toContain(number_format($lines->first()->net_minor / 100, 2, '.', ''));

    // THE SUMMARY: a real xlsx — a zip whose parts are a valid workbook — with
    // the deductions in it and no account numbers anywhere.
    $xlsx = $formats->find('summary')->build($paid, $lines);

    $tmp = tempnam(sys_get_temp_dir(), 'gstest');
    file_put_contents($tmp, $xlsx);

    $zip = new ZipArchive;

    expect($zip->open($tmp))->toBeTrue();

    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($tmp);

    expect($names)->toContain('[Content_Types].xml')
        ->and($names)->toContain('xl/workbook.xml')
        ->and($names)->toContain('xl/worksheets/sheet1.xml')
        ->and($sheet)->toContain('PAYE')

        // Numbers as numbers, so an accountant can total a column.
        ->and($sheet)->toContain('<v>'.($lines->first()->gross_minor / 100).'</v>')

        // The total row is summed here, never a formula that recomputes in the
        // reader's copy — this file is a record, not a working model.
        ->and($sheet)->toContain('<v>'.($lines->sum('net_minor') / 100).'</v>')
        ->and($sheet)->not->toContain('<f>');

    // No account number reaches the accountant's file.
    foreach ($lines as $line) {
        if ($line->employee->bank_account_number !== null) {
            expect($sheet)->not->toContain($line->employee->bank_account_number);
        }
    }
});

/* ------------------------------------------------------------------ */
/* the compliance calendar — 12 §2, Wave 4 */
/* ------------------------------------------------------------------ */

it('projects the monthly returns the register does not hold, and guesses no other date', function () {
    $payroll = app(Payroll::class);

    $calendar = $payroll->complianceCalendar(Carbon::parse('2026-09-16'));
    $rows = collect($calendar['months'])->flatMap(fn (array $month): array => $month['rows']);

    expect($calendar['projects'])->toBeTrue();

    // September's S01 is not on the register, so it is projected — due on the
    // 14th of October, the rule board 16's own dates follow.
    $september = $rows->first(fn (array $row): bool => $row['code'] === StatutoryFiling::S01 && $row['period'] === 'September 2026');

    expect($september)->not->toBeNull()
        ->and($september['source'])->toBe('projected')
        ->and($september['due_on'])->toBe('2026-10-14');

    // August's S01 IS on the register, so it is shown once, as the register
    // holds it, and never projected beside itself.
    expect($rows->filter(fn (array $row): bool => $row['code'] === StatutoryFiling::S01 && $row['period'] === 'August 2026')->count())->toBe(1)
        ->and($rows->first(fn (array $row): bool => $row['code'] === StatutoryFiling::S01 && $row['period'] === 'August 2026')['source'])->toBe('register');

    // NO RULE IS INVENTED: the GCT return and the P24 are never projected.
    expect($rows->filter(fn (array $row): bool => in_array($row['code'], [StatutoryFiling::GCT, StatutoryFiling::P24], true) && $row['source'] === 'projected'))->toBeEmpty();

    // An outstanding return is shown however far out it falls due.
    expect($rows->contains(fn (array $row): bool => $row['code'] === StatutoryFiling::P24 && $row['source'] === 'register'))->toBeTrue();

    // No return is owed for a month before the estate's first payroll.
    $first = Carbon::parse((string) PayrollRun::query()->min('period_start'));
    $early = $payroll->complianceCalendar($first->copy()->addDays(10));

    expect(collect($early['months'])->flatMap(fn (array $month): array => $month['rows'])
        ->filter(fn (array $row): bool => $row['source'] === 'projected' && Carbon::parse('1 '.$row['period'])->lt($first->copy()->startOfMonth())))
        ->toBeEmpty();
});
