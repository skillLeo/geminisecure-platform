<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\DuressAlertRingEvent;
use App\Services\Ring\RingAnswers;
use App\Services\Ring\RingTicket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * A RUNG PHONE ANSWERS, AND SAYS WHAT IT DID (the guard ring §6.6).
 *
 *   POST /ring/answer    "I'm responding" or "can't respond", on the ring's ticket
 *   POST /ring/receipt   shown, full screen, opened, held, dismissed, stopped, suppressed,
 *                        fallback — the phone's own word for what it did with a ring
 *   POST /ring/test      a test ring to this sign-in's own phone (bearer token)
 *
 * THE FIRST TWO TAKE NO BEARER TOKEN. The escalation rings off-duty officers whose Guard
 * App session timed out thirty minutes after they last used it, and a ring they cannot
 * answer is only half a ring. The ring ticket carried in the push is their credential —
 * signed, thirty minutes long, bound to one push address and one guard, and checked
 * against the rows every time (`RingAnswers::caller()`). Limited per ticket AND per
 * address, so forged tickets cannot each have a bucket of their own (G28).
 */
class RingController extends Controller
{
    public function answer(Request $request, RingAnswers $answers): JsonResponse
    {
        $data = $request->validate([
            'rt' => ['required', 'string', 'max:'.RingTicket::MAX_LENGTH],
            'answer' => ['required', 'string', 'in:'.RingAnswers::RESPONDING.','.RingAnswers::CANNOT],
            'age_ms' => ['required_if:answer,'.RingAnswers::RESPONDING, 'nullable', 'integer', 'min:0'],
            'scope' => ['nullable', 'string', 'in:'.RingAnswers::SCOPE_ROUND.','.RingAnswers::SCOPE_ALERT],
        ]);

        $deviceTime = self::deviceTime($request);

        $body = $answers->answer(
            $data['rt'],
            (string) $data['answer'],
            isset($data['age_ms']) ? (int) $data['age_ms'] : null,
            (string) ($data['scope'] ?? RingAnswers::SCOPE_ALERT),
            $deviceTime,
        );

        return response()->json([
            ...$body,
            'server_time' => Carbon::now()->toIso8601String(),
            'device_time' => $deviceTime?->toIso8601String(),
        ]);
    }

    public function receipt(Request $request, RingAnswers $answers): JsonResponse
    {
        $data = $request->validate([
            'rt' => ['required', 'string', 'max:'.RingTicket::MAX_LENGTH],
            'event' => ['required', 'string', 'in:'.implode(',', DuressAlertRingEvent::RECEIPTS)],
            'detail' => ['nullable', 'string', 'max:'.DuressAlertRingEvent::DETAIL_LENGTH],
        ]);

        // Checked before anything is written, as every write's device time is.
        self::deviceTime($request);

        // `ready` is read raw and parsed leniently (G15): a mask the server cannot read is ignored, never refused.
        $answers->receipt($data['rt'], (string) $data['event'], isset($data['detail']) ? (string) $data['detail'] : null, $request->input('ready'));

        return response()->json(['recorded' => true], 202);
    }

    public function test(DeviceContext $context, RingAnswers $answers): JsonResponse
    {
        return response()->json($answers->test($context, $this->accessTokenId()));
    }

    /** The Sanctum token this request was made with — the one sign-in whose phone is tested. */
    private function accessTokenId(): ?int
    {
        $principal = Auth::guard('sanctum')->user();
        $token = $principal !== null && method_exists($principal, 'currentAccessToken') ? $principal->currentAccessToken() : null;

        return $token instanceof Model ? (int) $token->getKey() : null;
    }

    /**
     * `X-Device-Time`, or `device_time` in the body, exactly as `DeviceTime` reads it: the
     * offset honoured, the moment kept in UTC. Kept for the audit only; staleness is the
     * phone's monotonic `age_ms`, never this.
     *
     * @throws ApiError
     */
    private static function deviceTime(Request $request): ?Carbon
    {
        $raw = $request->header('X-Device-Time') ?? $request->input('device_time');

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $raw)->utc();
        } catch (Throwable) {
            throw ApiError::unprocessable('device_time_invalid', 'X-Device-Time must be an ISO 8601 timestamp with its offset, e.g. 2026-09-17T09:41:02-05:00.');
        }
    }
}
