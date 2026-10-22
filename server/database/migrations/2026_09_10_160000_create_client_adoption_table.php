<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of what a client bought they are actually using — CENTRAL.
 *
 * THE ROLL-UP THE CLIENT HEALTH REPORT WAS WAITING ON. That report's own
 * catalogue card states the constraint it lives under: adoption is recorded
 * inside each estate's own database, and this console never opens one. The only
 * honest way to report it across clients is for the fact's OWNER to roll it up
 * here, which is what this table is.
 *
 * TWO WRITERS, EACH WRITING ONLY WHAT IT OWNS.
 *
 *   gemini   the capabilities Gemini itself operates at the estate — the Guard
 *            App running on its posts, visitors admitted on a pass rather than
 *            on a guard's judgement. Those facts are Gemini's own and already
 *            central; `AdoptionRollup` recomputes them.
 *
 *   estate   the capabilities the estate operates for itself — dues, facilities,
 *            governance, its own payroll. Those live in the estate's database
 *            and only the estate console can count them, so it writes its own
 *            row here and Gemini reads the row rather than the estate.
 *
 * A capability with NO ROW is not zero adoption. It is a capability nobody has
 * reported on yet, and the report says so in those words rather than drawing an
 * empty bar — "not measured" and "measured at nought" are different facts about
 * a client, and confusing them would have an account manager chasing a client
 * who is doing nothing wrong.
 *
 * TWO COUNTS, NOT A PERCENTAGE. `adopted` over `eligible` is stored, so the
 * report can say what the figure counts — "2 of 4 posts" — and so a client with
 * nothing to adopt yet reads as "nothing to measure" rather than as 0%. A
 * stored percentage would lose both.
 *
 * NO MONEY, NO NAMES, NO ROWS. Two integers and a label per capability: nothing
 * here identifies a household, a resident or an amount, so the roll-up cannot
 * become a way to read an estate's ledger from outside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_adoption', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Matches `modules.key` for the estate console where one exists, so
            // a bar can be traced back to the module a client is paying for.
            $table->string('module_key', 48);

            // What the bar says. Stored beside the key because the reporting
            // side names the capability — "Visitor passes" is Gemini's phrase
            // for it, and the estate console will have its own for its own.
            $table->string('label', 80);

            $table->unsignedInteger('adopted')->default(0);
            $table->unsignedInteger('eligible')->default(0);

            // "2 of 4 posts have a licensed, device-bound guard on shift." What
            // the two integers actually count, in words, for the hover.
            $table->string('detail', 200)->nullable();

            // gemini | estate — who owns this fact and wrote this row.
            $table->string('reported_by', 24);

            /*
             * When the count was taken, not when the row was touched.
             * `updated_at` moves on a no-op rewrite; this does not, so the
             * report can say plainly that a client has not reported in a
             * fortnight — which is itself a health signal.
             */
            $table->timestamp('measured_at');

            $table->timestamps();

            // One row per capability per client. A second row for the same
            // capability would be two answers to one question.
            $table->unique(['tenant_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_adoption');
    }
};
