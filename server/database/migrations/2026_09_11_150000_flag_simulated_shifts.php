<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one device-written table that carried no `is_simulated`.
 *
 * `duress_alerts`, `alertness_checks`, `checkpoint_scans` and `gate_events` all
 * say whether a handset or the simulator wrote them, and the source badge on
 * their screens is computed from that. A shift's clock-in is written by the
 * same handset through the same API, and the post coverage board is drawn from
 * it — so the board could show every post manned by the simulator with nothing
 * on screen saying so. Part C asked for a badge on every screen that shows
 * device data; this is the column that makes the coverage board able to carry
 * one honestly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('is_simulated')->default(false)->after('mock_location_flag');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('is_simulated');
        });
    }
};
