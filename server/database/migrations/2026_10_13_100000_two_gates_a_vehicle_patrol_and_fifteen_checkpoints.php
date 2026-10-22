<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT PHOENIX PARK ACTUALLY LOOKS LIKE (M26, M27; D-225).
 *
 * The client, 2 October 2026: two gates, "the main gate and the back gate, which is what we call gate number five or
 * phase five gate"; "patrols will be done via a vehicle"; "their patrol would start at the main gate"; fifteen
 * checkpoints, "three on average per phase".
 *
 *   posts.also_called                   the names the estate also uses for a post, "Gate 5, Phase 5 Gate". Every screen
 *                                       that names the post names them beside it (`Post::label()`).
 *   shifts.duty                         `post` (stands the post) or `patrol` (a vehicle patrol that starts at the post
 *                                       and is clocked in there, inside the post's own geofence). A fifty metre circle
 *                                       cannot hold an officer driving across five phases, so the patrol is evidenced
 *                                       by its checkpoint scans and the on duty position trail, never by a fence.
 *   patrol_checkpoints.phase            which phase a checkpoint stands in, printed on its tag (M27 §4.2).
 *   estate_sites.patrol_interval_minutes  how long after one checkpoint the next is due on this estate's patrol, set by
 *                                       the Director (M27 §4.4). Null keeps the platform's 15 minutes; a vehicle
 *                                       patrol across a large estate is slower between tags than a guard in a building.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('also_called', 60)->nullable()->after('name');
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->string('duty', 8)->default('post')->after('post_id');
        });

        Schema::table('patrol_checkpoints', function (Blueprint $table): void {
            $table->string('phase', 32)->nullable()->after('label');
        });

        Schema::table('estate_sites', function (Blueprint $table): void {
            $table->unsignedSmallInteger('patrol_interval_minutes')->nullable()->after('supervisor_phone');
        });
    }

    public function down(): void
    {
        Schema::table('estate_sites', function (Blueprint $table): void {
            $table->dropColumn('patrol_interval_minutes');
        });

        Schema::table('patrol_checkpoints', function (Blueprint $table): void {
            $table->dropColumn('phase');
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropColumn('duty');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('also_called');
        });
    }
};
