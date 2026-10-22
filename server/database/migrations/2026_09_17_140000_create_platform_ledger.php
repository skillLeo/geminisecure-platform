<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gemini's own books — the smallest ledger that lets a client invoice post (13 B1).
 *
 * "Build the invoice run … Posts Dr AR / Cr Service revenue." Until this
 * migration the platform held no ledger for Gemini at all: invoices were rows
 * with a status, and "what do clients owe Gemini" was a sum over a status
 * column that anybody could edit. This is the estate ledger's shape, in
 * `gs_platform`, with the same guarantees enforced the same way:
 *
 *   platform_journals_must_balance            lines first, header last; the
 *                                             header insert refuses an entry
 *                                             whose lines do not balance
 *   platform_journal_lines_no_late_addition   no line joins a posted entry
 *   platform_journal_lines_no_update/_delete  a posted line never changes
 *   platform_journals_no_update/_delete       nor does its header
 *
 * THE CHART IS TWO ACCOUNTS, because two is what is posted:
 *
 *   1100 Accounts Receivable — Clients   control, sub-ledger by client
 *   4000 Subscription & Service Revenue
 *
 * Cash receipts, bank accounts and expenses are not here. Gemini's bank and its
 * wages live in its own accounting system; this ledger answers one question —
 * what each client has been billed and credited — and a chart that pretended
 * to be Gemini's whole books would be a second set nobody reconciles.
 *
 * INVOICES RAISED BEFORE THIS LEDGER CARRY NO JOURNAL. They are seeded history;
 * none is posted retrospectively, because an opening balance invented from a
 * status column is exactly the figure this ledger exists to replace. A credit
 * note posts only against an invoice that did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 120);
            $table->string('type', 16);         // asset | liability | equity | income | expense
            $table->boolean('is_control')->default(false);
            $table->timestamps();
        });

        Schema::create('platform_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->string('entry_ref', 40);
            $table->foreignId('platform_account_id')->constrained('platform_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->char('currency', 3)->default('JMD');
            $table->string('memo', 200)->nullable();

            // The client a receivable line belongs to — the sub-ledger key.
            $table->string('tenant_id')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('entry_ref');
            $table->index(['platform_account_id', 'tenant_id']);
            $table->unique(['entry_ref', 'line_no']);
        });

        DB::statement('ALTER TABLE platform_journal_lines ADD CONSTRAINT platform_journal_lines_one_side_only CHECK ((debit_minor = 0) <> (credit_minor = 0))');
        DB::statement('ALTER TABLE platform_journal_lines ADD CONSTRAINT platform_journal_lines_never_negative CHECK (debit_minor >= 0 AND credit_minor >= 0)');

        Schema::create('platform_journals', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('memo', 200);
            $table->string('source', 24);          // invoice | credit_note
            $table->unsignedBigInteger('source_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');
            $table->date('posted_on');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->string('posted_by_name', 160)->nullable();
            $table->timestamps();

            $table->index(['source', 'source_id']);
        });

        DB::unprepared("
            CREATE TRIGGER platform_journals_must_balance BEFORE INSERT ON platform_journals
            FOR EACH ROW
            BEGIN
                DECLARE line_count INT DEFAULT 0;
                DECLARE debits BIGINT DEFAULT 0;
                DECLARE credits BIGINT DEFAULT 0;

                SELECT COUNT(*), COALESCE(SUM(debit_minor), 0), COALESCE(SUM(credit_minor), 0)
                  INTO line_count, debits, credits
                  FROM platform_journal_lines
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

        DB::unprepared("
            CREATE TRIGGER platform_journal_lines_no_late_addition BEFORE INSERT ON platform_journal_lines
            FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM platform_journals WHERE reference = NEW.entry_ref) THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'that journal is posted; a line cannot be added to it. Post a reversing entry';
                END IF;
            END
        ");

        foreach (['platform_journal_lines', 'platform_journals'] as $table) {
            DB::unprepared("
                CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
                FOR EACH ROW SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = '{$table} is append-only; post a reversing entry'
            ");

            DB::unprepared("
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                FOR EACH ROW SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = '{$table} is append-only'
            ");
        }

        DB::table('platform_accounts')->insert([
            ['code' => '1100', 'name' => 'Accounts Receivable — Clients', 'type' => 'asset', 'is_control' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '4000', 'name' => 'Subscription & Service Revenue', 'type' => 'income', 'is_control' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('invoices', function (Blueprint $table) {
            // The lines' sum before tax. Equal to the total while no tax is
            // ruled on Gemini's services — Q-019.
            $table->bigInteger('subtotal_minor')->nullable()->after('period_end');

            // Null on every invoice raised before this ledger existed.
            $table->string('journal_ref', 40)->nullable()->after('paid_on');
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('journal_ref', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn('journal_ref');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['subtotal_minor', 'journal_ref']);
        });

        foreach (['platform_journal_lines', 'platform_journals'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }

        DB::unprepared('DROP TRIGGER IF EXISTS platform_journal_lines_no_late_addition');
        DB::unprepared('DROP TRIGGER IF EXISTS platform_journals_must_balance');

        Schema::dropIfExists('platform_journals');
        Schema::dropIfExists('platform_journal_lines');
        Schema::dropIfExists('platform_accounts');
    }
};
