<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE GATE LOG IS LOCKED THE WAY THE LEDGER AND THE BALLOT ARE (Q-M43).
 *
 * `gate_events` is the ONLY record that an admission happened — who was let into
 * an estate, by which officer, on what basis, at what hour. It was described as
 * append-only everywhere it was discussed and it was not one: it sat in
 * `GrantedTables::CENTRAL_MUTABLE`, so the application user `gs_app` held UPDATE
 * and DELETE on it, and it carried none of the triggers that hold `audit_log`,
 * `platform_journals`, `platform_journal_lines`, `journals`, `journal_lines`,
 * `ballot_receipts` and `ballot_marks`.
 *
 * Nothing in this application has ever updated or deleted a gate event —
 * `App\Services\Dispatch\GateLog` only ever inserts, and returns the row it
 * already wrote when a handset retries. So this is unused privilege on the record
 * an insurer or a court asks for, and taking it away changes no behaviour.
 *
 * BOTH LOCKS, NEVER ONE. The withheld grant (applied by `php artisan
 * grants:append-only`, driven by `GrantedTables::CENTRAL_APPEND_ONLY`, where
 * `gate_events` now lives) stops the APPLICATION. These triggers stop EVERYBODY,
 * including a console opened as the schema owner and including a future migration
 * that means well. A grant alone is one `GRANT` away from being undone; a trigger
 * alone is bypassed by nobody but is invisible to the grant verifier. The five
 * tables above carry both, and now so does this one.
 *
 * WHAT IS DELIBERATELY LEFT MUTABLE, and this is the half that would have caused
 * an outage if it had been swept up: `duress_alerts` and `security_incidents` are
 * ACKNOWLEDGED, CANCELLED and RESOLVED after they are raised. An alert that cannot
 * be closed is a control room that never stops flashing. They stay exactly as they
 * were, in CENTRAL_MUTABLE, with no trigger.
 *
 * NO DATA MOVES. No column is added, no row is touched, nothing is dropped. The
 * only artefacts are two triggers, and `down()` removes them.
 *
 * A NOTE ON THE FOREIGN KEYS, because they look like a problem and are not.
 * `gate_events.tenant_id` cascades on delete and `guard_id` / `post_id` null on
 * delete. InnoDB performs a cascaded foreign-key action WITHOUT activating the
 * child table's triggers and without checking the caller's privileges on the
 * child table, so retiring a post or deleting a guard still works exactly as it
 * did. `audit_log` has carried the identical pair (a nullOnDelete to `users` under
 * BEFORE UPDATE / BEFORE DELETE triggers) since 2026-09-09, which is the proof
 * that this shape runs on this server rather than an argument that it should.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * IF EXISTS on the drops rather than on the creates, because MySQL has no
         * `CREATE TRIGGER IF NOT EXISTS`. Dropping first makes the migration safe
         * to re-run on a database where a half-applied attempt left one behind.
         */
        DB::unprepared('DROP TRIGGER IF EXISTS gate_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS gate_events_no_delete');

        DB::unprepared("
            CREATE TRIGGER gate_events_no_update BEFORE UPDATE ON gate_events
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'gate_events is append-only: a record of an admission is never edited. Write a correcting event'
        ");

        DB::unprepared("
            CREATE TRIGGER gate_events_no_delete BEFORE DELETE ON gate_events
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'gate_events is append-only: a record of an admission is never deleted'
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS gate_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS gate_events_no_delete');
    }
};
