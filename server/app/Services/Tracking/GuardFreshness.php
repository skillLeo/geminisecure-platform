<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use Illuminate\Support\Carbon;

/**
 * HOW FRESH A GUARD'S PIN IS, AND IT NEVER PRETENDS (Hassam, 12 Oct 2026; GUARD_TRACKING.md 5.5). One rule for both
 * consoles: the Gemini dispatcher's map and the estate's Security map read the same words, and the pages keep them
 * current with `resources/js/lib/guardFreshness.js`, which is this file in JavaScript.
 *
 *   live        the newest reading is under LIVE_SECONDS old
 *   last_seen   older than that, while something has ARRIVED within the estate's tolerance
 *   no_signal   nothing has ARRIVED for longer than the tolerance (or nothing since a clock-in that long ago): the
 *               dispatcher is alerted (`tracking:signal`)
 *   waiting     clocked in, nothing yet, still within the tolerance of the clock-in
 *   not_shared  the officer has not agreed to the current wording, or tracking is switched off
 *
 * A reading's age runs from when it was TAKEN, never later than when it ARRIVED (`readingAt`): a phone whose clock runs
 * fast cannot make a pin look newer than its arrival, and a reading queued offline for forty minutes reads "Last seen
 * 40 min ago" however recently it came in. "No signal" is judged on arrival, because it means the phone has gone quiet.
 */
final class GuardFreshness
{
    public const LIVE = 'live';

    public const LAST_SEEN = 'last_seen';

    public const NO_SIGNAL = 'no_signal';

    public const WAITING = 'waiting';

    public const NOT_SHARED = 'not_shared';

    /** A reading younger than this is Live. */
    public const LIVE_SECONDS = 60;

    /**
     * No reading at all, past the tolerance, on a screen that may not know when the shift began (Hassam, 12 Oct: the
     * estate never sees a clock-in time). The Gemini console keeps "No signal since clock-in at 7:00 PM".
     */
    public const NO_SIGNAL_SINCE_START = 'No signal since the shift began';

    /** An estate's "No signal" tolerance when nothing else says (`tracking.no_signal_after_seconds`). */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Each estate's No signal tolerance in seconds: THE one place it is read (12 Oct 2026). Today the configured default
     * for every estate, with no query; when Governance's site tracking settings land, their bulk reader goes here, so
     * the maps, the estate feed and the No signal watch all follow the estate's own setting at once.
     *
     * @param  list<string>  $tenantIds
     * @return array<string, int>
     */
    public static function tolerances(array $tenantIds): array
    {
        $seconds = max(60, (int) config('tracking.no_signal_after_seconds', self::DEFAULT_TOLERANCE_SECONDS));

        return array_fill_keys(array_map('strval', $tenantIds), $seconds);
    }

    /** When a reading was taken, as the map may believe it: never later than when it arrived. */
    public static function readingAt(Carbon $capturedAt, Carbon $receivedAt): Carbon
    {
        return $capturedAt->lessThan($receivedAt) ? $capturedAt->copy() : $receivedAt->copy();
    }

    public static function judge(bool $shared, ?Carbon $readingAt, ?Carbon $arrivedAt, Carbon $clockedInAt, int $toleranceSeconds, Carbon $now): string
    {
        if (! $shared) {
            return self::NOT_SHARED;
        }

        $tolerance = max(60, $toleranceSeconds);

        if ($arrivedAt === null || $readingAt === null) {
            return $clockedInAt->diffInSeconds($now, false) > $tolerance ? self::NO_SIGNAL : self::WAITING;
        }

        if ($arrivedAt->diffInSeconds($now, false) > $tolerance) {
            return self::NO_SIGNAL;
        }

        return $readingAt->diffInSeconds($now, false) < self::LIVE_SECONDS ? self::LIVE : self::LAST_SEEN;
    }

    /**
     * The words on the pin, as of `$now`. `$timeWords` writes a moment the way the console writes one (the estate's
     * clock, and on the Gemini console its zone's tag).
     *
     * @param  callable(Carbon): string  $timeWords
     */
    public static function label(string $freshness, ?Carbon $readingAt, Carbon $clockedInAt, Carbon $now, callable $timeWords): string
    {
        return match ($freshness) {
            self::LIVE => 'Live',
            self::LAST_SEEN => $readingAt === null ? 'Last seen' : 'Last seen '.self::ago((int) $readingAt->diffInSeconds($now, false)).' · '.$timeWords($readingAt),
            self::NO_SIGNAL => $readingAt === null ? 'No signal since clock-in at '.$timeWords($clockedInAt) : 'No signal · last seen '.$timeWords($readingAt),
            self::WAITING => 'Waiting for the first position',
            default => 'Position not shared',
        };
    }

    /** "1 min ago", "4 min ago", "1 h 5 min ago". Never under a minute: that is Live. */
    public static function ago(int $seconds): string
    {
        $minutes = max(1, intdiv(max(0, $seconds), 60));

        if ($minutes < 60) {
            return $minutes.' min ago';
        }

        return intdiv($minutes, 60).' h '.($minutes % 60).' min ago';
    }
}
