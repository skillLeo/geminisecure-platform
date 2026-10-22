<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A HAND-OFF SAYS WHICH RING IT WAS (the guard ring).
 *
 * `duress_alert_deliveries` was built for one fan-out per alert. The ring adds a leg per
 * round — "Ring 2 (escalation): 14 handsets of 11 officers" — and each says which round
 * it was. NULL keeps every row already written, and every row the raise writes, meaning
 * exactly what it meant: the raise.
 *
 * An ALTER is not a row UPDATE and fires neither append-only trigger; the withheld grant
 * is untouched. Nothing already written is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duress_alert_deliveries', function (Blueprint $table) {
            $table->unsignedTinyInteger('round')->nullable()->after('destination');
        });
    }

    public function down(): void
    {
        Schema::table('duress_alert_deliveries', function (Blueprint $table) {
            $table->dropColumn('round');
        });
    }
};
