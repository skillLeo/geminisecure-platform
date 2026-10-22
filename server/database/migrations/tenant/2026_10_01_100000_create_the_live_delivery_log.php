<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * THE LIVE DELIVERY LOG, ESTATE HALF (M11 Part C, DESIGN.md §1.1, §1.2).
 *
 * THE SAME THREE TABLES AS THE CENTRAL LOG, IN THE ESTATE'S OWN DATABASE, and that is the point.
 * A change and its event can share one transaction only if they are in the same database, and an
 * estate's tables carry no tenant column: the database is the boundary. So an estate's events
 * never leave it; a cross-estate read of this log fails on a grant error, like any other estate
 * table, and the estate's seq is its own (stream 'estate').
 *
 * Every column, index, collation and trigger is identical to the central migration's, whose note
 * says why each is there; `tests/Feature/Live/LiveBusTest.php` compares the two databases' CREATE
 * TABLE text and triggers so that they cannot drift. The one row differs only in its name and in
 * its epoch, made here, so an estate database re-provisioned from nothing is a new epoch.
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
            'stream' => 'estate',
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
