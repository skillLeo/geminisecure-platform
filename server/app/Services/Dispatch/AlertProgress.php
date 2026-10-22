<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Events\AlertProgressed;
use App\Live\Events\AlertLive;
use App\Live\LiveBus;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Push\PanicFanOut;
use App\Support\SiteTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHO WAS SENT, AND WHEN THEY GOT THERE — the three steps between an acknowledged alert and a
 * resolved one (M10 §1.3).
 *
 * "The resident's screen still shows the honest state: alerting → acknowledged → dispatched → en
 * route → on scene → resolved." The platform could record the first, the second and the last.
 * This is the rest, and it is the ONLY writer of `dispatched_at`, `en_route_at` and `on_scene_at`:
 *
 *   dispatch()   a dispatcher sends one officer from the alert's own reach (`PanicFanOut::guardsFor`)
 *   enRoute()    that officer is on the way — from their own handset, or recorded by a dispatcher
 *   onScene()    that officer is there           after a radio call, and each says which
 *
 * THE RESPONSE ONLY MOVES FORWARD. Each step is refused once the alert has reached it or passed
 * it: no second dispatch, no "en route" after "on scene", and nothing at all once the alert is
 * resolved, called a false alarm or cancelled. A step MAY be skipped — an officer who ran straight
 * to the lot has an `on_scene_at` and no `en_route_at` — and the skipped one stays null rather than
 * being back-filled, because a time nobody recorded is not a time.
 *
 * EVERY REFUSAL IS A SENTENCE (`AlertProgressRefused`), because the dispatcher and the guard are
 * both about to act on it. It is never a silent no-op: a guard who taps "On scene" on an alert the
 * control room has already closed must be told so, not shown a success.
 *
 * ONE ROW LOCK PER STEP. The dispatcher's "Mark en route" and the guard's own tap can land in the
 * same second; checking and writing under `lockForUpdate` is what makes one of them the step and
 * the other a refusal naming it. `duress_alerts` is a mutable table (CENTRAL_MUTABLE) — the lock
 * never touches `audit_log`, which is append-only and only ever inserted into here.
 *
 * AUDITED, EVERY STEP: `alert.dispatched`, `alert.en_route`, `alert.on_scene` — who (the audit
 * log's own actor: the console user, or "Guard App handset" and the officer's name), before, after
 * and when. The columns say where the response stands; the log is its history.
 *
 * WHAT IT DOES NOT DO IS PUSH. The pushes come off the row's own save (`PushServiceProvider` →
 * `PushAnnouncer::alertDispatched/alertEnRoute/alertOnScene`), after commit, the same way an
 * acknowledgement's always has — so a step that rolls back tells nobody anything.
 */
final class AlertProgress
{
    public const DISPATCHED = 'dispatched';

    public const EN_ROUTE = 'en_route';

    public const ON_SCENE = 'on_scene';

