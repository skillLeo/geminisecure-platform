<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * GUARD LIVE TRACKING, THE SERVER HALF (Hassam, 12 Oct 2026: "the one the client cares most about";
 * docs/handoff/GUARD_TRACKING.md section 5).
 *
 * guard_positions, the readings a phone takes on duty (D-174), gains what the batch address `POST presence/positions`
 * carries (the site is the estate: `estate_sites` is keyed by `tenant_id`, which every reading already holds):
 *   is_moving       whether the phone judged the guard moving when it took the reading
 *   clock_offset_s  the server's clock minus the phone's, measured on the batch that brought it (X-Device-Time): a
 *                   MEASUREMENT kept beside the reading, never applied to it
 *   clock_flag      `clock_ahead` or `clock_behind` when that difference, or the reading's own time, is more than two
 *                   minutes out: stored and flagged for supervisor review, never refused and never corrected
 *   source          wider, for `significant_change`
 * and a trigger that refuses any UPDATE: a reading is never edited. The only deletion is the nightly retention purge
 * (`gs:guard-positions-purge`, `tracking.retention_days`), so the table stays on the mutable list for DELETE alone.
 *
 * How long a guard on duty may send nothing before the map says "No signal" is the estate's setting in Governance's site
 * tracking settings, read through GuardFreshness::tolerances() (config `tracking.no_signal_after_seconds` until then).
 *
 * guard_signal_alerts: one row per stretch of silence on a shift, raised by `tracking:signal` and cleared (once, by
 * setting `cleared_at`) when a position arrives or the shift ends. The silence itself is read from the positions.
 *
 * guard_queue_reports: what the guard's phone said, with its newest batch, about the readings it holds unsent and any
 * it had to thin (`backlog`, `thinned`): one row per shift, written over with each batch that carries them, only while
 * the shift is open. A phone sitting on a long queue is an operational problem the dispatcher must see (section 9).
 *
 * duress_alert_positions: where the phone that raised a panic or a duress is while the alert stays open (the Resident
 * App, or a guard's handset), sent to `POST duress/{alert}/positions`. Part of the alert's record, so append-only and
 * kept for ever like the alert: locked twice, the grant (GrantedTables::CENTRAL_APPEND_ONLY) and the triggers below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guard_positions', function (Blueprint $table): void {
            $table->boolean('is_moving')->nullable()->after('battery_pct');
            $table->integer('clock_offset_s')->nullable()->after('device_captured_at');
            $table->string('clock_flag', 16)->nullable()->after('clock_offset_s');
        });

        Schema::table('guard_positions', function (Blueprint $table): void {
            $table->string('source', 24)->change();
        });

        DB::unprepared("
            CREATE TRIGGER guard_positions_never_updated BEFORE UPDATE ON guard_positions
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'guard_positions is never updated: a reading is a fact, deleted only by the retention purge'
        ");

        Schema::create('guard_signal_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('guard_id');
            // The newest reading's time when the silence was found, or null when nothing came since the clock-in.
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('raised_at');
            $table->dateTime('cleared_at')->nullable();
            // `position_arrived` or `shift_ended`.
            $table->string('cleared_reason', 24)->nullable();

            $table->unique(['shift_id', 'raised_at']);
            $table->index(['cleared_at', 'tenant_id']);
        });

        Schema::create('guard_queue_reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('shift_id')->primary();
            $table->unsignedBigInteger('guard_id');
            $table->string('tenant_id');
            // Readings the phone holds and has not sent, and when the oldest of them was taken.
            $table->unsignedInteger('waiting')->default(0);
            $table->dateTime('oldest_captured_at')->nullable();
            // Readings the phone thinned out of a full queue, and the span they came from.
            $table->unsignedInteger('thinned_count')->default(0);
            $table->dateTime('thinned_from')->nullable();
            $table->dateTime('thinned_to')->nullable();
            $table->dateTime('reported_at');
        });

        Schema::create('duress_alert_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('duress_alert_id')->constrained('duress_alerts');
            $table->string('client_id', 64)->unique();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('received_at')->useCurrent();
            $table->boolean('is_simulated')->default(false);

            $table->index(['duress_alert_id', 'captured_at']);
        });

        DB::unprepared("
            CREATE TRIGGER duress_alert_positions_no_update BEFORE UPDATE ON duress_alert_positions
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'duress_alert_positions is append-only: it is where somebody asking for help was'
        ");

        DB::unprepared("
            CREATE TRIGGER duress_alert_positions_no_delete BEFORE DELETE ON duress_alert_positions
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'duress_alert_positions is append-only'
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS duress_alert_positions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS duress_alert_positions_no_delete');
        Schema::dropIfExists('duress_alert_positions');
        Schema::dropIfExists('guard_queue_reports');
        Schema::dropIfExists('guard_signal_alerts');

        DB::unprepared('DROP TRIGGER IF EXISTS guard_positions_never_updated');

        Schema::table('guard_positions', function (Blueprint $table): void {
            $table->dropColumn(['is_moving', 'clock_offset_s', 'clock_flag']);
        });
    }
};
