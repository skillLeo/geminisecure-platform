<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\GuardRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Leave, equipment and shift-swap requests (13 D2). Board guard-app-05 screen 19.
 *
 * The same `guard_requests` rows board 29's inbox decides, so a request raised
 * on a handset is in front of a dispatcher the moment it is sent, and the
 * decision — who made it, and the note — comes back on the next read.
 *
 * A CERTIFICATE IS A FLAG, NOT A FILE. `certificate_attached` says one exists;
 * a medical certificate is a health record and has no business on a platform
 * that only needs to know a supervisor has seen one.
 */
class RequestsController extends Controller
{
    private const KINDS = ['leave', 'equipment', 'shift_swap'];

    private const LEAVE_SUBJECTS = ['vacation', 'sick', 'bereavement', 'maternity', 'paternity', 'unpaid', 'other'];

    public function index(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $items = GuardRequest::query()->where('guard_id', $guard->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (GuardRequest $r): array => $this->shape($r))
            ->all();

        $takenThisYear = GuardRequest::query()->where('guard_id', $guard->id)
            ->where('kind', GuardRequest::LEAVE)->where('status', 'approved')
            ->whereYear('starts_on', Carbon::now()->year)
            ->get()
            ->sum(static fn (GuardRequest $r): int => (int) $r->days());

        return response()->json([
            'items' => $items,
            'leave' => [
                'entitlement_days' => (int) ($guard->leave_entitlement_days ?? 0),
                'approved_days_this_year' => $takenThisYear,
                'remaining_days' => max(0, (int) ($guard->leave_entitlement_days ?? 0) - $takenThisYear),
            ],
        ]);
    }

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', self::KINDS)],
            'subject' => ['required', 'string', 'max:80'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:50', 'required_if:kind,equipment'],
            'starts_on' => ['nullable', 'date', 'required_if:kind,leave'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on', 'required_if:kind,leave'],
            // Nothing is optional (M18C): a leave request always says why, vacation included.
            'reason' => ['nullable', 'string', 'max:190', 'required_if:kind,leave'],
            'certificate_attached' => ['nullable', 'boolean'],
        ], [
            'reason.required_if' => 'Say why you need the time off — a few words is enough.',
        ]);

        if ($data['kind'] === 'leave' && ! in_array($data['subject'], self::LEAVE_SUBJECTS, true)) {
            throw ApiError::unprocessable('leave_type_unknown', 'Leave is one of: '.implode(', ', self::LEAVE_SUBJECTS).'.');
        }

        $row = new GuardRequest;
        $row->forceFill([
            'tenant_id' => $context->tenantId,
            'guard_id' => $guard->id,
            'kind' => $data['kind'],
            'subject' => $data['subject'],
            'quantity' => $data['quantity'] ?? null,
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'reason' => $data['reason'] ?? null,
            'certificate_attached' => (bool) ($data['certificate_attached'] ?? false),
            'status' => 'pending',
            'is_simulated' => $request->boolean('simulated'),
        ])->save();

        return response()->json([
            ...$this->shape($row->refresh()),
            'server_time' => $context->serverTime->toIso8601String(),
            'device_time' => $context->deviceTime?->toIso8601String(),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function shape(GuardRequest $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'subject' => $r->subject,
            'quantity' => $r->quantity,
            'starts_on' => $r->starts_on?->toDateString(),
            'ends_on' => $r->ends_on?->toDateString(),
            'days' => $r->days(),
            'reason' => $r->reason,
            'certificate_attached' => $r->certificate_attached,
            'status' => $r->status,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
