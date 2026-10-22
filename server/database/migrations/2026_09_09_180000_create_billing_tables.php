<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing and subscriptions - CENTRAL, in gs_platform.
 *
 * This is Gemini Security billing its clients, not an estate billing its
 * residents. The two are entirely separate ledgers and must not be conflated:
 * resident dues live in the estate database and never appear here.
 *
 * ACCESS IS NEVER WITHHELD OVER A BILLING DISPUTE. A subscription in `dunning`
 * or `suspended` gates billing features only. It never restricts entry, never
 * restricts a safety function, and never sets a household's access_restricted
 * flag - which is driven by estate arrears, a different thing entirely.
 *
 * Every amount is a bigint of minor units with an explicit currency. No float.
 * ASSUMPTION Q-001: JMD, pending a ruling on the platform currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 48)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();

            // Priced per unit per month, which is how an estate's bill scales.
            $table->bigInteger('price_per_unit_minor');
            $table->char('currency', 3)->default('JMD');

            $table->unsignedSmallInteger('min_units')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();  // one live subscription per estate
            $table->foreignId('plan_id')->constrained('plans');

            $table->unsignedSmallInteger('unit_count')->default(0);

            // active | onboarding | dunning | suspended | cancelled
            $table->string('status', 24)->default('onboarding');

            $table->date('started_on')->nullable();
            $table->date('renews_on')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            $table->string('reference', 40)->unique();
            $table->string('period', 32);           // "Aug 2026"
            $table->date('period_start');
            $table->date('period_end');

            $table->bigInteger('total_minor');
            $table->char('currency', 3)->default('JMD');

            $table->date('due_on');

            // draft | issued | paid | overdue | void
            $table->string('status', 24)->default('draft');
            $table->date('paid_on')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'period_start']);
            $table->index(['status', 'due_on']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 200);
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('total_minor');
            $table->char('currency', 3)->default('JMD');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
