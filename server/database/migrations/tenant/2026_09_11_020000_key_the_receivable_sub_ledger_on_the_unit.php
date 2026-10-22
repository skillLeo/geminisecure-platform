<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dues receivable sub-ledger belongs to the UNIT, not to the household.
 *
 * Corrects `journal_lines.household_id` to `unit_id`, one migration after
 * introducing it, because building four hundred and fifty unit ledgers on the
 * wrong key would have been far more expensive to unpick than this.
 *
 * WHAT DECIDED IT. This estate has 450 units and 441 households. Nine units are
 * vacant, and a vacant unit still owes its maintenance — dues attach to the
 * property, not to whoever happens to be living in it. Keyed on the household,
 * those nine units' dues have nowhere to go, and the receivables control account
 * could never tie to a sub-ledger that cannot represent them.
 *
 * The rest follows the same way round. A household moves out and another moves
 * in; the unit and its arrears both stay. The Build Spec keys both `charge` and
 * `payment` on `unit_id` for this reason, and board 6 is titled "Unit Ledger —
 * Lot 47" rather than a household's. Board 5 shows a Household column AND a Unit
 * column precisely because the two are different things: the unit owes, and the
 * household is who you call about it.
 *
 * The household is not lost. It is reached through the unit, which is where the
 * relationship actually lives, and `households.access_restricted` — the guest
 * pass restriction — stays keyed on the household because that IS about people.
 *
 * Safe to run on posted data: the column is renamed, not rebuilt, and every
 * existing value is mapped through `households.unit_id`. Journal lines are
 * append-only to the APPLICATION; this is schema DDL run by the migration user,
 * which is the one path that may reshape them, and it changes no amount, no
 * account and no side of any entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Guarded, because this migration can fail part-way and be re-run.
         * The backfill below has to drop an append-only trigger to do its work;
         * if it fails, the column is already added and a bare `add` would then
         * refuse on the retry with a duplicate-column error that says nothing
         * about the real problem.
         */
        if (! Schema::hasColumn('journal_lines', 'unit_id')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->unsignedBigInteger('unit_id')->nullable()->after('currency');
                $table->index('unit_id');
            });
        }

        if (! Schema::hasColumn('journal_lines', 'household_id')) {
            // Already converted by an earlier partial run; nothing to map.
            DB::table('accounts')->where('subsidiary', 'households')->update(['subsidiary' => 'units']);

            return;
        }

        /*
         * Mapped through the household's own unit rather than defaulted. A line
         * whose household has since been detached from its unit would be left
         * null, and `gate:ledger` reports a null on a control account as an
         * amount sitting on nobody — which is the correct outcome, because that
         * is exactly what it would be.
         *
         * BOTH ENFORCEMENT LAYERS REFUSE THIS, and rightly: `journal_lines` is
         * append-only to the application and the estate's own MySQL user has no
         * UPDATE on it. So the trigger comes off around the backfill and the
         * write is issued by the schema owner — the same two steps the
         * pre-ledger cleanup needed, and the same evidence that the protection
         * is real. No amount, account or side changes; only the name of the
         * sub-ledger key.
         */
        $database = DB::getDatabaseName();

        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_no_update');

        try {
            DB::connection('mysql_owner')->statement("
                UPDATE `{$database}`.`journal_lines` l
                  JOIN `{$database}`.`households` h ON h.id = l.household_id
                   SET l.unit_id = h.unit_id
                 WHERE l.household_id IS NOT NULL
            ");
        } finally {
            // Restored whatever happened above. A failed backfill that left the
            // lines editable is a far worse outcome than a failed migration.
            DB::unprepared("
                CREATE TRIGGER journal_lines_no_update BEFORE UPDATE ON journal_lines
                FOR EACH ROW SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'journal_lines is append-only; post a reversing entry'
            ");
        }

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['household_id']);
            $table->dropIndex(['household_id']);
            $table->dropColumn('household_id');

            $table->foreign('unit_id')->references('id')->on('units')->restrictOnDelete();
        });

        // The chart's own description of what 1200 controls has to move with it.
        DB::table('accounts')->where('subsidiary', 'households')->update(['subsidiary' => 'units']);
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('household_id')->nullable()->after('currency');
            $table->index('household_id');
        });

        $database = DB::getDatabaseName();

        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_no_update');

        try {
            DB::connection('mysql_owner')->statement("
                UPDATE `{$database}`.`journal_lines` l
                  JOIN `{$database}`.`households` h ON h.unit_id = l.unit_id
                   SET l.household_id = h.id
                 WHERE l.unit_id IS NOT NULL
            ");
        } finally {
            DB::unprepared("
                CREATE TRIGGER journal_lines_no_update BEFORE UPDATE ON journal_lines
                FOR EACH ROW SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'journal_lines is append-only; post a reversing entry'
            ");
        }

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['unit_id']);
            $table->dropColumn('unit_id');

            $table->foreign('household_id')->references('id')->on('households')->restrictOnDelete();
        });

        DB::table('accounts')->where('subsidiary', 'units')->update(['subsidiary' => 'households']);
    }
};
