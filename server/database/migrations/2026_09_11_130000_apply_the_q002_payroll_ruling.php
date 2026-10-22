<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Q-002 ruling, on the central payroll tables (D-082).
 *
 * THE CLIENT RULED: PAYE IS 25% ON THE AMOUNT ABOVE THE THRESHOLD, A BAND ON THE
 * EXCESS AND NEVER A FLAT RATE ON THE WHOLE. The calculation this platform had
 * been using was right; the sample payslips were wrong. What the ruling added is
 * what the rate card did not yet hold.
 *
 * TAJ'S PUBLISHED PERIODIC THRESHOLDS, STORED RATHER THAN DERIVED. The threshold
 * is published annually AND per pay period, and the periodic figures are not the
 * annual one divided: 2026's fortnightly threshold is 73,234.90, where 1,902,360
 * divided by 26 is 73,167.69. A calculator that divides drifts by cents on every
 * fortnightly payslip, and a golden test catches it the day an accountant checks.
 * So the three periodic figures are columns, and the engine reads them; division
 * survives only as the fallback for a version nobody has published figures into,
 * and such a version is never verified and so can never be approved.
 *
 * THE HIGHER BAND. 30% on chargeable income above 6,000,000 a year — 500,000 a
 * month. Stored with its own rate so a budget that moves either number is a data
 * change.
 *
 * HEART, the employer's 3% on gross emoluments where the monthly payroll exceeds
 * a statutory floor. The floor defaults to zero — apply it — because the ruling
 * says so pending the accountant's figure (QUESTIONS.md Q-016).
 *
 * `superseded_at`, BECAUSE THE PROVISIONAL VERSION CANNOT BE DELETED OR EDITED.
 * The draft card seeded before the ruling carries the same effective date as the
 * real 2026-04 version, and pay runs point at it. Deleting it breaks their
 * foreign key; editing it in place would silently restate every payslip computed
 * from it, which is the one thing a versioned rate card exists to prevent. So it
 * is marked superseded, the selector skips it, and the runs that used it keep
 * reading exactly what they were computed against.
 *
 * EMPLOYER CONTRIBUTIONS ON THE PAYSLIP ROW AND THE S01. The ruling put them on
 * the monthly S01 beside the employee deductions: PAYE, NIS, NHT, Education Tax
 * and HEART. So a payslip carries its employer's share — never shown to the
 * employee as a deduction, because it is not one — and a return carries both.
 *
 * THE FIRST-LIVE-RUN ACKNOWLEDGEMENT (Part F of the ruling). The figures come
 * from TAJ publications and reconcile to the cent, and nobody at the client has
 * countersigned them. So the first run approved on this platform records an
 * explicit acknowledgement that it was reconciled against current TAJ tables,
 * naming the rate version; after that it is never asked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_rate_versions', function (Blueprint $table): void {
            $table->bigInteger('paye_threshold_monthly_minor')->nullable()->after('paye_threshold_annual_minor');
            $table->bigInteger('paye_threshold_fortnightly_minor')->nullable()->after('paye_threshold_monthly_minor');
            $table->bigInteger('paye_threshold_weekly_minor')->nullable()->after('paye_threshold_fortnightly_minor');

            $table->unsignedInteger('paye_higher_bp')->default(3000)->after('paye_threshold_weekly_minor');
            $table->bigInteger('paye_higher_band_annual_minor')->default(6_000_000_00)->after('paye_higher_bp');

            $table->unsignedInteger('heart_employer_bp')->default(300)->after('paye_higher_band_annual_minor');
            $table->bigInteger('heart_monthly_floor_minor')->default(0)->after('heart_employer_bp');

            $table->timestamp('superseded_at')->nullable()->after('verified_on');
            $table->string('superseded_note', 255)->nullable()->after('superseded_at');
        });

        Schema::table('payslips', function (Blueprint $table): void {
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
            $table->string('reconciliation_rate_version', 60)->nullable()->after('reconciliation_acknowledged_by');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropColumn(['reconciliation_acknowledged_at', 'reconciliation_acknowledged_by', 'reconciliation_rate_version']);
        });

        Schema::table('statutory_filings', function (Blueprint $table): void {
            $table->dropColumn(['employer_nis_minor', 'employer_nht_minor', 'employer_education_tax_minor', 'heart_minor']);
        });

        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn(['employer_nis_minor', 'employer_nht_minor', 'employer_education_tax_minor', 'employer_heart_minor']);
        });

        Schema::table('statutory_rate_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'paye_threshold_monthly_minor', 'paye_threshold_fortnightly_minor', 'paye_threshold_weekly_minor',
                'paye_higher_bp', 'paye_higher_band_annual_minor',
                'heart_employer_bp', 'heart_monthly_floor_minor',
                'superseded_at', 'superseded_note',
            ]);
        });
    }
};
