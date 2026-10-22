<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per estate, holding that estate's configurable thresholds.
 *
 * Estate database only, so each community's settings are physically separate
 * and one estate cannot read or change another's.
 *
 * @property int $id
 * @property int $arrears_restriction_days
 * @property int $arrears_notice_days
 * @property bool $arrears_restriction_enabled
 * @property int $governance_arrears_days
 * @property bool $governance_tenure_check_enabled
 * @property bool $trust_primary_invites "Trust the main resident's invites" (stage 2): off by default, never fillable
 * @property int $governance_min_tenure_months
 * @property int $agm_notice_days
 * @property int $egm_notice_days
 * @property int $meeting_notice_days
 * @property int $meeting_quorum_percent
 * @property string $voting_eligibility `households` (the default) or `lots`: who may vote, and what a quorum is a share of
 * @property string|null $enquiries_email
 * @property string|null $enquiries_phone
 * @property string $security_provider
 * @property string|null $logo_path
 * @property string $staff_payroll_routing
 * @property bool $biometric_consent_enabled
 * @property string $payment_gateway_mode
 * @property bool $geofencing_enabled
 * @property int $sla_hours_high the hours a High ticket has before it is overdue (M98; 24 until an estate sets it)
 * @property int $sla_hours_medium the same for Medium (72)
 * @property int $sla_hours_low the same for Low (168)
 */
class EstateSetting extends Model
{
    /** The client's defaults (D-024), applied to a new estate. */
    public const DEFAULT_RESTRICTION_DAYS = 90;

    public const DEFAULT_NOTICE_DAYS = 14;

    /*
     * THERE IS NO SEPARATE AMENITY ARREARS SETTING, AND THERE USED TO BE.
     *
     * `DEFAULT_AMENITY_BLOCK_ENABLED` was false and `amenity_arrears_block_days`
     * sat at 90 beside the gate's own 90 — the cautious reading of a question
     * nobody had answered (Q-008). The client answered it the other way: one
     * arrears threshold across the estate, not two, because a household is
     * either in arrears or it is not.
     *
     * `Amenities::mayBook()` now reads `arrears_restriction_enabled` and
     * `arrears_restriction_days` — the same two the gate uses — so an estate
     * cannot end up admitting a household's visitors on Friday while refusing
     * the household itself the Club House on Saturday. An active payment plan
     * lifts both.
     */

    /**
     * How far into arrears a member may be and still stand for the committee.
     *
     * THE SAME 90 DAYS THE GATE USES, AND DELIBERATELY A SEPARATE FIGURE. One
     * decides whether a household's visitors get through a gate on a Friday
     * night (D-024); this decides whether a member may stand for the committee
     * that holds the estate's chequebook. They agree today by coincidence, and
     * an estate that softened one must not silently soften the other.
     */
    public const DEFAULT_GOVERNANCE_ARREARS_DAYS = 90;

    /**
     * Statutory notice periods — Q-010, ruled and confirmed.
     *
     * The AGM figure is the LONGER of the two Jamaican readings, 21 days under
     * the Companies Act rather than 14 under the Registration (Strata Titles)
     * Act. Refusing a meeting that could lawfully have been called costs the
     * estate a week; publishing one that could not costs it the meeting and
     * every decision taken at it.
     *
     * THE PERIODS ARE CONFIGURABLE; THE REFUSAL IS NOT. An estate may state its
     * own notice period and may not state that it has none, so there is no
     * `meeting_notice_enforced` column any more — it was a cautious escape
     * hatch of mine for an unconfirmed rule, and once confirmed it was only a
     * way to convene a challengeable meeting.
     */
    public const DEFAULT_AGM_NOTICE_DAYS = 21;

    public const DEFAULT_EGM_NOTICE_DAYS = 14;

    public const DEFAULT_MEETING_NOTICE_DAYS = 7;

    /**
     * The tenure an estate gets when it enables the check — Q-011, ruled.
     *
     * The CHECK ships off and stays off; this is the figure it takes when
     * somebody deliberately turns it on. It moved from 0 to 6 with the ruling,
     * because "enabled, threshold zero" disqualifies nobody while reading on a
     * screen as a working rule — the quietest possible way to have no rule.
     *
     * `governance_tenure_check_enabled` is deliberately NOT fillable, so it
     * cannot be switched on by a settings form posting one extra key. It is
     * never enabled silently.
     */
    public const DEFAULT_MIN_TENURE_MONTHS = 6;

    /** Board 12's "25% of eligible households". */
    public const DEFAULT_MEETING_QUORUM_PERCENT = 25;

