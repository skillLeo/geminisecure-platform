<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A PANIC EMAILS THE HOUSEHOLD'S OWN EMERGENCY CONTACTS (M12 §2.5).
 *
 * "Emergency contacts take a phone number and an email. Panic emails them and the estate immediately." The estate
 * is already emailed on every panic (the escalation mail, `escalated_contacts`); the household's contacts were only
 * ever TEXTED, and no SMS provider is connected. An email goes today.
 *
 * alert_email_dispatches              WHAT WAS ACTUALLY EMAILED, one row per contact per alert, written AFTER the
 *                                     attempt — `sent` only when the mail server accepted it. `alert_sms_dispatches`'
 *                                     rules exactly: the relationship as the label, the address MASKED (a private
 *                                     person's address stays in the estate's database), never a name; append-only,
 *                                     by the withheld grant (GrantedTables) and these two triggers.
 * duress_alerts.household_email_recipients
 *                                     how many contacts this alert is emailing (at most five); null on an officer's
 *                                     duress, which tells no household. The answer's `email.household.finished` is
 *                                     the rows reaching this number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_email_dispatches', function (Blueprint $table): void {
            $table->id();
            // No FK, `alert_sms_dispatches`' reason: the row is readable on its own, whatever becomes of the alert.
            $table->unsignedBigInteger('duress_alert_id');
            $table->string('tenant_id');
            $table->string('audience', 16)->default('household');
            $table->unsignedBigInteger('household_emergency_contact_id')->nullable();
            $table->string('label', 120);
            // MASKED: "an***@gm***.com".
            $table->string('address', 190);
            // sent | failed | no_address
            $table->string('status', 16);
            // A CODE, never the mail server's prose.
            $table->string('error', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->timestamp('attempted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['duress_alert_id', 'audience'], 'alert_email_dispatches_alert_index');
            $table->index(['tenant_id', 'attempted_at'], 'alert_email_dispatches_site_index');
        });

        DB::unprepared("
            CREATE TRIGGER alert_email_dispatches_no_update BEFORE UPDATE ON alert_email_dispatches
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'alert_email_dispatches is append-only: it is the record of who was told about an emergency'
        ");

        DB::unprepared("
            CREATE TRIGGER alert_email_dispatches_no_delete BEFORE DELETE ON alert_email_dispatches
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'alert_email_dispatches is append-only'
        ");

        if (! Schema::hasColumn('duress_alerts', 'household_email_recipients')) {
            Schema::table('duress_alerts', function (Blueprint $table): void {
                $table->unsignedSmallInteger('household_email_recipients')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('duress_alerts', 'household_email_recipients')) {
            Schema::table('duress_alerts', function (Blueprint $table): void {
                $table->dropColumn('household_email_recipients');
            });
        }

        DB::unprepared('DROP TRIGGER IF EXISTS alert_email_dispatches_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS alert_email_dispatches_no_delete');

        Schema::dropIfExists('alert_email_dispatches');
    }
};
