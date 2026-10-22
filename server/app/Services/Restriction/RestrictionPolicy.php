<?php

declare(strict_types=1);

namespace App\Services\Restriction;

use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Services\Estate\Collections;

/**
 * Whether a pass may be issued or admitted for a household, and why not.
 *
 * The single place that answers the restriction question. Both the Estate
 * Console and the Guard App's /api/v1 endpoints call this, so a guard at a gate
 * and a committee member at a desk can never be told different things about the
 * same household.
 *
 * THE RULES (D-024, client ruling)
 * --------------------------------
 * Estate-configurable, with these defaults:
 *   - eligible at 90 days overdue
 *   - 14 days' written notice before restriction takes effect
 *   - guest passes only
 *
 * NOT configurable, and enforced here rather than in a settings table:
 *   - a resident's own entry is never restricted
 *   - emergency and medical vehicles are never restricted
 *
 * Those two live in code because a settings table can be misconfigured, and a
 * misconfigured estate leaving an ambulance at a gate is not a recoverable
 * error. No estate may widen what restriction covers; it may only switch it off.
 *
 * AND THREE RULES THAT PREDATE THE CONFIGURATION
 *   - restriction follows ARREARS, never a failed payment
 *   - a declined card or a gateway outage never restricts anything
 *   - restriction never blocks a safety function
 *
 * A PAYMENT PLAN LIFTS RESTRICTION WHILE IT IS BEING MET (board 7). That is
 * checked here, after the exemptions and only for a household that is otherwise
 * restricted, so it can widen admission and can never narrow it — an edit to
 * the collections module cannot reach an ambulance, because the exempt path has
 * already returned by the time a plan is consulted.
 *
 * THE GUARD IS NEVER TOLD A PLAN EXISTS. A plan is a fact about money, and the
 * verdict this class returns carries no key and no wording that could reveal
 * one; a household on a plan is admitted exactly as a household with no arrears
 * is admitted, and is indistinguishable from one at the gate (invariant 2).
 */
class RestrictionPolicy
{
    /**
     * Pass categories that can never be restricted, whatever the arrears.
     *
     * Hardcoded on purpose. See the class docblock.
     */
    public const UNRESTRICTABLE_CATEGORIES = [
        'emergency',
        'medical',
        'resident',      // a resident's own entry
        'fire',
        'utility_emergency',
    ];

    /**
     * The only words a guard is ever given about a household's standing (D-025, Q-006). No em dash (Law 3, 12 Oct):
     * the Guard App's own copy (apps/guard/src/features/gate/verdict.ts, RESTRICTED_SENTENCE) already reads this way.
     */
    public const GATE_SENTENCE = 'Access restricted: contact management';

    /** The only category restriction may ever apply to. */
    public const RESTRICTABLE_CATEGORIES = [
        'guest',
        'visitor',
        'delivery',
        'contractor',
    ];

    /**
     * Resolved on demand rather than injected, and the reason is the guarantee.
     *
     * `new RestrictionPolicy` is how the invariant-2 suites build this class,
     * and they decide on households that were never saved — a constructor that
     * demanded a database-backed collaborator would put a query behind every
     * one of those assertions, or worse, make them awkward enough to weaken.
     * Nothing is asked of the container until a persisted household is actually
     * in front of it.
     */
    private ?Collections $collections = null;

