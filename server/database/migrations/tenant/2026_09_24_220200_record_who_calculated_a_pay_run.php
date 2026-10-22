<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who worked out a pay run's figures (bug hunt B025).
 *
 * The second-approver control compared the approver with `prepared_by` alone —
 * whoever pressed "Start new run", which creates an empty draft with no figures
 * in it. The figures come from resolving the run's exceptions and calculating it,
 * and one person could do both and then release the pay, while the banner still
 * named somebody else as the preparer.
 *
 * The exceptions already record who resolved them. This records who calculated
 * the run, and when, so `Payroll::approvalRefusal()` can refuse an approver who
 * started it, calculated it, or decided one of its exceptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('calculated_by')->nullable()->after('prepared_by_name');
            $table->string('calculated_by_name', 160)->nullable()->after('calculated_by');
            $table->timestamp('calculated_at')->nullable()->after('calculated_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropColumn(['calculated_by', 'calculated_by_name', 'calculated_at']);
        });
    }
};
