<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\DispatchMessage;
use App\Models\DuressAlert;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Ballot;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\Notice;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Services\Dispatch\ShiftAutoClose;
use App\Services\Estate\Settings;
use App\Support\SiteTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WHAT THE PLATFORM SAYS TO A CLOSED APP, AND TO WHOM (M4 §3.2).
 *
 * One method per thing that happens. Each works out the recipients, writes the
 * sentence, and hands both to `PushNotifier` — which decides whether each phone
 * wants it, whether it was told a moment ago, and queues the send.
 * `App\Providers\PushServiceProvider` calls these from the models' own events, so
 * an alert, a ticket or a notice reaches a closed app whichever screen, command or
 * API call made it.
 *
 * THE SENTENCES ARE THE APPS' OWN. Each title and body is what the phone's local
 * notifier (`packages/core/notify/guardDigest.ts`, `residentDigest.ts`) already
 * shows for the same event while the app is open — so a lock screen never says
 * more through a push than it did without one:
 *
 *   - NO MONEY. A payment is "recorded", a reminder is "about your account". Never
 *     an amount, to anybody — and nothing a guard receives is about money at all.
 *   - NO BALLOT CONTENT. An election is its title, that it opened, that results are
 *     out. Never an option, a candidate, a count, or whether this household voted.
 *   - A NAME ONLY WHERE THE APP ALREADY SHOWS ONE: the visitor at the gate. A guard
 *     is told WHERE a panic is, never who pressed it.
 *
 * SIMULATED RECORDS ARE NEVER PUSHED. The simulator exists to fill screens; a real
 * guard's phone must not ring for an alert nobody raised.
 *
 * NOTHING HERE THROWS. Every method is a courtesy on top of something that already
 * happened.
 */
class PushAnnouncer
{
    public function __construct(
        private readonly PushNotifier $push,
        private readonly PanicFanOut $fanOut,
    ) {}

    /* ------------------------------------------------------------------ */
    /* panic and duress — the highest priority */
    /* ------------------------------------------------------------------ */

    /**
     * A panic, duress, medical, fire or intrusion alert was raised: every guard ON
     * DUTY at that estate is told, except the guard who raised it.
     *
     * The raiser is never pushed their own alert — and a guard under SILENT duress
     * least of all: a phone lighting up is exactly what the silent path exists to
     * avoid (`useDuress.ts`).
     *
     * AND WHEN NOBODY IS CLOCKED ON (M6 §2.1). The sentence is written here, as every
     * sentence in this file is; WHO it goes to is `PanicFanOut` — guards on duty, then
     * the officers rostered at that site for the site's today, then the site's
     * escalation contact — and it writes onto the alert how many handsets it actually
     * reached, so the resident can be told the truth. The control room's copy of the
     * alert does not come through here at all and never did: it is the committed row
     * and the `AlertRaised` broadcast, both of which happen before this is called.
     */
    public function alertRaised(DuressAlert $alert): void
    {
        $this->safely(function () use ($alert): void {
            // Nobody real for a simulated alert, and nobody at all for a drill raised in drill mode (M53 §3A.1): this
            // is the whole fan-out — the officers' push and ring, the alert mail, the texts — so none of it starts.
            if ($alert->is_simulated || $alert->wakesNobody()) {
                return;
            }

            $where = $alert->unit_reference !== null && $alert->unit_reference !== '' ? 'At '.$alert->unit_reference.'. ' : '';

            [$title, $lead] = match ($alert->kind) {
                'panic' => ['Panic alert', $where === '' ? 'A resident pressed panic. ' : $where],
                'duress' => ['Officer duress alert', 'An officer at your site needs help. '],
                'medical' => ['Medical emergency', $where],
                'fire' => ['Fire reported', $where],
                'intrusion' => ['Intrusion reported', $where],
                default => ['Emergency alert', $where],
            };

            // A DRILL SAYS SO FIRST (M10 §1.3). It reaches real officers exactly as a
            // panic does, and a lock screen shows the title before anything else.
            $title = $alert->drillPrefix().$title;

            $this->fanOut->forAlert(
                $alert,
                new PushMessage('site_alert', $title, $lead.'Dispatch has it: respond as your orders say.', $alert->id, (string) $alert->kind),
            );
        });
    }

    /**
     * Dispatch acknowledged it: the person who raised it learns that the CONTROL ROOM HAS IT —
     * and nothing more, because nothing more is true yet (M10 §1.3).
     *
     * THIS SAID "Help is on the way" AND "someone is responding", on a bare acknowledgement. An
     * acknowledgement is a person in the control room pressing a button; nobody has been sent,
     * nobody is moving, and a resident told otherwise stops doing the things that would keep them
     * safe while they wait for an officer who is not coming yet. "Help is on the way" is first
     * said by `alertDispatched()`, when an officer actually has been sent.
     */
    public function alertAcknowledged(DuressAlert $alert): void
    {
        $this->alertUpdate($alert, 'acknowledged', 'The control room has your alert', 'Dispatch has seen it. Open the app to follow what happens next.');
    }

