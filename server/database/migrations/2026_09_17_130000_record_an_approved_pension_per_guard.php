<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved pension on Gemini's own guard payroll — Q-017, ruled (13 §0).
 *
 * The same field the estate payroll gained, because the ruling is about what
 * statutory income is and Gemini is an employer too. Zero for every guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guards', function (Blueprint $table): void {
            $table->bigInteger('approved_pension_minor')->default(0);
        });

        Schema::table('payslips', function (Blueprint $table): void {
            $table->bigInteger('pension_minor')->default(0)->after('paye_minor');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn('pension_minor');
        });

        Schema::table('guards', function (Blueprint $table): void {
            $table->dropColumn('approved_pension_minor');
        });
    }
};
