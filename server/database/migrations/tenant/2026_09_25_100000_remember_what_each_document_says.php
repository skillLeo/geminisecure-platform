<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT EACH DOCUMENT SAYS, SO A SECOND PRESS IS HANDED THE FIRST ONLY WHILE IT IS STILL TRUE (B027).
 *
 * A second press inside fifteen minutes is handed the document the first press made, so that
 * somebody who sees nothing for ten seconds does not end up with four statements for one balance.
 * It used to be handed that document whatever had happened in between: the office printed Lot 47's
 * statement showing J$12,400 owed, the resident paid, the office pressed again for proof — and was
 * told "Statement of account — Lot 47 is ready" and handed the same PDF, still showing it owed.
 *
 * `facts_sha256` is the hash of the facts the document states — the lines, the balance, the
 * minutes, the tally, the letterhead — as `DocumentRenderer::facts()` gathers them. It is written
 * when the document is asked for and replaced by what the worker actually rendered from, and a
 * press is handed an earlier document only when the facts now hash the same. Null on every row
 * written before this, so none of those is handed back again.
 *
 * AND IT IS RETAINED AS ISSUED. The trigger that freezes an issued document's file, size and hash
 * now freezes this too: it is the answer to "what did this document say?", and one that could be
 * rewritten after issue could make a stale statement look current.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->char('facts_sha256', 64)->nullable()->after('sha256');
        });

        DB::unprepared('DROP TRIGGER IF EXISTS documents_retention_fixed');

        DB::unprepared("
            CREATE TRIGGER documents_retention_fixed BEFORE UPDATE ON documents
            FOR EACH ROW
            BEGIN
                IF NEW.retain_until < OLD.retain_until THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A document retention date is never shortened.';
                END IF;

                IF OLD.status = 'ready' AND (
                    NOT (NEW.status <=> OLD.status)
                    OR NOT (NEW.path <=> OLD.path)
                    OR NOT (NEW.bytes <=> OLD.bytes)
                    OR NOT (NEW.sha256 <=> OLD.sha256)
                    OR NOT (NEW.facts_sha256 <=> OLD.facts_sha256)
                ) THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'An issued document is retained as issued: its file, size and hash never change.';
                END IF;
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS documents_retention_fixed');

        DB::unprepared("
            CREATE TRIGGER documents_retention_fixed BEFORE UPDATE ON documents
            FOR EACH ROW
            BEGIN
                IF NEW.retain_until < OLD.retain_until THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A document retention date is never shortened.';
                END IF;

                IF OLD.status = 'ready' AND (
                    NOT (NEW.status <=> OLD.status)
                    OR NOT (NEW.path <=> OLD.path)
                    OR NOT (NEW.bytes <=> OLD.bytes)
                    OR NOT (NEW.sha256 <=> OLD.sha256)
                ) THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'An issued document is retained as issued: its file, size and hash never change.';
                END IF;
            END
        ");

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('facts_sha256');
        });
    }
};
