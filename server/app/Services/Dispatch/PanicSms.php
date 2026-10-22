<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Jobs\Alerts\SendPanicSms;
use App\Models\DuressAlert;
use App\Services\Platform\OperatorEstates;
use App\Support\CentralQueue;
use App\Support\SiteTime;
use Throwable;

/**
 * A PANIC ALSO GOES OUT AS A TEXT — Q-M09, the client's own ruling.
 *
 * "Additionally the SMS must be sent to: President (1-876-508-8082), Property Office
 * (1-876-224-4444), PPV1 Head of Security (1-876-845-9422) and Gemini Dispatch
 * (876-354-2119)."
 *
 * IT IS THE FOURTH THING THAT HAPPENS, NEVER THE FIRST, AND NOTHING WAITS FOR IT.
 * ==============================================================================
 * The three paths that already existed are untouched and none of them can be delayed
 * or failed by this one:
 *
 *   the console      the committed `duress_alerts` row and the `AlertRaised`
 *                    broadcast, both of which happen in `Dispatch\AlertIntake` before
 *                    anything here is reached;
 *   the handsets     `Push\PanicFanOut`'s ladder — on duty, then rostered today, then
 *                    the escalation contact — which runs to completion and writes
 *                    `alerted_handsets` BEFORE this is called;
 *   the ladder       `Dispatch\SiteEscalation`, unchanged.
 *
 * This is called last, inside its own rescue, and all it does synchronously is count
 * the site's numbers and write a queued job. A provider that is slow, broken, unpaid or
 * absent cannot reach any of the three above, because none of them is downstream of it.
 *
 * WHAT THE TEXT SAYS: WHERE, AND WHAT. Nothing else.
 * =================================================
 * A text is read on a lock screen by whoever is holding the phone — in a car, in a
 * meeting, in somebody else's hand. So the message carries the estate, the unit, the
 * kind of emergency, the site-local time and the alert number, and it carries:
 *
 *   NO MONEY          nothing on this platform texts an amount, to anybody;
 *   NO BALLOT         nothing of an election is ever repeated anywhere;
 *   NO NAME. `PushAnnouncer` already rules that a guard is told WHERE a panic is and
 *            never who pressed it, and a text is the weaker channel, not the stronger
 *            one. The responder drives to a unit, not to a name; naming the resident
 *            would add nothing operational while putting "Andrea Fletcher needs help"
 *            on the lock screen of four phones, at least one of which is a personal
 *            mobile. The unit reference IS the answer to "where", and the console — a
 *            screen behind a sign-in — is where a name belongs.
 *
 * NOT FROM THE DEMONSTRATION ESTATE, and not from a simulated alert. `PanicFanOut`
 * already refuses to mail a real Head of Security about a resident who does not exist
 * (M5 §3); texting the client's President at 3 AM because an app-store reviewer pressed
 * a button would be the same mistake with a louder alarm on it. The alert is still
 * recorded, still on the console, and the platform still reports honestly that it
 * texted nobody.
 */
final class PanicSms
{
    /**
     * One GSM-7 segment. Longer messages split, cost more and arrive out of order on a
     * bad network, and the tail of this one is the part nobody needs.
     */
    public const MAX_LENGTH = 160;

    public function __construct(private readonly SiteEmergencyNumbers $numbers) {}

    /**
     * Queue the texts for this alert and write down how many numbers there were.
     *
     * @return int how many numbers the site had on file — NOT how many were delivered
     */
    public function raise(DuressAlert $alert): int
    {
        try {
            // A drill texts none of the site's numbers either (M69 SEC-2; M73 §0.2): it wakes nobody outside its officers.
            if ($alert->is_simulated || $alert->is_drill || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
                // Nothing to wait for, so both numbers are final: no text was attempted
                // and none was delivered. Said as a 0, never left as a null the app would
                // poll for ever.
                $this->write($alert, 0, 0);

                return 0;
            }

            $count = $this->numbers->forSite((string) $alert->tenant_id)->count();

            if ($count === 0) {
                $this->write($alert, 0, 0);

                return 0;
            }

            $this->write($alert, $count, null);

            /*
             * PINNED TO THE CENTRAL QUEUE, and put on the queue every worker reads FIRST.
             *
             * Pinned because this runs inside a `/api/v1/duress` request, where tenancy has
             * made the ESTATE's database the default and an estate database has no `jobs`
             * table (`Support\CentralQueue`) — left to the default, the texts would be
             * written nowhere, throw, be rescued below, and nobody would be told that
             * nobody was told. Urgent because a panic must not queue behind an estate-wide
             * notice's twenty jobs of network, exactly as `PushNotifier` argues.
             *
             * THE JOB CARRIES AN ID AND NOTHING ELSE. `SendExpoPush` keeps delivery
             * addresses out of a failed job's stored payload for the same reason; four
             * private phone numbers sitting in `failed_jobs` would be worse.
             */
            $job = CentralQueue::pin(new SendPanicSms((int) $alert->id));
            $urgentQueue = config('push.urgent_queue');

            if (is_string($urgentQueue) && $urgentQueue !== '') {
                $job->onQueue($urgentQueue);
            }

            dispatch($job);

            return $count;
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return 0;
        }
    }

