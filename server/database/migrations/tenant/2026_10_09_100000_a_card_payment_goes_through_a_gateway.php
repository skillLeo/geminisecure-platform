<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A CARD PAYMENT THAT GOES THROUGH A REAL GATEWAY (M16 §2.2, M17 §1.2; App\Services\Estate\CardGateway).
 *
 *   payment_intents           one resident's attempt to pay, keyed by the Idempotency-Key the phone chose before
 *                             the first tap — so a double tap or a retry finds the intent it already made instead
 *                             of charging twice. Its state only ever moves forward, and only a signed webhook
 *                             moves it.
 *   payment_gateway_events    every webhook the provider sent, once each: `(provider, event_id)` is unique, so a
 *                             redelivered or replayed event is recognised and answered without being applied again.
 *                             Written once and never edited by the application.
 *
 *   1080 Card Payments in Transit   where a captured card payment sits until the provider pays it out. The
 *                                   household's receivable is cleared at capture (the resident has paid), but the
 *                                   operating bank account only moves when the money actually arrives.
 *   5910 Bank & Card Charges        the provider's fee, taken at payout, so the books agree with the bank statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('unit_id')->constrained('units');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');
            $table->string('status', 16)->default('created')->index();
            $table->string('provider', 32);
            $table->string('provider_ref', 64)->nullable();
            // The provider's hosted card page the resident is sent to; a retried tap is sent to the same one.
            $table->string('next_action_url', 512)->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('payer_account_id')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('decline_reason')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->unsignedBigInteger('captured_minor')->default(0);
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('settled_net_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->unsignedBigInteger('refunded_before_settlement_minor')->default(0);
            $table->string('settlement_journal_ref', 32)->nullable();
            $table->timestamp('authorised_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->boolean('charged_back')->default(false);
            $table->timestamps();

            $table->unique(['provider', 'provider_ref']);
        });

        Schema::create('payment_gateway_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 128);
            $table->string('event_type', 48);
            $table->string('intent_reference', 24)->nullable()->index();
            $table->timestamp('occurred_at', 6)->nullable();
            $table->char('payload_sha256', 64);
            $table->string('outcome', 16);
            $table->string('note')->nullable();
            $table->timestamp('received_at', 6);

            $table->unique(['provider', 'event_id']);
        });

        // An estate whose chart is not seeded yet gets both with the rest of it (ChartOfAccountsSeeder).
        if (DB::table('accounts')->exists()) {
            foreach ([
                ['1080', 'Card Payments in Transit', 'asset'],
                ['5910', 'Bank & Card Charges', 'expense'],
            ] as [$code, $name, $type]) {
                if (DB::table('accounts')->where('code', $code)->exists()) {
                    continue;
                }

                DB::table('accounts')->insert([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'is_control' => false,
                    'subsidiary' => null,
                    'is_bank_account' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_events');
        Schema::dropIfExists('payment_intents');
    }
};
