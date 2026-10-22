<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Q-002 ruling and D-072, on an estate's own payroll (D-082, D-083).
 *
 * EMPLOYER CONTRIBUTIONS REACH THE BOOKS. D-072 found three employer rates in
 * the central rate card that nothing had ever read; the client has now ruled
 * they go on the monthly S01 beside the employee deductions, and that they are
 * posted:
 *
 *     Dr 5010 Employer Statutory Contributions   Cr 2100 Statutory Payables
 *
 * then cleared with the rest of 2100 when the S01 is filed. So a payslip line
 * carries its employer's share — the estate's cost, never shown to the employee
 * as a deduction because it is not one — and a return carries both halves.
 *
 * ACCOUNT 5010 IS INSERTED HERE, NOT ONLY SEEDED. The chart is seeded at
 * provisioning, and an estate provisioned before this ruling will never see the
 * seeder again; without the account, the first approval after this migration
 * would fail inside `Ledger::post()` on an account that does not exist. Inserted
 * only into a chart that already exists — an empty estate gets the whole chart,
 * this account included, from `ChartOfAccountsSeeder`.
 *
 * THE FIRST-LIVE-RUN ACKNOWLEDGEMENT, Part F of the ruling. Recorded on the run
 * that carries it, with the name and the rate version, so the answer to "who
 * confirmed these figures against TAJ, and against which tables" is a row rather
 * than a recollection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_lines', function (Blueprint $table): void {
            $table->bigInteger('employer_nis_minor')->default(0)->after('net_minor');
            $table->bigInteger('employer_nht_minor')->default(0)->after('employer_nis_minor');
            $table->bigInteger('employer_education_tax_minor')->default(0)->after('employer_nht_minor');
            $table->bigInteger('employer_heart_minor')->default(0)->after('employer_education_tax_minor');
        });

        Schema::table('statutory_filings', function (Blueprint $table): void {
            $table->bigInteger('employer_nis_minor')->nullable()->after('paye_minor');
            $table->bigInteger('employer_nht_minor')->nullable()->after('employer_nis_minor');
            $table->bigInteger('employer_education_tax_minor')->nullable()->after('employer_nht_minor');
            $table->bigInteger('heart_minor')->nullable()->after('employer_education_tax_minor');
        });

        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->timestamp('reconciliation_acknowledged_at')->nullable()->after('approved_at');
            $table->unsignedBigInteger('reconciliation_acknowledged_by')->nullable()->after('reconciliation_acknowledged_at');
            $table->string('reconciliation_acknowledged_by_name', 160)->nullable()->after('reconciliation_acknowledged_by');
            $table->string('reconciliation_rate_version', 60)->nullable()->after('reconciliation_acknowledged_by_name');
        });

        $chartExists = DB::table('accounts')->exists();
        $hasAccount = DB::table('accounts')->where('code', '5010')->exists();

        if ($chartExists && ! $hasAccount) {
            DB::table('accounts')->insert([
                'code' => '5010',
                'name' => 'Employer Statutory Contributions',
                'type' => 'expense',
                'is_control' => false,
                'subsidiary' => null,
                'is_bank_account' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'reconciliation_acknowledged_at',
                'reconciliation_acknowledged_by',
                'reconciliation_acknowledged_by_name',
                'reconciliation_rate_version',
            ]);
        });

        Schema::table('statutory_filings', function (Blueprint $table): void {
            $table->dropColumn(['employer_nis_minor', 'employer_nht_minor', 'employer_education_tax_minor', 'heart_minor']);
        });

        Schema::table('payroll_run_lines', function (Blueprint $table): void {
            $table->dropColumn(['employer_nis_minor', 'employer_nht_minor', 'employer_education_tax_minor', 'employer_heart_minor']);
        });

        // 5010 is left in place on rollback. An account with posted lines
        // cannot be deleted — the ledger refuses it — and one without them is
        // harmless; removing it here would fail on exactly the estates that used it.
    }
};
