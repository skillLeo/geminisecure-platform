<?php

declare(strict_types=1);

namespace App\Api;

use App\Services\Dispatch\ShiftClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * WHEN AN ACT QUEUED ON A PHONE ACTUALLY HAPPENED, and whether the phone's clock can be believed (M12 §1.4).
 *
 * `X-Device-Time` is fixed when the act is captured — it IS when the guard tapped — so on its own it cannot tell a
 * phone whose clock is wrong from an act that waited an hour for signal (DeviceContext::skewed() said exactly that).
 * The phones now also send, on every request, what their clock reads AT THE MOMENT OF SENDING:
 *
 *   X-Device-Sent-At   the phone's wall clock as the request leaves it. The server's clock minus this is the
 *                      phone's error — ahead or behind — to within the network's latency.
 *   X-Device-Run       an id the app picks each time it starts, and
 *   X-Device-Mono      its steady clock (milliseconds since that start), which no one can set.
 *
 * and a queued act carries the run and the steady clock of its CAPTURE in its body. So:
 *
 *   captured_at   the capture on the SERVER's clock: X-Device-Time moved by the phone's error. A queued clock-in
 *                 starts when the guard tapped, not when the signal came back — and never in the future.
 *   offset_s      the phone's error, kept on the record.
 *   live          sent within the tolerance of the tap: the guard is at the phone now, so a refusal can be shown
 *                 to them. An act that waited is recorded and flagged instead of refused.
 *   review        `clock_disagrees` past the tolerance; `clock_changed` when, in one run of the app, the wall clock
 *                 moved by a different amount from the steady clock between the tap and the send — somebody set it.
 *
 * A build that sends none of this (an older app in the field) is judged exactly as before: its device time is used
 * as sent, and only a time AHEAD of the server's is called a wrong clock.
 */
final class DeviceClock
{
    /** How far the wall clock and the steady clock may drift apart in one run before the clock counts as changed. */
    public const CHANGED_TOLERANCE_S = 60;

    /** An act older than this is not placed at its device time: a clock that far out is not a clock. */
    public const MAX_AGE_S = 86_400;

    /**
     * @return array{captured_at: Carbon, device_at: Carbon|null, offset_s: int|null, live: bool, review: list<string>}
     */
    public static function read(Request $request, DeviceContext $context, ?string $capturedRun = null, ?int $capturedMono = null): array
    {
        $server = $context->serverTime->copy()->utc();
        $device = $context->deviceTime?->copy()->utc();
        $sent = self::instant($request->header('X-Device-Sent-At'));
        $offset = $sent === null ? null : $server->getTimestamp() - $sent->getTimestamp();
        $review = [];

        if ($offset !== null ? abs($offset) > Catalogue::CLOCK_SKEW_SECONDS : DeviceContext::skewed($device, $server)) {
            $review[] = ShiftClock::REVIEW_CLOCK_DISAGREES;
        }

        $run = $request->header('X-Device-Run');
        $mono = $request->header('X-Device-Mono');

        if ($device !== null && $sent !== null && is_string($run) && $capturedRun !== null && $run === $capturedRun && is_numeric($mono) && $capturedMono !== null) {
            $steady = ((int) $mono - $capturedMono) / 1000;
            $wall = $sent->getTimestamp() - $device->getTimestamp();

            if (abs($wall - $steady) > self::CHANGED_TOLERANCE_S) {
                $review[] = ShiftClock::REVIEW_CLOCK_CHANGED;
            }
        }

        $captured = $device === null ? $server->copy() : $device->copy()->addSeconds($offset ?? 0);

        // Never in the future, and never a day in the past: past either, the server's own time, and it is flagged.
        if ($captured->greaterThan($server)) {
            $captured = $server->copy();
        } elseif ($server->getTimestamp() - $captured->getTimestamp() > self::MAX_AGE_S) {
            $captured = $server->copy();
            $review[] = ShiftClock::REVIEW_CLOCK_DISAGREES;
        }

        return [
            'captured_at' => $captured,
            'device_at' => $device,
            'offset_s' => $offset,
            'live' => $server->getTimestamp() - $captured->getTimestamp() <= Catalogue::CLOCK_SKEW_SECONDS,
            'review' => array_values(array_unique($review)),
        ];
    }

    private static function instant(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
