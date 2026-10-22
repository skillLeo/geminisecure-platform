<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch operations - CENTRAL, in gs_platform.
 *
 * Four boards could not be built honestly against the schema as it stood, and
 * each gap below is a Build Spec entity or entity field rather than a number a
 * screen wanted:
 *
 *   shifts               the spec's `shift` entity. The post coverage board
 *                        (screen 13) asks whether a post is staffed 7 AM - 7 PM
 *                        and again 7 PM - 7 AM. `guards.post_id` records a
 *                        standing assignment with NO TIME DIMENSION AT ALL, so
 *                        answering two windows from it would mean printing one
 *                        fact under two headings and letting a dispatcher read
 *                        it as knowledge of tonight. On a board whose whole
 *                        purpose is to show which posts are unmanned, that is
 *                        the one thing it must not do.
 *
 *   guards.device_id     the spec's `guard` entity lists `device_id`, and the
 *   guards.device_label  rule attached to it - "one device per guard, so a
 *                        single phone cannot start shifts for several people" -
 *                        is a security control, not a display detail. Screen 15
 *                        reports which guards are bound and reporting. The
 *                        label is separate because a binding identifier is not
 *                        a name: a dispatcher needs to be told "Company Pixel
 *                        7a", not a hash.
 *
 *   guard_requests       the requests inbox (screen 16) has no entity in the
 *   dispatch_messages    spec's table, yet the spec describes the workflow from
 *                        both ends: the Guard App raises leave and equipment
 *                        requests, "a leave request routes to the dispatcher
 *                        requests inbox and its decision is visible here", and
 *                        "every decision records the deciding user". A screen
 *                        that approves something has to have something to
 *                        approve.
 *
 *   guards.leave_        a leave balance is part of an employment record, and
 *   entitlement_days     the inbox states the balance a decision would leave.
 *                        Days taken are derived from approved requests; only
 *                        the entitlement is stored, so the two can never
 *                        disagree.
 *
 * NO POSITION COLUMN IS ADDED ANYWHERE, and that is deliberate. The live map
 * (screen 12) plots a parish, a site and a post - all fixed, all already known.
 * A guard's live coordinates are not modelled, are not stored and are therefore
 * not something this platform can leak or broadcast.
 *
 * NOTHING HERE HOLDS AN AMOUNT. A shift records hours, never a rate; a request
 * records days and items, never a value. Invariant 2 stands on structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            /*
             * The bound handset. Unique because the binding IS the control: two
             * guards sharing a device would let one phone clock in for both,
             * which is exactly the fraud the rule exists to stop. The database
             * refuses it rather than the application remembering to.
             */
            $table->string('device_id', 64)->nullable()->unique()->after('user_id');

            // "Company Pixel 7a", "Personal device". Company-issued versus a
            // guard's own phone is an operational fact a dispatcher acts on.
            $table->string('device_label', 80)->nullable()->after('device_id');

            /*
             * Annual leave entitlement in days. Jamaica's Holidays with Pay Act
             * sets a statutory minimum; the figure is per contract, so it is
             * stored per guard rather than assumed platform-wide.
             */
            $table->unsignedSmallInteger('leave_entitlement_days')->default(14)->after('device_label');
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();

            /*
             * Rostered against actual, kept apart.
             *
             * The roster says a shift should run 7 PM to 7 AM. The device says
             * when it actually started. Collapsing the two would destroy the
             * only evidence that a post stood empty for the first two hours of
             * a night, which is the coverage board's entire subject.
             */
            $table->timestamp('rostered_start');
            $table->timestamp('rostered_end');
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();

            // biometric | supervisor_pin. A PIN start is always recorded, never
            // hidden: it is the documented fallback when biometrics fail, and a
            // fallback nobody can audit is not a fallback.
            $table->string('start_method', 24)->nullable();

            /*
             * How far outside the geofence the clock-in was, in metres, and
             * whether the device reported a simulated location. Both are facts
             * about the START, not a position: a distance is not a coordinate
             * and cannot be turned back into one.
             */
            $table->unsignedSmallInteger('geofence_distance_m')->nullable();
            $table->boolean('mock_location_flag')->default(false);

            // rostered | active | completed | missed
            $table->string('status', 24)->default('rostered');

            $table->timestamps();

            $table->index(['tenant_id', 'rostered_start']);
            $table->index(['post_id', 'rostered_start']);
            $table->index(['guard_id', 'rostered_start']);
        });

        Schema::create('guard_requests', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();

            // leave | equipment | shift_swap | override
            $table->string('kind', 24);

            // vacation | sick | bereavement for leave; the item for equipment.
            $table->string('subject', 80);

            $table->unsignedSmallInteger('quantity')->nullable();   // equipment
            $table->date('starts_on')->nullable();                  // leave
            $table->date('ends_on')->nullable();
            $table->string('reason', 190)->nullable();

            /*
             * Whether supporting evidence is attached, NOT the evidence.
             *
             * A medical certificate is a health record. The inbox needs to know
             * one exists so a dispatcher can decide; it has no business holding
             * the document, and there is no image anywhere in this system.
             */
            $table->boolean('certificate_attached')->default(false);

            // pending | approved | denied | info_requested
            $table->string('status', 24)->default('pending');

            // Every decision records who made it. The spec states this outright
            // for this screen, and the requester can see it.
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 190)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['tenant_id', 'kind']);
        });

        Schema::create('dispatch_messages', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');

            // broadcast (dispatch to every guard at an estate) | inbound (one
            // guard to dispatch). A broadcast names no guard; an inbound one
            // always does.
            $table->string('direction', 16);

            $table->foreignId('guard_id')->nullable()->constrained('guards')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('body', 500);
            $table->timestamp('sent_at')->useCurrent();

            $table->timestamps();

            $table->index(['tenant_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_messages');
        Schema::dropIfExists('guard_requests');
        Schema::dropIfExists('shifts');

        Schema::table('guards', function (Blueprint $table) {
            $table->dropUnique(['device_id']);
            $table->dropColumn(['device_id', 'device_label', 'leave_entitlement_days']);
        });
    }
};