    /**
     * AN OFFICER WAS SENT (M10 §1.3): the first moment "help is on the way" is true.
     *
     * Two people are told. THE OFFICER SENT, at full priority on the `site_alert` type every
     * guard handset already routes and no switch can mute — kind `dispatched`, so the app can
     * tell "you are the one sent" from "there is a panic at your site". WHERE, never who: the
     * lot, or for an officer's duress the post they stand, and the estate. And THE PERSON WHO
     * RAISED IT, on their own alert's update type, which a silent duress keeps silent.
     */
    public function alertDispatched(DuressAlert $alert): void
    {
        $this->safely(function () use ($alert): void {
            $officer = $alert->getAttribute('responding_guard_id');

            // Drill mode wakes nobody (M53 §3A.1), an officer sent to it least of all; the resident's own update below still goes.
            if ($alert->is_simulated || $alert->wakesNobody() || $officer === null) {
                return;
            }

            $place = $alert->unit_reference !== null && $alert->unit_reference !== ''
                ? $alert->unit_reference
                : ($alert->guard_id !== null ? $alert->raisedByGuard?->post?->label() : null);
            $estate = $alert->estate->name ?? null;
            $where = implode(', ', array_filter([$place, $estate], static fn (?string $part): bool => $part !== null && $part !== ''));

            $what = match ($alert->kind) {
                'panic' => ' A resident pressed panic.',
                'duress' => ' An officer there needs help.',
                'medical' => ' Medical emergency.',
                'fire' => ' Fire reported.',
                'intrusion' => ' Intrusion reported.',
                default => '',
            };

            $this->push->toGuard((string) $alert->tenant_id, (int) $officer, new PushMessage(
                'site_alert',
                // DRILL first, as on the panic push itself: a lock screen shows the title before anything else.
                $alert->drillPrefix().'You are dispatched',
                ($where === '' ? 'You are dispatched to an alert at your site.' : 'You are dispatched to '.$where.'.').$what,
                $alert->id,
                'dispatched',
            ));
        });

        $this->alertUpdate($alert, 'dispatched', 'An officer has been dispatched', 'Help is on the way. The control room has sent an officer to you.');
    }

    /** The officer sent is on the way — said by their handset, or by dispatch after a radio call. */
    public function alertEnRoute(DuressAlert $alert): void
    {
        $this->alertUpdate($alert, 'en_route', 'An officer is on the way', 'An officer is heading to you now. Open the app to follow.');
    }

    /** The officer is there. */
    public function alertOnScene(DuressAlert $alert): void
    {
        $this->alertUpdate($alert, 'on_scene', 'An officer has arrived', 'An officer is recorded as at the scene of your alert.');
    }

    /** Dispatch closed it (resolved, or a false alarm). The raiser's own ten-second cancel is not news to them. */
    public function alertClosed(DuressAlert $alert): void
    {
        if ($alert->getAttribute('cancelled_at') !== null) {
            return;
        }

        $this->alertUpdate($alert, 'closed', 'Your alert was closed', 'Dispatch has closed your alert. Open the app for the details.');
    }

