<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE THE ESTATE'S PATROL STARTS (M30 §7.2, step 3: "The patrol starts at [Main Gate]").
 *
 * The client said it on 2 October (M27, D-225): "their patrol would start at the main gate". Until now that was only
 * where the patrol's shifts happened to be posted. Places now holds it as a fact about the estate, set by the Director,
 * and draws the route from it: the gate first, then the checkpoints in their order.
 *
 *   estate_sites.patrol_post_id   the gate the patrol starts from; null until it is set. A gate that is removed takes
 *                                 it with it (`nullOnDelete`), and the estate's patrol then has no start until it is
 *                                 set again.
 *
 * Additive and nullable: nothing that reads `estate_sites` today reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_sites', function (Blueprint $table): void {
            $table->foreignId('patrol_post_id')->nullable()->after('patrol_interval_minutes')->constrained('posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estate_sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('patrol_post_id');
        });
    }
};
