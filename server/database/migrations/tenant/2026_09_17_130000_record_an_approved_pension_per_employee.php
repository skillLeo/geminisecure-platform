<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved pension, per employee — Q-017, ruled (13 §0).
 *
 * "None. No employee has an approved scheme. Build the field, keep it at zero,
 * make it configurable per employee for later."
 *
 * `employees.approved_pension_minor` is the monthly contribution, zero for
 * everybody. `payroll_run_lines.pension_minor` is what a payslip withheld,
 * stored beside the statutory deductions because a payslip is a statement
 * issued on a date and is never recomputed.
 *
 * NO ACCOUNT IS ADDED TO THE CHART. A withheld pension is owed to the scheme,
 * not to TAJ, so it cannot sit in 2100 — the S01 would never clear it. It posts
 * to 2150 Pension Contributions Payable, and `Payroll::approve()` refuses a run
 * carrying one until the estate has added that account. With every contribution
 * at zero no estate needs it, and a chart line nobody posts to would move board
 * 25 for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->bigInteger('approved_pension_minor')->default(0)->after('monthly_rate_minor');
        });

        Schema::table('payroll_run_lines', function (Blueprint $table): void {
            $table->bigInteger('pension_minor')->default(0)->after('paye_minor');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_lines', function (Blueprint $table): void {
            $table->dropColumn('pension_minor');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('approved_pension_minor');
        });
    }
};