    private function alertUpdate(DuressAlert $alert, string $kind, string $title, string $body): void
    {
        $title = $alert->drillPrefix().$title;

        $this->safely(function () use ($alert, $kind, $title, $body): void {
            if ($alert->is_simulated) {
                return;
            }

            $tenant = (string) $alert->tenant_id;
            $account = $alert->getAttribute('raised_by_account_id');

            if ($alert->guard_id !== null) {
                // AUDIBLE ONLY. A silent duress stays silent on the guard's own phone until THEY open the app.
                if ($alert->getAttribute('mode') === 'audible') {
                    $this->push->toGuard($tenant, (int) $alert->guard_id, new PushMessage('duress_update', $title, $body, $alert->id, $kind));
                }

                return;
            }

            if ($account !== null) {
                $this->push->toAccount($tenant, (int) $account, new PushMessage('panic_update', $title, $body, $alert->id, $kind));
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Guard App */
    /* ------------------------------------------------------------------ */

    /** A random alertness check was issued. It is worth two minutes and no longer. */
    public function alertnessCheck(int $guardId, int $checkId, int $respondWithinSeconds = 120): void
    {
        $this->safely(function () use ($guardId, $checkId, $respondWithinSeconds): void {
            $tenant = Guard::query()->whereKey($guardId)->value('tenant_id');

            if ($tenant !== null) {
                $this->push->toGuard((string) $tenant, $guardId, new PushMessage('alertness_check', 'Alertness check-in', 'Open the app and tap "I\'m here" — you have two minutes.', $checkId, ttl: $respondWithinSeconds));
            }
        });
    }

    /** The control room wrote to one guard, or broadcast to the estate. A guard's own message to dispatch is not news. */
    public function dispatchMessage(DispatchMessage $message): void
    {
        $this->safely(function () use ($message): void {
            if ($message->direction === DispatchMessage::INBOUND) {
                return;
            }

            $tenant = (string) $message->tenant_id;

            if ($message->direction === DispatchMessage::BROADCAST) {
                $this->push->toGuards($tenant, $this->guardsOf($tenant), new PushMessage('broadcast', 'Site broadcast', PushMessage::clip((string) $message->body, 110), $message->id));
            } elseif ($message->guard_id !== null) {
                $this->push->toGuard($tenant, (int) $message->guard_id, new PushMessage('message', 'Control room', PushMessage::clip((string) $message->body, 110), $message->id));
            }
        });
    }

    /** A new or revised set of standing orders: the guards it applies to. */
    public function ordersPublished(int $setId, string $title): void
    {
        $this->safely(function () use ($setId, $title): void {
            $set = DB::connection('mysql')->table('standing_order_sets')->where('id', $setId)->first(['id', 'tenant_id', 'post_id']);

            if ($set === null) {
                return;
            }

            $guards = Guard::query()->where('status', 'active')->whereNotNull('tenant_id')
                ->when($set->tenant_id !== null, static fn ($q) => $q->where('tenant_id', $set->tenant_id))
                ->when($set->post_id !== null, static fn ($q) => $q->where('post_id', $set->post_id))
                ->get(['id', 'tenant_id']);

            $message = new PushMessage('order_published', 'Standing orders updated', $title.($set->post_id !== null ? ' — read and acknowledge before your next round.' : ''), $setId);

            foreach ($guards->groupBy('tenant_id') as $tenant => $group) {
                $this->push->toGuards((string) $tenant, $group->pluck('id')->map(static fn ($id): int => (int) $id)->all(), $message);
            }
        });
    }

    /** A shift still ahead was given to a guard, or its times moved. What the guard did themselves (clocking on) is not news. */
    public function shiftChanged(Shift $shift): void
    {
        $this->safely(function () use ($shift): void {
            if ($shift->is_simulated || $shift->guard_id === null || $shift->status !== Shift::ROSTERED || $shift->rostered_start->isPast()) {
                return;
            }

            $tenant = (string) $shift->tenant_id;
            $when = SiteTime::local($shift->rostered_start, $tenant)->format('D j M, g:i A');

            // A roster published for a month is thirty saves and one buzz: coalesced on the roster, not on the shift.
            $this->push->toGuard($tenant, (int) $shift->guard_id, new PushMessage('shift_changed', 'Your roster changed', trim($shift->postLabel().' · '.$when, ' ·'), $shift->id, coalesceAs: 'roster'));
        });
    }

    /**
     * THE PLATFORM CLOSED A SHIFT THE GUARD NEVER CLOSED (M6 §2.1 part 4).
     *
     * "The guard is told their shift was auto-closed." Said plainly, in the words a
     * person uses, and without an accusation in it: a forgotten tap at the end of a
     * twelve-hour night is not misconduct, and the officer may well have worked past
     * the hour this row now records.
     *
     * ON `shift_changed`, DELIBERATELY, rather than a type of its own. An Android
     * notification channel is fixed at first install and can never be changed
     * afterwards, so a new channel id would mean a new release and a phone already
     * out there receiving a push to a channel it never created — which Android
     * demotes to the app's default, silently. `shift_changed` already exists on both
     * sides (`packages/core/notify/links.ts`), is governed by the guard's own
     * "Shifts & roster" switch, and opens Home, where the shift they are looking for
     * is. Its id is the SHIFT, not the roster, so this never coalesces with "your
     * roster changed".
     *
     * The push is the courtesy. The record the guard can act on is the shift itself,
     * flagged, and the Head of Security's attendance exception beside it — neither of
     * which depends on a phone being reachable.
     */
    public function shiftAutoClosed(Shift $shift, int $hours): void
    {
        $this->safely(function () use ($shift, $hours): void {
            if ($shift->is_simulated || $shift->guard_id === null) {
                return;
            }

            $tenant = (string) $shift->tenant_id;

            /*
             * THE TIME THAT IS ON THE ROW (bug hunt B100). An officer who clocked on after the
             * rostered end had their shift closed at the clock-on, and was told it closed at
             * its rostered end — a time before they had started.
             */
            $atClockOn = ShiftAutoClose::closedAtClockOn($shift);
            $ended = SiteTime::local($atClockOn ? $shift->actual_start : $shift->rostered_end, $tenant)->format('D j M, g:i A');

            $this->push->toGuard($tenant, (int) $shift->guard_id, new PushMessage(
                'shift_changed',
                'Your shift was closed for you',
                'No clock-out came in, so your shift was closed '.($atClockOn ? 'at the time you clocked on' : 'at its rostered end').' ('.$ended.'), '.$hours.' hour'.($hours === 1 ? '' : 's').' ago. Tell your supervisor if you worked later.',
                $shift->id,
                coalesceAs: 'auto-close-'.$shift->id,
            ));
        });
    }

    /**
     * A guard was taken off one of his shifts, or replaced on it (M30 §5.2). On `shift_changed`, for the same reason as
     * `shiftAutoClosed()`: a channel every installed phone already has, opening Home. Its id is the shift, so it never
     * coalesces with "Your roster changed".
     */
    public function shiftTakenOff(Shift $shift, int $guardId, string $reason): void
    {
        $this->safely(function () use ($shift, $guardId, $reason): void {
            if ($shift->is_simulated) {
                return;
            }

            $tenant = (string) $shift->tenant_id;
            $local = SiteTime::local($shift->rostered_start, $tenant);
            $when = $local->format('D j M, g:i A').SiteTime::label($local);

            $this->push->toGuard($tenant, $guardId, new PushMessage(
                'shift_changed',
                'A shift is no longer yours',
                trim($shift->postLabel().' · '.$when, ' ·').'. Reason: '.$reason.'. Do not report for it.',
                $shift->id,
                coalesceAs: 'taken-off-'.$shift->id,
            ));
        });
    }

    /**
     * A shift a guard was on was removed before it started (M30 §5.2). The row is gone by the time this is sent, so
     * what it was arrives already said ("Main Gate · Tue 6 Oct, 6:00 PM JA").
     */
    public function shiftRemoved(string $tenant, int $shiftId, int $guardId, string $what, string $reason): void
    {
        $this->safely(function () use ($tenant, $shiftId, $guardId, $what, $reason): void {
            $this->push->toGuard($tenant, $guardId, new PushMessage(
                'shift_changed',
                'A shift is no longer yours',
                $what.'. It was removed from the roster. Reason: '.$reason.'. Do not report for it.',
                $shiftId,
                coalesceAs: 'taken-off-'.$shiftId,
            ));
        });
    }

    /** A shift was offered to whoever will take it: the estate's guards, who may claim it. */
    public function shiftOffered(Shift $shift): void
    {
        $this->safely(function () use ($shift): void {
            if ($shift->is_simulated || $shift->status !== Shift::OPEN || $shift->rostered_start->isPast()) {
                return;
            }

            $tenant = (string) $shift->tenant_id;
            $when = SiteTime::local($shift->rostered_start, $tenant)->format('D j M, g:i A');

            $this->push->toGuards($tenant, $this->guardsOf($tenant), new PushMessage('shift_open', 'A shift is open', trim($shift->postLabel().' · '.$when, ' ·').'. Open the app to claim it.', $shift->id, coalesceAs: 'open-shifts'));
        });
    }

    /**
     * A supervisor answered an officer's claim on an open shift (the Roster's claims, 26 Sep; `ShiftClaims`).
     *
     * `claim_decided` opens the open-shifts screen and is governed by the guard's "Shifts & roster" switch — the same
     * type the Guard App raises locally from the claims in `sync.pull`. An approval coalesces on the ROSTER, like the
     * "Your roster changed" the assignment itself sends, so the officer is buzzed once for being given a shift.
     */
    public function claimDecided(Shift $shift, int $guardId, bool $approved): void
    {
        $this->safely(function () use ($shift, $guardId, $approved): void {
            if ($shift->is_simulated) {
                return;
            }

            $tenant = (string) $shift->tenant_id;
            $when = SiteTime::local($shift->rostered_start, $tenant)->format('D j M, g:i A');
            $what = trim(($shift->post === null ? 'Shift' : $shift->postLabel()).' · '.$when, ' ·');

            $this->push->toGuard($tenant, $guardId, $approved
                ? new PushMessage('claim_decided', 'Your claim was approved', $what.'. It is on your roster now.', $shift->id, coalesceAs: 'roster')
                : new PushMessage('claim_decided', 'Your claim was not approved', $what.'. Other open shifts are in the app.', $shift->id));
        });
    }

    /** A supervisor answered a leave, equipment or swap request. The note stays in the app. */
    public function requestDecided(GuardRequest $request): void
    {
        $this->safely(function () use ($request): void {
            $what = match ($request->kind) {
                GuardRequest::EQUIPMENT => 'equipment request',
                'shift_swap' => 'shift swap',
                default => 'leave request',
            };

            $decision = match ($request->status) {
                'approved' => 'It was approved.',
                'denied', 'declined' => 'It was not approved.',
                'info_requested' => 'It needs more information.',
                default => 'It was answered.',
            };

            $this->push->toGuard((string) $request->tenant_id, (int) $request->guard_id, new PushMessage('request_decided', 'Your '.$what, $decision.' Open the app for the note.', $request->id, (string) $request->kind));
        });
    }

    /**
     * WHO `payslipsReady()` TELLS: each guard with a payslip on the run and an estate to be told through (a guard between
     * postings has none). The pay run's page counts its confirmation from this same query (DR-073), so the question
     * "…to N guards?" and the push it asks about can never count differently.
     */
    public static function payslipAudience(int $payrollRunId): QueryBuilder
    {
        return DB::connection('mysql')->table('payslips')->where('payroll_run_id', $payrollRunId)->whereNotNull('tenant_id');
    }

    /** A payroll run was approved: each guard on it has a payslip. That one exists — never what it says. */
    public function payslipsReady(int $payrollRunId): void
    {
        $this->safely(function () use ($payrollRunId): void {
            $slips = self::payslipAudience($payrollRunId)->get(['guard_id', 'tenant_id']);
            $message = new PushMessage('payslip_ready', 'Payslip ready', 'A new payslip has been posted. Open the app to read it.', $payrollRunId);

            foreach ($slips->groupBy('tenant_id') as $tenant => $group) {
                $this->push->toGuards((string) $tenant, $group->pluck('guard_id')->map(static fn ($id): int => (int) $id)->all(), $message);
            }
        });
    }

    /**
     * A RESIDENT SAYS SOMETHING IS HAPPENING NOW (M4 §3.5) — told to the guards ON
     * DUTY at that site, and to nobody else. Raised by a resident; it is a GUARD's
     * notification, which is why it sits here.
     *
     * "Yes — notify, but do not reassign. Guards on duty at that site get the alert.
     * The ticket itself still belongs to community staff with source = resident.
     * Notification is not ownership." So this method READS the ticket and writes
     * nothing: no owner, no status and no assignment moves because a phone buzzed.
     *
     * WHERE, NEVER WHO. The same rule as a panic: the place the resident gave (or
     * their unit, which is what the ticket's location already is), never the name of
     * the household that sent it — a lock screen is read by whoever is standing near.
     */
    public function residentReportedNow(MaintenanceTicket $ticket, ?string $tenantId = null): void
    {
        $this->safely(function () use ($ticket, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant === null || $ticket->is_simulated) {
                return;
            }

            $where = trim((string) $ticket->location_label);

            $this->push->toGuardsOnDuty($tenant, new PushMessage(
                'resident_report',
                'A resident reports something happening now',
                PushMessage::clip((string) $ticket->title, 90).($where === '' ? '' : ' · '.PushMessage::clip($where, 60)).'. Open the app for what they said.',
                $ticket->id,
            ));
        });
    }

    /* ------------------------------------------------------------------ */
    /* Resident App */
    /* ------------------------------------------------------------------ */

    /** A guard is holding a walk-up visitor at the gate and the household has about two minutes to answer. */
    public function approvalRequested(string $tenantId, int $unitId, int $approvalId, string $visitorName, ?string $postName, int $respondWithinSeconds, bool $simulated = false): void
    {
        $this->safely(function () use ($tenantId, $unitId, $approvalId, $visitorName, $postName, $respondWithinSeconds, $simulated): void {
            if ($simulated) {
                return;
            }

            $at = $postName === null || $postName === '' ? 'the gate' : $postName;

            $this->push->toUnit($tenantId, $unitId, new PushMessage(
                'approval_request', 'Someone is at the gate for you', PushMessage::clip($visitorName, 60).' is at '.$at.'. Open to let them in or turn them away.', $approvalId, ttl: $respondWithinSeconds,
            ));
        });
    }

    /** A visitor's single-use pass was used at the gate. The resident's own e-pass being used is them coming home — not news. */
    public function visitorAdmitted(VisitorPass $pass, ?string $tenantId = null): void
    {
        $this->safely(function () use ($pass, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant === null || $pass->is_simulated || $pass->category === 'resident') {
                return;
            }

            $this->push->toUnit($tenant, (int) $pass->unit_id, new PushMessage('visitor_admitted', 'Your visitor has arrived', PushMessage::clip((string) $pass->visitor_name, 60).' was let in at the gate.', $pass->id));
        });
    }

    /** The estate approved a unit claim: the account that made it can now use the app. */
    public function claimApproved(UnitClaim $claim, ?string $tenantId = null): void
    {
        $this->safely(function () use ($claim, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant === null) {
                return;
            }

            $accounts = ResidentAccount::query()->where('tenant_id', $tenant)->where('claim_id', $claim->id)->where('status', '!=', ResidentAccount::SUSPENDED)->pluck('id');

            $this->push->toAccounts($tenant, $accounts->map(static fn ($id): int => (int) $id)->all(), new PushMessage('claim_approved', 'Your account is approved', 'The estate has linked your account to your unit. Open the app to get started.', $claim->id));
        });
    }

    /** A notice was posted: the estate, or the one phase it is addressed to. Its title — never its body. */
    public function noticePosted(Notice $notice, ?string $tenantId = null): void
    {
        $this->safely(function () use ($notice, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant === null || $notice->published_at === null) {
                return;
            }

            $units = $notice->audience_scope === Notice::ONE_PHASE
                ? Unit::query()->where('block', (string) $notice->audience_phase)->pluck('id')->map(static fn ($id): int => (int) $id)->all()
                : null;

            $this->push->toEstateResidents($tenant, new PushMessage('notice', 'New notice from your estate', PushMessage::clip((string) $notice->title, 90), $notice->id), $units);
        });
    }