    /**
     * WHERE and WHAT, in one segment, in the site's own clock.
     *
     * Built from the most important fact outwards, because a lock screen truncates the
     * tail: what happened, where, when, which alert, and then the two courtesies. If an
     * estate's name is long enough to push the whole message past one segment, the NAME
     * is shortened — never the unit, never the time, never the alert number.
     */
    public static function body(DuressAlert $alert): string
    {
        $kind = match ((string) $alert->kind) {
            'panic' => 'PANIC ALARM',
            'duress' => 'OFFICER DURESS',
            'medical' => 'MEDICAL EMERGENCY',
            'fire' => 'FIRE',
            'intrusion' => 'INTRUSION',
            default => 'EMERGENCY ALERT',
        };

        // A DRILL SAYS SO FIRST (M10 §1.3): `panic:walk --force` texts real people, and
        // "PANIC ALARM" at 3 AM with no word of a drill in it would be a real alarm.
        $kind = ($alert->is_drill ? 'DRILL ' : '').$kind;

        $estate = trim((string) ($alert->estate->name ?? $alert->tenant_id));
        $unit = trim((string) ($alert->unit_reference ?? ''));

        // THE ONE CLOCK: a full instant stored in UTC, read out in the SITE's zone by
        // its IANA name. A control room in Kingston must never be texted a time in UTC.
        // "9:38 AM JA, 27 Sep": a text has no room for the clock's name, and no banner either (M19A §8.7).
        $when = SiteTime::tagged($alert->server_time, (string) $alert->tenant_id).', '.SiteTime::local($alert->server_time, (string) $alert->tenant_id)->format('j M');

        $tail = ' The control room has it. Do not reply to this text.';
        $head = 'GeminiSecure: '.$kind.' at ';
        $rest = ($unit === '' ? '' : ', '.$unit).'. '.$when.'. Alert #'.$alert->id.'.';

        $room = self::MAX_LENGTH - strlen($head) - strlen($rest) - strlen($tail);

        if ($room < strlen($estate)) {
            // Never below something a person can still recognise; a message one segment
            // over is a smaller problem than a message that does not say where.
            $estate = rtrim(substr($estate, 0, max(12, $room)));
        }

        return $head.$estate.$rest.$tail;
    }

    /**
     * THE ESTATE'S HEAD OF SECURITY, TOLD NOBODY HAS ANSWERED (the guard ring §3.4, G14).
     *
     * Sent by round 1 of the ring — at 60 seconds, or at once when the raise reached
     * nobody — AFTER that round's rings have gone, so the number of phones it names is the
     * real one. It is NOT a repeat of `body()`: at the raise the same person was already
     * texted "PANIC ALARM at…", and this carries the new facts they can act on — that
     * nobody has answered, or that nobody was on duty, and how many phones are now being
     * rung. It says it is a follow-up.
     *
     * The same rules as `body()`: where and what, never who pressed it, DRILL first on a
     * drill, one GSM-7 segment — plain ASCII, because a single character outside GSM-7
     * turns the whole text into a 70-character UCS-2 message — and when the estate's name
     * will not fit, the NAME is shortened, never the lot, the facts or the alert number.
     *
     * `$reason` is the round's own (`DuressAlertRingRound`): nobody_on_duty, no_handset,
     * declined or unanswered.
     */
    public static function unansweredBody(DuressAlert $alert, string $reason, int $phones): string
    {
        $kind = match ((string) $alert->kind) {
            'panic' => 'PANIC ALARM',
            'duress' => 'OFFICER DURESS',
            'medical' => 'MEDICAL EMERGENCY',
            'fire' => 'FIRE',
            'intrusion' => 'INTRUSION',
            default => 'EMERGENCY ALERT',
        };

        $lead = match ($reason) {
            'nobody_on_duty' => 'FOLLOW-UP, NOBODY ON DUTY',
            'no_handset' => 'FOLLOW-UP, NO OFFICER PHONE REACHED',
            'declined' => 'FOLLOW-UP, NO OFFICER ON DUTY CAN RESPOND',
            default => 'UNANSWERED '.max(1, (int) config('ring.escalate_after', 60)).'s',
        };

        $estate = trim((string) ($alert->estate->name ?? $alert->tenant_id));
        $unit = trim((string) ($alert->unit_reference ?? ''));

        $head = 'GeminiSecure '.($alert->is_drill ? 'DRILL ' : '').$lead.': '.$kind.' at '.($unit === '' ? '' : $unit.', ');
        $rest = '. Alert #'.$alert->id.'. ';
        $tail = $phones > 0
            ? 'Every officer of the estate is being rung: '.$phones.' phone'.($phones === 1 ? '' : 's').'.'
            : 'No officer\'s phone could be reached. Send someone.';

        $room = self::MAX_LENGTH - strlen($head) - strlen($rest) - strlen($tail);

        if ($room < strlen($estate)) {
            $estate = rtrim(substr($estate, 0, max(12, $room)));
        }

        return $head.$estate.$rest.$tail;
    }

    /**
     * Write the two counts onto the alert, quietly.
     *
     * `saveQuietly` for `PanicFanOut::record()`'s reason: this runs inside the alert's
     * own `created` announcement, and a save that fired `updated` would send the model
     * back round `PushServiceProvider` on the path of a panic. The in-memory model is
     * updated too, so the handset that raised the alert is answered with the truth in
     * the same response rather than having to poll for it.
     */
    private function write(DuressAlert $alert, int $recipients, ?int $delivered): void
    {
        rescue(static fn () => $alert->forceFill([
            'sms_recipients' => $recipients,
            'sms_delivered' => $delivered,
        ])->saveQuietly(), report: false);
    }
}
