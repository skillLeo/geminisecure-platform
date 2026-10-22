<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AN ESTATE'S OWN BOUNDARY, THE SHAPE ITS SITE PLAN DRAWS (M57.5 §4.5).
 *
 * The estate's fence was a circle (`estate_sites.latitude`, `longitude`, `geofence_radius_m`): 50 m round a point for a
 * strip nearly three kilometres long, and nothing to say a guard's position in Pakistan was not on the estate. This is
 * the heavy blue "project boundary" line of the site plan as a polygon of [latitude, longitude] points, and where they
 * came from (`boundary_source`: the plan, and how it was placed on the map). The live maps draw it; a guard's position
 * far outside it is refused as impossible (GuardTracking). The circle stays: posts and clock-ins still use theirs.
 *
 * Set by `estate:structure-from-plan` after its dry run, never by a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_sites', function (Blueprint $table) {
            $table->json('boundary')->nullable()->after('geofence_radius_m');
            $table->string('boundary_source', 255)->nullable()->after('boundary');
        });
    }

    public function down(): void
    {
        Schema::table('estate_sites', function (Blueprint $table) {
            $table->dropColumn(['boundary', 'boundary_source']);
        });
    }
};
