<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DEMONSTRATION PLACEHOLDER CAN BE RETIRED, NOT DELETED (M32 F1).
 *
 *   retired_at       when `residents:retire-samples` retired a "Lot N householder" from a household a real person now
 *                    holds. A retired person is REMOVED too (`removed_at`), so the default scope hides them from every
 *                    list; this says the removal was a retirement, not a member the household took off
 *   retired_reason   the command's own words, naming the real person who holds the household now
 *
 * Additive: two nullable columns. Nothing is written by the migration, and nothing is deleted by anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            if (! Schema::hasColumn('residents', 'retired_at')) {
                $table->timestamp('retired_at')->nullable();
            }
            if (! Schema::hasColumn('residents', 'retired_reason')) {
                $table->string('retired_reason', 300)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            foreach (['retired_at', 'retired_reason'] as $column) {
                if (Schema::hasColumn('residents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
