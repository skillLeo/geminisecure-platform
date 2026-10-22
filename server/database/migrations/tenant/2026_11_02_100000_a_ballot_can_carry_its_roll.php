<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A BALLOT CAN CARRY ITS OWN ROLL (M62 A9; Hassam, 10 October 2026): the households it is put to, chosen and recorded
 * when it is made, never worked out by a rule. The committee's ballot on the 2027 budget goes to seven households: an
 * explicit list of seven ids cannot become 443 by accident, where a derived rule can. Null, as on every ballot before
 * it, means every household the estate's own rule lets vote. Who is told, who sees the ballot in the Resident App, who
 * may vote and how many it counts all read this list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ballots', function (Blueprint $table) {
            $table->json('roll')->nullable()->after('eligible_households');
        });
    }

    public function down(): void
    {
        Schema::table('ballots', function (Blueprint $table) {
            $table->dropColumn('roll');
        });
    }
};
