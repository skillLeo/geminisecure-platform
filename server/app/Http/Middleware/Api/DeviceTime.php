<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Api\ApiError;
use App\Api\DeviceContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Device time beside server time, on every write (13 D2).
 *
 *   device.time            `X-Device-Time` is required
 *   device.time:optional   accepted and echoed when sent (the first seven endpoints)
 *
 * THE DEVICE CLOCK IS KEPT AND NEVER CORRECTED. The handset says when it thinks
 * the act happened — for an act captured offline, that may be an hour before the
 * request — and the server records its own time beside it. Every write response
 * carries both and `clock_skewed`, true when they disagree by more than two
 * minutes, so the app can tell a guard their phone is wrong. A disagreement is
 * evidence about a handset, and correcting it silently would destroy the only
 * record that it happened.
 */
final class DeviceTime
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $raw = $request->header('X-Device-Time') ?? $request->input('device_time');
        $deviceTime = null;

        if ($raw !== null && $raw !== '') {
            try {
                // The offset is honoured and the MOMENT is kept, in UTC (M4 §3.1). Carried in the
                // phone's own zone, it was written to a record as that zone's digits — five hours
                // out — wherever a model, not a query, did the writing.
                $deviceTime = Carbon::parse((string) $raw)->utc();
            } catch (Throwable) {
                throw ApiError::unprocessable('device_time_invalid', 'X-Device-Time must be an ISO 8601 timestamp with its offset, e.g. 2026-09-17T09:41:02-05:00.');
            }
        } elseif ($mode === 'required') {
            throw ApiError::unprocessable('device_time_required', 'Send the handset\'s own clock in X-Device-Time on every write, so the record keeps both times.');
        }

        $context = app(DeviceContext::class)->withDeviceTime($deviceTime);
        app()->instance(DeviceContext::class, $context);

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $data = $response->getData(true);

            if (is_array($data) && ! array_is_list($data)) {
                $response->setData([
                    ...$data,
                    'server_time' => $data['server_time'] ?? $context->serverTime->toIso8601String(),
                    'device_time' => $data['device_time'] ?? $context->deviceTime?->toIso8601String(),
                    'clock_skewed' => $data['clock_skewed'] ?? $context->clockSkewed(),
                ]);
            }
        }

        return $response;
    }
}
