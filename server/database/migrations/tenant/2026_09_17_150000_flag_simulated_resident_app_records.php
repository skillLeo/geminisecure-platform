<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The estate tables the Resident App writes, flagged real or simulated (13 C3).
 *
 * Maintenance tickets, amenity bookings, unit claims and ballot receipts — each
 * gets a Resident App endpoint in 13 D3, and each is the primary dataset of a
 * Category B screen. See the central migration of the same date for why the
 * flag is on the row.
 *
 * ON THE RECEIPT, NEVER THE MARK. A ballot receipt already names its household;
 * saying whether a simulator cast it links nothing new. A flag on
 * `ballot_marks` would be one more column a mark could be matched on, and the
 * two tables share no key by design (see `create_governance_tables`). Adding a column is DDL, which the
 * receipts' no-update trigger does not fire on.
 */
return new class extends Migration
{
    private const TABLES = ['maintenance_tickets', 'amenity_bookings', 'unit_claims', 'ballot_receipts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('is_simulated')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('is_simulated');
            });
        }
    }
};
