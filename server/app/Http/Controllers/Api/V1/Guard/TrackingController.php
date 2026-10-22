<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\Tracking\GuardTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ON-DUTY LOCATION SHARING, from the Guard App (M11 §3; D-174). The rules are GuardTracking's; this reads the
 * request and answers.
 *
 *   GET  guards/me/tracking            the flag, the policy version to show, this officer's answer, how to sample
 *   POST guards/me/tracking/consent    the officer's answer to the policy the phone showed
 *   POST shifts/{shift}/positions      what the phone sampled on this shift, in a batch
 */
class TrackingController extends Controller
{
    public function status(DeviceContext $context, GuardTracking $tracking): JsonResponse
    {
        return response()->json($tracking->status($context->guardOrFail()));
    }

    public function consent(Request $request, DeviceContext $context, GuardTracking $tracking): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'decision' => ['required', 'string', Rule::in([GuardTracking::GRANTED, GuardTracking::REFUSED])],
            'policy_version' => ['required', 'string', 'max:20'],
        ]);

        $tracking->decide($guard, (string) $data['decision'], (string) $data['policy_version'], $context);

        return response()->json($tracking->status($guard));
    }

    public function positions(Request $request, Shift $shift, DeviceContext $context, GuardTracking $tracking): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'positions' => ['required', 'array', 'min:1', 'max:'.(int) config('tracking.max_batch', 200)],
            'positions.*.id' => ['required', 'string', 'max:64'],
            'positions.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'positions.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'positions.*.accuracy_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'positions.*.source' => ['required', 'string', Rule::in(GuardTracking::SOURCES)],
            'positions.*.battery_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'positions.*.captured_at' => ['required', 'date'],
            'simulated' => ['nullable', 'boolean'],
        ]);

        if ((string) $shift->tenant_id !== $context->tenantId) {
            throw ApiError::notFound('not_found', 'No such shift.');
        }

        /** @var list<array<string, mixed>> $positions */
        $positions = array_values($data['positions']);

        $result = $tracking->record($guard, $shift, $positions, $context, $request->boolean('simulated'));

        return response()->json([
            'shift_id' => $shift->id,
            'accepted' => $result['accepted'],
            'refused' => $result['refused'],
        ]);
    }

    /** `POST presence/positions`: a batch of readings, answered one by one (GuardTracking::recordBatch; GUARD_TRACKING.md 5.1). */
    public function presence(Request $request, DeviceContext $context, GuardTracking $tracking): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'positions' => ['required', 'array', 'min:1', 'max:'.(int) config('tracking.max_batch', 200)],
            'positions.*.idempotency_key' => ['required', 'string', 'max:64'],
            'positions.*.captured_at' => ['required', 'date'],
            'positions.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'positions.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'positions.*.accuracy_metres' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'positions.*.battery_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'positions.*.is_moving' => ['nullable', 'boolean'],
            'positions.*.source' => ['required', 'string', Rule::in(GuardTracking::READING_SOURCES)],
            // What the phone says about its own queue (GUARD_TRACKING.md section 9), for the dispatcher.
            'backlog' => ['nullable', 'array'],
            'backlog.waiting' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'backlog.oldest_captured_at' => ['nullable', 'date'],
            'thinned' => ['nullable', 'array'],
            'thinned.count' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'thinned.from' => ['nullable', 'date'],
            'thinned.to' => ['nullable', 'date'],
            'simulated' => ['nullable', 'boolean'],
        ]);

        /** @var list<array<string, mixed>> $positions */
        $positions = array_values($data['positions']);

        $queue = ['backlog' => is_array($data['backlog'] ?? null) ? $data['backlog'] : null, 'thinned' => is_array($data['thinned'] ?? null) ? $data['thinned'] : null];

        return response()->json($tracking->recordBatch($guard, $positions, $context, $request->boolean('simulated'), $queue));
    }

    /** `GET presence/positions`: what is recorded about this guard today (GuardTracking::ownRecord; GUARD_TRACKING.md 5.2). */
    public function mine(DeviceContext $context, GuardTracking $tracking): JsonResponse
    {
        return response()->json($tracking->ownRecord($context->guardOrFail(), $context));
    }
}
