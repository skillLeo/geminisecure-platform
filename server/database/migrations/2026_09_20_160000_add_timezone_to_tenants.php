<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The site's time zone, on the estate record (M4 §3.1).
 *
 * A SITE IS A ROW IN `tenants`. There is no `sites` table: the estate carries its own address,
 * parish, gates and phases (`add_site_columns_to_tenants_table`), and the Guard App's `site.id`
 * is this row's id. So `sites.timezone` in the ruling is `tenants.timezone`.
 *
 * AN IANA NAME, NEVER AN OFFSET. Storage is UTC and stays UTC. What the office types at an
 * estate — a 07:00 shift, a 10:00 AGM — is a wall-clock time in THIS zone, stored as the UTC
 * moment it means and sent to a phone as that moment with this name beside it. Jamaica keeps
 * no daylight saving; the next territory may, and only the zone's name knows when.
 *
 * Every estate that exists today is in Jamaica, which is the default, so there is no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('timezone', 64)->default('America/Jamaica')->after('parish');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
