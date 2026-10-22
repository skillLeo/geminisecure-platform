<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AN EMAIL THAT COULD NOT BE SENT IS RECORDED WHERE AN ADMIN SEES IT (M12 §5.1: "25 Sep 18:58 UTC, connection to
 * Hostinger timed out … surface failures where an admin can see them rather than only in the log").
 *
 * One row per message the platform gave up on, written AFTER the last attempt (ResilientSmtpTransport) — never an
 * intention. What it was (its subject), to whom (MASKED: "so***@gm***.com" — a resident's address is not a Gemini
 * admin's to read), how many attempts, and why, as a short sentence with no credentials in it. Append-only: the
 * record of who did not get a sign-in code or an alert is not something to tidy away. Locked twice, like every
 * ledger: the withheld grant (GrantedTables::CENTRAL_APPEND_ONLY) and these two triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_failures', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('failed_at');
            $table->string('subject', 200);
            // MASKED, and every recipient of the message — "so***@gm***.com, an***@ya***.com".
            $table->string('recipients', 400);
            $table->unsignedTinyInteger('attempts');
            // A sentence the platform wrote (the exception's class and its first line, with any address masked).
            $table->string('reason', 400);
            $table->string('relay', 120);
            $table->string('tenant_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('failed_at', 'mail_failures_failed_at_index');
        });

        DB::unprepared("
            CREATE TRIGGER mail_failures_no_update BEFORE UPDATE ON mail_failures
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'mail_failures is append-only: it is the record of who was not sent an email'
        ");

        DB::unprepared("
            CREATE TRIGGER mail_failures_no_delete BEFORE DELETE ON mail_failures
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'mail_failures is append-only'
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS mail_failures_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS mail_failures_no_delete');

        Schema::dropIfExists('mail_failures');
    }
};
