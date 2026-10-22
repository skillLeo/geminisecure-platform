<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estate money.
 *
 * Every amount is a bigint of MINOR UNITS plus an explicit ISO currency code,
 * read back through brick/money. Never a float, never a rounded double — a
 * float cannot represent 0.10 and a community's ledger must reconcile exactly.
 *
 * `journals` is append-only (invariant 4), enforced in two independent layers:
 *   1. REVOKE UPDATE, DELETE from the estate's MySQL user, at provisioning
 *   2. BEFORE UPDATE / BEFORE DELETE triggers raising SQLSTATE 45000
 *
 * Both, not one: a MySQL grant is per-table and easily lost during a later
 * migration, while a trigger survives it. A correction is a new reversing
 * entry that references the original, never an edit.
 *
 * ASSUMPTION Q-001: currency defaults to JMD. No supplied document names a
 * currency; Gemini Security Limited is Jamaican and the PAYE threshold of
 * 1,800,000 is a JMD figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('description', 200);

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');

            $table->date('due_on');
            $table->string('status', 24)->default('outstanding');
            $table->timestamps();

            $table->index(['status', 'due_on']);
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('memo', 200);

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');

            $table->date('posted_on');

            /*
             * A correction references the entry it reverses. This is the only
             * way to undo a posted journal — there is no edit path, by design.
             */
            $table->unsignedBigInteger('reverses_journal_id')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('posted_on');
            $table->index('reverses_journal_id');
        });

        // Layer 2. Layer 1 (REVOKE) is applied per estate at provisioning,
        // because the estate's MySQL user does not exist until then.
        DB::unprepared("
            CREATE TRIGGER journals_no_update BEFORE UPDATE ON journals
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journals is append-only; post a reversing entry'
        ");

        DB::unprepared("
            CREATE TRIGGER journals_no_delete BEFORE DELETE ON journals
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journals is append-only'
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journals_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS journals_no_delete');

        Schema::dropIfExists('journals');
        Schema::dropIfExists('charges');
    }
};
