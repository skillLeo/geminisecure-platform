<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AN ALERTNESS CHECK IS A TAP AND A TIME, AND NOW THE TABLE SAYS SO (Legal review pack §7.10).
 *
 * `alertness_checks.score` was built for a camera-based drowsiness check that never shipped.
 * The Guard App has only ever sent the tap, and the Privacy Policy promises that no image,
 * template or score leaves the phone. A nullable column nobody writes still reads, to anybody
 * who finds it, like a place the promise could be broken - so it goes, together with the
 * request field that could have filled it.
 *
 * Nothing reads it: no console screen, no report, no export. Adds no table, so no `grants:*`
 * run and no `GrantedTables` entry. The down() puts the empty column back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('alertness_checks', 'score')) {
            Schema::table('alertness_checks', function (Blueprint $table): void {
                $table->dropColumn('score');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('alertness_checks', 'score')) {
            Schema::table('alertness_checks', function (Blueprint $table): void {
                $table->unsignedTinyInteger('score')->nullable()->after('guard_id');
            });
        }
    }
};
