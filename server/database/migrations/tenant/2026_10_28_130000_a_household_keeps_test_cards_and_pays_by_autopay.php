<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "SAVE CARD" AND "TURN ON AUTOPAY" (boards resident-app-10, -35, -40; M50 §4.2 #3, §6.6) — in the estate's own
 * database, beside the dues they pay.
 *
 *   household_cards        a card a resident saved: Stripe's TEST cards only (D-179) — the brand, the last four, the
 *                          expiry and the name. Never a number: the full card never leaves the phone, so there is
 *                          nothing here to steal. `is_demonstration` says so on every row.
 *   autopay_arrangements   one household's standing instruction: pay what is owed, up to `limit_minor`, on
 *                          `day_of_month` (1–28, a day every month has), with that card. `dues:autopay` keeps it, on the
 *                          estate's own clock, through the same demonstration checkout a tap on Pay uses — a real
 *                          receipt, posted to 1090, and no money moves. What happened at its last run is on the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->unsignedBigInteger('resident_account_id');
            $table->string('brand', 16);
            $table->char('last4', 4);
            $table->unsignedTinyInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            $table->string('holder', 80)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_demonstration')->default(true);
            $table->timestamps();

            $table->index('resident_account_id', 'household_cards_account_index');
        });

        Schema::create('autopay_arrangements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->unsignedBigInteger('resident_account_id');
            $table->foreignId('household_card_id')->nullable()->constrained('household_cards')->nullOnDelete();
            $table->unsignedBigInteger('limit_minor');
            $table->unsignedTinyInteger('day_of_month');
            $table->string('status', 8);
            $table->timestamp('turned_on_at')->nullable();
            $table->timestamp('turned_off_at')->nullable();
            $table->date('last_run_on')->nullable();
            $table->string('last_outcome', 200)->nullable();
            $table->unsignedBigInteger('last_payment_id')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'status'], 'autopay_arrangements_unit_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autopay_arrangements');
        Schema::dropIfExists('household_cards');
    }
};
