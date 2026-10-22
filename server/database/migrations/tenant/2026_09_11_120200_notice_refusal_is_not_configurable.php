<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rulings on Q-010 and Q-011.
 *
 * Q-010 — THE PERIODS ARE CONFIGURABLE; THE REFUSAL IS NOT. 21 days AGM, 14 EGM,
 * 7 committee, all confirmed as they shipped. What changes is that
 * `meeting_notice_enforced` is gone: an estate may say how long its notice
 * period is and may not say that it has none. Publishing a meeting inside its
 * own stated period is refused, always, and there is no longer a column that
 * turns the refusal off.
 *
 * The column was mine, not the client's — a cautious escape hatch for a rule
 * nobody had confirmed. Once the rule is confirmed the hatch is just a way to
 * convene a meeting that can be challenged, and every decision taken at it with
 * it. `EstateGovernanceTest` asserted the hatch worked; it now asserts there
 * isn't one.
 *
 * Q-011 — TENURE STAYS OFF, AND ITS DEFAULT BECOMES 6 MONTHS. Confirmed off, so
 * nobody is disqualified by a rule their estate never stated. But the threshold
 * default moves from 0 to 6: when an estate does enable the check, 0 would mean
 * "enabled and disqualifying nobody", which reads on a screen as the rule
 * working and is the quietest possible way to have no rule at all.
 *
 * "NEVER ENABLED SILENTLY" is enforced in the model rather than here:
 * `governance_tenure_check_enabled` comes out of `$fillable`, so a settings form
 * posting one extra key cannot switch it on the way it could before. It takes a
 * deliberate write. That is the same protection `HELD_BACK` gives biometrics and
 * the payment gateway, and for the same reason — a mass-assigned array is how a
 * flag nobody discussed ends up true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_settings', function (Blueprint $table): void {
            $table->dropColumn('meeting_notice_enforced');

            $table->unsignedSmallInteger('governance_min_tenure_months')->default(6)->change();
        });

        /*
         * Estates already carrying the zero it shipped with are moved to 6.
         *
         * Only where the check is OFF, which today is every estate — a hypothetical
         * estate that had enabled it and deliberately set zero would be stating a
         * rule, and this migration does not get to overrule it.
         */
        DB::table('estate_settings')
            ->where('governance_tenure_check_enabled', false)
            ->where('governance_min_tenure_months', 0)
            ->update(['governance_min_tenure_months' => 6]);
    }

    public function down(): void
    {
        Schema::table('estate_settings', function (Blueprint $table): void {
            $table->boolean('meeting_notice_enforced')->default(true);

            $table->unsignedSmallInteger('governance_min_tenure_months')->default(0)->change();
        });
    }
};
