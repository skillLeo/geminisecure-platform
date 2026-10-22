<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\AlertRaised;
use App\Events\AlertReachKnown;
use App\Models\DevicePushToken;
use App\Models\DispatchMessage;
use App\Models\DuressAlert;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Ballot;
use App\Models\Estate\DunningNotice;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\Notice;
use App\Models\Estate\Payment;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\PayrollRun;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Services\Push\PanicDeliveries;
use App\Services\Push\PushAnnouncer;
use App\Services\Push\PushNotifier;
use App\Services\Push\PushTokens;
use App\Services\Ring\GuardRing;
use App\Services\Ring\RingRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * BEHIND EVERYTHING THAT ALREADY HAPPENS, ONE PLACE THAT ALSO PUSHES (M4 §3.2).
 *
 * The platform had no notification service for the phones: each app worked out
 * what was new by comparing lists while it was OPEN. So the hook is the records
 * themselves. Whatever saved an alert, a ticket, a notice or a shift — a console
 * screen, an API call, a scheduled command, one written next year — the model's
 * own event lands here, and `PushAnnouncer` decides what a closed app is told.
 * No screen and no service had to be taught to push, and none can forget to.
 *
 * (THREE records are written with the query builder and raise no model event: a
 * walk-up request, an alertness check and a standing-order version. Their writers
 * call `PushAnnouncer` directly — one line each. There is no fourth. A shift claim's
 * DECISION has no push sentence, because nothing on the platform decides a claim:
 * `shift_claims` is only ever inserted `pending` and read back, and no console screen
 * answers one. The Guard App still shows the decision while it is OPEN, from the
 * claims in `sync.pull`. The note where `PushAnnouncer::claimDecided` used to be says
 * what the screen adds when it is built.)
 *
 * AFTER COMMIT. An announcement waits for the transaction the record was saved in:
 * a payment whose journal failed to balance is rolled back, and nobody is told it
 * was recorded.
 *
 * AND ACCESS THAT ENDS, ENDS HERE TOO. A guard who is no longer active or whose
 * handset is unbound, and a Resident App account that is suspended — a household
 * member the primary resident removed — lose their push addresses in the same
 * save, inside the same transaction. (`PushNotifier` checks again at send time, so
 * a path that deletes API tokens without touching the principal is covered too.)
 */
