<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Settings module — boards 21, 22, 23 and 24.
 *
 * FOUR BOARDS AND ONE NEW TABLE, because three of the four already have
 * somewhere to live and the fourth is a decision the estate makes.
 *
 *   21  Estate profile      — the estate's own contact details, added to
 *                             `estate_settings` beside the arrears and meeting
 *                             thresholds it already holds
 *   22  Users & roles       — CENTRAL. Users, roles and assignments live in
 *                             gs_platform (D-012) and nothing here duplicates
 *                             them
 *   23  Feature toggles     — the catalogue is CENTRAL (`package_features`,
 *                             `plan_features`, `subscriptions`); this table
 *                             holds only what THIS estate decided differently
 *   24  Role access matrix  — CENTRAL, read-only, and not stored twice
 *
 * WHAT IS DELIBERATELY NOT ADDED HERE
 * ===================================
 *
 * NO `estate_name`, `address`, `total_units` OR `phase_count`. Every one of
 * those already exists: the name, address line and parish are real columns on
 * the central `tenants` record (D-033), the unit count is `SELECT COUNT(*) FROM
 * units` in this very database, and the phase count is derived from the phase
 * STRUCTURE rather than stored beside it — D-033 settled that too, in these
 * words: "Two places holding one count is how they come to disagree."
 *
 * Board 21 draws all four as form inputs. Copying them into `estate_settings`
 * so a form could write them would give this estate a second name, free to
 * diverge from the one on Gemini's invoices and on the dispatch board that
 * sends a supervisor to the site. The screen shows them; it does not own them.
 *
 * NO COLUMN FOR THE RESIDENT-ENTRY OR EMERGENCY-VEHICLE EXEMPTIONS, and there
 * never will be one. `2026_09_09_200000_create_restriction_tables` states the
 * rule and this migration restates it because a SETTINGS screen is exactly
 * where somebody would think to add the switch: a resident's own entry and an
 * emergency or medical vehicle are never restricted, that is hardcoded in
 * `App\Services\Restriction\RestrictionPolicy`, and a misconfigured estate
 * leaving an ambulance at a gate is not recoverable by any amount of UI copy.
 * `EstateSettingsTest` asserts that no writable field on this module could
 * switch either of them off.
 *
 * ASSUMPTION Q-013 — the arrears thresholds themselves. `estate_settings`
 * already holds `arrears_restriction_days`, `arrears_notice_days` and
 * `arrears_restriction_enabled` at the client's defaults (D-024), and the Build
 * Spec calls them estate-configurable. None of boards 21 to 24 draws one, so
 * whether an estate administrator may change them HERE is unstated. The safest
 * option is applied: this module neither reads them onto a screen nor writes
 * them, and `SettingsController::saveProfile()` validates a fixed three-key
 * allowlist. Switching restriction off decides who gets through a gate on a
 * Friday night for several hundred households, and that is not a field to open
 * by default.
 *
 * NO `is_owner` ON A USER. Board 22 draws exactly one amber "Owner" badge. It
 * is DERIVED — the seniormost estate role assigned in this estate, which is the
 * Community Super Admin where one has been designated and the President where
 * none has. A stored flag would be a second answer to a question the role
 * matrix already answers, and the two would disagree the first time a committee
 * changed hands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_settings', function (Blueprint $table) {
            /* ------------------------------------------- board 21, Contact */

            /*
             * The estate's own general-enquiries mailbox and telephone.
             *
             * These are the estate's, not the security company's. The client
             * record on `tenants` carries what Gemini needs to invoice and to
             * dispatch to; this is the number a resident rings about a blocked
             * drain, and the committee changes it without asking anybody.
             *
             * Stored as displayed — "(876) 555 0100". A normalised E.164 form
             * would be right if anything dialled it programmatically, and
             * nothing does: it is printed on a notice.
             */
            $table->string('enquiries_email', 160)->nullable();
            $table->string('enquiries_phone', 40)->nullable();

            /* ---------------------------------- board 21, Security provider */

            /*
             * Who guards the estate. Gemini Security Limited today, and a
             * column rather than a constant because an estate that changes
             * provider does not stop being an estate — the record of who was
             * on the gate is a fact about the community, not about this
             * platform's identity.
             */
            $table->string('security_provider', 120)->default('Gemini Security Limited');

            /*
             * The estate's logo, as a path on the tenant disk.
             *
             * A PATH, NEVER THE BYTES. `tenant_asset()` already resolves
             * per-estate storage (config/tenancy.php), so one estate's logo
             * cannot be served from another's URL. Nullable, because an estate
             * with no logo yet must render the placeholder mark board 21 draws
             * rather than a broken image.
             */
            $table->string('logo_path', 255)->nullable();

            /* --------------------------------------- board 23, the routings */

            /*
             * Which system originates each payroll — board 23's two segmented
             * rows, and NOT one setting with two values.
             *
             * `staff_payroll_routing` is the estate's own staff: gardeners,
             * office help, a caretaker. In-house by default, because they are
             * the estate's employees and their payroll journals belong in the
             * estate's own ledger.
             *
             * `security_payroll_routing` is the guards, and it is LOCKED to
             * gemini-managed. Guards are Gemini Security Limited's employees,
             * paid by Gemini, and their statutory deductions are filed under
             * Gemini's TRN. An estate that routed guard pay in-house would be
             * claiming to employ people it does not employ, and would post a
             * payroll liability it has no obligation to settle. The column
             * exists so the screen can SHOW the answer; `App\Services\Estate\
             * Settings` refuses to change it and says why.
             */
            $table->string('staff_payroll_routing', 24)->default('in_house');
            $table->string('security_payroll_routing', 24)->default('gemini_managed');

            /* ------------------- the three held back, each behind its own flag */

            /*
             * BIOMETRIC CONSENT — OFF, and it ships off. D-022 closed Q-003:
             * legal review of the consent copy is pending, the copy that exists
             * is marked DRAFT, and the flag governs whether the feature runs at
             * all. Invariant 9 is unaffected either way — no template, no
             * landmark set and no image exists anywhere in this system, so
             * there is nothing for the flag to protect except the consent.
             *
             * Named, and on the estate, because consent is given by the people
             * of one community and cannot be given on their behalf centrally.
             */
            $table->boolean('biometric_consent_enabled')->default(false);

            /*
             * PAYMENT GATEWAY — MANUAL, and manual is not a placeholder.
             *
             * ASSUMPTION Q-012 — who decides when an estate starts taking
             * cards. D-023 settled that day one is manual; it did not settle
             * whether the community may switch itself over from this panel or
             * whether that is a commercial change Gemini makes. The safest
             * option is applied: locked off here, refused by the service, and
             * the column ready for either ruling.
             *
             * D-023 closed Q-004: dues are recorded manually on day one and
             * card capture arrives behind the `PaymentGateway` interface, which
             * has a null implementation. `manual` is therefore the true state
             * of this estate and not a default nobody chose. An estate switched
             * to `gateway` today would offer residents a card form that talks
             * to nothing, and a resident who believes they have paid is a
             * resident whose gate access is about to be restricted for arrears
             * they thought were settled.
             *
             * manual | gateway
             */
            $table->string('payment_gateway_mode', 16)->default('manual');

            /*
             * GEOFENCING — OFF, deferred (D-033).
             *
             * The estate's boundary is the Guard App's clock-in check, and
             * D-033 declined to stub it: it needs surveyed coordinates rather
             * than a nullable column nobody populates, and nothing reads it
             * before Phase 3. The flag exists so this screen can say the
             * feature is deferred rather than silently omitting it, which is
             * the difference between a decision and an oversight.
             */
            $table->boolean('geofencing_enabled')->default(false);
        });

        /*
         * What THIS estate decided about a feature the catalogue offers it.
         *
         * ONLY OVERRIDES LIVE HERE. A row means somebody in this community made
         * a decision; no row means the plan's answer stands. Writing a row per
         * feature at provisioning would make every estate's settings look
         * decided when nothing had been decided, and the day a plan changed,
         * eleven stale copies of its old answer would outvote it.
         *
         * The effective state is therefore resolved, never read:
         *
         *     enabled = this estate's override, else the plan includes it
         *
         * NO FOREIGN KEY TO `package_features`, AND THERE CANNOT BE ONE. The
         * catalogue is central, in gs_platform; this table is in the estate's
         * own database, which authenticates as a MySQL user holding no grant on
         * gs_platform at all. A cross-database foreign key would either fail to
         * create or become the one join that reaches across the isolation
         * boundary. `feature_key` carries the catalogue's own key and
         * `App\Services\Estate\Settings` drops an override whose feature the
         * catalogue no longer offers, rather than showing a switch for
         * something that no longer exists.
         */
        Schema::create('estate_features', function (Blueprint $table) {
            $table->id();

            /*
             * `package_features.key` — "accounting_core", "evoting". Unique,
             * because an estate has one answer per feature: two rows would mean
             * the community both enabled and disabled the same thing, and
             * whichever the query read first would win.
             */
            $table->string('feature_key', 64)->unique();

            $table->boolean('enabled');

            /*
             * WHO, WHEN AND WHY — on the row, and again in the central audit
             * log. Board 23 states it plainly: "Every toggle change writes an
             * audit record with actor, timestamp, and reason."
             *
             * The two are not redundant. `audit_log` is append-only and holds
             * the HISTORY — every change ever made, unedited, by anyone. These
             * columns hold the CURRENT answer's provenance, which is what the
             * screen needs to print beside a switch without opening a second
             * database. The log is the record; this is the caption.
             *
             * `changed_by_name` is denormalised beside the id deliberately, for
             * the reason every other actor line in this system is: a committee
             * member's account can be closed, and "who turned the accounting
             * module off" must still answer with a name a year later.
             */
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('changed_by_name', 160)->nullable();

            /*
             * The reason, and it is NOT NULL. A feature switched off changes
             * what several hundred households can do, and an unexplained change
             * to that is exactly what an audit has to be able to question. The
             * service refuses a blank one before it ever reaches the column.
             */
            $table->string('reason', 300);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estate_features');

        Schema::table('estate_settings', function (Blueprint $table) {
            $table->dropColumn([
                'enquiries_email',
                'enquiries_phone',
                'security_provider',
                'logo_path',
                'staff_payroll_routing',
                'security_payroll_routing',
                'biometric_consent_enabled',
                'payment_gateway_mode',
                'geofencing_enabled',
            ]);
        });
    }
};
