<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\Shift;
use App\Notifications\PanicRaisedAtSite;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\HeadsOfSecuritySms;
use App\Services\Dispatch\HouseholdContactsMail;
use App\Services\Dispatch\HouseholdContactsSms;
use App\Services\Dispatch\PanicSms;
use App\Services\Dispatch\SiteEscalation;
use App\Services\Platform\OperatorEstates;
use App\Services\Ring\GuardRing;
use App\Services\Ring\RingTargets;
use App\Support\CentralQueue;
use App\Support\SiteTime;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * WHO A PANIC REACHES WHEN NOBODY IS CLOCKED ON (M6 §2.1).
 *
 * THE CONSOLE IS NOT IN THIS FILE, AND THAT IS THE FIRST RULE.
 * ===========================================================
 * "The control room console always receives a panic, regardless of who is on shift.
 * That path already works and must never depend on the fallback logic below."
 *
 * It does not. The control room gets an alert from the ROW — `AlertIntake::record()`
 * writes `duress_alerts`, and `PushServiceProvider::announceAlert()` broadcasts
 * `AlertRaised` before any of this is reached (M10 §1.2; until then it went out after),
 * and the dispatch screens poll the same table when Reverb is down. Nothing here is
 * called until the alert is already committed, nothing here can fail the insert, and
 * every method below is wrapped so that a thrown exception becomes a reported one.
 * `PanicFallbackTest` proves the console still gets an alert at an estate with no
 * guard, no roster and no escalation contact at all.
 *
 * THE LADDER
 * ==========
 *   1. ON DUTY       every guard clocked on at that site now (`toGuardsOnDuty`).
 *                    Unchanged (M6 §2.1 part 2).
 *   2. ROSTERED      the estate's rostered officers FOR THAT DAY — every guard on a
 *                    shift whose rostered window touches the site's today, whether or
 *                    not they clocked in. An officer who is due at that gate today is
 *                    the closest person there is to somebody on duty.
 *   3. ESCALATION    the site's escalation contact (`SiteEscalation`). Console users,
 *                    not handsets: a mail and the console's own bell, and NEVER
 *                    counted as a handset in what the resident is told.
 *
 * AND ALONGSIDE ALL THREE, NEVER INSTEAD OF ANY OF THEM: the site's own emergency
 * numbers are TEXTED (Q-M09 — "the SMS must be sent to: President, Property Office,
 * PPV1 Head of Security and Gemini Dispatch"). That is `Dispatch\PanicSms`, it happens
 * after the ladder has finished and its columns are written, it is queued, and it is
 * wrapped twice. A text is not a handset and is never counted as one.
 *
 * WHEN IT DESCENDS. A tier hands over when it reached NO HANDSET — not merely when
 * the roster is empty. The ruling says "if no guard has an active shift", and the
 * commonest way that turns into silence is exactly that; but a guard who IS clocked
 * on whose handset was never registered, was signed out, or was revoked produces the
 * identical outcome for the resident, and stopping at tier 1 there would leave a
 * panic reaching nobody while somebody reachable stood one tier down. So the test is
 * the outcome, not the roster. An officer woken by mistake is a far smaller cost than
 * a resident with no answer.
 *
 * "THAT DAY" IS A DAY ON THE ESTATE'S CALENDAR, never the server's. It goes through
 * `SiteTime::dayBounds(null, $tenantId)`, so a site in Kingston and a site in a DST
 * zone each get their own midnight and nothing in here knows a number of hours.
 *
 * WHAT IS WRITTEN DOWN. The outcome is recorded on the alert — `alerted_handsets`,
 * `alerted_via`, `escalated_contacts` — because the RESIDENT has to be told the truth
 * (M6 §2.1 part 5). A panic screen that says "help is coming" when no phone rang is
 * the worst thing this product could do, and the only way to avoid it is for the
 * server to count honestly and say the number out loud.
 *
 * A HANDSET MEANS A PUSH QUEUED, AND THE WORDING NEVER CLAIMS MORE. Expo, the
 * carrier and the phone are all downstream of this; the server knows how many live,
 * entitled addresses it sent to, and that is all `alerted_handsets` ever asserts.
 */
final class PanicFanOut
{
    /** Guards clocked on at the site right now. */
    public const ON_DUTY = 'on_duty';

    /** Nobody clocked on: the officers rostered at the site for the site's today. */
    public const ROSTERED = 'rostered';

    /** No handset at all: the site's escalation contact was told, off-app. */
    public const ESCALATION = 'escalation';

    /** Nothing was reached. The resident is told so, plainly. */
    public const NOBODY = 'none';

    public function __construct(
        private readonly PushNotifier $push,
        private readonly SiteEscalation $escalation,
        private readonly PanicSms $sms,

        /*
         * EACH LEG'S HAND-OFF TIME (M10 §1.3), against the same clock as
         * `fanout_ms`. In memory while the legs go, one insert after they have
         * all gone, and it never throws — see `PanicDeliveries`.
         */
        private readonly PanicDeliveries $deliveries,
    ) {}

    /**
     * Fan the alert out and write down what it actually reached.
     *
     * @return array{handsets: int, via: string, escalated: int}
     */
    public function forAlert(DuressAlert $alert, PushMessage $message): array
    {
        $tenantId = (string) $alert->tenant_id;

        /*
         * THE CLOCK STARTS HERE (M10 §1). `hrtime` rather than microtime: it is
         * monotonic, so a clock adjustment mid-panic cannot produce a negative
         * figure or a fictional one.
         */
        $startedAt = hrtime(true);
        $this->deliveries->start($alert, $startedAt);

        // The guard who raised it is never pushed their own alert — a guard under
        // SILENT duress least of all (`PushAnnouncer::alertRaised`).
        $except = $alert->guard_id === null ? null : (int) $alert->guard_id;

        /*
         * EVERY GUARD ON SHIFT, AND THEN EVERYONE ELSE ANYWAY - M10 section 1.
         *
         * THIS USED TO BE A LADDER and the client has ruled it out. Each rung ran
         * only when the one above reached ZERO handsets: on-duty guards, else
         * guards rostered today, else an e-mail to the site escalation contact.
         * Read as a routing rule that is defensible - do not wake the off-shift
         * officer when somebody is already at the gate. Read as what a panic
         * needs, it is wrong, and the client's own reasoning is why:
         *
         *   "A panic alert doesn't have to have anything to do with security.
         *    Maybe someone has a medical problem. Security are not supposed to
         *    put out fires and they're not supposed to deal with medical
         *    services... the property office should be able to take action."
         *
         * A guard at the gate does not make the property office's knowing
         * unnecessary. So the rungs become parallel legs: the on-duty push
         * happens, the rostered-today push happens, and the escalation contact is
         * told - none of them waiting on another having failed.
         *
         * `$via` KEEPS ITS OLD MEANING so nothing downstream has to change: it
         * still names the strongest thing this alert reached, which is what the
         * resident's screen and the audit trail are built on. What changed is
         * that the weaker legs are no longer SKIPPED when a stronger one worked.
         *
         * A guard on a shift today appears on both lists; `PushNotifier`
         * de-duplicates by token, so nobody is pushed twice.
         */
        $onDuty = $this->push->onDutyGuardIds($tenantId, $except);
        $rostered = $this->rosteredToday($tenantId, $except);

        /*
         * UNIONED, AND PUSHED ONCE. The two sets overlap heavily — a guard
         * clocked on is almost always also rostered for today — and two separate
         * calls would dispatch two jobs: the same handset buzzing twice for one
         * panic, and `alerted_handsets` counting it twice.
         *
         * That number is what the RESIDENT'S OWN SCREEN shows them. "2 officers
         * have been alerted" when one was is a false claim about how much help is
         * coming, to somebody who has just pressed a panic button.
         */
        $officers = array_values(array_unique([...$onDuty, ...$rostered]));

        $handsets = $this->push->toGuards($tenantId, $officers, $message);

        $this->deliveries->pushed($alert, count($officers), $handsets);

        /*
         * ALWAYS, not only when nobody was reached. The escalation contact is the
         * site's own responsible person, and "a guard is already on it" is
         * exactly the thing they are entitled to be told rather than kept from.
         * What they are told carries the real numbers — the handsets pushed AND
         * the officers on the list — so the mail cannot claim nobody was on duty
         * while two officers walk to the lot, or while an officer whose phone
         * could not be pushed stands at the gate.
         */
        $escalated = $this->tellTheEscalationContact($alert, $handsets, count($officers));

        /*
         * DERIVED FROM THE OUTCOME, not from position in a ladder — which is the
         * ladder's one genuinely useful property, kept without the gating.
         *
         * ON_DUTY means a handset was reached AND somebody was clocked on.
         * ROSTERED means a handset was reached but only off the day's roster.
         * ESCALATION means no handset at all, and somebody was told off-app.
         * NOBODY still means nobody — which is what the resident is told, and
         * what `DuressController::shape()` and the phones are built on.
         */
        $via = match (true) {
            $handsets > 0 && $onDuty !== [] => self::ON_DUTY,
            $handsets > 0 => self::ROSTERED,
            $escalated > 0 => self::ESCALATION,
            default => self::NOBODY,
        };

        /*
         * THE CLOCK STOPS BEFORE THE WRITE, not after it. What is being measured
         * is how long the platform took to TELL people; the update that records
         * the figure is bookkeeping, and including it would make the number
         * partly a measurement of itself.
         */
        $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $this->record($alert, $handsets, $via, $escalated, $elapsedMs, count($onDuty));

        /*
         * AND THE SITE'S FOUR NUMBERS ARE TEXTED — Q-M09, and the LAST thing that
         * happens here.
         *
         * Deliberately after `record()` and deliberately alongside, never instead of.
         * The ladder above has already run to completion and the three columns it
         * writes are already saved, so nothing about the console, the push or the
         * escalation is downstream of a text message. `PanicSms::raise()` only counts
         * the site's numbers and queues a job; it has its own rescue, and this one is
         * belt and braces for the same reason every method in this class has one.
         *
         * IT IS NEVER COUNTED AS A HANDSET. `handsets` is untouched by it and the
         * return value is unchanged, because a text to a committee member's phone is
         * not an officer being sent — and the resident's screen is built on that number.
         */
        $texted = rescue(fn () => $this->sms->raise($alert), 0, report: false);
        $this->deliveries->texted($alert, (int) $texted);

        /*
         * AND THE HOUSEHOLD'S OWN EMERGENCY CONTACTS — M10 §1.2 item 7, a leg of its
         * own and not a fifth site number (`Dispatch\HouseholdContactsSms`).
         *
         * Alongside the site's texts and gated behind nothing: it does not wait for
         * them, for a handset, for the escalation contact or for a dispatcher, and it
         * queues its own job on the panic queue rather than riding in theirs. A
         * resident's panic only — an officer's duress texts no household, and the
         * leg refuses one itself. Rescued twice, like everything else in this class,
         * and never counted as a handset.
         *
         * STAMPED AS §1.2's `emergency_contacts`, by SMS, with what `raise()` answered:
         * how many contacts were queued, or NULL when the leg failed. The recorder
         * works out the outcome — none, skipped, queued, failed — after every leg has
         * gone, never here in front of one.
         */
        $household = rescue(static fn () => app(HouseholdContactsSms::class)->raise($alert), null, report: false);
        $this->deliveries->householdTexted($alert, $household === null ? null : (int) $household);

        /*
         * AND THE SAME CONTACTS BY EMAIL (M12 §2.5): the texts need a provider nobody has bought; an email goes today.
         * Its own leg, its own jobs (one per contact) on the panic queue, gated behind nothing and never a handset.
         */
        rescue(static fn () => app(HouseholdContactsMail::class)->raise($alert), null, report: false);

        /*
         * AND BOTH HEADS OF SECURITY BY TEXT — M10 §1.2 items 4 and 5, the SMS half of
         * the escalation mail above, to the phone each gave on §1.4's page
         * (`Dispatch\HeadsOfSecuritySms`). Every alert, a guard's duress included;
         * gated behind nothing; its own job on the panic queue; a number already on
         * the site's list is texted once. The mail's own routing is not touched.
         *
         * STAMPED as `gemini_head_of_security` and `estate_head_of_security` by SMS,
         * with the plan `raise()` answered — or NULL when the leg failed.
         */
        $heads = rescue(static fn () => app(HeadsOfSecuritySms::class)->raise($alert), null, report: false);
        $this->deliveries->headsTexted($alert, is_array($heads) ? $heads : null);

        /*
         * AND THE RING STARTS — the guard ring, and the LAST thing here, after every leg above
         * has been handed off: the push, the mail, all three texts. Today's push is not changed
         * by it in any way. `begin()` makes no network call and never sleeps — a header row,
         * the rounds, the officers just pushed, and two queued jobs — so what it adds to this
         * request is a few inserts, and a failure in it is reported and costs nothing above.
         * Resolved here rather than injected, so the ring can ask this class who is rostered.
         */
        rescue(static fn () => app(GuardRing::class)->begin($alert, $officers, $handsets), null, report: true);

        /*
         * EVERY LEG HAS GONE. A stamp only: `PushServiceProvider::announceAlert()`
         * writes the per-leg record next, in one insert, never in front of anybody
         * being told, and then broadcasts what this reached (`AlertReachKnown`).
         */
        $this->deliveries->fanOutFinished($alert);

        return ['handsets' => $handsets, 'via' => $via, 'escalated' => $escalated];
    }

    /**
     * Every guard the estate has rostered for the site's TODAY.
     *
     * Overlapping, not starting: a night shift that began at 7 PM yesterday and runs
     * to 7 AM covers the estate right now, and asking only for shifts that START
     * today would leave exactly that officer out at exactly the hour they matter.
     *
     * A simulated shift is skipped for the same reason a simulated alert pushes
     * nobody: a real officer's phone must not ring for a roster nobody wrote.
     *
     * @return list<int>
     */
    public function rosteredToday(string $tenantId, ?int $exceptGuardId = null): array
    {
        try {
            [$dayStart, $dayEnd] = SiteTime::dayBounds(null, $tenantId);

            return Shift::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('guard_id')
                ->where('is_simulated', false)
                ->where('rostered_start', '<=', $dayEnd)
                ->where('rostered_end', '>=', $dayStart)
                ->when($exceptGuardId !== null, static fn ($q) => $q->where('guard_id', '!=', $exceptGuardId))
                ->pluck('guard_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return [];
        }
    }

    /**
     * EVERY OFFICER THIS ALERT'S FAN-OUT RINGS, without ringing anybody (M10 §1.3).
     *
     * The same two lists `forAlert()` unions and pushes — the guards clocked on at the site
     * and the officers rostered there for the site's today — minus the guard who raised it.
     * `AlertProgress` asks it twice: whom a dispatcher may SEND (the console's pick list),
     * and which guard may say "en route" from their own handset when nobody was sent.
     *
     * THE SAME SET ON PURPOSE. A dispatcher who could pick an officer the panic never
     * reached would be sending somebody who has not been told there is anything to go to;
     * and a guard who WAS pushed the panic and ran must be able to say so, because nothing
     * in a panic waits on the dispatcher (M10 §1.3). One answer to "who can help", in the
     * one file that already decides it.
     *
     * @return list<int>
     */
    public function guardsFor(DuressAlert $alert): array
    {
        $tenantId = (string) $alert->tenant_id;
        $except = $alert->guard_id === null ? null : (int) $alert->guard_id;

        /*
         * AND EVERY OFFICER THE RING HAS RUNG (the guard ring §3.3). The escalation rings every
         * active officer of the estate, off duty too; one who hears it and runs must be able
         * to open the alert and say so, and a dispatcher must be able to send them. The set
         * is still exactly who was rung — it includes the rings now. Never the raiser.
         */
        return array_values(array_diff(array_unique([
            ...$this->push->onDutyGuardIds($tenantId, $except),
            ...$this->rosteredToday($tenantId, $except),
            ...RingTargets::guardIdsFor($alert),
        ]), $except === null ? [] : [$except]));
    }

    /**
     * Tell the site's escalation contact that a panic reached no handset.
     *
     * NOT FROM THE DEMONSTRATION ESTATE (M5 §3), for `SupervisorOverride::report`'s
     * reason: a reviewer walking the panic screen would otherwise put a mail headed
     * "A panic alert reached no handset" in front of every Head of Security on the
     * platform, about a resident who does not exist. The alert is still recorded,
     * still on the control room's screen, and the reviewer is still told honestly
     * that no handset was reached — which is the true answer on that estate.
     *
     * @return int how many people were told
     */
    private function tellTheEscalationContact(DuressAlert $alert, int $handsets, int $officers): int
    {
        try {
            if ($alert->is_simulated || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
                $this->deliveries->notMailed($alert, DuressAlertDelivery::SKIPPED, 'Demonstration estate or simulated alert: nobody real is mailed about it (M5 §3).');

                return 0;
            }

            // A DRILL WAKES NOBODY OUTSIDE THE OFFICERS IT PROVES (M69 SEC-2; M73 §0.2): it mailed every Head of Security
            // and the committee, "DRILL" in the subject, about a test. Its officers are still told, labelled DRILL.
            if ($alert->is_drill) {
                $this->deliveries->notMailed($alert, DuressAlertDelivery::SKIPPED, 'A drill: its officers are told, and nobody outside them is mailed about it.');

                return 0;
            }

            /*
             * EVERYONE, not whichever of the two existed. `contactsFor()` chose
             * the Head of Security and fell back to the committee only when
             * there was none — a second gate hiding inside the leg M10 §1
             * un-gates, producing exactly what the client objected to: a panic,
             * a security officer told, and the property office that would have
             * to handle a fire or a medical emergency never hearing about it.
             *
             * AND ONLY AT A PROVEN ADDRESS (M10 §1.4). "An unverified address is
             * never used for alerting." `alertRecipientsFor()` sets aside everybody
             * whose `alertEmail()` is null, BEFORE the send, so that `$contacts` is
             * exactly the people mailed: the number returned below becomes
             * `escalated_contacts`, which the resident's own screen is built on,
             * and counting somebody whose mail was never going to be sent would be
             * telling a person in a panic that more people know than do.
             *
             * The unproven are not dropped silently either. They go to the
             * recorder beside the people mailed, and become a row that says how
             * many could not be emailed and why — which is what `panic:walk` and
             * the dispatcher's alert screen print.
             */
            ['mail' => $contacts, 'unverified' => $unverified] = $this->escalation->alertRecipientsFor((string) $alert->tenant_id);

            if ($contacts->isEmpty()) {
                $this->deliveries->mailed($alert, $contacts, $unverified);

                return 0;
            }

            /*
             * PINNED TO THE CENTRAL QUEUE, and this is not a detail (`Support\CentralQueue`).
             * This runs inside a `/api/v1/duress` request, where tenancy has made the ESTATE's
             * database the default — which has no `jobs` table. Left to the default, the last
             * tier of a panic would be written nowhere, throw, be rescued two lines below, and
             * nobody would ever be told that nobody was told.
             *
             * ADDRESSED BY `AlertMailChannel`, which is where `PanicRaisedAtSite` sends: each
             * person's proven `alertEmail()`, one mail each, read again when the worker sends
             * it. Not `Notification::route()` here — the rule belongs to the alert, so no
             * other caller of it can reach a sign-in address either.
             */
            Notification::send($contacts, CentralQueue::pin(new PanicRaisedAtSite(
                alertId: (int) $alert->id,
                kind: (string) $alert->kind,
                estate: (string) ($alert->estate->name ?? $alert->tenant_id),
                where: $alert->unit_reference === null || $alert->unit_reference === '' ? null : (string) $alert->unit_reference,
                raisedAt: SiteTime::named($alert->server_time, (string) $alert->tenant_id, 'D, M j, Y g:i A'),

                /*
                 * WHAT IT ACTUALLY REACHED, so the mail cannot lie. Under the old
                 * ladder this only ever went out when nothing had been reached, so
                 * it could say "NOBODY ON DUTY" as a fact. It now goes out on every
                 * panic, and telling a Head of Security nobody was on duty while
                 * two officers walk to the lot is exactly the kind of false claim
                 * this change set exists to remove.
                 *
                 * THE OFFICERS AS WELL AS THE HANDSETS. No handset pushed is not
                 * nobody on duty: an officer's phone may have no push address, push
                 * may be switched off, and a lone officer's own duress excludes the
                 * one officer there. The officer count is the list the push went to
                 * (`PanicDeliveries::pushLeg()` reads the same two numbers), and a
                 * duress says its raiser is on post.
                 */
                handsets: $handsets,

                // A drill says so in the subject (M10 §1.3): a real Head of Security reads it.
                isDrill: (bool) $alert->is_drill,

                officers: $officers,
                raisedByOfficer: $alert->guard_id !== null,

                // So each reader's button opens the alert in THEIR console (bug hunt B093).
                tenantId: (string) $alert->tenant_id,
            )));

            $this->deliveries->mailed($alert, $contacts, $unverified);

            return $contacts->count();
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
            $this->deliveries->notMailed($alert, DuressAlertDelivery::FAILED, 'The alert mail could not be queued; the error was reported.');

            return 0;
        }
    }

    /**
     * Write the outcome onto the alert, once.
     *
     * QUIETLY. This runs inside the alert's own `created` announcement, and a save
     * that fired `updated` would send the model back round `PushServiceProvider` on
     * the path of a panic. Nothing downstream wants to hear that three columns were
     * filled in, and `saveQuietly` is the narrowest way to say so.
     *
     * The in-memory model is updated too, so `DuressController::store()` answers the
     * handset that raised the alert with the reach it actually got — not a null the
     * app would have to poll for.
     */
    private function record(DuressAlert $alert, int $handsets, string $via, int $escalated, ?int $elapsedMs = null, ?int $onDuty = null): void
    {
        rescue(static fn () => self::writeReach($alert, $handsets, $via, $escalated, $elapsedMs, $onDuty), report: false);

        // Then the consoles' "phones reached" and the resident's timeline move (M11 Part C, DESIGN.md §2.4 A2) —
        // on a commit of its own, as the raise's is: the count is on the alert whatever the live log does.
        AlertIntake::announce($alert, 'reach');
    }

    private static function writeReach(DuressAlert $alert, int $handsets, string $via, int $escalated, ?int $elapsedMs, ?int $onDuty): void
    {
        $alert->forceFill([
            'alerted_handsets' => $handsets,
            'alerted_via' => $via,
            'escalated_contacts' => $escalated,

            /*
             * WHO WAS CLOCKED ON AT THE RAISE (M18A §1.2). With `alerted_handsets` at 0 the resident's screen says one
             * of two different things — nobody is on duty, or officers are on duty and their phones could not be
             * reached — and this is the number that decides which. The raiser is not counted (`$except`).
             */
            'on_duty_officers' => $onDuty,

            /*
             * HOW LONG THE PLATFORM'S OWN SHARE TOOK (M10 §1), against the PRD's
             * target of under three seconds. Nothing measured anything before:
             * the only timing fact on an alert was `acknowledged_at`, which is
             * how long a HUMAN took to answer.
             *
             * It is wall time from entering the fan-out to leaving it — every
             * push queued, every mail queued, the contacts resolved. It is NOT
             * delivery to a phone and must never be quoted as if it were: Expo,
             * the carrier, the mail server and the handset are all downstream
             * and none reports back synchronously. `CheckExpoPushReceipts` is
             * what learns later whether Expo accepted a batch.
             */
            'fanout_ms' => $elapsedMs,
        ])->saveQuietly();
    }
}