    /**
     * WHO MAY VOTE, AND SO WHAT A QUORUM IS A SHARE OF (Hassam, 9 October 2026): the households that exist, by default,
     * or every lot, vacant ones included. Counted by lot, Phoenix Park's 25% quorum is 500 of 1,999 with about 443
     * households living there: never reachable. Whether an absent owner votes is the client's to say (CONFIRM-01), so it
     * is a setting; `estate:voting-eligibility` changes it, with a reason. Not fillable: no settings form flips it.
     */
    public const VOTES_BY_HOUSEHOLDS = 'households';

    public const VOTES_BY_LOTS = 'lots';

    /**
     * Who guards the estate, until an estate says otherwise — board 21.
     *
     * A default rather than a constant. Every estate on this platform is
     * guarded by Gemini Security Limited today, and an estate that changed
     * provider would still be an estate: who stood on its gate is a fact about
     * the community, not about this platform's identity.
     */
    public const DEFAULT_SECURITY_PROVIDER = 'Gemini Security Limited';

    /** Board 23's two payroll routings — the same vocabulary, different answers. */
    public const IN_HOUSE = 'in_house';

    public const GEMINI_MANAGED = 'gemini_managed';

    /**
     * How a resident's payment reaches the estate. MANUAL, and D-023 says so.
     *
     * Card capture is behind the `PaymentGateway` interface, whose only
     * implementation is a null one. `manual` is the true state of every estate
     * on this platform, not a placeholder waiting to be changed.
     *
     * // ASSUMPTION Q-004 — the payment gateway. Ruled: manual recording on day
     * // one, card capture behind the adapter. This constant is the ruling.
     */
    public const PAYMENT_MANUAL = 'manual';

    public const PAYMENT_GATEWAY = 'gateway';

    /**
     * The three settings this module may READ and must never WRITE.
     *
     * Each is a hard-stop category — money, biometrics, restriction — with a
     * recorded ruling behind it, and each ships in its safest position:
     * biometric consent off (D-022), payments manual (D-023), geofencing off
     * and deferred (D-033). The columns exist so a screen can state the
     * decision rather than silently omit the feature; `App\Services\Estate\
     * Settings` refuses every attempt to change one from this console and names
     * the ruling in the refusal.
     *
     * // ASSUMPTION Q-003 — biometric consent ships off and is per person, never
     * // an estate setting. The absence of an "enable biometrics" path IS the
     * // ruling; this list is what keeps it absent.
     *
     * @var list<string>
     */
    public const HELD_BACK = [
        'biometric_consent_enabled',
        'payment_gateway_mode',
        'geofencing_enabled',
    ];

    protected $fillable = [
        'arrears_restriction_days',
        'arrears_notice_days',
        'arrears_restriction_enabled',
        'governance_arrears_days',
        'governance_min_tenure_months',
        'agm_notice_days',
        'egm_notice_days',
        'meeting_notice_days',
        'meeting_quorum_percent',

        /*
         * Board 21's editable fields, and the two payroll routings behind board
         * 23's segmented controls.
         *
         * NONE OF `HELD_BACK` IS HERE, and that absence is load-bearing rather
         * than tidy. A settings form posts an array; a fillable
         * `biometric_consent_enabled` would let one extra key in that array
         * switch a legal-review-pending feature on, past every guard in the
         * service, without anybody typing the word "biometric". Laravel would
         * simply assign it. `EstateSettingsTest` asserts this list holds none of
         * them.
         */
        'enquiries_email',
        'enquiries_phone',
        'security_provider',
        'logo_path',
        'staff_payroll_routing',
    ];

    protected function casts(): array
    {
        return [
            'arrears_restriction_days' => 'integer',
            'arrears_notice_days' => 'integer',
            'arrears_restriction_enabled' => 'boolean',
            'governance_arrears_days' => 'integer',
            'governance_tenure_check_enabled' => 'boolean',
            // Not fillable either, for the same reason: binding a person to a household unreviewed is never
            // switched on by a stray form key. `ResidentInviteTrust::set()` writes it, with a reason.
            'trust_primary_invites' => 'boolean',
            'governance_min_tenure_months' => 'integer',
            'agm_notice_days' => 'integer',
            'egm_notice_days' => 'integer',
            'meeting_notice_days' => 'integer',
            'meeting_quorum_percent' => 'integer',
            'biometric_consent_enabled' => 'boolean',
            // What Dependents and Helpers may do here (App\Api\AccessRules): written only by AccessRules::save().
            'resident_access_rules' => 'array',
            'geofencing_enabled' => 'boolean',
            // Written only by Maintenance::setTargets(), with the ladder checked and an audit entry.
            'sla_hours_high' => 'integer',
            'sla_hours_medium' => 'integer',
            'sla_hours_low' => 'integer',
        ];
    }

    /**
     * Is this estate's own staff payroll run here, or by Gemini?
     *
     * Board 23 draws the answer as a caption under the segmented control —
     * "Estate's own staff" against In-house, "Managed in Gemini Console"
     * against Gemini-managed — so the caption is a function of the value and
     * cannot be stored beside it and left to disagree.
     */
    public function routingCaption(string $routing): string
    {
        return $routing === self::GEMINI_MANAGED
            ? 'Managed in Gemini Console'
            : "Estate's own staff";
    }

