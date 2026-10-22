<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHO A PANIC WAS HANDED TO, BY WHICH CHANNEL, AND HOW FAST (M10 §1.3).
 *
 * "Measure and record delivery time to each destination." The platform recorded ONE
 * figure, `duress_alerts.fanout_ms`: how long the whole fan-out took. That cannot
 * answer the question §10 asks — "confirm every destination in §1.2 received it, with
 * the measured time to each" — because a single number cannot say that the guards were
 * pushed at 9 ms while the Head of Security's mail went at 140 ms, or that the estate's
 * pop-up never went at all because no live channel is configured.
 *
 * duress_alert_deliveries   ONE ROW PER DESTINATION PER CHANNEL, per alert. The seven
 *                           destinations are §1.2's seven, in its own words
 *                           (`DuressAlertDelivery::DESTINATIONS`); the channel is how
 *                           that leg went — push, broadcast, email, sms.
 *
 *                           `elapsed_ms` IS A HAND-OFF, NEVER AN ARRIVAL. It is measured
 *                           on the fan-out's own monotonic clock (`hrtime`), from the
 *                           moment the fan-out starts to the moment that leg was HANDED
 *                           OFF — to the push queue, the mail queue, the socket server,
 *                           the SMS job. Expo, the carrier, the mail relay and the
 *                           handset are all downstream, none of them reports back
 *                           synchronously, and a column that let a screen say
 *                           "delivered in 212 ms" would be the same class of false
 *                           statement as `alerted_handsets` counting one officer twice.
 *                           Every label that shows it says "handed off in".
 *
 *                           `outcome` is one of five plain words — `queued`, `sent`,
 *                           `none`, `failed`, `skipped` — and `note` is the sentence
 *                           that says why, because "skipped" on its own is exactly the
 *                           kind of word that gets read as "fine".
 *
 *                           `tenant_id` IS ON THE ROW although the alert carries it, for
 *                           `alert_sms_dispatches`' reason: a row that can be scoped to
 *                           an estate on its own is a row no future report has to join
 *                           its way into scoping.
 *
 *                           APPEND-ONLY, WITH BOTH LOCKS. It is the record of who was
 *                           told that somebody pressed a panic button and how quickly,
 *                           and a record that can be edited afterwards is not a record.
 *                           The withheld grant (`GrantedTables::CENTRAL_APPEND_ONLY`) and
 *                           the two triggers below. Written in ONE insert per alert and
 *                           never read with a row lock — `SELECT … FOR UPDATE` needs the
 *                           very UPDATE privilege this table withholds.
 *
 *                           No foreign key, for `alert_sms_dispatches`' reason: a
 *                           cascade would meet the BEFORE DELETE trigger and fail, and an
 *                           alert nobody may delete needs no cascade.
 *
 * duress_alerts.is_drill    A DRILL IS A REAL ALERT THAT EVERYBODY IS TOLD IS A DRILL.
 *                           `panic:walk` raises one to prove every destination is
 *                           reached; it travels the resident's path exactly, and so it
 *                           must say DRILL wherever it lands — the dispatcher's queue,
 *                           the estate's pop-up, the mail subject, the push title — and
 *                           it must never move a response-time figure. It is NOT
 *                           `is_simulated`: a simulated alert is sent to nobody on
 *                           purpose, and a drill that reached nobody would prove nothing.
 *                           Default false, so every alert already in the table stays
 *                           exactly what it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duress_alert_deliveries', function (Blueprint $table) {
            $table->id();

            // No FK — see the class note.
            $table->unsignedBigInteger('duress_alert_id');
            $table->string('tenant_id');

            // guards_on_shift | dispatcher_console | director_dashboard |
            // gemini_head_of_security | estate_head_of_security | estate_console |
            // emergency_contacts — §1.2's seven, `DuressAlertDelivery::DESTINATIONS`.
            $table->string('destination', 32);

            // push | broadcast | email | sms
            $table->string('channel', 16);

            // Handsets for a push, people for a mail, numbers for a text, channels for a
            // broadcast. Never a count of people WATCHING — the platform cannot know that.
            $table->unsignedInteger('recipients')->default(0);

            // queued | sent | none | failed | skipped
            $table->string('outcome', 16);

            // From the fan-out's hrtime start to this leg's HAND-OFF. Never arrival.
            $table->unsignedInteger('elapsed_ms');

            // Why, in a sentence. Never a name, a number or an address.
            $table->string('note', 240)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['duress_alert_id'], 'duress_alert_deliveries_alert_index');
            $table->index(['tenant_id', 'created_at'], 'duress_alert_deliveries_site_index');
        });

        DB::unprepared("
            CREATE TRIGGER duress_alert_deliveries_no_update BEFORE UPDATE ON duress_alert_deliveries
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'duress_alert_deliveries is append-only: it is the record of who was told about an emergency and how fast'
        ");

        DB::unprepared("
            CREATE TRIGGER duress_alert_deliveries_no_delete BEFORE DELETE ON duress_alert_deliveries
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'duress_alert_deliveries is append-only'
        ");

        if (! Schema::hasColumn('duress_alerts', 'is_drill')) {
            Schema::table('duress_alerts', function (Blueprint $table) {
                $table->boolean('is_drill')->default(false)->after('is_simulated');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('duress_alerts', 'is_drill')) {
            Schema::table('duress_alerts', function (Blueprint $table) {
                $table->dropColumn('is_drill');
            });
        }

        DB::unprepared('DROP TRIGGER IF EXISTS duress_alert_deliveries_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS duress_alert_deliveries_no_delete');

        Schema::dropIfExists('duress_alert_deliveries');
    }
};
