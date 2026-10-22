<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The estate's own staff payroll — boards 13, 14, 15, 16 and 37.
 *
 * A DIFFERENT POPULATION FROM `payroll_runs` / `payslips` IN `gs_platform`, and
 * deliberately a different set of tables rather than an extension of them.
 * Those two are Gemini Security's own guards, keyed on `guards.id` and holding
 * `tenant_id` only to say where a guard was POSTED — the guard is Gemini's
 * employee whichever client's gate they stand at, and Gemini pays them.
 * Patricia Morgan, Neil Anderson, Wayne Thomas and Simone Clarke are the
 * opposite: the ESTATE employs them, the estate pays them, and their pay never
 * touches Gemini's own payroll run. Reusing the central tables would need
 * `guard_id` to point at people who are not guards, or a nullable alternative
 * key on a table two other modules already read by `guard_id` — either one
 * blurs a boundary the Build Spec draws in words ("security guards are Gemini
 * Security Limited employees paid centrally... and must be excluded from these
 * runs"). So this is new tenant tables, in the estate's own database, exactly
 * where `bills` and `vendors` are for the same reason. See DECISIONS.md D-057.
 *
 * `statutory_rate_versions` IS NOT DUPLICATED HERE. The rates are Jamaica-wide
 * TAJ rates and not a fact about one estate, so `payroll_runs.statutory_rate_
 * version_id` is a plain `unsignedBigInteger` pointing at the CENTRAL table —
 * no `->constrained()`, because a foreign key cannot reach across the two
 * physical databases tenancy keeps. `gate:ledger` and the append-only grants
 * both already treat a cross-database reference this way (`journal_lines.
 * vendor_id` has no FK for a related reason: the referenced row is optional
 * rather than cross-database, but the lesson is the same — a total that must
 * be provably right is proved by a test that re-joins it, not by a constraint
 * MySQL cannot express). `EstatePayrollTest` re-fetches the version centrally
 * and recomputes from it rather than trusting the id blindly.
 *
 * THE MONEY ITSELF GOES THROUGH `Ledger::post()`, THE ONE DOOR. Nothing here
 * stores a balance and nothing here is a second ledger: `payroll_run_lines`
 * carries what payroll bookkeeping has no place for — a payslip's own
 * NIS/NHT/Education Tax/PAYE breakdown, at the moment it was calculated — and
 * disbursing a run posts through `Ledger::post()` exactly as approving a bill
 * does. Two tables (`payroll_runs`, `payroll_run_lines`) do carry the figures
 * directly rather than only computing them on read, and that is not the same
 * mistake `Account`/`Journal` warn against: an account's balance is a
 * standing total that MANY entries contribute to over the account's whole
 * life, so storing one is a second copy of an ever-changing sum. A payroll
 * line's gross and its four deductions are the INPUT a single entry is about
 * to post, exactly as `bills.amount_minor` is — set once, and immutable in
 * practice because the service refuses to calculate or approve a run twice.
 *
 * NO SUB-LEDGER TIE IS REGISTERED ON 2100 STATUTORY DEDUCTIONS PAYABLE. The
 * control-account tie `gate:ledger` proves is for a balance built from MANY
 * unrelated postings against a named debtor or creditor (`accounts.
 * subsidiary`) — dues receivable against every unit, payables against every
 * vendor. The four deduction lines a pay run credits are not a subsidiary
 * ledger in that sense: they are one period's remittance, named by their own
 * memo, and the S01 filing that clears them reads the SAME journal lines back
 * rather than a second store. `Account::SUBSIDIARY_UNITS` /
 * `SUBSIDIARY_VENDORS` are the only two subsidiaries this chart has any use
 * for, and 2100 stays `is_control = false` — set by `ChartOfAccountsSeeder`
 * already, unchanged here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            $table->string('full_name', 160);
            $table->string('job_title', 120);

            // Only "Full-time" appears on board 37; the column exists because
            // the board draws it as a fact about the row, not because a second
            // value is reachable from anywhere in this phase.
            $table->string('employment_type', 24)->default('full_time');

            /*
             * FULL VALUES, MASKED AT THE BOUNDARY. Board 37 prints "NCB ••••
             * 3315" and "••• •••5 208" — a bank name plus the account's last
             * four digits, and a NIS number with only its last four digits
             * showing. What clears a statutory remittance and what a bank
             * transfer needs is the whole number, so the whole number is what
             * is stored; `Employee::maskedBankAccount()` and
             * `::maskedNisNumber()` are the only routes a controller payload
             * takes to reach either column, and neither is ever assigned to a
             * board response directly.
             */
            $table->string('bank_name', 40)->nullable();
            $table->string('bank_account_number', 40)->nullable();
            $table->string('nis_number', 40)->nullable();

            // The monthly gross rate. Board 37 calls this "Rate"; the four
            // rates sum to J$440,000, which is what a pay run debits 5000 for.
            $table->bigInteger('monthly_rate_minor');
            $table->char('currency', 3)->default('JMD');

            $table->date('employed_since');

            // active | inactive. Deactivated, never deleted — an employee with
            // payslips behind them is history the estate has to keep, and
            // `payroll_run_lines.employee_id` restricts on delete for exactly
            // the reason `bills.vendor_id` does.
            $table->string('status', 16)->default('active');

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 40)->unique();

            // "aug-2026", "sep-2026" — board 13 and 15's own URLs key runs on
            // this, not on the numeric id, exactly as board 38 keys a resident
            // detail page on the LOT and not on the person.
            $table->string('slug', 20)->unique();

            $table->string('period_label', 40);
            $table->date('period_start');
            $table->date('period_end');

            // 12 for a calendar month, which is what every run on these boards
            // is. Carried per run, like the central table, so a run reproduces
            // exactly however pay frequency is set the day it was calculated.
            $table->unsignedSmallInteger('periods_per_year')->default(12);

            // Central, cross-database — see the file docblock.
            $table->unsignedBigInteger('statutory_rate_version_id');

            // draft | exceptions | calculated | paid
            $table->string('status', 16)->default('draft');

            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->char('currency', 3)->default('JMD');

            /*
             * WHO PREPARED IT AND WHO APPROVED IT, kept apart on purpose.
             * Board 15's own banner reads "Prepared by Tracey Reid — awaiting
             * your approval. As a second approver, this run cannot be
             * disbursed until you review and approve it" — a run's preparer
             * may not be its own approver (D-013), and the service checks
             * these two columns against each other to enforce it rather than
             * trusting that whoever calculated the run will not also be the
             * one who clicks approve.
             */
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->string('prepared_by_name', 160)->nullable();

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 160)->nullable();
            $table->timestamp('approved_at')->nullable();

            // Why the preparer sent it back, where they did. Board 15's
            // "Request changes" — `update`, not `approve` (D-013): it answers
            // by making the opposite decision and authorises nobody.
            $table->string('changes_requested_reason', 190)->nullable();

            // The single entry disbursement raises: Dr 5000, Cr 2100 (x4), Cr
            // the bank. Null until the run is actually posted.
            $table->string('journal_ref', 40)->nullable();

            $table->timestamps();

            $table->index(['status', 'period_start']);
        });

        Schema::create('payroll_run_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            $table->bigInteger('gross_minor');
            $table->bigInteger('nis_minor')->default(0);
            $table->bigInteger('nht_minor')->default(0);
            $table->bigInteger('education_tax_minor')->default(0);
            $table->bigInteger('paye_minor')->default(0);
            $table->bigInteger('net_minor');
            $table->char('currency', 3)->default('JMD');

            // Why PAYE is zero, in words — `PayrollCalculator`'s own output.
            // A bare $0 on Simone Clarke's line reads as a defect otherwise.
            $table->string('paye_note', 200)->nullable();

            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payroll_run_exceptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            // missing_timesheet | overtime_anomaly
            $table->string('type', 32);
            $table->string('detail', 200);

            // For missing_timesheet.
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            // For overtime_anomaly. Both hours, so "more than double the
            // 3-month average" is arithmetic over two stored numbers rather
            // than a sentence with nothing behind it.
            $table->unsignedSmallInteger('overtime_hours')->nullable();
            $table->unsignedSmallInteger('overtime_baseline_hours')->nullable();

            // unresolved | entered | excluded | reviewed | approved_as_is
            $table->string('status', 20)->default('unresolved');
            $table->string('resolution_note', 190)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolved_by_name', 160)->nullable();

            $table->timestamps();

            $table->index(['payroll_run_id', 'status']);
        });

        Schema::create('statutory_filings', function (Blueprint $table) {
            $table->id();

            // S01 | S02 | GCT | P24 — board 16's own codes. "GCT Return" has
            // no code prefix on the board, so its code and title overlap; the
            // service derives the printed line rather than storing a
            // precomposed string that could drift from the two parts.
            $table->string('form_code', 12);
            $table->string('form_title', 120);

            $table->string('period_label', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');

            // not_started | due | filed — mirrors the central
            // `StatutoryFiling`'s own vocabulary (App\Models\StatutoryFiling),
            // which this table does not extend for the reason `payroll_runs`
            // does not: a guard's S01 and an estate's S01 are two different
            // authorities' filings about two different employers.
            $table->string('status', 16)->default('not_started');

            $table->date('filed_on')->nullable();
            $table->string('confirmation_reference', 64)->nullable();

            // The run whose deductions this remittance clears. Nullable: the
            // annual P24 and the GCT return are not tied to one pay run.
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();

            $table->unsignedInteger('employees_covered')->nullable();
            $table->bigInteger('nis_minor')->nullable();
            $table->bigInteger('nht_minor')->nullable();
            $table->bigInteger('education_tax_minor')->nullable();
            $table->bigInteger('paye_minor')->nullable();
            $table->bigInteger('total_minor')->nullable();
            $table->char('currency', 3)->default('JMD');

            // The remittance entry: Dr 2100 (x4), Cr the bank. Null until
            // filed — an S01 that has not moved money has raised nothing.
            $table->string('journal_ref', 40)->nullable();

            $table->timestamps();

            // "S01" for August and "S01" for July are two different rows, one
            // per period — the composite is what a second seed run checks
            // before it writes another.
            $table->unique(['form_code', 'period_label'], 'statutory_filings_code_period_unique');
            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_filings');
        Schema::dropIfExists('payroll_run_exceptions');
        Schema::dropIfExists('payroll_run_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employees');
    }
};
