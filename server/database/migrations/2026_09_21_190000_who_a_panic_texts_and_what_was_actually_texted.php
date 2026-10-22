<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE CLIENT'S FOUR NUMBERS (Q-M09), AND THE PROOF OF WHAT WAS TEXTED.
 *
 * "Additionally the SMS must be sent to: President (1-876-508-8082), Property Office
 * (1-876-224-4444), PPV1 Head of Security (1-876-845-9422) and Gemini Dispatch
 * (876-354-2119)."
 *
 * Two central tables and two additive columns. Nothing is dropped and nothing existing
 * is rewritten; the console, the push fan-out and the escalation ladder are untouched.
 *
 * site_emergency_numbers   WHO A PANIC TEXTS AT THIS SITE — one row per person, PER
 *                          ESTATE. Four rows for Phoenix Park Village 1 today; another
 *                          estate has different people, and the office changes them
 *                          without a developer because they are rows and not a constant.
 *
 *                          CENTRAL, not in the estate's own database, for three reasons
 *                          that all point the same way: the alert it answers
 *                          (`duress_alerts`) is central, the queued job that does the
 *                          texting runs with no tenant open (an estate database has no
 *                          `jobs` table — see `Support\CentralQueue`), and the ledger
 *                          below has to sit beside the alert to be readable as one
 *                          record. `estate_assignments` already stores "who is
 *                          accountable at this site" exactly here.
 *
 *                          `number` IS WHAT THE OFFICE TYPED, CHARACTER FOR CHARACTER.
 *                          The client gave three numbers with a 1-876 prefix and one
 *                          without; both are stored as written. `e164` is the diallable
 *                          form worked out from it (`Support\JamaicanNumber`) and kept
 *                          beside it, so a normalisation is a SECOND column somebody can
 *                          read and disagree with rather than a silent edit of what was
 *                          given. Null `e164` means the digits could not be resolved —
 *                          the row is then visibly unroutable instead of quietly wrong.
 *
 * alert_sms_dispatches     WHAT WAS ACTUALLY TEXTED, one row per number per alert,
 *                          WRITTEN AFTER THE ATTEMPT AND NEVER BEFORE. `status` is the
 *                          outcome in the sender's own words: `sent` (a provider
 *                          accepted it), `recorded` (there is no provider, so the
 *                          message was written down and delivered to NOBODY), `failed`
 *                          (a provider was configured and refused) or `unroutable` (the
 *                          stored number has no diallable form).
 *
 *                          APPEND-ONLY, with the two triggers as well as the grant. It
 *                          is the evidence of who was told that somebody pressed panic,
 *                          and evidence that can be edited afterwards is not evidence.
 *                          No foreign key to `duress_alerts` on purpose: a cascade
 *                          delete would meet the BEFORE DELETE trigger and fail, and an
 *                          alert nobody may delete needs no cascade. `audit_log` holds
 *                          its references the same way.
 *
 * duress_alerts.sms_*      The same shape as `alerted_handsets` and for the same reason
 *                          (M6 §2.1 part 5): `sms_recipients` is how many numbers the
 *                          site had on file when the alert was raised, written as the
 *                          job is queued; `sms_delivered` is how many a provider
 *                          actually ACCEPTED, written when the job finishes.
 *
 *                          NULL IS NOT ZERO here either. Null on `sms_delivered` means
 *                          the texting has not finished; 0 is the positive statement
 *                          that no text was delivered — which is the TRUE answer on this
 *                          platform today, because no SMS provider is configured and the
 *                          default driver records rather than sends. A text that was
 *                          only written down is never counted as a phone that was
 *                          reached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_emergency_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');

            // `president`, `property_office`, `head_of_security`, `dispatch` — what this
            // person is to the site, so a screen and a text can name the role rather than
            // the individual. See `Services\Dispatch\SiteEmergencyNumbers::ROLES`.
            $table->string('role', 32);

            // As the office would write it on a list: "President", "Gemini Dispatch".
            $table->string('label', 120);

            // EXACTLY AS GIVEN. Never rewritten by the platform.
            $table->string('number', 40);

            // The diallable form, or null when the digits cannot be resolved.
            $table->string('e164', 20)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            // One row per number per estate: a duplicate would text somebody twice for
            // one emergency and count them twice in what the resident is told.
            $table->unique(['tenant_id', 'number'], 'site_emergency_numbers_unique');
            $table->index(['tenant_id', 'is_active', 'sort'], 'site_emergency_numbers_active_index');
        });

        Schema::create('alert_sms_dispatches', function (Blueprint $table) {
            $table->id();

            // No FK — see the class note. The alert it belongs to, and the estate, so the
            // row is readable on its own.
            $table->unsignedBigInteger('duress_alert_id');
            $table->string('tenant_id');

            // Who was texted, copied onto the row rather than joined: the recipient list
            // is editable, and "who was told that night" must not change when it is.
            $table->unsignedBigInteger('site_emergency_number_id')->nullable();
            $table->string('role', 32);
            $table->string('label', 120);
            $table->string('number', 40);
            $table->string('e164', 20)->nullable();

            // sent | recorded | failed | unroutable
            $table->string('status', 16);

            // Which driver answered — `log` today. Never a credential.
            $table->string('driver', 32);

            // The provider's own id for the message, when it gives one.
            $table->string('reference', 120)->nullable();

            // A CODE, never the provider's prose and never the message body.
            $table->string('error', 64)->nullable();

            $table->timestamp('attempted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['duress_alert_id'], 'alert_sms_dispatches_alert_index');
            $table->index(['tenant_id', 'attempted_at'], 'alert_sms_dispatches_site_index');
        });

        DB::unprepared("
            CREATE TRIGGER alert_sms_dispatches_no_update BEFORE UPDATE ON alert_sms_dispatches
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'alert_sms_dispatches is append-only: it is the record of who was told about an emergency'
        ");

        DB::unprepared("
            CREATE TRIGGER alert_sms_dispatches_no_delete BEFORE DELETE ON alert_sms_dispatches
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'alert_sms_dispatches is append-only'
        ");

        Schema::table('duress_alerts', function (Blueprint $table) {
            $table->unsignedSmallInteger('sms_recipients')->nullable()->after('escalated_contacts');
            $table->unsignedSmallInteger('sms_delivered')->nullable()->after('sms_recipients');
        });
    }

    public function down(): void
    {
        Schema::table('duress_alerts', function (Blueprint $table) {
            $table->dropColumn(['sms_recipients', 'sms_delivered']);
        });

        DB::unprepared('DROP TRIGGER IF EXISTS alert_sms_dispatches_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS alert_sms_dispatches_no_delete');

        Schema::dropIfExists('alert_sms_dispatches');
        Schema::dropIfExists('site_emergency_numbers');
    }
};
