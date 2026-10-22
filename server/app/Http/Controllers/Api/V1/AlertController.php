<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Unit;
use App\Services\Dispatch\AlertIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/alerts - raise a panic, duress, medical, fire or intrusion alert.
 *
 * Consumed by the Guard App and the Resident App. The simulator calls this
 * same endpoint, so nothing built against simulated data has to be rewritten
 * when the real apps arrive.
 *
 * NOTHING here returns a monetary amount, and nothing it touches holds one.
 * That is invariant 2 enforced structurally rather than by remembering to omit
 * a field.
 *
 * THE ESTATE AND THE RAISER COME FROM THE TOKEN (13 D1). `tenant_id` and
 * `guard_id` were taken from the body, so a guard's handset could raise an alert
 * in another estate's name. They are still accepted — the simulator and the
 * first handset builds send them — and refused when they disagree with the token.
 */
class AlertController extends Controller
{
    public function store(Request $request, AlertIntake $intake, DeviceContext $context): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'string', 'max:64'],
            'kind' => ['required', 'string', 'in:panic,duress,medical,fire,intrusion'],
            'guard_id' => ['nullable', 'integer'],
            'raised_by_name' => ['nullable', 'string', 'max:160'],
            'unit_reference' => ['nullable', 'string', 'max:32'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'device_time' => ['nullable', 'date'],
            'captured_offline' => ['nullable', 'boolean'],

            /*
             * Client-generated, and the whole point of it is that the client
             * chooses it before the first attempt. Required, because an
             * unkeyed retry from a flaky connection duplicates a panic alert.
             */
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        if (($data['tenant_id'] ?? $context->tenantId) !== $context->tenantId
            || (isset($data['guard_id']) && (int) $data['guard_id'] !== $context->guard?->id)) {
            throw ApiError::forbidden('wrong_site', 'This handset raises alerts for its own estate and its own guard, and no other.');
        }

        $key = (string) ($request->header('Idempotency-Key') ?? $data['idempotency_key'] ?? '');

        if ($key === '') {
            throw ApiError::unprocessable('idempotency_key_required', 'An alert needs an Idempotency-Key: an unkeyed retry from a flaky connection duplicates a panic.');
        }

        $resident = $context->resident;

        /*
         * A RESIDENT'S ALERT CARRIES THEIR OWN HOME, FROM THE TOKEN (review, M6 §1).
         *
         * `unit_reference` is the ADDRESS on the alert — the line a dispatcher reads when
         * deciding where to send people. It used to be taken from the body whenever the body
         * sent one, and only derived from the token when it was omitted, so a resident could
         * raise a panic naming any lot on their estate: guards sent to a neighbour's door, and
         * the estate's permanent record of the alert naming a household that never pressed
         * anything. `DuressController` — the endpoint the current apps actually use — has
         * always derived it from the token, and this is the same rule.
         *
         * IGNORED, NOT REFUSED. A mismatched field must never cost somebody their panic: a
         * stale unit label cached on a handset would turn an emergency into a 403. The
         * server simply uses what it knows and says nothing about what it was told.
         *
         * A GUARD may still name a unit — an intrusion at Lot 12 is reported by the officer
         * standing at it, and the token says which officer that is.
         */
        $unitReference = $resident !== null
            ? ($resident->unit_id === null ? null : Unit::query()->whereKey($resident->unit_id)->value('reference'))
            : ($data['unit_reference'] ?? null);

        $alert = $intake->record(
            tenantId: $context->tenantId,
            kind: $data['kind'],
            guardId: $context->guard?->id,
            raisedByName: $resident !== null ? $resident->full_name : ($data['raised_by_name'] ?? null),
            unitReference: $unitReference,
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            deviceTime: $context->deviceTime,
            capturedOffline: (bool) ($data['captured_offline'] ?? false),
            idempotencyKey: $key,
            isSimulated: $request->boolean('simulated'),
        );

        return response()->json([
            'id' => $alert->id,
            'status' => $alert->status,
            'server_time' => $alert->server_time->toIso8601String(),
            'clock_skewed' => $alert->clock_skewed,
        ], 201);
    }
}
