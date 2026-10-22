<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dated changes to a platform rate — the per-guard add-on (M10 §3).
 *
 * THE CLIENT'S WORDS: the Director "can edit the subscription tiers and rates
 * shown in Platform settings → Pricing & rates: the per-unit price of each tier,
 * the per-guard add-on, and any client line items. Changing a rate must not
 * silently rewrite invoices already issued — issued invoices keep the rate they
 * were raised on, and the change applies from the next billing period. Every
 * change is audited."
 *
 * The tier price already had all of that (`plan_price_changes`, Wave 4). The
 * per-guard add-on had none of it: `platform_rates` was read in about eleven
 * places and written by nothing but `PlatformCatalogueSeeder`. A rate change
 * needs the same five facts a tier price change needs — what it was, what it
 * becomes, when, why and who — and the same never-retroactive guarantee, so
 * this table is `plan_price_changes` column for column.
 *
 * A SECOND TABLE, NOT A NULLABLE `plan_id` ON THE FIRST. Every reader of
 * `plan_price_changes` joins `plans` on every row; a rate row there would either
 * break that join or teach every existing query about a second kind of change.
 *
 * AND NOT "RETIRE THE OLD RATE ROW AND INSERT A NEW ONE", which is the tempting
 * shortcut and is wrong twice. `platform_rates.key` is unique, so two rows cannot
 * both be `security_provider_guard`; and `InvoiceRun::draft()` bills EVERY active
 * `basis = 'guard'` rate, so two active rows would bill every client twice.
 *
 * `platform_rates.amount_minor` STAYS AS WHAT IS IN FORCE TODAY, for the reason
 * the tier-price migration gives: every projection already reads it. What an
 * INVOICE is raised at is the rate in force on its period's first day, resolved
 * from this table by `PlatformPricing::rateMinorOn()` — which is what makes
 * "applies from the next billing period" true of the invoice rather than of the
 * day somebody happened to open the rate card.
 *
 * MUTABLE, NOT APPEND-ONLY. `applied_at` is stamped after insert, when the change
 * comes due, exactly as it is on `plan_price_changes`; withholding UPDATE would
 * break the sweep. The record that must never change — who changed which rate,
 * from what, to what and why — is also written to `audit_log`, which is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_rate_changes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('platform_rate_id')->constrained('platform_rates')->cascadeOnDelete();

            // Both ends, so a reader a year later does not have to reconstruct
            // the old rate from whatever the next row happens to say.
            $table->bigInteger('from_minor');
            $table->bigInteger('to_minor');
            $table->char('currency', 3)->default('JMD');

            $table->date('effective_from');

            // WHY. A rate change re-prices every client with guards deployed,
            // and an unexplained one is the entry an auditor stops at.
            $table->string('reason', 255);

            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->string('changed_by_name', 120);

            // Null while pending. Stamped the moment the new rate takes effect.
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['platform_rate_id', 'effective_from']);
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_rate_changes');
    }
};