    /**
     * Decide admission for one household and one pass category.
     *
     * Returns a verdict the guard can act on, never a figure. Invariant 2: the
     * guard receives `access_restricted` as a boolean and nothing more, so
     * nothing in this return value carries an amount, an ageing bucket or a
     * payment history — and nothing in the reason wording implies money.
     *
     * @return array{
     *     admitted: bool,
     *     verdict: 'admit'|'restricted',
     *     reason: string|null,
     *     category_exempt: bool
     * }
     */
    public function decide(Household $household, string $passCategory): array
    {
        $category = strtolower(trim($passCategory));

        /*
         * Exemption is checked FIRST, before the household is even consulted.
         *
         * Ordering it this way means no future edit to the arrears logic can
         * accidentally reach an emergency vehicle: the exempt path returns
         * before restriction is considered at all.
         */
        if (in_array($category, self::UNRESTRICTABLE_CATEGORIES, true)) {
            return [
                'admitted' => true,
                'verdict' => 'admit',
                'reason' => null,
                'category_exempt' => true,
            ];
        }

        // Anything not explicitly restrictable is admitted. An unrecognised
        // category must not become a denial by default.
        if (! in_array($category, self::RESTRICTABLE_CATEGORIES, true)) {
            return [
                'admitted' => true,
                'verdict' => 'admit',
                'reason' => null,
                'category_exempt' => true,
            ];
        }

        if (! $household->access_restricted) {
            return [
                'admitted' => true,
                'verdict' => 'admit',
                'reason' => null,
                'category_exempt' => false,
            ];
        }

        /*
         * The household is restricted — unless an agreed payment plan is
         * currently being met, in which case the restriction is lifted for as
         * long as that stays true (board 7).
         *
         * The flag itself is left standing, and is not cleared when a plan is
         * activated. The arrears did not go away; they were given a schedule.
         * Clearing it would leave collections with nothing to reinstate when an
         * instalment is missed, and would put the reinstatement in a module
         * that is not this one — which is precisely how a household ends up
         * restricted at a gate and clear on a screen.
         *
         * The returned verdict is byte-for-byte the ordinary admit. A guard
         * must not be able to tell a household on a plan from a household with
         * no arrears at all.
         */
        if ($this->shieldedByPaymentPlan($household)) {
            return [
                'admitted' => true,
                'verdict' => 'admit',
                'reason' => null,
                'category_exempt' => false,
            ];
        }

        /*
         * The only wording a guard ever sees (D-025).
         *
         * // ASSUMPTION Q-006 — how a restricted household presents at the gate.
         * // Ruled: these exact words, amber rather than red, and no amount and
         * // no form of words implying one. This line is where that landed.
         *
         * No amount, no bucket, no history, and nothing implying money — the
         * guard's next action is to call management, not to discuss a balance
         * with the person at the gate.
         */
        return [
            'admitted' => false,
            'verdict' => 'restricted',
            'reason' => self::GATE_SENTENCE,
            'category_exempt' => false,
        ];
    }

    /**
     * Is an agreed payment plan currently standing over this household's
     * arrears?
     *
     * KEYED ON THE UNIT, because the unit is what owes the dues. A household
     * with no unit — an unsaved one, or a record mid-move — is shielded by
     * nothing, which is the safe answer in both directions: it neither admits a
     * restricted household on a plan that does not exist, nor restricts anyone
     * the exemptions above have already let through.
     */
    private function shieldedByPaymentPlan(Household $household): bool
    {
        // An unsaved household cannot own a plan, and asking the database about
        // one would be a query with no row to find.
        if (! $household->exists) {
            return false;
        }

        $unit = $household->unit;

        if ($unit === null) {
            return false;
        }

        $this->collections ??= app(Collections::class);

        return $this->collections->isProtected($unit);
    }

    /**
     * Is this household eligible for restriction yet?
     *
     * Eligibility is not restriction. A household becomes eligible at the
     * threshold and may only be restricted after written notice has run.
     */
    public function isEligible(int $daysOverdue, ?EstateSetting $settings = null): bool
    {
        $settings ??= EstateSetting::current();

        if (! $settings->arrears_restriction_enabled) {
            return false;
        }

        return $daysOverdue >= $settings->arrears_restriction_days;
    }

    /**
     * May an eligible household now be restricted?
     *
     * Requires notice to have been served AND the notice period to have
     * elapsed. Serving notice and restricting on the same day is the failure
     * this guards against.
     */
    public function noticePeriodHasElapsed(
        ?\DateTimeInterface $notifiedAt,
        ?EstateSetting $settings = null,
    ): bool {
        if ($notifiedAt === null) {
            return false;
        }

        $settings ??= EstateSetting::current();

        return now()->diffInDays($notifiedAt, absolute: true) >= $settings->arrears_notice_days;
    }
}
