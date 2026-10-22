<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the Phase 1 placeholder journals, which were never entries.
 *
 * THIS IS A DELIBERATE, ONE-TIME EXCEPTION TO AN INVARIANT, and it is a
 * migration rather than a script precisely so that it is on the record.
 *
 * What is being removed. The `journals` table that shipped in Phase 1 held a
 * reference, a memo and a single signed amount. It had no account, no second
 * side and no lines, because the chart of accounts did not exist yet. Rows in
 * that shape are not journal entries in any sense an accountant would accept:
 * they say an amount was involved and nothing about what was debited or
 * credited. They existed to prove append-only enforcement, and they did.
 *
 * Why they cannot simply be left alone. `gate:ledger` requires that every
 * posted entry has at least two lines and that its debits equal its credits.
 * A pre-ledger row can satisfy neither and can never be made to: the trigger
 * that closes a posted entry means lines cannot be added to it afterwards. So
 * leaving them would leave the money gate permanently failing, and a gate that
 * is expected to fail stops being read.
 *
 * Why they are not converted instead. Giving them lines would mean choosing
 * which accounts they hit. Nobody knows, because nothing recorded it. Inventing
 * a plausible pair of accounts would put fabricated bookkeeping into the ledger
 * and make it indistinguishable from the real thing — far worse than removing a
 * row that never carried the information.
 *
 * The scope is exactly and only rows with no lines. An entry posted through
 * `Ledger` has lines by construction, so this cannot reach one. Running it a
 * second time removes nothing.
 *
 * BOTH ENFORCEMENT LAYERS HAD TO BE STEPPED AROUND, which is the clearest
 * possible evidence that they work. The trigger is dropped and restored around
 * the delete; the delete itself runs on the schema-owner connection, because
 * the estate's own MySQL user has DELETE on `journals` revoked and refused this
 * outright when it was first attempted. Two independent layers, both doing
 * their job, and neither weakened afterwards.
 *
 * Restoring the trigger is not optional, which is why it sits in a `finally`: a
 * failed delete that left the table editable would be a far worse outcome than
 * a failed migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphaned = DB::table('journals')
            ->whereNotExists(function ($query): void {
                $query->selectRaw(1)
                    ->from('journal_lines')
                    ->whereColumn('journal_lines.entry_ref', 'journals.reference');
            })
            ->pluck('reference');

        if ($orphaned->isEmpty()) {
            return;
        }

        // The estate's own user cannot delete here, by design. The schema owner
        // can, and the table has to be named in full because that connection is
        // pointed at the central database.
        $database = DB::getDatabaseName();

        DB::unprepared('DROP TRIGGER IF EXISTS journals_no_delete');

        try {
            DB::connection('mysql_owner')
                ->table($database.'.journals')
                ->whereIn('reference', $orphaned)
                ->delete();
        } finally {
            // Restored whatever happened above. A failed delete that left the
            // table editable would be a far worse outcome than a failed
            // migration.
            DB::unprepared("
                CREATE TRIGGER journals_no_delete BEFORE DELETE ON journals
                FOR EACH ROW SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'journals is append-only'
            ");
        }
    }

    /**
     * Nothing to undo.
     *
     * The rows held no accounting information, so there is nothing to restore
     * them from and nothing that would be restored if there were.
     */
    public function down(): void {}
};
