<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry bookkeeping, enforced by the database.
 *
 * The Build Spec's `journal` entity is explicit: "lines[] (account, debit,
 * credit) ... Debits must equal credits. A posted journal is reversed by an
 * equal and opposite journal, never edited." The table that shipped in Phase 1
 * held a single signed amount and no account at all — enough to prove
 * append-only, not enough to be a ledger. This is the ledger.
 *
 * WHY THE LINES ARE WRITTEN BEFORE THE HEADER, which looks backwards and is the
 * whole point.
 *
 * "Debits equal credits" is a statement about a SET of rows, and MySQL has no
 * way to check a set as it is being built: a trigger on the lines fires once per
 * line, when the entry is still half-written, and a trigger on the header fires
 * before the lines it would need to count exist. Checking it in PHP would leave
 * the guarantee one forgotten service call away from being false — and an
 * unbalanced ledger is not a bug anyone finds by looking.
 *
 * So the order is inverted. Lines are inserted first, carrying `entry_ref`. The
 * header is inserted last, and `journals_must_balance` fires BEFORE that insert,
 * by which time every line exists and can be summed. An entry that does not
 * balance never gets a header, and the transaction it was written in rolls back.
 *
 * The cost is that `journal_lines` carries no foreign key to `journals` — a
 * foreign key would demand the header first, which is exactly the ordering that
 * makes the check impossible. `entry_ref` points at `journals.reference`, which
 * is unique, and `php artisan gate:ledger` re-proves the link and the balance
 * across every entry rather than trusting either.
 *
 * FOUR TRIGGERS, EACH CLOSING A DIFFERENT DOOR:
 *
 *   journals_must_balance          an entry whose debits and credits differ,
 *                                  or that has fewer than two lines, or whose
 *                                  header total disagrees with its own lines,
 *                                  cannot be posted
 *   journal_lines_no_late_addition a line cannot be added to an entry that
 *                                  already has a header — otherwise a balanced
 *                                  entry could be unbalanced a second later
 *   journal_lines_no_update        a posted line is never edited
 *   journal_lines_no_delete        a posted line is never removed
 *
 * The last two match what `journals` already does, because a header nobody can
 * edit above lines anybody can edit is not append-only.
 *
 * NO BALANCE IS EVER STORED ON AN ACCOUNT. An account's balance is the sum of
 * its posted lines and is computed on read. A stored balance is a second copy
 * of the ledger that is free to disagree with it, and the copy is always the one
 * a committee reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            /*
             * The account code, which is what an accountant navigates by and
             * what the chart is ordered on. Kept as a string rather than an
             * integer: real charts use "1100-01" and leading zeroes, and an
             * integer would silently discard both.
             */
            $table->string('code', 16)->unique();
            $table->string('name', 120);

            // asset | liability | equity | income | expense. The five the Build
            // Spec names, and the five that decide which side increases an
            // account — so `normal_balance` is NOT stored: it is a function of
            // this column and a stored copy could contradict it.
            $table->string('type', 16);

            /*
             * The chart is a tree: "1100 Dues receivable" sits under "1000
             * Current assets". Restricted rather than cascading — deleting a
             * parent must not silently take its children and their history.
             */
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();

            /*
             * A control account is one whose balance MUST equal the total of a
             * sub-ledger kept outside the chart: dues receivable against every
             * household's balance, bills payable against every vendor's. Naming
             * the sub-ledger here rather than hardcoding a list of account codes
             * is what lets `gate:ledger` check the tie without knowing this
             * estate's numbering.
             */
            $table->boolean('is_control')->default(false);
            $table->string('subsidiary', 24)->nullable();  // households | vendors

            /*
             * Whether this account is money at a bank.
             *
             * Not a display detail and not a shortcut for a dashboard tile.
             * Bank reconciliation reconciles ONE account against ONE statement,
             * so the screen cannot exist without knowing which accounts are
             * reconcilable — and an estate with an operating account and a
             * reserve account reconciles them separately, against separate
             * statements. "Cash on hand" is the sum of these, which is a
             * consequence of the column rather than its reason.
             */
            $table->boolean('is_bank_account')->default(false);

            /*
             * Archived, never deleted. The Build Spec: "An account with posted
             * journals cannot be deleted, only archived." A deleted account
             * would orphan every line ever posted to it, which is to say it
             * would destroy the history the ledger exists to keep.
             */
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->index(['type', 'code']);
            $table->index('parent_id');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();

            /*
             * The entry this line belongs to, by the entry's own reference.
             * Not a foreign key, and the file header says why at length: the
             * header does not exist yet when this row is written, because the
             * header's insert is what checks that these lines balance.
             */
            $table->string('entry_ref', 40);

            // Restricted: an account with a posted line against it cannot be
            // deleted. That is the Build Spec's rule, enforced by the database
            // rather than by remembering to check.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->unsignedSmallInteger('line_no');

            /*
             * Minor units, both sides, and exactly one of them non-zero. A
             * single signed column would let a credit be written as a negative
             * debit — the same arithmetic and a different statement — and a
             * trial balance printed from it would not have two columns to
             * compare. The CHECK below is the database refusing that.
             */
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->char('currency', 3)->default('JMD');

            $table->string('memo', 200)->nullable();

            /*
             * The sub-ledger this line belongs to, where it belongs to one.
             *
             * A line hitting Dues receivable is owed by a particular household;
             * a line hitting Bills payable is owed to a particular vendor. These
             * are what make the control-account tie checkable — without them,
             * "the sub-ledger agrees with its control account" is not a question
             * the database can be asked.
             *
             * Nullable because most lines belong to no sub-ledger at all: cash,
             * income and expense accounts have no subsidiary.
             */
            $table->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $table->unsignedBigInteger('vendor_id')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('entry_ref');
            $table->index(['account_id', 'id']);
            $table->index('household_id');
            $table->index('vendor_id');

            // One line per position per entry, so a re-posted line cannot
            // quietly become a second line.
            $table->unique(['entry_ref', 'line_no']);
        });

        /*
         * Exactly one side, and neither side negative.
         *
         * `(debit = 0) <> (credit = 0)` is exclusive-or: it rejects a line with
         * both sides filled and a line with neither. A negative debit is a
         * credit wearing the wrong hat, and would make every total in the
         * ledger arguable.
         */
        DB::statement('
            ALTER TABLE journal_lines
            ADD CONSTRAINT journal_lines_one_side_only
            CHECK ((debit_minor = 0) <> (credit_minor = 0))
        ');

        DB::statement('
            ALTER TABLE journal_lines
            ADD CONSTRAINT journal_lines_never_negative
            CHECK (debit_minor >= 0 AND credit_minor >= 0)
        ');

        Schema::table('journals', function (Blueprint $table) {
            /*
             * What caused this entry, and which row.
             *
             * A journal is almost never typed by hand: a charge posts one, a
             * payment posts one, a bill posts one. Recording the origin is what
             * lets the unit ledger show a charge and its journal as one event
             * rather than two unrelated rows, and what lets a reversal find
             * everything it has to undo.
             */
            $table->string('source', 24)->default('manual')->after('memo');
            $table->unsignedBigInteger('source_id')->nullable()->after('source');

            /*
             * Who posted it, denormalised beside the id. A committee member can
             * leave the estate; the record of who posted an entry cannot leave
             * with them, and the id alone stops resolving the day they do.
             */
            $table->unsignedBigInteger('posted_by')->nullable()->after('posted_on');
            $table->string('posted_by_name', 160)->nullable()->after('posted_by');

            $table->index(['source', 'source_id']);
        });

        /*
         * The balance check. Fires before the header is written, by which time
         * every line of the entry exists and can be counted.
         */
        DB::unprepared("
            CREATE TRIGGER journals_must_balance BEFORE INSERT ON journals
            FOR EACH ROW
            BEGIN
                DECLARE line_count INT DEFAULT 0;
                DECLARE debits BIGINT DEFAULT 0;
                DECLARE credits BIGINT DEFAULT 0;

                SELECT COUNT(*), COALESCE(SUM(debit_minor), 0), COALESCE(SUM(credit_minor), 0)
                  INTO line_count, debits, credits
                  FROM journal_lines
                 WHERE entry_ref = NEW.reference;

                IF line_count < 2 THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'a journal entry needs at least two lines; write the lines before the header';
                END IF;

                IF debits <> credits THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'debits must equal credits';
                END IF;

                IF debits <> NEW.amount_minor THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'the entry total must equal the sum of its debits';
                END IF;
            END
        ");

        /*
         * Once an entry has a header it is posted, and posted means closed.
         * Without this, a balanced entry could be unbalanced by one more insert
         * a second later and every trial balance after it would be wrong.
         */
        DB::unprepared("
            CREATE TRIGGER journal_lines_no_late_addition BEFORE INSERT ON journal_lines
            FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM journals WHERE reference = NEW.entry_ref) THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'that journal is posted; a line cannot be added to it. Post a reversing entry';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER journal_lines_no_update BEFORE UPDATE ON journal_lines
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journal_lines is append-only; post a reversing entry'
        ");

        DB::unprepared("
            CREATE TRIGGER journal_lines_no_delete BEFORE DELETE ON journal_lines
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journal_lines is append-only'
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_no_late_addition');
        DB::unprepared('DROP TRIGGER IF EXISTS journals_must_balance');

        Schema::table('journals', function (Blueprint $table) {
            $table->dropIndex(['source', 'source_id']);
            $table->dropColumn(['source', 'source_id', 'posted_by', 'posted_by_name']);
        });

        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('accounts');
    }
};