    /** A maintenance request moved on: its number and its new status, to the unit that reported it. */
    public function ticketMoved(MaintenanceTicket $ticket, ?string $tenantId = null): void
    {
        $this->safely(function () use ($ticket, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            $status = [
                'acknowledged' => 'Acknowledged',
                'assigned' => 'Assigned to a technician',
                'in_progress' => 'In progress',
                'completed' => 'Completed',
                'verified' => 'Closed',
                'cancelled' => 'Cancelled',
            ][$ticket->status] ?? null;

            if ($tenant === null || $status === null || $ticket->unit_id === null || $ticket->is_simulated) {
                return;
            }

            $this->push->toUnit($tenant, (int) $ticket->unit_id, new PushMessage('ticket_update', 'Request #'.$ticket->number, $status.'.', $ticket->id));
        });
    }

    /** The office wrote to the household on a request (M53 §4.10). THAT it did — the words stay in the app, off the lock screen. */
    public function ticketMessage(MaintenanceTicket $ticket, ?string $tenantId = null): void
    {
        $this->safely(function () use ($ticket, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant === null || $ticket->unit_id === null || $ticket->is_simulated) {
                return;
            }

            $this->push->toUnit($tenant, (int) $ticket->unit_id, new PushMessage('ticket_update', 'Request #'.$ticket->number, 'The estate office sent you a message.', $ticket->id));
        });
    }

    /** The office recorded a payment on a household's account. THAT it was recorded — never how much. */
    public function paymentRecorded(int $unitId, int $paymentId, ?string $tenantId = null): void
    {
        $this->safely(function () use ($unitId, $paymentId, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant !== null) {
                $this->push->toUnit($tenant, $unitId, new PushMessage('payment_recorded', 'Payment recorded', 'The estate office recorded a payment on your account. Open the app to see your statement.', $paymentId));
            }
        });
    }

    /** The arrears ladder sent a household a reminder. No amount, no step, no word like "overdue" on a lock screen. */
    public function duesReminder(int $unitId, ?string $tenantId = null): void
    {
        $this->safely(function () use ($unitId, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant !== null) {
                $this->push->toUnit($tenant, $unitId, new PushMessage('dues_reminder', 'A message about your account', 'Your estate office has sent a reminder. Open the app to see your statement.', $unitId));
            }
        });
    }

    /**
     * THE COMMITTEE MEMBERS' HOUSEHOLDS (Hassam's rule; M73 §1, step 6): the unit of each active Resident App account in
     * this estate whose sign-in email is exactly the console email of somebody on its committee now
     * (`Settings::committeeEmails`). Nobody is matched by a name; a member with no such account is told nothing by phone,
     * and with none at all the list is empty and nobody is.
     *
     * @return list<int>
     */
    public static function committeeHouseholds(string $tenant): array
    {
        $emails = Settings::committeeEmails($tenant);

        if ($emails === []) {
            return [];
        }

        return DB::connection('mysql')->table('resident_accounts')
            ->where('tenant_id', $tenant)
            ->where('channel', 'email')
            ->where('status', ResidentAccount::ACTIVE)
            ->whereNotNull('unit_id')
            ->whereIn(DB::raw('LOWER(destination)'), $emails)
            ->distinct()
            ->orderBy('unit_id')
            ->pluck('unit_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** A meeting was published, or is tomorrow (`$kind` = `scheduled` | `reminder`). */
    public function meeting(Meeting $meeting, string $kind = 'scheduled', ?string $tenantId = null): void
    {
        $this->safely(function () use ($meeting, $kind, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant === null || $meeting->published_at === null || $meeting->status !== Meeting::SCHEDULED) {
                return;
            }

            // A committee's own meeting: its members' own households, and no phone at all when none has an account.
            $units = match ($meeting->audience_scope) {
                Meeting::COMMITTEE_ONLY => self::committeeHouseholds($tenant),
                Meeting::PHASE_SUBSET => Unit::query()->where('block', (string) $meeting->phase)->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                default => null,
            };

            if ($units === []) {
                return;
            }

            $starts = SiteTime::local($meeting->starts_at, $tenant);
            // A resident reading this on a phone set to another zone has no banner: the time says whose it is (M19A §8.7).
            $when = $starts->format('D j M, g:i A').' '.SiteTime::clockTag(SiteTime::zone($tenant));

            $this->push->toEstateResidents($tenant, new PushMessage(
                'meeting_reminder', $kind === 'reminder' ? self::reminderTitle($starts, SiteTime::now($tenant)) : 'A meeting has been called', PushMessage::clip((string) $meeting->title, 70).' · '.$when, $meeting->id, $kind, coalesceAs: $kind.'-'.$meeting->id,
            ), $units);
        });
    }

    /**
     * WHAT A MEETING'S LIFE TELLS ITS AUDIENCE (M56 §2.1), after the announcement itself:
     *
     *   moved      everyone in the audience: "This meeting has moved to …" (their replies were reset)
     *   place      the households that said they are coming (`$units`): "The place has changed to …"
     *   postponed  everyone: "Postponed. A new date will follow."
     *   postponed_to  everyone: "Postponed to …", and why, in one message (M94 §3.3; their replies were reset)
     *   cancelled  everyone: "Cancelled", and the reason
     *   minutes    everyone: "Minutes are available"
     *
     * A committee's own meeting reaches its members' own households (`committeeHouseholds()`), as its announcement does
     * (M73 §1, step 6). The meeting's id opens it in the app.
     *
     * @param  list<int>|null  $units  only these households (the place change); null: the whole audience
     * @param  string|null  $reason  why, for a postponement to a new date: the meeting keeps no postponement once it has one
     */
    public function meetingChanged(Meeting $meeting, string $kind, ?string $tenantId = null, ?array $units = null, ?string $reason = null): void
    {
        $this->safely(function () use ($meeting, $kind, $tenantId, $units, $reason): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant === null || $meeting->published_at === null) {
                return;
            }

            // A committee's own meeting tells its members' own households (`committeeHouseholds()`), as its announcement does.
            $audience = match ($meeting->audience_scope) {
                Meeting::COMMITTEE_ONLY => self::committeeHouseholds($tenant),
                Meeting::PHASE_SUBSET => Unit::query()->where('block', (string) $meeting->phase)->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                default => null,
            };

            if ($units !== null) {
                $audience = $audience === null ? $units : array_values(array_intersect($audience, $units));
            }

            if ($audience === []) {
                return;
            }

            $starts = SiteTime::local($meeting->starts_at, $tenant);
            $when = $starts->format('D j M, g:i A').' '.SiteTime::clockTag(SiteTime::zone($tenant));
            $title = PushMessage::clip((string) $meeting->title, 70);

            [$heading, $body] = match ($kind) {
                'moved' => ['Meeting moved', $title.' has moved to '.$when.'. Please reply again.'],
                'place' => ['Meeting place changed', $title.': the place has changed to '.PushMessage::clip($meeting->placeLine(), 80).'.'],
                'postponed' => ['Meeting postponed', $title.' is postponed. A new date will follow.'],
                'postponed_to' => ['Meeting postponed', $title.' is postponed to '.$when.($reason === null || trim($reason) === '' ? '' : ': '.rtrim(PushMessage::clip($reason, 90), '. ')).'. Please reply again.'],
                'cancelled' => ['Meeting cancelled', $title.' is cancelled: '.PushMessage::clip((string) $meeting->cancel_reason, 90)],
                'minutes' => ['Minutes are available', 'The minutes of '.$title.' are published.'],
                default => [null, null],
            };

            if ($heading === null) {
                return;
            }

            $this->push->toEstateResidents($tenant, new PushMessage('meeting_reminder', $heading, $body, $meeting->id, $kind, coalesceAs: $kind.'-'.$meeting->id.'-'.$meeting->updated_at?->timestamp), $audience);
        });
    }

    /**
     * "Meeting today" or "Meeting tomorrow", by the estate's own calendar (M11 §4.2). Every reminder used to say
     * "tomorrow", including one sent at 10 AM for a meeting that evening.
     */
    public static function reminderTitle(CarbonInterface $startsLocal, CarbonInterface $nowLocal): string
    {
        return $startsLocal->toDateString() === $nowLocal->toDateString() ? 'Meeting today' : 'Meeting tomorrow';
    }

    /**
     * Voting opened, closes within a day, or was given longer (`$kind` = `opened` | `closing` | `extended`, M56 §3: an
     * extension is announced). The title of the election — nothing on the paper.
     */
    public function election(Ballot $ballot, string $kind = 'opened', ?string $tenantId = null): void
    {
        $this->safely(function () use ($ballot, $kind, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant === null || $ballot->stage !== Ballot::VOTING_OPEN) {
                return;
            }

            [$title, $body] = match ($kind) {
                'closing' => ['Voting closes soon', PushMessage::clip((string) $ballot->title, 90).'. Open the app to vote.'],
                'extended' => ['Voting extended', PushMessage::clip((string) $ballot->title, 70).' now closes '.SiteTime::local($ballot->closes_at, $tenant)->format('D j M, g:i A').'.'],
                default => ['Voting is open', PushMessage::clip((string) $ballot->title, 90).'. Open the app to vote.'],
            };

            // A ballot with a roll is announced to the households on it and to nobody else (M62 A9): the committee's
            // seven, not the estate. An empty roll tells nobody; null, a ballot without one, tells every household.
            $this->push->toEstateResidents($tenant, new PushMessage(
                'election_open', $title, $body, $ballot->id, $kind, coalesceAs: $kind.'-'.$ballot->id.($kind === 'extended' ? '-'.$ballot->closes_at?->timestamp : ''),
            ), $ballot->hasRoll() ? $ballot->rollUnitIds() : null);
        });
    }

    /**
     * AN ELECTION WAS CANCELLED after voting opened (M56 §3.1.4): every household that voted is told, with the reason.
     * Their ballots are kept and counted for nothing; the push says so in a sentence, never what anyone chose.
     *
     * @param  list<int>  $units  the households that voted
     */
    public function electionCancelled(Ballot $ballot, array $units, ?string $tenantId = null): void
    {
        $this->safely(function () use ($ballot, $units, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant === null || $units === []) {
                return;
            }

            $this->push->toEstateResidents($tenant, new PushMessage(
                'election_open', 'Election cancelled', PushMessage::clip((string) $ballot->title, 60).' is cancelled: '.PushMessage::clip((string) $ballot->cancel_reason, 90), $ballot->id, 'cancelled', coalesceAs: 'cancelled-'.$ballot->id,
            ), $units);
        });
    }

    /** The certified result was published. THAT it is out — never what it is. */
    public function electionResults(Ballot $ballot, ?string $tenantId = null): void
    {
        $this->safely(function () use ($ballot, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            if ($tenant !== null && $ballot->published_at !== null) {
                // To the households on its roll only, when it has one (M62 A9).
                $this->push->toEstateResidents($tenant, new PushMessage('election_results', 'Election results are out', PushMessage::clip((string) $ballot->title, 90).'. Open the app to read them.', $ballot->id), $ballot->hasRoll() ? $ballot->rollUnitIds() : null);
            }
        });
    }

    /** The estate answered a booking request. The amenity and the answer — no fee, no deposit. */
    public function bookingDecided(AmenityBooking $booking, ?string $tenantId = null): void
    {
        $this->safely(function () use ($booking, $tenantId): void {
            $tenant = $tenantId ?? $this->openEstate();

            $answer = [
                AmenityBooking::CONFIRMED => 'confirmed',
                AmenityBooking::DECLINED => 'not approved',
                AmenityBooking::CANCELLED => 'cancelled',
                // M53 §2: nobody decided it before its time, which is not a refusal and is never called one.
                AmenityBooking::EXPIRED => 'request expired before the estate decided',
            ][$booking->status] ?? null;

            if ($tenant === null || $answer === null || $booking->is_simulated) {
                return;
            }

            $this->push->toUnit($tenant, (int) $booking->unit_id, new PushMessage('booking_decided', 'Your booking', PushMessage::clip((string) ($booking->amenity->name ?? 'Your booking'), 60).': '.$answer.'.', $booking->id));
        });
    }

    /* ------------------------------------------------------------------ */

    /** @return list<int> every active guard posted to this estate */
    private function guardsOf(string $tenantId): array
    {
        return Guard::query()->where('tenant_id', $tenantId)->where('status', 'active')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** The estate whose database is open — what an estate record belongs to. Null outside an estate, and then nothing is sent. */
    private function openEstate(): ?string
    {
        $key = function_exists('tenant') ? tenant()?->getTenantKey() : null;

        return $key === null || $key === '' ? null : (string) $key;
    }

    /** Whether this estate-wide reminder has gone before — so a scheduled command can run every hour and say it once. */
    public function firstTime(string $tenantId, string $type, string $record): bool
    {
        try {
            return DB::connection('mysql')->table('push_coalescing')->insertOrIgnore([
                'tenant_id' => $tenantId, 'principal_type' => 'estate', 'principal_id' => 0, 'type' => $type, 'record' => $record, 'last_pushed_at' => Carbon::now(), 'suppressed' => 0,
            ]) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
        }
    }
}
