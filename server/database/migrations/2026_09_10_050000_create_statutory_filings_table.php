<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The returns Gemini Security owes the Jamaican authorities - CENTRAL.
 *
 * WHY THIS IS A TABLE AND NOT A DERIVED VIEW.
 *
 * A remittance could almost be recomputed from the payroll runs it covers, and
 * for the current period that is exactly what happens. But a FILED return is a
 * different kind of thing: it is the record of a submission that was made, on a
 * date, under a confirmation number, for amounts that were correct on the day.
 * Recomputing it later against a corrected roster or a re-signed rate version
 * would silently rewrite history that the tax authority holds a copy of.
 *
 * So a filing carries its own amounts, and once it is filed it is APPEND-ONLY
 * (invariant 4). A correction is an amended return, never an edit. The two
 * triggers below refuse the edit and the delete outright, because "no update
 * route" protects only the routes anyone remembered to look at.
 *
 * D-035 records the decision to add this table rather than derive the screen.
 *
 * AMOUNTS ARE NULLABLE, AND THAT IS THE POINT. A return whose period has closed
 * but whose pay run is not approved has no amounts yet, and must not be given
 * provisional ones: the Build Spec's rule for this screen is that amounts derive
 * from APPROVED runs only, and a draft run never appears in a filing. NULL says
 * "not yet known"; a zero would say "nothing is owed", which is a different and
 * false claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_filings', function (Blueprint $table) {
            $table->id();

            // The form as the authority names it: S01, S02, P24.
            $table->string('form_code', 12);
            $table->string('form_title', 120);

            // "September 2026", "Tax year 2026" - what the return covers, in
            // the words the return itself uses.
            $table->string('period_label', 60);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');

            // not_started | due | filed
            $table->string('status', 16)->default('not_started');

            $table->date('filed_on')->nullable();

            // The authority's receipt for the submission. The one field that
            // proves the filing happened, so it is stored rather than implied.
            $table->string('confirmation_reference', 60)->nullable();

            /*
             * The approved run the amounts came from.
             *
             * Null on a return that predates this system, and null while the
             * period's run is still unapproved - which is the case that matters
             * today, because D-021 holds every run short of approval.
             */
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs');

            $table->unsignedSmallInteger('employees_covered')->nullable();

            // Minor units and an explicit currency, like every other amount in
            // this system. Null until the return can be prepared.
            $table->bigInteger('nis_minor')->nullable();
            $table->bigInteger('nht_minor')->nullable();
            $table->bigInteger('education_tax_minor')->nullable();
            $table->bigInteger('paye_minor')->nullable();
            $table->bigInteger('total_minor')->nullable();
            $table->char('currency', 3)->default('JMD');

            $table->timestamps();

            // One return per form per period. A second S01 for September is an
            // amended return with its own period label, not a duplicate row.
            $table->unique(['form_code', 'period_label']);
            $table->index(['status', 'due_on']);
        });

        DB::unprepared("
            CREATE TRIGGER statutory_filings_no_edit_after_filing BEFORE UPDATE ON statutory_filings
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'filed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'a filed statutory return is append-only; file an amended return instead';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER statutory_filings_no_delete_after_filing BEFORE DELETE ON statutory_filings
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'filed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'a filed statutory return cannot be deleted; it is the record of a submission the authority also holds';
                END IF;
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS statutory_filings_no_delete_after_filing');
        DB::unprepared('DROP TRIGGER IF EXISTS statutory_filings_no_edit_after_filing');

        Schema::dropIfExists('statutory_filings');
    }
};
