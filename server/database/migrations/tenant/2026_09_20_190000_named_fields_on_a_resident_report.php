<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the resident's own two screens ask for, as COLUMNS (M4 §3.5).
 *
 * Until now the Resident App appended three of these to the description as
 * labelled lines — "Entry when I'm not home: allowed", "Best time to come: …" —
 * because the ticket had nowhere to put them. The ruling: "Add proper columns.
 * These are named fields on a named screen in the specification, not free text."
 *
 *   access_permitted    the household said somebody may let a technician in while
 *                       nobody is home. NULLABLE and three-valued on purpose:
 *                       null is "the request did not say" (every ticket raised in
 *                       the office, and every one filed before this migration),
 *                       which is not the same statement as "no".
 *   preferred_time      when the household would like the visit, in their own
 *                       words — the form offers a free line, so the column is one.
 *                       It is a note, never a booking: nothing schedules from it.
 *   latitude/longitude  where the resident was standing when they sent a SECURITY
 *   location_accuracy_m report, if they agreed to attach it. Same precision as
 *                       `posts.latitude` and `security_incidents.latitude`
 *                       (decimal 10,7 — about a centimetre), and the fix's own
 *                       accuracy beside it, because a pin with no accuracy invites
 *                       a guard to trust a 2 km circle as a doorstep.
 *   happening_now       the resident said it is happening AS THEY WRITE. It alerts
 *                       the guards on duty at that site and appears in their
 *                       handsets' `sync.pull` — and it changes NOTHING about who
 *                       the ticket belongs to: community staff, source resident.
 *                       "Notification is not ownership."
 *
 * Six additive, nullable columns (one boolean defaulting to false). Nothing
 * existing is touched, and a ticket that carries its notes in the description
 * still reads exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tickets', function (Blueprint $table): void {
            $table->boolean('access_permitted')->nullable()->after('description');
            $table->string('preferred_time', 80)->nullable()->after('access_permitted');
            $table->decimal('latitude', 10, 7)->nullable()->after('preferred_time');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedSmallInteger('location_accuracy_m')->nullable()->after('longitude');
            $table->boolean('happening_now')->default(false)->after('location_accuracy_m');

            // The guards' pull asks one question of this table: "anything happening
            // now since I last looked?" — answered from the index, never a scan.
            $table->index(['happening_now', 'reported_at'], 'mt_happening_now_reported_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tickets', function (Blueprint $table): void {
            $table->dropIndex('mt_happening_now_reported_at');
            $table->dropColumn(['access_permitted', 'preferred_time', 'latitude', 'longitude', 'location_accuracy_m', 'happening_now']);
        });
    }
};
