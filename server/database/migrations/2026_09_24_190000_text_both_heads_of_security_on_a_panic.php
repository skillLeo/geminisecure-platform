<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOTH HEADS OF SECURITY ARE TEXTED ON A PANIC — M10 §1.2 items 4 and 5.
 *
 * "The Gemini Head of Security for that site — dashboard alert plus email (and SMS
 * when a provider exists)" and "the estate's own Head of Security — dashboard alert
 * plus email/SMS". The mail half was built; the text half was not, and since the
 * Director can now connect Twilio from the console there is no longer a reason for it
 * to wait. The number is the one each of them gave on §1.4's verification page
 * (`users.contact_phone`).
 *
 * THE SAME LEDGER, A THIRD AUDIENCE. `alert_sms_dispatches.audience` already tells the
 * estate's own numbers (`site`) from the resident's nominated contacts (`household`);
 * a Head of Security's text is `staff`. The only column the shape lacks is WHO:
 *
 *   user_id      the console user texted, and nothing else about them. Their NAME is
 *                never copied here — `label` carries the role ("Gemini Head of
 *                Security"), the site rows' rule — and the number is MASKED with
 *                `e164` left null, the household rows' rule: a person's own phone is
 *                on their account, and the ledger needs to say who was told, not to
 *                hold a second copy of it. No FK, for `duress_alert_id`'s reason: the
 *                BEFORE DELETE trigger would meet a cascade and fail.
 *
 * A NUMBER THAT IS ALREADY ONE OF THE SITE'S IS TEXTED ONCE. "PPV1 Head of Security"
 * is one of the four numbers Q-M09 names, and the estate's own Head of Security is very
 * likely that same phone. Two identical emergency texts to one handset read as two
 * emergencies, so the second is not sent — and the row says so: status `deduplicated`,
 * with `site_emergency_number_id` pointing at the site number whose text covered it.
 * No existing status changes meaning, and `deduplicated` is neither an attempt nor a
 * delivery, so no count built on `sent` moves.
 *
 * AND FOUR COUNTS ON THE ALERT, in the shape the site's and the household's already
 * have, written as the leg is queued and finished by the job:
 *
 *   staff_sms_recipients      Heads of Security being texted (after the dedupe)
 *   staff_sms_delivered       how many a PROVIDER ACCEPTED; NULL IS NOT ZERO — null
 *                             means still texting
 *   staff_sms_no_phone        Heads of Security on file with no phone to text
 *   staff_sms_deduplicated    Heads of Security not texted twice, because the same
 *                             number is already being texted for this alert
 *
 * Neither table is new, so `GrantedTables` does not change, and adding columns fires
 * neither of the ledger's row-level triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('alert_sms_dispatches', 'user_id')) {
            Schema::table('alert_sms_dispatches', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->after('household_emergency_contact_id');
            });
        }

        if (! Schema::hasColumn('duress_alerts', 'staff_sms_recipients')) {
            Schema::table('duress_alerts', function (Blueprint $table): void {
                $table->unsignedSmallInteger('staff_sms_recipients')->nullable()->after('household_sms_over_cap');
                $table->unsignedSmallInteger('staff_sms_delivered')->nullable()->after('staff_sms_recipients');
                $table->unsignedSmallInteger('staff_sms_no_phone')->nullable()->after('staff_sms_delivered');
                $table->unsignedSmallInteger('staff_sms_deduplicated')->nullable()->after('staff_sms_no_phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('duress_alerts', 'staff_sms_recipients')) {
            Schema::table('duress_alerts', function (Blueprint $table): void {
                $table->dropColumn(['staff_sms_recipients', 'staff_sms_delivered', 'staff_sms_no_phone', 'staff_sms_deduplicated']);
            });
        }

        if (Schema::hasColumn('alert_sms_dispatches', 'user_id')) {
            Schema::table('alert_sms_dispatches', function (Blueprint $table): void {
                $table->dropColumn('user_id');
            });
        }
    }
};
