<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M84'S BUDGET LINES AND MANUAL JOURNAL DRAFTS CARRY THE SEEDED MARK (M33 §5.2, KI-25), like every estate table a
 * seeder may write. `budget_lines` and `journal_drafts` arrived without `seeded_at` and `seeded_by`; SeededMarksTest
 * holds every estate table to SeededTables' lists (gate 2 on the Sunday package, part 36). Additive: two nullable
 * columns each, null on every row a person sets or drafts.
 */
return new class extends Migration
{
    private const TABLES = ['budget_lines', 'journal_drafts'];

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
