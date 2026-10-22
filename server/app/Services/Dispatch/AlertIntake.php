<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Api\DeviceContext;
use App\Live\Events\AlertLive;
use App\Live\LiveBus;
use App\Models\DuressAlert;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * An alert's life: raised on a device, then moved through its states.
 *
 * `record()` is the single entry point for /api/v1 and for the simulator
 * alike, so a simulated alert travels exactly the path a real one will and
 * nothing built against the simulator has to be rewritten when the apps
 * arrive.
 *
 * `acknowledge()` and `resolve()` are the other end of the same path. They
 * live here rather than in the console controller because the Guard App will
 * shortly call them too — an acknowledgement tapped on a handset and one
 * recorded by a dispatcher must produce the same row and the same audit
 * entry, and two implementations would eventually disagree about that.
 *
 * Both transitions are IDEMPOTENT and never overwrite an earlier one. The
 * first acknowledgement is the one that happened; a second click, a double
 * submit or a retry from a flaky connection must not rewrite who responded or
 * when, because that timestamp is the evidence of how long the person waited.
 */
class AlertIntake
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Device and server clocks are considered to disagree beyond this.
     *
     * Two minutes tolerates ordinary drift and a slow upload without masking a
     * device whose clock is genuinely wrong.
     */
    public const CLOCK_SKEW_TOLERANCE_SECONDS = 120;

    public function record(
        string $tenantId,
        string $kind,
        ?int $guardId = null,
        ?string $raisedByName = null,
        ?string $unitReference = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?CarbonInterface $deviceTime = null,
        bool $capturedOffline = false,
        ?string $idempotencyKey = null,
        bool $isSimulated = false,

        /*
         * A DRILL (M10 §1.3): a real alert, on this same path, that everybody it
         * reaches is told is a drill. Only `panic:walk` passes it; no endpoint does,
         * because a handset that could mark its own panic a drill could hide one.
         */
        bool $isDrill = false,

        // The Resident App account that raised it, on the row from the first insert (below).
        ?int $raisedByAccountId = null,

        /*
         * PRESSED ON THE PANIC OR DURESS BUTTON (bug hunt B086) — `DuressController`, and
         * `panic:walk`, which walks that path. A press stays a panic whatever label the
         * resident gave it; an incident reported on `POST /alerts` is not a press.
         */
        bool $fromPanicButton = false,

        /*
         * RAISED IN DRILL MODE (M53 §3A.1): a drill that wakes nobody (`DuressAlert::wakesNobody()`). Only
         * `DuressController` passes it, and only from the account's own switch (`PanicDrillMode`), never from anything
         * the handset sent. It makes the alert a drill as well.
         */
        bool $drillMode = false,
    ): DuressAlert {
        /*
         * Idempotency first.
         *
         * An offline device retries on reconnect, and a panic alert duplicated
         * by a retry wastes a dispatcher's attention at the worst moment. The
         * key is client-generated, so retries are safe by construction rather
         * than by the server guessing which rows look alike.
         *
         * A KEY ANSWERS ONLY FOR THE ONE WHO RAISED IT (bug hunt B095). The key is
         * unique across the whole platform and the lookup was too, so a resident at
         * one estate who sent a key another estate's alert held was handed THAT alert
         * — its id, status and times — and `DuressController` wrote their account onto
         * it, while their own press raised nothing. A key now returns an alert only to
         * the same estate and the same raiser (the officer, or the Resident App account).
         * Anybody else's press is a new alert, always: a panic is never refused, and never
         * answered with somebody else's, because of a key. It gets a key of its own
         * derived from the one it sent, so ITS retries are still idempotent.
         */
        if ($idempotencyKey !== null) {
            $existing = DuressAlert::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null && self::raisedBy($existing, $tenantId, $guardId, $raisedByAccountId)) {
                return $existing;
            }

            if ($existing !== null) {
                // Reported by id and estate only — never the key, which is the caller's secret.
                rescue(static fn () => report(new RuntimeException(sprintf(
                    'An alert at estate [%s] was raised with an Idempotency-Key that alert #%d at estate [%s] already holds; it was recorded as a new alert under a key of its own.',
                    $tenantId,
                    (int) $existing->id,
                    (string) $existing->tenant_id,
                ))), report: false);

                $idempotencyKey = self::ownKey($idempotencyKey, $tenantId, $guardId, $raisedByAccountId);
                $mine = DuressAlert::where('idempotency_key', $idempotencyKey)->first();

                if ($mine !== null && self::raisedBy($mine, $tenantId, $guardId, $raisedByAccountId)) {
                    return $mine;
                }

                // Somebody already holds even that key: a new alert with none rather than no alert.
                if ($mine !== null) {
                    $idempotencyKey = null;
                }
            }
        }

        $serverTime = Carbon::now();

        $alert = $this->create($tenantId, $kind, $fromPanicButton, $guardId, $raisedByName, $raisedByAccountId, $unitReference, $latitude, $longitude, $deviceTime, $serverTime, $capturedOffline, $idempotencyKey, $isSimulated, $isDrill || $drillMode, $drillMode);

        /*
         * EVERY OPEN SCREEN HEARS OF IT (M11 Part C, DESIGN.md §2.4 A1) — ON ITS OWN COMMIT, AFTER THE ALERT'S.
         * The other steps record their event inside their own transaction; the raise does not, on purpose. A
         * live log that cannot be written (a deadlock, a full disk) would take the transaction with it, and
         * nothing may ever cost a panic its row. So the alert commits exactly as it always has — its push, its
         * broadcast and its fan-out with it — and `alert.raised` follows. If that fails the panic stands, the
         * failure is reported, and the screens still have the broadcast and their polls.
         */
        self::announce($alert, 'raised');

        return $alert;
    }

    /**
     * An alert's live event on a commit of its own, after the row's: the raise's rule (above) and the reach's
     * (PanicFanOut). Reported, never thrown, in production; thrown in local and testing, so a mistake shows.
     */
    public static function announce(DuressAlert $alert, string $verb): void
    {
        try {
            DB::connection($alert->getConnectionName())->transaction(static fn () => LiveBus::recordAll(AlertLive::for($alert, $verb)));
        } catch (Throwable $e) {
            if (app()->environment(['local', 'testing'])) {
                throw $e;
            }

            report($e);
        }
    }

    /** The alert's first insert (see record()). */
    private function create(
        string $tenantId,
        string $kind,
        bool $fromPanicButton,
        ?int $guardId,
        ?string $raisedByName,
        ?int $raisedByAccountId,
        ?string $unitReference,
        ?float $latitude,
        ?float $longitude,
        ?CarbonInterface $deviceTime,
        Carbon $serverTime,
        bool $capturedOffline,
        ?string $idempotencyKey,
        bool $isSimulated,
        bool $isDrill,
        bool $drillMode,
    ): DuressAlert {
        $alert = DuressAlert::create([
            'tenant_id' => $tenantId,
            'kind' => $kind,

            // On the FIRST insert: the broadcast goes out from the `created` event, and it
            // has to know this is a panic to sound the siren (`DuressAlert::isPanic()`).
            'from_panic_button' => $fromPanicButton,

            'guard_id' => $guardId,
            'raised_by_name' => $raisedByName,

            /*
             * WHO RAISED IT, ON THE ROW FROM THE FIRST INSERT (M10 §1.2 item 7).
             *
             * `DuressController` used to write this in a second save after `record()`
             * returned — by which time the `created` announcement had already run the
             * whole fan-out. Nothing in the fan-out needed it then. The household's own
             * emergency contacts are the first leg that does: the account is the only
             * exact route from a panic to ONE household, and a leg that ran before the
             * second save would find no household and text nobody.
             */
            'raised_by_account_id' => $raisedByAccountId,

            'unit_reference' => $unitReference,
            'status' => 'open',
            'latitude' => $latitude,
            'longitude' => $longitude,

            /*
             * Both times are kept. The device's clock is never rewritten to
             * match the server's: a disagreement is evidence, and silently
             * correcting it destroys the only record that it happened.
             */
            'device_time' => $deviceTime,
            'server_time' => $serverTime,
            'clock_skewed' => $this->clockSkewed($deviceTime, $serverTime),

            'captured_offline' => $capturedOffline,
            'idempotency_key' => $idempotencyKey,
            'is_simulated' => $isSimulated,
            'is_drill' => $isDrill,

            // On the FIRST insert, like `from_panic_button`: the fan-out the `created` event runs asks it.
            'drill_mode' => $drillMode,
        ]);

        /*
         * THE BROADCAST IS NO LONGER SENT FROM HERE (M10 §1.2). It went out on this
         * line, after `create()` — which, with no transaction open, had already run the
         * whole fan-out inside the model's `created` event, so the consoles heard last.
         * `PushServiceProvider::announceAlert()` now sends it FIRST, from that same
         * event, once the row has committed, and then the fan-out, and then what it
         * reached (`AlertReachKnown`).
         *
         * Still only for a genuinely new alert: the idempotent early return above
         * creates nothing, so a retry from a flaky device raises no event and cannot
         * make the queue flash a second time for an alert already on screen. Still
         * rescued there (13 C1): a Reverb outage never becomes an error on the
         * raiser's handset.
         */

        return $alert;
    }

    /**
     * Record that someone has taken responsibility for this alert.
     *
     * Returns the alert unchanged if it already carries an acknowledgement or
     * has already been closed. Neither is an error: the dispatcher's screen
     * polls every three seconds, so a guard's acknowledgement can land between
     * the click and the request, and refusing it with an exception would show
     * a failure for something that in fact went right.
     */
    public function acknowledge(DuressAlert $alert, User $actor): DuressAlert
    {
        return $this->locked($alert, function (DuressAlert $row) use ($actor): void {
            if ($row->acknowledged_at !== null || $this->isClosed($row)) {
                return;
            }

            $this->acknowledgeRow($row, $actor);
        });
    }

    /** The acknowledgement itself, on a row read under its lock. */
    private function acknowledgeRow(DuressAlert $alert, User $actor): void
    {
        $before = ['status' => $alert->status, 'acknowledged_at' => null];

        $alert->forceFill([
            /*
             * NEVER A STEP BACKWARDS (M10 §1.3). An officer on shift may take a panic from
             * their own handset before anybody in the control room has — `AlertProgress`
             * then makes it `responding` with no acknowledgement. The control room taking
             * it afterwards is still worth recording, but "acknowledged" would read as
             * though the officer had stopped coming.
             */
            'status' => $alert->status === AlertProgress::RESPONDING ? AlertProgress::RESPONDING : 'acknowledged',
            'acknowledged_by' => $actor->id,
            'acknowledged_at' => Carbon::now(),
        ])->save();

        /*
         * Audited, because this is a claim about a life-safety response.
         * "Marcus was acknowledged as responding at 6:44" is a statement
         * someone may later have to answer for, and the log is the only place
         * that records who made it from a console rather than from a handset.
         */
        /*
         * `alert.*`, not `dispatch.*`.
         *
         * The audit log's reader classifies an entry by the domain the action
         * names before the dot, and `alert` is the domain it already knows as
         * Dispatch. A name of my own would still render, but under a category
         * of its own, and the log would start describing dispatch in two
         * vocabularies.
         */
        $this->audit->record(
            action: 'alert.acknowledged',
            entityType: 'DuressAlert',
            entityId: (string) $alert->id,
            before: $before,
            after: [
                'status' => $alert->status,
                'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            ],
            tenantId: $alert->tenant_id,
        );

        // Every screen showing it hears it, in this transaction (M11 Part C, DESIGN.md §2.4 A5): the estate's
        // bar stops, a second dispatcher's queue moves, the resident's timeline says the control room has it.
        LiveBus::recordAll(AlertLive::for($alert, 'acknowledged'));
    }

    /**
     * Close an alert out.
     *
     * `false_alarm` is a first-class outcome rather than a failure: a resident
     * who pressed panic and is safe has used the system correctly, and burying
     * that under a generic "resolved" would lose the one signal that tells
     * dispatch which alerts to review.
     *
     * `$classification` is one of AlertClassification's keys, chosen in the
     * console's resolve dialog. It is null only for callers that close an alert
     * without a dispatcher's judgement (the handset's own cancel).
     */
    public function resolve(DuressAlert $alert, User $actor, ?string $note = null, bool $falseAlarm = false, ?string $classification = null): DuressAlert
    {
        return $this->locked($alert, function (DuressAlert $row) use ($actor, $note, $falseAlarm, $classification): void {
            if ($this->isClosed($row)) {
                return;
            }

            $before = ['status' => $row->status, 'resolved_at' => null];

            $row->forceFill([
                'status' => $falseAlarm ? 'false_alarm' : 'resolved',
                'resolved_at' => Carbon::now(),
                'resolution_note' => $note,
                'resolution_classification' => $classification,

                /*
                 * Closing an alert nobody acknowledged still records who was
                 * there. Leaving acknowledged_by null would read, later, as though
                 * the alert closed itself.
                 */
                'acknowledged_by' => $row->acknowledged_by ?? $actor->id,
            ])->save();

            $this->audit->record(
                action: 'alert.resolved',
                entityType: 'DuressAlert',
                entityId: (string) $row->id,
                before: $before,
                after: [
                    'status' => $row->status,
                    'resolved_at' => $row->resolved_at?->toIso8601String(),
                    'resolution_note' => $note,
                    'resolution_classification' => $classification,
                ],
                tenantId: $row->tenant_id,
            );

            /*
             * THE STEP THE CLIENT NAMED (M11 §2, DESIGN.md §2.4 A9). The resolve wrote the row and broadcast
             * nothing, so every other open screen waited for its poll. Now, in this transaction: every console,
             * the estate's Security page and bar, the resident's panic screen and the guards' phones.
             */
            LiveBus::recordAll(AlertLive::for($row, $falseAlarm ? 'false_alarm' : 'resolved'));
        });
    }

    /**
     * DECIDE FROM THE ROW AS IT IS, UNDER ITS LOCK — `AlertProgress::step()`'s rule
     * (bug hunt B013).
     *
     * `acknowledge()` and `resolve()` decided from the copy of the alert the REQUEST had
     * loaded — the route binding's — and wrote over the row with it. A dispatcher's
     * Acknowledge landing a moment after a colleague's Dispatch (or an officer's "On my
     * way") put a `responding` alert back to `acknowledged` and re-stamped who took it and
     * when; two acknowledgements, or two resolves, both passed the check and both wrote,
     * the second over the first. So the row is read again with `lockForUpdate` in one
     * transaction, the decision is taken from THAT row, and a step that has already
     * happened is not taken twice. `duress_alerts` is mutable; the audit entry is only
     * ever inserted, and the pushes the save sets off go after the commit.
     *
     * The caller's copy is brought up to date either way, so a controller answering from
     * it never reads a stale column.
     *
     * @param  callable(DuressAlert): void  $decide
     */
    private function locked(DuressAlert $alert, callable $decide): DuressAlert
    {
        $row = DB::connection($alert->getConnectionName())->transaction(static function () use ($alert, $decide): DuressAlert {
            $row = DuressAlert::query()->whereKey($alert->getKey())->lockForUpdate()->firstOrFail();

            $decide($row);

            return $row;
        });

        $alert->setRawAttributes($row->getAttributes(), true);
        $alert->unsetRelation('respondingGuard');

        return $alert;
    }

    private function isClosed(DuressAlert $alert): bool
    {
        return in_array($alert->status, ['resolved', 'false_alarm'], true);
    }

    /**
     * Whether this alert was raised by this caller: the same estate, the same officer (or
     * none), the same Resident App account (or none). What makes a retry a retry.
     */
    private static function raisedBy(DuressAlert $alert, string $tenantId, ?int $guardId, ?int $raisedByAccountId): bool
    {
        $guard = $alert->guard_id === null ? null : (int) $alert->guard_id;
        $account = $alert->getAttribute('raised_by_account_id');

        return (string) $alert->tenant_id === $tenantId
            && $guard === $guardId
            && ($account === null ? null : (int) $account) === $raisedByAccountId;
    }

    /**
     * A key of this caller's own, derived from the one they sent — the same every time they
     * send it, so their retries find the alert their first press raised. 64 characters, the
     * column's width.
     */
    private static function ownKey(string $key, string $tenantId, ?int $guardId, ?int $raisedByAccountId): string
    {
        return hash('sha256', implode("\n", [$tenantId, (string) $guardId, (string) $raisedByAccountId, $key]));
    }

    /** The API's one rule (bug hunt B066): a duress delivered late from the queue is not a wrong clock. */
    private function clockSkewed(?CarbonInterface $deviceTime, CarbonInterface $serverTime): bool
    {
        return DeviceContext::skewed($deviceTime, $serverTime);
    }
}
