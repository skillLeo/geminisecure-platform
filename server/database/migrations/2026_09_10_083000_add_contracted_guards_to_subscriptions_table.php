<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many guards a client is CONTRACTED for - board screens super-admin-06
 * and super-admin-10.
 *
 * Not a metric. A commercial term, and a billed quantity.
 *
 * `platform_rates` already holds the Security Provider add-on at $4,500 per
 * guard per month, on a `guard` basis, applying on top of any tier. That rate
 * has had no quantity to multiply: nothing in central billing recorded how many
 * guards an estate actually agreed to pay for. Board 06 draws the field that
 * sets it ("Guards to assign", feeding "Security add-on - 3 x $4,500"), and
 * board 10 draws what it is measured against ("Contracted for 4 guards",
 * "4 of 4 filled").
 *
 * WHY NOT COUNT THE GUARD ROWS INSTEAD. Because a contracted quantity exists
 * precisely so that it does NOT move when deployment does. Counting
 * `guards.tenant_id` would reprice a client the moment an officer resigned, and
 * would make the fill rate on board 10 - and the utilisation KPI the spec names
 * on screen 40, "guards contracted, guards deployed, fill rate" - a comparison
 * of a number against itself, permanently 100%. The whole point of the pair is
 * that the two can disagree, and that a shortfall is visible.
 *
 * Nullable rather than defaulted to nought, because "no guard cover contracted"
 * and "cover contracted, none yet agreed" are different facts about a client.
 * An estate that runs its own security and buys the software alone is the first;
 * the directory already draws that case as "0 - self-managed security".
 *
 * NO AMOUNT IS STORED HERE. The rate stays in `platform_rates`, where it is
 * edited once for the whole platform. A copy of $4,500 on each subscription
 * would be a second place for the price to live and a first place for it to
 * drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('contracted_guards')
                ->nullable()
                ->after('unit_count');
        });

        /*
         * Backfilled from what is actually deployed today.
         *
         * The alternative was to leave every existing subscription null, which
         * would have drawn "Contracted for 0 guards / 4 of 0 filled" on a client
         * that plainly has four officers standing its gates. Today's deployment
         * is the best available evidence of what each client agreed to, and it
         * is the figure an operator would confirm or correct on board 06.
         *
         * Every guard assigned to the estate counts, including one on leave and
         * one whose licence has lapsed. Both are still on the contract; whether
         * they can stand a post this morning is the fill rate's question, not
         * the contract's.
         */
        DB::connection('mysql')->statement('
            UPDATE subscriptions s
            SET s.contracted_guards = (
                SELECT COUNT(*) FROM guards g WHERE g.tenant_id = s.tenant_id
            )
            WHERE s.contracted_guards IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('contracted_guards');
        });
    }
};
