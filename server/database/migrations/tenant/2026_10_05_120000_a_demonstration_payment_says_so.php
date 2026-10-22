<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * THE TEST-CARD CHECKOUT, PAID FOR REAL IN THE BOOKS AND NEVER IN THE BANK (M12 §2.6; D-179).
 *
 * The client asked for a card checkout that takes a payment through to a receipt and a lower balance, marked as a
 * demonstration. So a demonstration payment is a real Payment with a real receipt number, and it clears the
 * household's receivable exactly as money would — but the debit side is NOT the estate's bank:
 *
 *   1090 Demonstration Card Payments (no money)   an asset account that holds only what test cards "paid". The
 *                                                 operating bank account never sees a cent that did not arrive,
 *                                                 and a treasurer reads the whole of it on one line.
 *   payments.is_demonstration                     the receipt, the resident's list and the console say so
 *   amenity_bookings.deposit_demonstration        a deposit a test card paid is held, refunded and forfeited
 *                                                 against 1090, never against the bank
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->boolean('is_demonstration')->default(false)->after('status');
        });

        Schema::table('amenity_bookings', function (Blueprint $table): void {
            $table->boolean('deposit_demonstration')->default(false)->after('deposit_state');
        });

        // An estate whose chart is not seeded yet gets 1090 with the rest of it (ChartOfAccountsSeeder).
        if (DB::table('accounts')->exists() && ! DB::table('accounts')->where('code', '1090')->exists()) {
            DB::table('accounts')->insert([
                'code' => '1090',
                'name' => 'Demonstration Card Payments (no money)',
                'type' => 'asset',
                'is_control' => false,
                'subsidiary' => null,
                'is_bank_account' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('amenity_bookings', function (Blueprint $table): void {
            $table->dropColumn('deposit_demonstration');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('is_demonstration');
        });
    }
};
