<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CHECKPOINT SCAN SAYS WHERE, AND ON WHICH SHIFT (M21 §1.1).
 *
 * A scan was the tag's code and a time. Nothing recorded the shift it belonged to, where the phone was when the tag
 * was read, or that it came before the tour's cadence was up — so a tag photographed and scanned from the gatehouse
 * read the same as one scanned where it hangs. Now:
 *
 *   patrol_checkpoints.radius_m   how close a phone must be to the checkpoint's placed position for its scan to
 *                                 count; null reads as PatrolCheckpoint::DEFAULT_RADIUS_M
 *   checkpoint_scans.shift_id     the shift the guard was standing when the tag was reached
 *   .latitude/.longitude          where the phone was, as it said, and .accuracy_m its own estimate of how sure
 *   .distance_m                   how far that was from the checkpoint's placed position, when both were known
 *   .early                        the same guard scanned the same checkpoint within the tour cadence before
 *
 * All additive and nullable (early defaults false), so every scan already made reads as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patrol_checkpoints', function (Blueprint $table): void {
            $table->unsignedSmallInteger('radius_m')->nullable()->after('longitude');
        });

        Schema::table('checkpoint_scans', function (Blueprint $table): void {
            $table->unsignedBigInteger('shift_id')->nullable()->after('guard_id');
            $table->decimal('latitude', 10, 7)->nullable()->after('shift_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedSmallInteger('accuracy_m')->nullable()->after('longitude');
            $table->unsignedInteger('distance_m')->nullable()->after('accuracy_m');
            $table->boolean('early')->default(false)->after('distance_m');
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('checkpoint_scans', function (Blueprint $table): void {
            $table->dropIndex(['shift_id']);
            $table->dropColumn(['shift_id', 'latitude', 'longitude', 'accuracy_m', 'distance_m', 'early']);
        });

        Schema::table('patrol_checkpoints', function (Blueprint $table): void {
            $table->dropColumn('radius_m');
        });
    }
};
