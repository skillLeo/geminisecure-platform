<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dated tier price changes — board 45's Save (12 §2, Wave 4).
 *
 * THE OLD REASON ASKED FOR EXACTLY THIS: "changing a tier price re-prices every
 * client on that tier, so it is a privileged, audited write WITH AN EFFECTIVE
 * DATE — not a save button on a display screen."
 *
 * `plans.price_per_unit_minor` STAYS AS WHAT IS IN FORCE TODAY. Every screen,
 * every invoice and every report already reads it, and turning it into a
 * derived lookup would mean auditing all of them. What this table adds is the
 * history and the future: what it was, what it becomes, when, why and who.
 *
 * NEVER RETROACTIVE. A change dated before today is refused — an invoice already
 * raised was raised at the price in force, and re-pricing the past would make
 * the ledger disagree with the paper a client holds. A change dated today
 * applies at once; one dated ahead is pending until the day arrives.
 *
 * APPLIED WHEN IT COMES DUE, and `applied_at` is how anybody knows it did. A
 * pending change that silently never applied would be a price the platform
 * believes it charges and does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_price_changes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();

            // Both ends, so a reader a year later does not have to reconstruct
            // the old price from whatever the next row happens to say.
            $table->bigInteger('from_minor');
            $table->bigInteger('to_minor');
            $table->char('currency', 3)->default('JMD');

            $table->date('effective_from');

            // WHY. A price change re-prices every client on the tier, and an
            // unexplained one is the entry an auditor stops at.
            $table->string('reason', 255);

            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->string('changed_by_name', 120);

            // Null while pending. Stamped the moment the new price takes effect.
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['plan_id', 'effective_from']);
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_price_changes');
    }
};
