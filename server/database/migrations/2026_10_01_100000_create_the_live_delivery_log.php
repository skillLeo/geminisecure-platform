<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * THE LIVE DELIVERY LOG, CENTRAL HALF (M11 Part C, DESIGN.md §1.2).
 *
 * "If a record changes anywhere, every screen currently showing that record updates itself." Every
 * change writes one row here INSIDE ITS OWN TRANSACTION (the outbox, App\Live\LiveBus), so a
 * rollback broadcasts nothing and a commit cannot be lost between the database and the socket. A
 * relay then gives committed rows a gap-free sequence number and publishes them.
 *
 * live_events       One row per (event, audience). `id` is staging order only and is NEVER a
 *                   cursor: an auto-increment is handed out at INSERT, not at COMMIT, so a slow
 *                   transaction can commit a lower id after a higher one was read. `seq` is the
 *                   monotonic id (C6), NULL until App\Live\Relay\Sequencer gives it, and it is
 *                   also the record's version on the wire, so no version columns are added
 *                   anywhere. `summary` holds SAFE keys only (App\Live\SummaryPolicy): anything a
 *                   screen needs beyond them is hydrated when published and never stored here.
 *                   `available_at` NULL means HELD until LiveBus::release(); a future value is a
 *                   scheduled row the sequencer leaves until its time.
 *
 * live_deliveries   One row per (event, channel), written ONLY by the sequencer. `prev_seq` is the
 *                   previous seq on the same channel in this stream, which is what lets a client
 *                   see an exact gap rather than guess one.
 *
 * live_streams      Exactly one row, 'central'. `epoch` is made here, so a database rebuilt from
 *                   nothing is a new epoch and every client cursor into the old one is reset.
 *
 * WRITTEN ONCE, BY TRIGGER. These are delivery logs, not records of truth (that stays with the
 * business tables and `audit_log`), so pruning must be able to delete, and there is no DELETE
 * trigger. What must never happen is a row that says one thing when written and another when read
 * back by a catch-up a minute later: the content columns are refused any update, a seq once given
 * never changes, and a delivery is never updated at all.
 *
 * TEXT IS COMPARED AS BYTES. The identifying columns and every channel name use `utf8mb4_bin`, so
 * `estate.pp.security` and `Estate.PP.security` are two different keys and a change of case alone
 * is an update the trigger sees. Under the connection's `_ci` collation both would pass as equal.
 *
 * TIMES ARE THE DATABASE'S OWN, IN UTC. Every column here is written and compared with
 * UTC_TIMESTAMP(6), never with a PHP time and never with NOW(6): the session zone on a MySQL
 * server is whatever the machine says (it is not UTC on the development laptop), and the log's
 * clock must agree with itself before it agrees with anything else.
 *
 * The estate half is `database/migrations/tenant/2026_10_01_100000_create_the_live_delivery_log.php`,
 * the same tables in every `gs_estate_*` (stream 'estate'). Both are in GrantedTables' mutable lists:
 * the relay updates `seq`, `published_at` and the stream head, and the prune deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seq')->nullable();
            $table->string('tenant_id', 64)->collation('utf8mb4_bin')->nullable();
            $table->string('type', 64)->collation('utf8mb4_bin');
            $table->string('entity', 40)->collation('utf8mb4_bin');
            $table->string('entity_id', 64)->collation('utf8mb4_bin');
            $table->enum('mode', ['ref', 'inline']);
            $table->string('hydrate', 40)->collation('utf8mb4_bin')->nullable();
            $table->json('summary')->nullable();
            $table->json('channels');
            $table->boolean('urgent')->default(false);
            $table->boolean('simulated')->default(false);
            $table->char('origin', 16)->collation('utf8mb4_bin')->nullable();
            $table->dateTime('available_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('sequenced_at', 6)->nullable();
            $table->dateTime('published_at', 6)->nullable();
            $table->unsignedSmallInteger('publish_attempts')->default(0);
            $table->dateTime('publish_failed_at', 6)->nullable();

            $table->unique('seq', 'live_events_seq_unique');
            $table->index(['seq', 'available_at', 'id'], 'live_events_unsequenced');
            $table->index(['published_at', 'seq'], 'live_events_unpublished');
        });

        Schema::create('live_deliveries', function (Blueprint $table) {
            $table->string('channel', 160)->collation('utf8mb4_bin');
            $table->unsignedBigInteger('seq');
            $table->unsignedBigInteger('prev_seq')->nullable();
            $table->unsignedBigInteger('event_id');

            $table->primary(['channel', 'seq']);
            $table->index('seq', 'live_deliveries_seq');
        });

        Schema::create('live_streams', function (Blueprint $table) {
            $table->string('stream', 16)->collation('utf8mb4_bin')->primary();
            $table->unsignedBigInteger('head_seq')->default(0);
            $table->unsignedBigInteger('floor_seq')->default(0);
            $table->char('epoch', 26)->collation('utf8mb4_bin');
            $table->dateTime('ticked_at', 6)->nullable();
            $table->dateTime('relay_seen_at', 6)->nullable();
            $table->dateTime('updated_at', 6);
        });

        DB::unprepared("
            CREATE TRIGGER live_events_written_once BEFORE UPDATE ON live_events
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.type <=> OLD.type)
                    OR NOT (NEW.entity <=> OLD.entity)
                    OR NOT (NEW.entity_id <=> OLD.entity_id)
                    OR NOT (NEW.mode <=> OLD.mode)
                    OR NOT (NEW.hydrate <=> OLD.hydrate)
                    OR NOT (NEW.summary <=> OLD.summary)
                    OR NOT (NEW.channels <=> OLD.channels)
                    OR NOT (NEW.tenant_id <=> OLD.tenant_id)
                    OR NOT (NEW.origin <=> OLD.origin)
                    OR NOT (NEW.created_at <=> OLD.created_at)
                    OR NOT (NEW.urgent <=> OLD.urgent)
                    OR NOT (NEW.simulated <=> OLD.simulated)
                THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'live_events is written once: what an event says never changes after it is recorded';
                END IF;

                IF OLD.seq IS NOT NULL AND NOT (NEW.seq <=> OLD.seq) THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'live_events is written once: a seq, once given, never changes';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER live_deliveries_written_once BEFORE UPDATE ON live_deliveries
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'live_deliveries is written once: a delivery is never updated'
        ");

        DB::table('live_streams')->insert([
            'stream' => 'central',
            'head_seq' => 0,
            'floor_seq' => 0,
            'epoch' => (string) Str::ulid(),
            'updated_at' => DB::raw('UTC_TIMESTAMP(6)'),
        ]);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS live_events_written_once');
        DB::unprepared('DROP TRIGGER IF EXISTS live_deliveries_written_once');

        Schema::dropIfExists('live_streams');
        Schema::dropIfExists('live_deliveries');
        Schema::dropIfExists('live_events');
    }
};
