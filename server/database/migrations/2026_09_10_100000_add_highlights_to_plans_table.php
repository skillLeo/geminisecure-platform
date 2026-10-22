<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a tier is sold as, in the customer's words.
 *
 * The platform already records what a tier CONTAINS, in `plan_features` — a
 * per-feature toggle grid that the package builder edits and that an estate's
 * available feature set is resolved from. That is the operational truth and it
 * is not this.
 *
 * The subscription plans board draws something else: three short lines per
 * card, in a different vocabulary. "Dues & ledger" and "Notices & basic
 * reporting" describe a tier to somebody deciding whether to buy it; the
 * feature grid says whether `gated_meetings` is on. Deriving one from the
 * other would mean either putting toggle keys on a pricing page or writing
 * marketing copy into the table the runtime resolves features from.
 *
 * So a plan carries its own highlights: an ORDERED LIST, because the order is
 * the argument — the first line of a higher tier is "Everything in Standard",
 * and that only reads correctly first.
 *
 * A plan with none renders its card without the list rather than with an empty
 * box. A newly created tier is unsold until someone writes its pitch, and the
 * screen should say that by omission rather than by inventing three lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('highlights')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('highlights');
        });
    }
};
