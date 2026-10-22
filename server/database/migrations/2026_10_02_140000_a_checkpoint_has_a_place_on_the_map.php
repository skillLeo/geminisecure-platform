<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WHERE A PATROL CHECKPOINT IS (M11 §6).
 *
 * A post has its surveyed position and geofence (posts.latitude/longitude/geofence_radius_m); a checkpoint had a
 * label, a code and a place in the tour, and no position at all — so the guard's checkpoint map and the Gemini
 * console's geofence editor had nothing to draw it at. Both nullable: a checkpoint nobody has placed yet is
 * listed, and said to be unplaced, rather than drawn at a guess. Placed from the Gemini console's geofence editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patrol_checkpoints', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('sequence');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('patrol_checkpoints', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
