<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE HOUSEHOLD'S OWN EMERGENCY CONTACTS ARE TEXTED ON A PANIC — M10 §1.2 item 7.
 *
 * "The household's nominated emergency contacts — SMS as already built." It was not
 * built. `household_emergency_contacts` has been stored in the estate's database since
 * 13 D3 and nothing on the panic path ever read it: the only way a sister or a son was
 * told was the resident's own phone opening its Messages app, which is exactly the step
 * the client says a person in a panic will not remember to take.
 *
 * ONE LEDGER, TWO AUDIENCES — NOT A SIBLING TABLE.
 * ================================================
 * `alert_sms_dispatches` is already the append-only record of every text a panic
 * caused, with the outcome in the sender's own words (`sent`, `recorded`, `failed`,
 * `unroutable`). A household contact's text is the same fact about the same alert,
 * and a second table would mean two places to read "what did this panic text", two
 * sets of triggers and two ways for the counts to disagree. So the shape is widened,
 * and the difference is a column:
 *
 *   audience                        `site` (the estate's own emergency numbers, Q-M09)
 *                                   or `household` (the resident's nominated contacts).
 *                                   DEFAULT `site`, so every row already written keeps
 *                                   the meaning it was written with and the existing
 *                                   job needs no backfill.
 *
 *   household_emergency_contact_id  which contact, by the ESTATE database's id. An
 *                                   identifier and nothing else — no FK, because the
 *                                   row lives in another database, and because the
 *                                   ledger must outlive the contact being deleted.
 *
 * WHAT A HOUSEHOLD ROW DOES NOT HOLD, and why the shape still fits. The contact is a
 * private person the resident nominated, not an office the estate published, so the
 * central ledger keeps only what "who was told" needs: the relationship in `label`
 * ("sister"), the number MASKED in `number` ("+1876•••0199"), and `e164` left NULL.
 * The contact's NAME is never copied here — the site rows hold an office's label
 * ("Property Office"), never a person's name, and this keeps to that. The full number
 * stays in the estate's database, where it already was.
 *
 * APPEND-ONLY STILL HOLDS. Adding a column fires neither trigger (they are row-level
 * BEFORE UPDATE / BEFORE DELETE), the grant is table-level and unchanged, and no
 * existing row is rewritten: the default supplies `site` without an UPDATE.
 *
 * AND THREE COLUMNS ON THE ALERT, in the shape `sms_recipients` / `sms_delivered`
 * already have and for the same reason (M6 §2.1 part 5 — the resident is told the truth):
 *
 *   household_sms_recipients   how many of the household's contacts this alert is
 *                              texting — at most `HouseholdContactsSms::CAP` — written as
 *                              the job is queued. NOT how many were delivered.
 *   household_sms_delivered    how many a PROVIDER ACCEPTED, written when the job ends.
 *                              NULL IS NOT ZERO: null means still texting.
 *   household_sms_over_cap     THE CAP, RECORDED. How many further contacts the household
 *                              had on file that were NOT texted because a panic texts at
 *                              most five. 0 almost always; never silently dropped.
 *
 * All three stay NULL on an officer's duress, which texts no household at all — null
 * there is the statement "this leg does not apply", not "not finished".
 *
 * `duress_alerts` is CENTRAL_MUTABLE and `alert_sms_dispatches` is CENTRAL_APPEND_ONLY;
 * neither is a new table, so `GrantedTables` does not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('alert_sms_dispatches', 'audience')) {
            Schema::table('alert_sms_dispatches', function (Blueprint $table): void {
                // site | household
                $table->string('audience', 16)->default('site')->after('tenant_id');
                $table->unsignedBigInteger('household_emergency_contact_id')->nullable()->after('site_emergency_number_id');

                $table->index(['duress_alert_id', 'audience'], 'alert_sms_dispatches_alert_audience_index');
            });
        }

        if (! Schema::hasColumn('duress_alerts', 'household_sms_recipients')) {
            Schema::table('duress_alerts', function (Blueprint $table): void {
                $table->unsignedSmallInteger('household_sms_recipients')->nullable()->after('sms_delivered');
                $table->unsignedSmallInteger('household_sms_delivered')->nullable()->after('household_sms_recipients');
                $table->unsignedSmallInteger('household_sms_over_cap')->nullable()->after('household_sms_delivered');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('duress_alerts', 'household_sms_recipients')) {
            Schema::table('duress_alerts', function (Blueprint $table): void {
                $table->dropColumn(['household_sms_recipients', 'household_sms_delivered', 'household_sms_over_cap']);
            });
        }

        if (Schema::hasColumn('alert_sms_dispatches', 'audience')) {
            Schema::table('alert_sms_dispatches', function (Blueprint $table): void {
                $table->dropIndex('alert_sms_dispatches_alert_audience_index');
                $table->dropColumn(['audience', 'household_emergency_contact_id']);
            });
        }
    }
};
