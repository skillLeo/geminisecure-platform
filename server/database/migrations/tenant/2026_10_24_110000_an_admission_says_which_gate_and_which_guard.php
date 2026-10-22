<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WHERE AND BY WHOM A VISITOR WAS LET IN, ON THE HOUSEHOLD'S SIDE (M47 §5.4).
 *
 * The Visitors tab's Recent activity reads "Entered · Main Gate · by Guard Whyte" on the board, and the household's
 * phone could say only "Entered": `last_admitted_at` recorded WHEN, and the gate log (central, and deliberately
 * unable to name a household) recorded where and by whom. The gate now writes both beside the moment, as it admits:
 *
 *   visitor_passes.last_admitted_post   the gate, by the name the estate gives the post ("Main Gate")
 *   visitor_passes.last_admitted_by     the guard who let them in, by name
 *
 * Snapshots, like the gate log's own: a guard who leaves or a gate renamed later does not rewrite what happened.
 * Additive — two trailing nullable columns, no default, no index; a pass admitted before this carries neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_passes', function (Blueprint $table): void {
            if (! Schema::hasColumn('visitor_passes', 'last_admitted_post')) {
                $table->string('last_admitted_post', 120)->nullable();
            }
            if (! Schema::hasColumn('visitor_passes', 'last_admitted_by')) {
                $table->string('last_admitted_by', 160)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('visitor_passes', function (Blueprint $table): void {
            foreach (['last_admitted_post', 'last_admitted_by'] as $column) {
                if (Schema::hasColumn('visitor_passes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
