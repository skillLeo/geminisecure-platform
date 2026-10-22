<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an estate physically is, and how it is laid out.
 *
 * The Build Spec's `estate` entity reads:
 *
 *     id, name, parish, subdomain, unit_count, gate_count, phase structure,
 *     geofence polygon, geofence tolerance (metres), plan, status
 *
 * Of those, `parish`, `gate_count` and `phase structure` had never been
 * modelled, and `address_line` — not in that list — is missing too. A security
 * company's client record has to say where the site is: dispatch sends a
 * supervisor there, a guard is posted there, and a client detail screen that
 * cannot name the street is not a record of a physical place.
 *
 * These are stored as REAL COLUMNS rather than left in stancl's `data` JSON,
 * because the Gemini Console filters and sorts clients by them and a JSON
 * extract cannot use an index.
 *
 * PHASE STRUCTURE IS A STRUCTURE, NOT A COUNT.
 *
 * The boards display "5 phases", and it would be smaller to store the 5. But a
 * phase has a name residents use ("Phase 2-5" is a real guard post here), units
 * belong to one, and amenity bookings and ballots are scoped by them. Storing
 * the count would mean re-deriving the names from somewhere else the first time
 * any of that is built. The count is derived from the structure; never the
 * other way round.
 *
 * Geofence polygon and tolerance are the two entity fields still unmodelled.
 * They are deliberately not added here: they are the Guard App's clock-in
 * boundary, they need real surveyed coordinates rather than a nullable column
 * nobody populates, and nothing reads them until Phase 3. Recorded in
 * DECISIONS.md rather than stubbed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // "Waterloo Road" — the street line only. The parish is its own
            // field because it is an administrative division Jamaicans filter
            // and report by, not part of the street address.
            $table->string('address_line')->nullable()->after('name');

            // One of Jamaica's 14 parishes, e.g. "St. Andrew".
            $table->string('parish', 40)->nullable()->after('address_line');

            // Staffed entry points. Distinct from guard posts: a gate may carry
            // several posts across a shift, and a patrol post has no gate.
            $table->unsignedSmallInteger('gate_count')->nullable()->after('parish');

            /*
             * Ordered list of phase labels, e.g. ["Phase 1", ... , "Phase 5"].
             * The displayed count is count(phases).
             */
            $table->json('phases')->nullable()->after('gate_count');

            // Clients are listed and reported by parish.
            $table->index('parish');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['parish']);
            $table->dropColumn(['address_line', 'parish', 'gate_count', 'phases']);
        });
    }
};
