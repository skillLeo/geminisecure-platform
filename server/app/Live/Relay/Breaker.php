<?php

declare(strict_types=1);

namespace App\Live\Relay;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Reverb is not answering": publishing rests for 10 s, and nothing else does (DESIGN.md §1.5).
 *
 * After a publish that Reverb failed to answer (it could not be reached, it hung, or it answered
 * 5xx), the breaker is set for `live.breaker_seconds`. While it is set the relay keeps SEQUENCING,
 * so catch-up keeps answering every client that asks, but it does not publish, and
 * Relay::flushNow does not try: no write, a panic's included, ever pays the connect timeout
 * against a Reverb already known to be down. After the rest the next publish tries again, and
 * sets it again if Reverb is still not there.
 *
 * A publish Reverb REFUSED (4xx) does not set it. Reverb answered; the fault is that message's,
 * and the relay retries it alone and gives it up after five tries (Publisher).
 *
 * SHARED BY EVERY PROCESS, through the cache store, and never inside an estate's tags: the relay
 * publishes estate streams with the estate open, and tenancy's cache bootstrapper tags every
 * `Cache::` call made then. `Cache::store()` is the store itself, untagged, so the relay in an
 * estate, the relay in none and a panic's request all read one key.
 */
final class Breaker
{
    public const KEY = 'live:reverb_down';

    public function open(): void
    {
        $seconds = max(1, (int) config('live.breaker_seconds', 10));

        try {
            Cache::store()->put(self::KEY, Carbon::now()->addSeconds($seconds)->getTimestamp(), $seconds);
        } catch (Throwable $e) {
            // The cache is down too. Publishing then simply tries each time; say so once.
            report($e);
        }
    }

    public function isOpen(): bool
    {
        try {
            $until = Cache::store()->get(self::KEY);
        } catch (Throwable) {
            return false;
        }

        return is_int($until) && $until > Carbon::now()->getTimestamp();
    }

    public function close(): void
    {
        try {
            Cache::store()->forget(self::KEY);
        } catch (Throwable) {
            // Nothing to put right: an unreadable breaker reads as closed.
        }
    }
}