class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PushNotifier::class);
        $this->app->singleton(PushAnnouncer::class);

        // ONE instance, because two callers stamp it: `announceAlert()` below stamps the
        // broadcast and settles the record, and the fan-out stamps its own legs in between
        // (M10 §1.3). Two instances would each hold half, and the write would lose a leg.
        $this->app->singleton(PanicDeliveries::class);
    }

    public function boot(): void
    {
        $say = fn (): PushAnnouncer => $this->app->make(PushAnnouncer::class);

        /* ── panic and duress ─────────────────────────────────────────── */

        // The consoles first, then the fan-out, then what it reached — see `announceAlert()`.
        DuressAlert::created(fn (DuressAlert $alert) => $this->afterCommit($alert, fn () => $this->announceAlert($alert)));

        DuressAlert::updated(function (DuressAlert $alert) use ($say): void {
            /*
             * THE RESPONSE, ONE STEP AT A TIME (M10 §1.3). Each fires on its own column the
             * first time it is written — `AlertProgress` writes each once and never again — and
             * NOT on `status`: dispatching makes the status `responding` and an officer arriving
             * does not change it again, so the status alone cannot say which step this was.
             */
            foreach (['dispatched_at' => 'alertDispatched', 'en_route_at' => 'alertEnRoute', 'on_scene_at' => 'alertOnScene'] as $column => $announce) {
                if ($alert->wasChanged($column) && $alert->getAttribute($column) !== null) {
                    $this->afterCommit($alert, fn () => $say()->{$announce}($alert));
                }
            }

            /*
             * THE RING STOPS ON EVERY PHONE THE MOMENT ANYBODY ANSWERS (the guard ring §5.2).
             * Before the status early-return below, on purpose: a console acknowledge on an
             * alert already `responding` keeps that status and fires no status event, and a
             * guard's "on my way" changes a column, not always the status. Every path that
             * answers an alert saves it — the console's acknowledge, resolve and dispatch,
             * a radio call recorded, a guard's handset, a ring answered, the raiser's cancel —
             * so this one hook catches all of them. After commit, and rescued: `stop()` only
             * queues the stops, and a failure is the sweep's to repair.
             */
            if ($alert->wasChanged(['acknowledged_at', 'responding_guard_id', 'cancelled_at', 'status']) && RingRule::answered($alert) !== null) {
                $this->afterCommit($alert, fn () => rescue(fn () => $this->app->make(GuardRing::class)->stop($alert)));
            }

            if (! $alert->wasChanged('status')) {
                return;
            }

            if ($alert->status === 'acknowledged') {
                $this->afterCommit($alert, fn () => $say()->alertAcknowledged($alert));
            } elseif (in_array($alert->status, ['resolved', 'false_alarm'], true)) {
                $this->afterCommit($alert, fn () => $say()->alertClosed($alert));
            }
        });

        /* ── Guard App ────────────────────────────────────────────────── */

        DispatchMessage::created(fn (DispatchMessage $message) => $this->afterCommit($message, fn () => $say()->dispatchMessage($message)));

        Shift::created(function (Shift $shift) use ($say): void {
            $this->afterCommit($shift, fn () => $shift->status === Shift::OPEN ? $say()->shiftOffered($shift) : $say()->shiftChanged($shift));
        });

        Shift::updated(function (Shift $shift) use ($say): void {
            if ($shift->wasChanged('status') && $shift->status === Shift::OPEN) {
                $this->afterCommit($shift, fn () => $say()->shiftOffered($shift));
            } elseif ($shift->wasChanged(['guard_id', 'post_id', 'rostered_start', 'rostered_end'])) {
                $this->afterCommit($shift, fn () => $say()->shiftChanged($shift));
            }
        });

        GuardRequest::updated(function (GuardRequest $request) use ($say): void {
            if ($request->wasChanged('status') && $request->decided_at !== null) {
                $this->afterCommit($request, fn () => $say()->requestDecided($request));
            }
        });

        PayrollRun::updated(function (PayrollRun $run) use ($say): void {
            if ($run->wasChanged('status') && $run->status === 'approved') {
                $this->afterCommit($run, fn () => $say()->payslipsReady((int) $run->id));
            }
        });

        /* ── Resident App ─────────────────────────────────────────────── */

        VisitorPass::updated(function (VisitorPass $pass) use ($say): void {
            if ($pass->wasChanged('status') && $pass->status === VisitorPass::USED) {
                $this->afterCommit($pass, fn () => $say()->visitorAdmitted($pass));
            }
        });

        UnitClaim::updated(function (UnitClaim $claim) use ($say): void {
            if ($claim->wasChanged('status') && $claim->status === UnitClaim::APPROVED) {
                $this->afterCommit($claim, fn () => $say()->claimApproved($claim));
            }
        });

        Notice::created(fn (Notice $notice) => $this->afterCommit($notice, fn () => $say()->noticePosted($notice)));

        MaintenanceTicket::updated(function (MaintenanceTicket $ticket) use ($say): void {
            if ($ticket->wasChanged('status')) {
                $this->afterCommit($ticket, fn () => $say()->ticketMoved($ticket));
            }
        });

        Payment::created(function (Payment $payment) use ($say): void {
            // `recorded` is the column's default, so a payment created without naming a status is one.
            if (($payment->getAttribute('status') ?? 'recorded') === 'recorded') {
                $this->afterCommit($payment, fn () => $say()->paymentRecorded((int) $payment->unit_id, (int) $payment->id));
            }
        });

        // A step of the arrears ladder — sent by the morning run or by hand. The push says a reminder came; never which, never how much.
        DunningNotice::created(fn (DunningNotice $notice) => $this->afterCommit($notice, fn () => $say()->duesReminder((int) $notice->unit_id)));

        Meeting::created(function (Meeting $meeting) use ($say): void {
            if ($meeting->published_at !== null) {
                $this->afterCommit($meeting, fn () => $say()->meeting($meeting));
            }
        });

        Meeting::updated(function (Meeting $meeting) use ($say): void {
            if ($meeting->wasChanged('published_at') && $meeting->published_at !== null) {
                $this->afterCommit($meeting, fn () => $say()->meeting($meeting));
            }
        });

        Ballot::updated(function (Ballot $ballot) use ($say): void {
            if ($ballot->wasChanged('stage') && $ballot->stage === Ballot::VOTING_OPEN) {
                $this->afterCommit($ballot, fn () => $say()->election($ballot));
            } elseif ($ballot->wasChanged('published_at') && $ballot->published_at !== null) {
                $this->afterCommit($ballot, fn () => $say()->electionResults($ballot));
            }
        });

        AmenityBooking::updated(function (AmenityBooking $booking) use ($say): void {
            if ($booking->wasChanged('status')) {
                $this->afterCommit($booking, fn () => $say()->bookingDecided($booking));
            }
        });

        /* ── access that ends ─────────────────────────────────────────── */

        Guard::updated(function (Guard $guard): void {
            if ($guard->wasChanged(['status', 'device_id', 'tenant_id']) && ($guard->status !== 'active' || $guard->device_id === null || $guard->wasChanged('tenant_id'))) {
                $this->revoke((string) ($guard->wasChanged('tenant_id') ? $guard->getOriginal('tenant_id') : $guard->tenant_id), DevicePushToken::GUARD, (int) $guard->id);
            }
        });

        ResidentAccount::updated(function (ResidentAccount $account): void {
            if ($account->wasChanged('status') && $account->status === ResidentAccount::SUSPENDED) {
                $this->revoke((string) $account->tenant_id, DevicePushToken::RESIDENT, (int) $account->id);
            }
        });
    }

    /**
     * A NEW ALERT, TOLD IN §1.2's ORDER: THE CONSOLES, THEN EVERYONE ELSE, THEN WHAT IT REACHED.
     *
     * "In the same instant, with no step gated behind another" (M10 §1.2). PHP does one
     * thing at a time, so "the same instant" is a question of what goes FIRST — and the
     * answer is the one leg that needs nothing looked up: the live broadcast the
     * dispatcher's queue and the estate's pop-up listen to. It used to go last, from
     * `AlertIntake::record()`, after `create()` had run the whole fan-out — so the
     * control room waited on push tokens being read, mail being queued and the site's
     * numbers being counted before a row appeared on its screen.
     *
     *   1  `AlertRaised` — the consoles have it. It cannot yet say how many officers'
     *      phones were reached, and does not pretend to (`alerted_handsets` is null).
     *   2  the fan-out — the guards' push, the alert mail, the texts (`PushAnnouncer`),
     *      unchanged, and still sent to nobody for a simulated alert.
     *   3  the hand-off record, in one insert (`PanicDeliveries`) — after every
     *      destination has been handed its leg, and before step 4, so that a screen
     *      which re-reads the server on step 4 finds the record already there.
     *   4  `AlertReachKnown` — the count the fan-out wrote, on the same channel. Not a
     *      destination; nobody new is told.
     *
     * HERE, NOT IN `AlertIntake`, because this is where the fan-out already starts: the
     * alert's own `created` event, after commit. Both broadcasts now wait for the commit
     * too — the old one went out straight after `create()` whether or not a transaction
     * around it would later roll the row back. And only a genuinely new row fires this:
     * `AlertIntake`'s idempotent retry returns the existing alert and creates nothing, so a
     * flaky handset still cannot make the queue flash twice.
     *
     * NOTHING HERE MAY THROW, because at commit time there is no caller left to catch it.
     * Each step is rescued on its own, so a Reverb outage (13 C1) cannot keep a guard's
     * phone from ringing, and a failed push cannot keep the consoles from hearing.
     */
    private function announceAlert(DuressAlert $alert): void
    {
        $deliveries = rescue(fn (): PanicDeliveries => $this->app->make(PanicDeliveries::class), null, false);

        $deliveries?->begin($alert);

        // 1 — the consoles. Rescued and reported: the dispatch screens poll in exactly that case.
        $accepted = rescue(static function () use ($alert): bool {
            AlertRaised::dispatch($alert);

            return true;
        }, false);

        $deliveries?->broadcast($alert, $accepted === true);

        // 2 — the guards, the mail, the texts. `alertRaised()` rescues itself; this is for resolving it.
        rescue(fn () => $this->app->make(PushAnnouncer::class)->alertRaised($alert));

        // 3 — the bookkeeping, once every destination has had its leg.
        $deliveries?->settle($alert);

        // 4 — what it reached, only if the fan-out actually counted something. A simulated
        //     alert's fan-out never runs, and there is no number to send.
        if ($alert->getAttribute('alerted_handsets') !== null) {
            rescue(static fn () => AlertReachKnown::dispatch($alert));
        }
    }

    /** Run once the record's transaction has committed — at once when there is none. Never throws into the save. */
    private function afterCommit(Model $model, callable $announce): void
    {
        try {
            DB::connection($model->getConnectionName())->afterCommit($announce);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
        }
    }

    private function revoke(string $tenantId, string $principalType, int $principalId): void
    {
        rescue(fn () => $this->app->make(PushTokens::class)->revokeFor($tenantId, $principalType, $principalId));
    }
}
