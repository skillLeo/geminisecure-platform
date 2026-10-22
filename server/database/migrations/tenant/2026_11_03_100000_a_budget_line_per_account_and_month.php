<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUDGETS, THE SMALLEST HONEST VERSION (M84 §3.3): an amount for one income or expense account in one month.
 *
 *   budget_lines   account · period ("2026-10") · amount — a plan, never posted; Budget vs Actual reads it beside the
 *                  ledger. Changed and removed in place (ESTATE_MUTABLE): a budget is the committee's intention, not a
 *                  record of what happened, and the ledger it is compared with is untouched by it.
 *
 * Behind FEATURE_BUDGETS (config/features.php). An additive table: the release before this one never reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('budget_lines')) {
            return;
        }

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->char('period', 7);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');
            $table->unsignedBigInteger('set_by')->nullable();
            $table->string('set_by_name', 120)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
