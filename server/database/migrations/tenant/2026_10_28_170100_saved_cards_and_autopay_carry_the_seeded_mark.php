<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A HOUSEHOLD'S SAVED TEST CARDS AND ITS AUTOPAY CARRY THE SEEDED MARK (M33 §5.2, KI-25) — like every estate table a
 * seeder may write. `household_cards` and `autopay_arrangements` (M50 §4.2 #3) arrived without `seeded_at` and
 * `seeded_by`; SeededMarksTest holds every estate table to SeededTables' lists. Additive: two nullable columns each.
 */
return new class extends Migration
{
    private const TABLES = ['household_cards', 'autopay_arrangements'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (! Schema::hasColumn($name, 'seeded_at')) {
                    $table->timestamp('seeded_at')->nullable();
                }
                if (! Schema::hasColumn($name, 'seeded_by')) {
                    $table->string('seeded_by', 64)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                foreach (['seeded_at', 'seeded_by'] as $column) {
                    if (Schema::hasColumn($name, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
