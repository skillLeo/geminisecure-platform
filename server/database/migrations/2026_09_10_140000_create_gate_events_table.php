<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Gemini's guards did at the gate, across every client.
 *
 * WHY THIS IS CENTRAL, AND WHY THAT IS NOT AN ISOLATION BREACH.
 *
 * The estate's own record of an arrival lives in its own database, joined to
 * the household and the pass that authorised it. That stays there. This is a
 * different record of the same moment: Gemini Security's account of work its
 * own employee performed, on a post it staffs, under a contract it holds.
 *
 * The distinction matters because the live gate activity board is explicitly
 * ACROSS ALL CLIENTS, and a cross-client view can only be built two ways —
 * this, or a query that opens every estate database in turn. The second is the
 * breach. A row written centrally at the moment of the scan is not: no estate
 * is ever read from another estate's context, and each row is written by the
 * request that caused it.
 *
 * WHAT IT DELIBERATELY CANNOT HOLD. There is no household id, no resident id,
 * no charge, no balance and no room for one. `subject` is the free-text
 * description a guard sees on their own screen — "Marcia James · Lot 47" — and
 * a unit reference is not a resident. Invariant 2 stands on the shape of this
 * table rather than on anybody remembering.
 *
 * No position either. A gate is a fixed place and `post_id` names it; nothing
 * here is a coordinate and nothing here could be turned into a track.
 *
 * WHAT IT DOES NOT HOLD, EITHER: checkpoint scans and shift starts. Those are
 * already recorded centrally, in `checkpoint_scans` and `shifts`, by the screens
 * that own them. Writing a second copy here so one query could serve the
 * activity feed would create two records of one event that can disagree — and
 * the one that disagrees is the one an insurer reads. The feed reads all three
 * tables and merges; only the gate DECISION, which has no other central home,
 * lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_events', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            /*
             * Nullable, both of them. A guard can leave the company and a post
             * can be retired; the record of what happened at that gate on that
             * night cannot leave with them. The names are kept alongside for
             * the same reason.
             */
            $table->foreignId('guard_id')->nullable()->constrained('guards')->nullOnDelete();
            $table->string('guard_name', 160)->nullable();

            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->string('post_name', 120)->nullable();

            // admit | deny | override — what the guard decided at the gate.
            $table->string('verdict', 16);

            // guest | delivery | contractor | resident | emergency | patrol
            $table->string('category', 32)->nullable();

            /*
             * What the guard was told, in the words they were told it. Not a
             * resident reference: the estate holds that, and copying an id
             * here would make this table a way to enumerate households.
             */
            $table->string('subject', 200);

            // "QR pass", "pre-approved", "manual code", "no pass on file".
            $table->string('basis', 120)->nullable();

            /*
             * Device time beside server time. A gate handset can be offline
             * and sync later, and the difference between when a guard says
             * something happened and when the platform heard about it is
             * itself evidence.
             */
            $table->timestamp('occurred_at');
            $table->timestamp('device_time')->nullable();

            /*
             * The handset's own key for this decision, unique.
             *
             * A gate phone can lose signal mid-shift, queue what it recorded and
             * push the queue when it comes back — and a push that times out
             * gets retried. Without this, one admit uploaded twice becomes two
             * admits, and the day's count is wrong in the direction nobody
             * checks. `checkpoint_scans` and `duress_alerts` carry the same
             * column for the same reason.
             */
            $table->string('idempotency_key', 64)->nullable()->unique();

            /*
             * Whether this row came from a handset or from the seed. Every
             * device-originated table in this platform carries it, so a console
             * can say plainly which of the two it is showing rather than
             * presenting demonstration data as a record of a real night.
             */
            $table->boolean('is_simulated')->default(false);

            $table->timestamps();

            $table->index(['occurred_at']);
            $table->index(['tenant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_events');
    }
};