    /** "In-house" / "Gemini-managed", as board 23 prints them on the control. */
    public function routingLabel(string $routing): string
    {
        return $routing === self::GEMINI_MANAGED ? 'Gemini-managed' : 'In-house';
    }

    /**
     * The same two, as board 23 prints them inside a sentence.
     *
     * "Staff payroll: in-house · Security payroll: Gemini-managed". One falls to
     * lower case mid-sentence and the other does not, because "in-house" is a
     * description and "Gemini" is a company's name — lower-casing the label
     * mechanically would print "gemini-managed" against the firm that owns the
     * platform.
     */
    public function routingSentenceLabel(string $routing): string
    {
        return $routing === self::GEMINI_MANAGED ? 'Gemini-managed' : 'in-house';
    }

    /**
     * How many days' notice a meeting of this type needs before publication.
     *
     * An unknown type takes the general figure rather than zero. A type nobody
     * has taught this method about is not a type with no notice period — it is
     * one whose period nobody has stated, and the safe reading of silence is the
     * estate's general rule rather than none at all.
     */
    public function noticeDaysFor(string $type): int
    {
        return match ($type) {
            'agm' => $this->agm_notice_days,
            'egm' => $this->egm_notice_days,
            default => $this->meeting_notice_days,
        };
    }

    /**
     * This estate's settings, creating the defaults on first read.
     *
     * Returns a real row rather than a null-object, so a caller reading
     * `arrears_notice_days` always gets a number and never has to decide what
     * a missing setting means.
     */
    public static function current(): self
    {
        $settings = static::query()->firstOrCreate([], [
            'arrears_restriction_days' => self::DEFAULT_RESTRICTION_DAYS,
            'arrears_notice_days' => self::DEFAULT_NOTICE_DAYS,
            'arrears_restriction_enabled' => true,

            /*
             * The three above are the WHOLE arrears rule for this estate, and
             * the amenity module reads them rather than carrying its own pair.
             * Q-008, ruled: one arrears threshold across the estate, not two.
             */

            /*
             * THE GOVERNANCE RULES ARE WRITTEN HERE TOO, and they were not.
             *
             * These columns carry database defaults, so an estate whose row
             * already existed reads them back correctly — but the row this
             * method CREATES is the one it hands straight back to its caller,
             * and a column that was never in the INSERT is null on that
             * in-memory model however sensible the column default is. The first
             * meeting scheduled on a brand-new estate therefore went in with a
             * null quorum percentage and the database refused it.
             *
             * The whole point of this method is the sentence above it: a caller
             * always gets a number and never has to decide what a missing
             * setting means. Leaving eight columns out of it made that untrue
             * for exactly one estate — a new one. See D-052.
             */
            'governance_arrears_days' => self::DEFAULT_GOVERNANCE_ARREARS_DAYS,

            // Off, and 6 months for the estate that deliberately turns it on.
            // Never enabled silently: the flag is not fillable (Q-011, ruled).
            'governance_tenure_check_enabled' => false,
            'governance_min_tenure_months' => self::DEFAULT_MIN_TENURE_MONTHS,

            'agm_notice_days' => self::DEFAULT_AGM_NOTICE_DAYS,
            'egm_notice_days' => self::DEFAULT_EGM_NOTICE_DAYS,
            'meeting_notice_days' => self::DEFAULT_MEETING_NOTICE_DAYS,
            'meeting_quorum_percent' => self::DEFAULT_MEETING_QUORUM_PERCENT,
            // `voting_eligibility` is not fillable: its column default (households) is read back below.
        ]);

        /*
         * RE-READ WHAT WAS JUST INSERTED, AND THIS IS D-052 A SECOND TIME.
         *
         * `firstOrCreate` mass-assigns, so anything the `$fillable` guard drops
         * — `governance_tenure_check_enabled`, now deliberately not fillable so
         * it can never be switched on by a stray form key (Q-011) — never
         * reaches the in-memory model, even though the database applies its own
         * column default and stores the right value. The same is true of the
         * three `HELD_BACK` columns, which rely on column defaults and appear in
         * no array above.
         *
         * The row is therefore correct in the database and INCOMPLETE in memory,
         * for exactly one estate: a brand-new one. A caller reading
         * `governance_tenure_check_enabled` off it got null, and null is neither
         * true nor false — which is how a test's own teardown came to write null
         * into a NOT NULL column and fail on the first governance test that ran.
         *
         * The sentence above this method is the whole contract: a caller always
         * gets a number and never has to decide what a missing setting means.
         * One re-read on the create path keeps it true.
         */
        if ($settings->wasRecentlyCreated) {
            $settings->refresh();
        }

        return $settings;
    }
}
