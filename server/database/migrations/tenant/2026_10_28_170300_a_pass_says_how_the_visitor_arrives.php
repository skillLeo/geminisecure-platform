<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOW THE VISITOR ARRIVES (M50 §6.3, §6.10) — Walk in, Drive in or Ride in, as Invite visitor asks it. The pass kept
 * only `no_vehicle`, so the pass screen could say no more than "On foot or by taxi" for both a visitor on foot and a
 * passenger in somebody else's car. Now it keeps which: `walk`, `drive` or `ride` — null on a pass issued before.
 * Additive: one nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('visitor_passes') && ! Schema::hasColumn('visitor_passes', 'arrival')) {
            Schema::table('visitor_passes', function (Blueprint $table): void {
                $table->string('arrival', 8)->nullable()->after('no_vehicle');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('visitor_passes', 'arrival')) {
            Schema::table('visitor_passes', function (Blueprint $table): void {
                $table->dropColumn('arrival');
            });
        }
    }
};
