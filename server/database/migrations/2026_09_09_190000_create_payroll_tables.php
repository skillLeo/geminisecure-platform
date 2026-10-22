<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guard payroll - CENTRAL, in gs_platform.
 *
 * Guards are Gemini Security's employees, so their pay is Gemini's payroll and
 * never an estate's. The estate's own staff payroll is a separate table in the
 * estate database, and the two must never be conflated.
 *
 * STATUTORY RATES ARE VERSIONED DATA WITH EFFECTIVE DATES, NEVER CONSTANTS.
 * A run records the rate version it used, so it reproduces exactly, to the
 * cent, years later. That is why the version is a foreign key on the run and
 * not merely a date lookup performed at read time.
 *
 * DEDUCTION ORDER IS LOAD-BEARING: NIS is deducted BEFORE Education Tax and
 * PAYE, because the latter two are computed on income after NIS. Reordering
 * them changes everybody's net pay.
 *
 * ASSUMPTION Q-002: the seeded rates are PROVISIONAL and marked unverified.
 * An approved run is blocked until an accountant signs them off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_rate_versions', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120);           // "2026/27 TAJ rates"
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Rates as basis points (1/100th of a percent) so no float is ever
            // involved: 3% is 300, not 0.03.
            $table->unsignedInteger('nis_employee_bp');
            $table->unsignedInteger('nis_employer_bp');
            $table->bigInteger('nis_ceiling_annual_minor');

            $table->unsignedInteger('nht_employee_bp');
            $table->unsignedInteger('nht_employer_bp');

            $table->unsignedInteger('education_tax_employee_bp');
            $table->unsignedInteger('education_tax_employer_bp');

            $table->unsignedInteger('paye_bp');
            $table->bigInteger('paye_threshold_annual_minor');

            /*
             * Unverified until an accountant signs off. Build Spec open item
             * [A]: do not go live on unverified numbers. A run may be
             * CALCULATED against an unverified version so the figures can be
             * checked, but never APPROVED.
             */
            $table->boolean('is_verified')->default(false);
            $table->string('verified_by', 160)->nullable();
            $table->date('verified_on')->nullable();

            $table->timestamps();

            $table->index('effective_from');
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('period_label', 40);      // "Sep 2026 - fortnight 2"
            $table->date('period_start');
            $table->date('period_end');

            // Pay periods per year. 26 for a fortnight, which is what makes
            // the PAYE threshold 1,800,000 / 26 = 69,230.77.
            $table->unsignedSmallInteger('periods_per_year')->default(26);

            $table->foreignId('statutory_rate_version_id')->constrained('statutory_rate_versions');

            // draft | calculated | approved | paid
            $table->string('status', 24)->default('draft');

            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->char('currency', 3)->default('JMD');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'period_start']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guard_id')->constrained('guards');
            $table->string('tenant_id')->nullable();  // the client they were posted at

            $table->bigInteger('gross_minor');
            $table->bigInteger('nis_minor')->default(0);
            $table->bigInteger('nht_minor')->default(0);
            $table->bigInteger('education_tax_minor')->default(0);
            $table->bigInteger('paye_minor')->default(0);
            $table->bigInteger('net_minor');
            $table->char('currency', 3)->default('JMD');

            /*
             * Why PAYE was zero, in words, for the payslip to display.
             *
             * Most guards fall under the threshold, so a bare 0.00 reads as a
             * bug. Naming the threshold turns it into an explanation.
             */
            $table->string('paye_note', 200)->nullable();

            $table->timestamps();

            $table->unique(['payroll_run_id', 'guard_id']);
        });

        /*
         * An APPROVED payroll run is append-only (invariant 4). Enforced by a
         * trigger that permits the draft -> calculated -> approved transitions
         * and refuses any edit afterwards, plus the withheld grant applied by
         * grants:append-only.
         */
        DB::unprepared("
            CREATE TRIGGER payroll_runs_no_edit_after_approval BEFORE UPDATE ON payroll_runs
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('approved', 'paid') AND NOT (OLD.status = 'approved' AND NEW.status = 'paid') THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'an approved payroll run is append-only; post an adjustment run instead';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER payslips_no_edit_after_approval BEFORE UPDATE ON payslips
            FOR EACH ROW
            BEGIN
                IF (SELECT status FROM payroll_runs WHERE id = OLD.payroll_run_id) IN ('approved', 'paid') THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'payslips on an approved run are append-only';
                END IF;
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payslips_no_edit_after_approval');
        DB::unprepared('DROP TRIGGER IF EXISTS payroll_runs_no_edit_after_approval');

        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('statutory_rate_versions');
    }
};
