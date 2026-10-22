<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Live\Events\AlertLive;
use App\Live\LiveBus;
use App\Models\DuressAlert;
use App\Models\Estate\Unit;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\PanicAudio;
use App\Services\Dispatch\PanicDrillMode;
use App\Services\Dispatch\PanicEmailOutcome;
use App\Services\Dispatch\PanicMessages;
use App\Services\Dispatch\PanicPositions;
use App\Services\Dispatch\PanicSmsOutcome;
use App\Services\Ring\RingReach;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Duress, from either app, and its ten-second cancel (13 D2, D3).
 *
 * Board guard-app-03 screen 14 and resident-app's panic. The alert goes through
 * `AlertIntake::record` â€” the path every alert takes â€” so dispatch sees a duress
 * from a handset exactly as it sees one from the simulator.
 *
 * SILENT OR AUDIBLE is the handset's choice and changes nothing at dispatch: a
 * silent duress is the one where the guard cannot be seen raising it, and the
 * console treats both as the same emergency.
 *
 * THE CANCEL WINDOW IS TEN SECONDS AND IS THE SERVER'S. A press in a pocket is
 * cancellable for ten seconds from when the server received it, and not once a
 * dispatcher has acknowledged it â€” after that a person is already responding,
 * and "cancel" is a conversation with dispatch, not a button.
 */
class DuressController extends Controller
{
    public const CANCEL_WINDOW_SECONDS = 10;

    public function store(Request $request, DeviceContext $context, AlertIntake $intake): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', 'string', 'in:silent,audible'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'captured_offline' => ['nullable', 'boolean'],

