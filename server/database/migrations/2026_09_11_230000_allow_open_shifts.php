<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An open shift — a post that needs somebody, and nobody yet (12 §2, Wave 5).
 *
 * `guard_id` BECOMES NULLABLE, and that is the whole of this migration. Board 26
 * draws "Post open shift" and boards 20 and 21 offer to "post open shifts to
 * cover" for an officer who cannot stand their post; both produce a shift with
 * a post, a date and a window, and no officer. Forcing a guard onto the row
 * would mean either inventing a placeholder person — who would then appear on
 * the coverage board as somebody standing a gate — or not recording the gap at
 * all, which is the one thing an operations console exists to show.
 *
 * THE COVERAGE BOARD ALREADY READS `actual_start`, so an open shift shows as
 * uncovered without any change there: nobody clocked in because nobody was
 * rostered, and that is exactly what it should say.
 *
 * `released_reason` IS WHY THE SHIFT IS OPEN. A shift released because an
 * officer was suspended is a different operational fact from one posted because
 * the estate asked for extra cover, and a dispatcher picking up the phone needs
 * to know which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('guard_id')->nullable()->change();

            // Why it is open, where it was not posted open from the start.
            $table->string('released_reason', 190)->nullable()->after('status');

            $table->unsignedBigInteger('posted_by_id')->nullable()->after('released_reason');
            $table->string('posted_by_name', 120)->nullable()->after('posted_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['released_reason', 'posted_by_id', 'posted_by_name']);
        });
    }
};