    /** The status an alert takes once somebody is being sent — the value `status` has always listed and nothing wrote. */
    public const RESPONDING = 'responding';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PanicFanOut $fanOut,
    ) {}

    /**
     * The officers a dispatcher may send to this alert, in name order.
     *
     * EXACTLY THE SET THE PANIC WAS PUSHED TO (`PanicFanOut::guardsFor`), narrowed to officers
     * still active at this estate: somebody the fan-out never rang has not been told there is
     * anything to go to, and a suspended officer's handset receives nothing. The raiser of a
     * duress is not in it — help goes TO them. Name order is an order, not a ranking (M10 §0).
     *
     * @return Collection<int, Guard>
     */
    public function candidates(DuressAlert $alert): Collection
    {
        $ids = $this->fanOut->guardsFor($alert);

        if ($ids === []) {
            return new Collection;
        }

        return Guard::query()
            ->with('post')
            ->whereIn('id', $ids)
            ->where('tenant_id', $alert->tenant_id)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Send one officer. The first moment "help is on the way" becomes true.
     *
     * STATUS BECOMES `responding`. And an alert nobody had acknowledged is acknowledged by the
     * same act, by the same dispatcher, at the same moment — sending an officer IS the control
     * room taking the alert, and leaving `acknowledged_at` empty would let the raiser's ten-second
     * cancel stand down an officer already on their way. An earlier acknowledgement is never
     * overwritten: that timestamp is the evidence of how long the person waited.
     *
     * @throws AlertProgressRefused
     */
    public function dispatch(DuressAlert $alert, Guard $officer, User $dispatcher): DuressAlert
    {
        return $this->step($alert, self::DISPATCHED, function (DuressAlert $row) use ($officer, $dispatcher): array {
            $this->refuseIfClosed($row, 'nobody can be sent to it now');

            if ($row->dispatched_at !== null) {
                throw new AlertProgressRefused(
                    AlertProgressRefused::ALREADY_DISPATCHED,
                    $this->officerName($row).' was already dispatched to this alert at '.$this->clock($row, $row->dispatched_at).'. It takes one dispatched officer — message another officer if more help is needed.',
                );
            }

            if ($row->en_route_at !== null || $row->on_scene_at !== null) {
                throw new AlertProgressRefused(
                    AlertProgressRefused::ALREADY_RESPONDING,
                    $this->officerName($row).' is already '.($row->on_scene_at !== null ? 'at the scene' : 'on the way').', from their own handset, so there is nobody left to dispatch. Message another officer if more help is needed.',
                );
            }

            if (! $this->candidates($row)->contains('id', $officer->id)) {
                throw new AlertProgressRefused(
                    AlertProgressRefused::NOT_ON_SHIFT,
                    $officer->full_name.' is not on shift at '.$this->estateName($row).', so this alert never reached them. Pick an officer it did reach.',
                    422,
                );
            }

            $now = Carbon::now();

            return [
                'status' => self::RESPONDING,
                'dispatched_at' => $now,
                'dispatched_by' => $dispatcher->id,
                'responding_guard_id' => $officer->id,
                'acknowledged_at' => $row->acknowledged_at ?? $now,
                'acknowledged_by' => $row->acknowledged_by ?? $dispatcher->id,
            ];
        }, ['dispatched_by_name' => $dispatcher->name]);
    }

    /**
     * The officer is on the way.
     *
     * `$by` is WHO SAID SO: a console `User` recording it on the officer's behalf after a radio
     * call, or the officer's own `Guard` handset. Recorded in `en_route_recorded_by` — the user's
     * id, or null for the handset — and in the audit entry, so every timeline can say which.
     *
     * The column holds the SERVER's time, when the platform learned it — which is when the
     * resident is told. A tap captured offline keeps its handset time in the audit entry beside it.
     *
     * @throws AlertProgressRefused
     */
    public function enRoute(DuressAlert $alert, User|Guard $by, ?CarbonInterface $deviceTime = null): DuressAlert
    {
        return $this->onTheirBehalfOrOwn($alert, self::EN_ROUTE, $by, $deviceTime);
    }

    /**
     * The officer is there. The same rules, one step further.
     *
     * @throws AlertProgressRefused
     */
    public function onScene(DuressAlert $alert, User|Guard $by, ?CarbonInterface $deviceTime = null): DuressAlert
    {
        return $this->onTheirBehalfOrOwn($alert, self::ON_SCENE, $by, $deviceTime);
    }

    /**
     * Why this guard's handset may NOT record `$stage` on this alert now — or null when it may.
     *
     * The same checks `enRoute()` and `onScene()` make, with nothing written: it is what the
     * Guard App reads to decide whether to draw the button at all, so a screen never offers a
     * step the server is about to refuse.
     */
    public function refusalFor(DuressAlert $alert, Guard $guard, string $stage): ?AlertProgressRefused
    {
        try {
            $this->refuseTheHandset($alert, $guard);
            $this->refuseIfPast($alert, $stage, true);
        } catch (AlertProgressRefused $refused) {
            return $refused;
        }

        return null;
    }

    /**
     * Whether this guard may READ where the response stands: the officer responding, or any
     * officer the alert reached. The same audience as the push; nobody else at the estate.
     */
    public function mayFollow(DuressAlert $alert, Guard $guard): bool
    {
        return (int) $alert->responding_guard_id === $guard->id
            || in_array($guard->id, $this->fanOut->guardsFor($alert), true);
    }

    /* ------------------------------------------------------------------ */

    /** @throws AlertProgressRefused */
    private function onTheirBehalfOrOwn(DuressAlert $alert, string $stage, User|Guard $by, ?CarbonInterface $deviceTime): DuressAlert
    {
        $column = $stage === self::EN_ROUTE ? 'en_route_at' : 'on_scene_at';
        $recordedBy = $stage === self::EN_ROUTE ? 'en_route_recorded_by' : 'on_scene_recorded_by';
        $fromHandset = $by instanceof Guard;

        return $this->step($alert, $stage, function (DuressAlert $row) use ($by, $fromHandset, $stage, $column, $recordedBy): array {
            if ($by instanceof Guard) {
                $this->refuseTheHandset($row, $by);
            } else {
                $this->refuseIfClosed($row, 'there is nothing left to record');

                /*
                 * FOR THE OFFICER WHO WAS SENT, and there is none. A radio call reporting
                 * "on my way" is recorded against a named officer; recording it against
                 * nobody would put "an officer is on the way" on the resident's screen with
                 * no officer behind the sentence.
                 */
                if ($row->responding_guard_id === null) {
                    throw new AlertProgressRefused(
                        AlertProgressRefused::NOBODY_SENT,
                        'Dispatch an officer first: '.($stage === self::EN_ROUTE ? 'en route' : 'on scene').' is recorded for the officer who was sent, and nobody has been.',
                    );
                }
            }

            $this->refuseIfPast($row, $stage, $fromHandset);

            return [
                'status' => self::RESPONDING,
                $column => Carbon::now(),
                $recordedBy => $by instanceof User ? $by->id : null,

                // A guard on shift who took an alert nobody was sent to is its responder from now on.
                'responding_guard_id' => $row->responding_guard_id ?? ($by instanceof Guard ? $by->id : null),
            ];
        }, [
            'recorded_via' => $fromHandset ? 'handset' : 'console',
            'recorded_by' => $by instanceof User ? $by->name : $by->full_name,
            'device_time' => SiteTime::iso($deviceTime),
        ]);
    }

    /**
     * Lock the row, decide, write, audit — one transaction, one step.
     *
     * @param  callable(DuressAlert): array<string, mixed>  $decide  the columns to write, or a refusal
     * @param  array<string, mixed>  $note  extra facts for the audit entry's `after`
     *
     * @throws AlertProgressRefused
     */
    private function step(DuressAlert $alert, string $stage, callable $decide, array $note = []): DuressAlert
    {
        $connection = DB::connection($alert->getConnectionName());

        $row = $connection->transaction(function () use ($alert, $stage, $decide, $note, $connection): DuressAlert {
            $row = DuressAlert::query()->whereKey($alert->getKey())->lockForUpdate()->firstOrFail();

            $changes = $decide($row);

            $before = [];
            foreach (array_keys($changes) as $key) {
                $before[$key] = $this->auditable($row->getAttribute($key));
            }

            $row->forceFill($changes)->save();

            // The refusal checks may have read the responder while there was none; read them again.
            $row->unsetRelation('respondingGuard');

            $after = [];
            foreach (array_keys($changes) as $key) {
                $after[$key] = $this->auditable($row->getAttribute($key));
            }

            $this->audit->record(
                action: 'alert.'.$stage,
                entityType: 'DuressAlert',
                entityId: (string) $row->id,
                before: $before,
                after: [...$after, 'officer' => $row->respondingGuard?->full_name, ...array_filter($note, static fn ($v): bool => $v !== null)],
                tenantId: (string) $row->tenant_id,
            );

            /*
             * THE DISPATCH SCREENS RE-READ — after commit, so a step that rolled back moves no
             * screen. Rescued: the step is recorded, and a Reverb outage must not turn a guard's
             * "on scene" into an error on their handset (the screens poll while it is down).
             */
            $connection->afterCommit(static fn () => rescue(static fn () => AlertProgressed::dispatch(
                (string) $row->tenant_id,
                (int) $row->id,
                $stage,
                (bool) $row->is_simulated,
            )));

            // And to the live log, in this transaction (M11 Part C, DESIGN.md §2.4 A6–A8): every console, the
            // estate's Security page, the resident's timeline, and the officers it reached — the one sent too.
            LiveBus::recordAll(AlertLive::for($row, $stage));

            return $row;
        });

        // The caller's copy tells the truth too: a controller answering from it must not read stale columns.
        $alert->setRawAttributes($row->getAttributes(), true);
        $alert->setRelation('respondingGuard', $row->respondingGuard);

        return $row;
    }

    /**
     * What a guard's OWN handset may not do, whatever the step.
     *
     * @throws AlertProgressRefused
     */
    private function refuseTheHandset(DuressAlert $row, Guard $guard): void
    {
        if ($row->guard_id !== null && (int) $row->guard_id === $guard->id) {
            throw new AlertProgressRefused(
                AlertProgressRefused::OWN_ALERT,
                'This is your own alert. Help is being sent to you — you cannot be the officer sent.',
                403,
            );
        }

        $this->refuseIfClosed($row, 'there is nothing left to respond to');

        /*
         * THE OFFICER SENT, OR — WHEN NOBODY WAS — ANY OFFICER THE ALERT REACHED (M10 §1.3).
         *
         * Nothing in a panic waits on the dispatcher: every officer on shift is pushed it at once,
         * and one who hears it and runs must be able to say so without first being picked. But
         * once an officer is named — by a dispatcher, or by being first to say "on my way" — only
         * THEIR handset speaks for the response. A second guard's "on scene" would tell the resident
         * an officer has arrived while the one sent is still three streets away, and the row names
         * one responder. A second officer going too tells dispatch by radio.
         */
        if ($row->responding_guard_id !== null) {
            if ((int) $row->responding_guard_id !== $guard->id) {
                throw new AlertProgressRefused(
                    AlertProgressRefused::ANOTHER_OFFICER,
                    $this->officerName($row).' is the officer responding to this alert, so only their handset can record it. If you are going too, tell dispatch by radio.',
                    403,
                );
            }

            return;
        }

        if (! in_array($guard->id, $this->fanOut->guardsFor($row), true)) {
            throw new AlertProgressRefused(
                AlertProgressRefused::NOT_ON_SHIFT,
                'You are not on shift at '.$this->estateName($row).', so this alert was not sent to you and you cannot respond to it from this handset.',
                403,
            );
        }
    }

    /**
     * Forward only: refused once the alert has reached this step or passed it.
     *
     * @throws AlertProgressRefused
     */
    private function refuseIfPast(DuressAlert $row, string $stage, bool $fromHandset): void
    {
        $who = $fromHandset ? 'You were' : $this->officerName($row).' was';

        if ($stage === self::EN_ROUTE && $row->en_route_at !== null) {
            throw new AlertProgressRefused(
                AlertProgressRefused::ALREADY_EN_ROUTE,
                $who.' already recorded as on the way at '.$this->clock($row, $row->en_route_at).'.',
            );
        }

        if ($row->on_scene_at !== null) {
            throw new AlertProgressRefused(
                AlertProgressRefused::ALREADY_ON_SCENE,
                $stage === self::ON_SCENE
                    ? $who.' already recorded at the scene at '.$this->clock($row, $row->on_scene_at).'.'
                    : $who.' already recorded at the scene at '.$this->clock($row, $row->on_scene_at).', so "on the way" can no longer be recorded — the response only moves forward.',
            );
        }
    }

    /** @throws AlertProgressRefused */
    private function refuseIfClosed(DuressAlert $row, string $consequence): void
    {
        if (! in_array($row->status, ['resolved', 'false_alarm'], true)) {
            return;
        }

        $how = match (true) {
            $row->getAttribute('cancelled_at') !== null => 'cancelled from the phone that raised it',
            $row->status === 'false_alarm' => 'closed as a false alarm',
            default => 'resolved',
        };

        throw new AlertProgressRefused(
            AlertProgressRefused::CLOSED,
            'This alert was '.$how.($row->resolved_at === null ? '' : ' at '.$this->clock($row, $row->resolved_at)).', so '.$consequence.'.',
        );
    }

    /** A moment on the ESTATE's clock (M4 §3.1) — the one a dispatcher and a guard both read. */
    private function clock(DuressAlert $row, CarbonInterface $at): string
    {
        return SiteTime::stamp($at, (string) $row->tenant_id, 'g:i A');
    }

    private function officerName(DuressAlert $row): string
    {
        return $row->respondingGuard->full_name ?? 'Another officer';
    }

    private function estateName(DuressAlert $row): string
    {
        return $row->estate->name ?? 'this estate';
    }

    /** A value as the audit entry keeps it: instants as UTC ISO strings, everything else as it is. */
    private function auditable(mixed $value): mixed
    {
        return $value instanceof CarbonInterface ? SiteTime::iso($value) : $value;
    }
}
