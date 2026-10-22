<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a dispatcher classified an alert when resolving it (App\Support\AlertClassification).
 *
 * NULLABLE, AND NEVER BACKFILLED. Every alert resolved before this column existed
 * was classified in free text, in `resolution_note`, and guessing a category from
 * that text would put words in a dispatcher's mouth. Those rows keep their note
 * and show no category.
 *
 * `duress_alerts` is CENTRAL_MUTABLE on purpose (GrantedTables, Q-M43): an alert is
 * acknowledged and resolved after it is raised. Adding a column changes no grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duress_alerts', function (Blueprint $table) {
            $table->string('resolution_classification', 32)->nullable()->after('resolution_note');
        });
    }

    public function down(): void
    {
        Schema::table('duress_alerts', function (Blueprint $table) {
            $table->dropColumn('resolution_classification');
        });
    }
};
