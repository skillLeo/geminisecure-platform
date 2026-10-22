<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the CONTRACT itself is next up for renewal — board community-admin-40.
 *
 * NOT `renews_on`, AND THE PRECEDENT FOR THAT IS ALREADY ON THIS TABLE.
 * `2026_09_10_120000_add_term_months_to_subscriptions_table` added `term_months`
 * for exactly this reason and said it plainly: "`renews_on` already exists and
 * is the NEXT renewal — a moving date that is recomputed each cycle... neither
 * can be derived from the other." `renews_on` is the billing cycle — the date
 * the next INVOICE is raised, a few weeks out at most. A contract's own term is
 * a different clock: Phoenix Park's board draws "Next invoice: Sep 1" beside
 * "Contract renewal: Mar 2027" as two distinct facts on the same hero card, and
 * no arithmetic on `started_on` and `term_months` reproduces the second one —
 * a term that has already rolled over once, twice, or been renegotiated is a
 * fact about that client's history, not a formula.
 *
 * Nullable, because a client mid-onboarding or on a rolling month-to-month
 * arrangement has no fixed term to report, and an estate whose committee has
 * never been told a renewal date deserves an em dash rather than a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->date('contract_renewal_on')->nullable()->after('term_months');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('contract_renewal_on');
        });
    }
};