            /*
             * WHAT KIND OF EMERGENCY, IF THEY CAN SAY — M10 §1, and NEVER
             * required.
             *
             * The client's reasoning is the whole design here: "When people are
             * in a panic they don't remember to do the things they're supposed
             * to do." So the button works with nothing attached, and a resident
             * who manages to say "medical" gets that in front of the responder.
             *
             * `security` maps to the plain `panic` kind rather than adding a
             * sixth value: a security emergency IS what the button has always
             * meant, and an unlabelled one goes to everyone exactly the same.
             */
            'type' => ['nullable', 'string', 'in:medical,fire,security,other'],
        ]);

        $guard = $context->guard;
        $resident = $context->resident;

        /*
         * A GUARD'S IS ALWAYS DURESS. The kind is derived from the token and a
         * guard cannot override it: `duress` means an officer is under threat and
         * carries the silent-mode handling that goes with it, and letting a
         * handset relabel that would let a compromised one hide what it was.
         *
         * A resident's is `panic` unless they chose otherwise.
         */
        $kind = $guard !== null
            ? 'duress'
            : match ($data['type'] ?? null) {
                'medical' => 'medical',
                'fire' => 'fire',
                default => 'panic',
            };

        $alert = $intake->record(
            tenantId: $context->tenantId,
            kind: $kind,
            guardId: $guard?->id,
            raisedByName: $resident?->full_name,
            unitReference: $resident?->unit_id === null ? null : Unit::query()->whereKey($resident->unit_id)->value('reference'),
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            deviceTime: $context->deviceTime,
            capturedOffline: (bool) ($data['captured_offline'] ?? false),
            idempotencyKey: $request->header('Idempotency-Key'),
            isSimulated: $request->boolean('simulated'),

            // On the row from its first insert, so the household's own emergency
            // contacts can be found by the fan-out that insert sets off (M10 §1.2 item 7).
            raisedByAccountId: $resident?->id,

            /*
             * THE BUTTON, SO THE LABEL NEVER MAKES IT QUIETER (M10 §1.3, bug hunt B086).
             * A press labelled "medical" or "fire" keeps that kind for the responder, and
             * is broadcast urgent, sounds the siren, sits on the Panic alerts tab and
             * sorts with the panics — exactly as the same press with no label does.
             */
            fromPanicButton: true,

            /*
             * DRILL MODE (M53 §3A.1): from the account's own switch, which only `panic:drill-mode` sets — nothing in this
             * request can make a press a drill. A resident's only; an officer's duress is never one. And never a press
             * that waited on the phone for a signal: nobody can say the switch was on when it was pressed.
             */
            drillMode: $guard === null && PanicDrillMode::appliesTo($resident, (bool) ($data['captured_offline'] ?? false)),
        );

        /*
         * THE MODE, AND NOTHING ELSE (bug hunt B095). `record()` only ever hands back an
         * alert THIS caller raised — a key another estate's alert holds is a new alert —
         * and the raiser's account is already on the row from its first insert. This used
         * to write `raised_by_account_id` again, onto whatever alert the key had returned,
         * which was once another estate's.
         */
        if ($alert->getAttribute('mode') === null) {
            $alert->forceFill([
                'mode' => $data['mode'] ?? 'silent',
            ])->save();
        }

        return response()->json($this->shape($alert), 201);
    }

    public function cancel(Request $request, int $alert, DeviceContext $context): JsonResponse
    {
        // Optional (mobile-api, 2026-09-19): why it was a false alarm, in the person's own words, kept
        // on the alert's resolution note where dispatch already reads it. Cancelling never REQUIRES it
        // here — a pocket press must be cancellable in ten seconds — the apps ask for it.
        $reason = trim((string) ($request->validate(['reason' => ['nullable', 'string', 'max:160']])['reason'] ?? ''));

        $record = DuressAlert::query()->whereKey($alert)->where('tenant_id', $context->tenantId)
            ->when($context->guard !== null, fn ($q) => $q->where('guard_id', $context->guard?->id))
            ->when($context->resident !== null, fn ($q) => $q->whereNull('guard_id')->where('raised_by_account_id', $context->resident?->id))
            ->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No alert of yours with that id.');
        }

        /*
         * DECIDED FROM THE ROW AS IT IS, UNDER ITS LOCK (bug hunt B094) — `AlertProgress::step()`'s
         * rule. The row above was read to find it; an officer's "On my way" or a dispatcher's
         * resolve can commit between that read and this write, and a cancel written from the
         * earlier copy closed an alert an officer was running to, or overwrote the control
         * room's resolution and note. So it is read again with `lockForUpdate`, and every check
         * below asks THAT row. A refusal thrown in here rolls the transaction back.
         */
        $record = $record->getConnection()->transaction(function () use ($record, $reason): DuressAlert {
            $row = DuressAlert::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            /*
             * ALREADY CLOSED — by this phone's own earlier cancel, or by the control room.
             * Answered with the alert as it is, changed in nothing: the phone learns the
             * alert is closed, and how, and the dispatcher's outcome and note stand. It used
             * to be overwritten with "false alarm" and a new resolution time, and everybody
             * was pushed "closed" a second time.
             */
            if ($row->getAttribute('cancelled_at') !== null || in_array($row->status, ['resolved', 'false_alarm'], true)) {
                return $row;
            }

            /*
             * AN OFFICER ON SHIFT MAY TAKE A PANIC FROM THEIR OWN HANDSET BEFORE THE CONTROL ROOM
             * DOES (`AlertProgress`), inside these ten seconds. Cancelling then would close an
             * alert an officer is running to, and nobody would tell them to stop. Asked FIRST: since
             * M57 §5.2 that tap records the acknowledgement with it, and "an officer is on the way"
             * is the truer sentence of the two.
             */
            if ($row->en_route_at !== null || $row->on_scene_at !== null) {
                throw ApiError::conflict('already_responding', 'An officer is already on the way to this alert, so it can no longer be cancelled from the phone. Call dispatch to stand them down.');
            }

            /*
             * SAID AS IT IS (M10 §1.3). This sentence read "…and someone is responding" on a
             * bare acknowledgement — the claim the acknowledgement push made, and just as untrue:
             * the control room having an alert is not an officer coming.
             */
            if ($row->acknowledged_at !== null) {
                throw ApiError::conflict('already_acknowledged', 'The control room has already taken this alert, so it can no longer be cancelled from the phone. Call dispatch to stand it down.');
            }

            if ($row->server_time->copy()->addSeconds(self::CANCEL_WINDOW_SECONDS)->lessThan(Carbon::now())) {
                throw ApiError::conflict('cancel_window_closed', 'An alert can be cancelled for '.self::CANCEL_WINDOW_SECONDS.' seconds after it is raised. Call dispatch to stand it down.');
            }

            $row->forceFill([
                'cancelled_at' => Carbon::now(),
                'status' => 'false_alarm',
                'resolved_at' => Carbon::now(),
                'resolution_note' => 'Cancelled from the handset within '.self::CANCEL_WINDOW_SECONDS.' seconds.'.($reason === '' ? '' : ' Reason given: '.$reason),
            ])->save();

            // Every screen showing it stands down, in this transaction (M11 Part C, DESIGN.md §2.4 A10).
            LiveBus::recordAll(AlertLive::for($row, 'cancelled'));

            return $row;
        });

        return response()->json($this->shape($record));
    }

    /**
     * Read an alert back — `GET /duress/{alert}` (mobile-api, 2026-09-19).
     *
     * The Resident App's panic board draws a timeline — sent, acknowledged,
     * responding, resolved — and the Guard App must learn that dispatch has its
     * duress. Until now the status came back only in the answer to the press
     * itself, so neither app could ever show anything after "sent". A read, of
     * the caller's OWN alert only, in exactly the shape `store` and `cancel`
     * already answer with: nothing new is disclosed, and somebody else's alert
     * is the same 404 as an id nobody has.
     */
    public function show(int $alert, DeviceContext $context, PanicMessages $messages): JsonResponse
    {
        $record = DuressAlert::query()->whereKey($alert)->where('tenant_id', $context->tenantId)
            ->when($context->guard !== null, fn ($q) => $q->where('guard_id', $context->guard?->id))
            ->when($context->resident !== null, fn ($q) => $q->whereNull('guard_id')->where('raised_by_account_id', $context->resident?->id))
            ->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No alert of yours with that id.');
        }

        // The resident's phone is reading it: every reply from the control room on it has now been shown (M50 §4.2 #2).
        if ($context->resident !== null) {
            $messages->seenByResident($record);
        }

        return response()->json($this->shape($record));
    }

    /**
     * "Message dispatch…" — the resident writes to the control room on their own open panic (M50 §4.2 #2). The answer
     * is the alert, its thread included, as `duress.show` reads it. See `PanicMessages`.
     */
    public function message(Request $request, int $alert, DeviceContext $context, PanicMessages $messages): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'client_key' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $account = $context->residentOrFail();
        $record = DuressAlert::query()->whereKey($alert)->where('tenant_id', $context->tenantId)
            ->whereNull('guard_id')->where('raised_by_account_id', $account->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No alert of yours with that id.');
        }

        $messages->fromResident($record, $account, (string) $data['body'], $data['client_key'] ?? null);

        return response()->json($this->shape($record), 201);
    }

    /**
     * Where the phone that raised this alert is now, while it stays open (12 Oct 2026; GUARD_TRACKING.md 5.3): the
     * caller's OWN alert only, a resident's panic or a guard's duress. See `PanicPositions`.
     */
    public function positions(Request $request, int $alert, DeviceContext $context, PanicPositions $positions): JsonResponse
    {
        $data = $request->validate([
            'positions' => ['required', 'array', 'min:1', 'max:'.PanicPositions::MAX_BATCH],
            'positions.*.idempotency_key' => ['required', 'string', 'max:64'],
            'positions.*.captured_at' => ['required', 'date'],
            'positions.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'positions.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'positions.*.accuracy_metres' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'simulated' => ['nullable', 'boolean'],
        ]);

        $record = DuressAlert::query()->whereKey($alert)->where('tenant_id', $context->tenantId)
            ->when($context->guard !== null, fn ($q) => $q->where('guard_id', $context->guard?->id))
            ->when($context->resident !== null, fn ($q) => $q->whereNull('guard_id')->where('raised_by_account_id', $context->resident?->id))
            ->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No alert of yours with that id.');
        }

        /** @var list<array<string, mixed>> $readings */
        $readings = array_values($data['positions']);

        return response()->json($positions->record($record, $readings, $context, $request->boolean('simulated')));
    }

    /**
     * "Share live audio" — one piece of what the resident's phone hears, on their own open panic (M50 §4.2 #1).
     * Multipart: `file`, `seq`, `duration_ms`. See `PanicAudio`.
     */
    public function audio(Request $request, int $alert, DeviceContext $context, PanicAudio $audio): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.PanicAudio::MAX_KB, 'mimetypes:'.implode(',', PanicAudio::MIMES)],
            'seq' => ['required', 'integer', 'min:0', 'max:100000'],
            'duration_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600000'],
        ]);

        $account = $context->residentOrFail();
        $record = DuressAlert::query()->whereKey($alert)->where('tenant_id', $context->tenantId)
            ->whereNull('guard_id')->where('raised_by_account_id', $account->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No alert of yours with that id.');
        }

        $piece = $audio->store($record, $account, $request->file('file'), (int) $data['seq'], isset($data['duration_ms']) ? (int) $data['duration_ms'] : null);

        return response()->json(['seq' => (int) $piece->seq, 'received' => true], 201);
    }

    /**
     * @return array<string, mixed>
     *
     * WHAT THE ALERT REACHED IS PART OF THE ALERT (M6 §2.1 part 5).
     *
     * `console_alerted` is true because this row exists: the control room's copy of a
     * panic is the committed row and the `AlertRaised` broadcast, neither of which
     * depends on any handset, any roster or any fallback (M6 §2.1 part 1).
     *
     * `alerted_handsets` is passed through EXACTLY as `Services\Push\PanicFanOut` wrote
     * it, including null. Null means the fan-out has not finished — the app says "still
     * reaching your officers" and polls. Zero is the positive statement that no handset
     * was reached, and the panic screen says that plainly with 119 given prominence. A
     * `?? 0` here would turn "we do not know yet" into "nobody came", and a `?? 1` would
     * be the lie this whole rule exists to prevent; so neither is written.
     */
    private function shape(DuressAlert $alert): array
    {
        $cancelledAt = $alert->getAttribute('cancelled_at');
        $handsets = $alert->getAttribute('alerted_handsets');
        $escalated = $alert->getAttribute('escalated_contacts');
        $smsRecipients = $alert->getAttribute('sms_recipients');
        $smsDelivered = $alert->getAttribute('sms_delivered');

        return [
            'id' => $alert->id,
            'kind' => $alert->kind,
            'mode' => $alert->getAttribute('mode'),
            'status' => $alert->status,
            'cancellable_until' => $alert->server_time->copy()->addSeconds(self::CANCEL_WINDOW_SECONDS)->toIso8601String(),
            'cancelled_at' => $cancelledAt === null ? null : Carbon::parse((string) $cancelledAt)->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),

            /*
             * THE REST OF THE HONEST STATE (M10 §1.3): alerting → acknowledged → dispatched →
             * en route → on scene → resolved. ADDED beside `acknowledged_at`, never instead of
             * it — both apps already read it. Each is passed through as recorded, null included:
             * an officer who ran straight to the lot has an `on_scene_at` and no `en_route_at`,
             * and the screen must draw that step as not recorded rather than as done.
             * `dispatched_at` is the first of these that makes "help is on the way" true.
             */
            'dispatched_at' => $alert->dispatched_at?->toIso8601String(),
            'en_route_at' => $alert->en_route_at?->toIso8601String(),
            'on_scene_at' => $alert->on_scene_at?->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),

            'server_time' => $alert->server_time->toIso8601String(),
            'device_time' => $alert->device_time?->toIso8601String(),
            'clock_skewed' => $alert->clock_skewed,
            'console_alerted' => true,
            'alerted_handsets' => $handsets === null ? null : (int) $handsets,
            'alerted_via' => $alert->getAttribute('alerted_via'),
            'escalated_contacts' => $escalated === null ? null : (int) $escalated,

            /*
             * THE CALL-STYLE RING (M18 §1.6). `alerted_handsets` is the RAISE's count, and it stays exactly that. When it
             * is 0 the ring does not stop there: it rings every active officer of the estate at once (round 1,
             * `GuardRing::begin`). On production (alerts 18 and 19) the resident's screen went on saying "No officer's
             * phone was reached" while, seconds later, an officer's phone was ringing. This is how many officer phones
             * the ring has been SENT to so far — any round — or null while it has not begun (a demonstration estate,
             * a drill, the ring switched off). Sent, like `alerted_handsets`: not that anybody answered.
             */
            'rang_handsets' => RingReach::rang($alert),
            'rang_at' => RingReach::rangAt($alert),

            /*
             * WHO WAS CLOCKED ON AT THE RAISE (M18A §1.2), so the screen can tell "nobody is on duty" from "officers are
             * on duty but their phones could not be reached". Null on an alert raised before it was recorded, or while
             * the fan-out is still running: the phone then claims neither.
             */
            'on_duty_officers' => ($onDuty = $alert->getAttribute('on_duty_officers')) === null ? null : (int) $onDuty,

            /*
             * THE TEXTS (Q-M09), and the same null rule as `alerted_handsets`.
             *
             * `sms_delivered` counts only what a PROVIDER ACCEPTED. There is no provider on
             * this platform today, so it is 0 — and 0 is what the app must be told, because
             * an app that read "four numbers were on file" as "four people were texted"
             * would be the same lie about a text that "help is coming" is about a handset.
             * A text is never a handset and never raises `alerted_handsets`.
             */
            'sms_recipients' => $smsRecipients === null ? null : (int) $smsRecipients,
            'sms_delivered' => $smsDelivered === null ? null : (int) $smsDelivered,

            /*
             * WHAT THE TEXTS ACTUALLY DID, IN ONE PLACE (M10 §1) — added beside the two
             * counts, never instead of them, because both apps already read those.
             *
             * `sms_recipients` is how many numbers were on file, and nothing stops a
             * screen writing "4 people texted" from it. This object is built from the
             * `alert_sms_dispatches` rows and `SmsSettings::canSend()`, and carries the
             * sentence to show — so the resident's panic screen never has to compose a
             * claim about a text out of a number. See `PanicSmsOutcome`.
             */
            'sms' => app(PanicSmsOutcome::class)->of($alert),

            /*
             * THE EMAILS TO THE HOUSEHOLD'S OWN CONTACTS (M12 §2.5), counted from `alert_email_dispatches` rows —
             * `sent` only when the mail server accepted one. Null on an officer's duress. See `PanicEmailOutcome`.
             */
            'email' => ['household' => app(PanicEmailOutcome::class)->of($alert)],

            /*
             * A DRILL (M10 §1.3). Only `panic:walk` raises one and no handset can, so a
             * resident will almost never see it — but the status screen is one of the
             * places an alert renders, and a drill must say so wherever it does.
             */
            'is_drill' => (bool) $alert->is_drill,

            /*
             * RAISED IN DRILL MODE (M53 §3A.1): the office had drill mode on for this account, so no officer's phone rang,
             * nobody was mailed or texted and no siren sounded. The counts above stay null for ever (nothing was sent), so
             * the screen says this instead of waiting on them, and offers the emergency number in case it is real.
             */
            'drill_mode' => $alert->wakesNobody(),

            /*
             * THE LIVE CHANNEL WITH DISPATCH (M50 §4.2 #2): what the resident and the control room wrote on this alert,
             * oldest first. Empty on an officer's duress, whose channel is the officer's own.
             */
            'messages' => app(PanicMessages::class)->forResident($alert),
        ];
    }
}
