<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll files are kept, and nothing kept can be removed early (13 A2).
 *
 * "Payroll XLSX and bank CSV are generated and not kept. Store them through the
 * same `Documents` path as every other financial document, 7-year retention,
 * deletion guarded. A file the estate cannot produce in year three is not
 * retained."
 *
 * `content_type`, because a document is no longer always a PDF: the summary is
 * a workbook and the bank file a CSV, and serving either as `application/pdf`
 * hands the reader a file their computer refuses to open. Null reads as PDF,
 * which is what every row written before this migration is.
 *
 * THE GUARD IS IN THE DATABASE, not in a model event, for the reason the ledger
 * and the ballot triggers are: an event guards the one code path that remembers
 * to fire it, and a retention promise has to hold against a query typed by hand.
 *
 *   documents_no_early_delete    no row leaves before its `retain_until`
 *   documents_retention_fixed    `retain_until` never moves earlier, and an
 *                                issued document's path, size and hash never
 *                                change — replacing the bytes behind a hash is
 *                                deleting the document and keeping its name
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('content_type', 100)->nullable()->after('filename');
        });

        DB::unprepared("
            CREATE TRIGGER documents_no_early_delete BEFORE DELETE ON documents
            FOR EACH ROW
            BEGIN
                IF OLD.retain_until > NOW() THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A document is retained for seven years from issue and cannot be deleted before its retain_until date.';
                END IF;
            END
        ");

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
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS documents_no_early_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS documents_retention_fixed');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('content_type');
        });
    }
};
