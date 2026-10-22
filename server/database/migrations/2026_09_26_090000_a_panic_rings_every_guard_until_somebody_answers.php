<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A PANIC RINGS EVERY GUARD'S PHONE LIKE A CALL, UNTIL SOMEBODY ANSWERS (the guard ring).
 *
 * Today a panic pushes the guards on shift or rostered today ONCE, and nothing follows:
 * no second ring, no widening to the estate's other officers when nobody answers, and no
 * word to the other phones when somebody does. These four tables are the ring's own
 * record, written by `App\Services\Ring\GuardRing` and read by the dispatcher's screen.
 *
 * duress_alert_rings         ONE ROW PER ALERT: when the ring began, the last round it
 *                            may reach (0 on the demonstration estate, 1 for a drill),
 *                            and when and why it was ANSWERED — the moment every phone
 *                            is told to stop. `answered_at` is written once, by one
 *                            statement that only the first answer wins.
 *
 * duress_alert_ring_rounds   ONE ROW PER ROUND, all six written at the raise with the
 *                            moment each falls due. A round is CLAIMED by one statement
 *                            before it sends, so the queued job and the safety-net sweep
 *                            can never both send it; what it reached is written when it
 *                            finishes. Mutable: a claim and a finish are updates.
 *
 * duress_alert_ring_targets  EVERY GUARD THIS ALERT HAS RUNG, once each. It is the set
 *                            that may answer: an off-duty officer rung at the escalation
 *                            can open the alert and say "on my way" because they are on
 *                            this list (`PanicFanOut::guardsFor`). A guard's "can't
 *                            respond" is kept here too.
 *
 * duress_alert_ring_events   WHAT HAPPENED TO EACH PHONE: sent the ring, sent the visible
 *                            backup, sent the stop, shown on the phone (the phone's own
 *                            receipt), answered, refused. APPEND-ONLY, with both locks —
 *                            the withheld grant (`GrantedTables::CENTRAL_APPEND_ONLY`) and
 *                            the two triggers below — because it is the only evidence of
 *                            which phones were rung and which said they rang, and a
 *                            record of that which could be edited afterwards is not one.
 *                            The unique key is what makes every send and every receipt
 *                            idempotent: a retried job, a second sweep or a replayed
 *                            receipt is an `insertOrIgnore` that changes nothing.
 *
 *                            `test_seq` IS NOT NULL DEFAULT 0 ON PURPOSE (G24). A test
 *                            ring has no alert (`duress_alert_id` 0), so two tests from
 *                            one phone would share every other column of the key; each
 *                            test has its own number. Nullable would have been wrong the
 *                            other way: MySQL treats NULLs in a unique key as distinct,
 *                            which would silently remove the idempotency of real rings.
 *
 * NO FOREIGN KEYS, for `duress_alert_deliveries`' reason: a cascade would meet the append-
 * only trigger, and an alert nobody may delete needs no cascade. `tenant_id` is on every
 * row so each can be scoped to an estate on its own.
 *
 * EVERY TIME ON THESE ROWS IS WRITTEN FROM PHP, never by MySQL's clock (G8): the only
 * column default is `created_at`'s, and the ring never compares against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duress_alert_rings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('duress_alert_id')->unique();
            $table->string('tenant_id');
            $table->boolean('is_drill')->default(false);

            // The last round this alert may ring: `ring.max_round`, the drill cap, or 0 on the demonstration estate.
            $table->unsignedTinyInteger('max_round');

            // What round 0 had to work with, so round 1 can say WHY it rang at once.
            $table->unsignedInteger('officers_at_raise')->default(0);
            $table->unsignedInteger('handsets_at_raise')->default(0);

            $table->timestamp('started_at');

            // cancelled | closed | responding | acknowledged — `RingRule::answered()`.
            $table->timestamp('answered_at')->nullable();
            $table->string('answered_why', 16)->nullable();

            // The first stop list built, and how many phones it went to.
            $table->timestamp('stop_sent_at')->nullable();
            $table->unsignedInteger('stop_handsets')->nullable();

            $table->timestamps();

            $table->index(['started_at'], 'duress_alert_rings_started_index');
        });

        Schema::create('duress_alert_ring_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('duress_alert_id');
            $table->string('tenant_id');
            $table->unsignedTinyInteger('round');

            // initial | nobody_on_duty | no_handset | unanswered | declined | re_ring
            $table->string('reason', 16);

            $table->timestamp('due_at');
            $table->timestamp('claimed_at')->nullable();

            // job | sweep — the safety net says so on the console.
            $table->string('claimed_by', 6)->nullable();
            $table->timestamp('finished_at')->nullable();

            // What the round reached — counted apart, never added into one "can ring" figure (G11).
            $table->unsignedInteger('officers')->nullable();
            $table->unsignedInteger('phones')->nullable();
            $table->unsignedInteger('phones_ring')->nullable();
            $table->unsignedInteger('phones_visible')->nullable();
            $table->unsignedInteger('phones_ready')->nullable();
            $table->unsignedInteger('phones_partial')->nullable();
            $table->unsignedInteger('heads_texted')->nullable();

            // sent | partly_sent | partly_failed | none | failed | skipped_answered | skipped_late | skipped_drill | skipped_demo | skipped_off
            $table->string('outcome', 20)->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->unique(['duress_alert_id', 'round'], 'duress_alert_ring_rounds_alert_round_unique');
            $table->index(['claimed_at', 'due_at'], 'duress_alert_ring_rounds_due_index');
        });

        Schema::create('duress_alert_ring_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('duress_alert_id');
            $table->string('tenant_id');
            $table->unsignedBigInteger('guard_id');

            // on_shift | estate — who they were rung as.
            $table->string('audience', 10);
            $table->unsignedTinyInteger('first_round');

            // cannot — said for the whole alert; a lock-screen decline is an event only (ruling R10b).
            $table->string('answer', 10)->nullable();
            $table->timestamp('answered_at')->nullable();

            $table->timestamps();

            $table->unique(['duress_alert_id', 'guard_id'], 'duress_alert_ring_targets_alert_guard_unique');
            $table->index(['guard_id'], 'duress_alert_ring_targets_guard_index');
        });

        Schema::create('duress_alert_ring_events', function (Blueprint $table) {
            $table->id();

            // 0 for a test ring, which belongs to no alert.
            $table->unsignedBigInteger('duress_alert_id');
            $table->string('tenant_id');
            $table->unsignedTinyInteger('round')->default(0);
            $table->unsignedInteger('test_seq')->default(0);
            $table->unsignedBigInteger('guard_id')->nullable();
            $table->unsignedBigInteger('push_token_id');

            // sent_ring | sent_visible | sent_stop | sent_visible_stop | sent_test | shown | full_screen | opened | held | dismissed
            // | stopped | suppressed | fallback | answered_responding | answered_cannot | refused | failed | test_requested
            $table->string('event', 20);

            // Never a name, a number or an address: a state, a code, or the phone's sound check.
            $table->string('detail', 60)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['duress_alert_id', 'round', 'test_seq', 'push_token_id', 'event'], 'duress_alert_ring_events_once');
            $table->index(['push_token_id', 'event'], 'duress_alert_ring_events_token_index');
        });

        DB::unprepared("
            CREATE TRIGGER duress_alert_ring_events_no_update BEFORE UPDATE ON duress_alert_ring_events
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'duress_alert_ring_events is append-only: it is the record of which phones were rung about an emergency'
        ");

        DB::unprepared("
            CREATE TRIGGER duress_alert_ring_events_no_delete BEFORE DELETE ON duress_alert_ring_events
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'duress_alert_ring_events is append-only'
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS duress_alert_ring_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS duress_alert_ring_events_no_delete');

        Schema::dropIfExists('duress_alert_ring_events');
        Schema::dropIfExists('duress_alert_ring_targets');
        Schema::dropIfExists('duress_alert_ring_rounds');
        Schema::dropIfExists('duress_alert_rings');
    }
};
