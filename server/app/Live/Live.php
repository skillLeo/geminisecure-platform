<?php

declare(strict_types=1);

namespace App\Live;

use Closure;

/**
 * The two pieces of ambient state the live log reads (DESIGN.md §1.3).
 *
 * MUTED: seeders, demonstration commands and probes change a great many records that nobody is
 * watching, and a probe's throwaway principals must never reach a real screen. Inside
 * `Live::muted()` LiveBus writes nothing. The scope is counted, so it nests, and it is closed in a
 * `finally`, so a seeder that throws half way does not leave the rest of the process silent.
 *
 * ORIGIN: the browser sends a random 16-hex nonce with each write (`X-Live-Origin`, §6.1), so the
 * tab that made a change can recognise its own event and not flash its own row. It is a per-write
 * nonce, never a stable id; anything that is not exactly sixteen lowercase hex characters is
 * dropped rather than stored.
 */
final class Live
{
    public const ORIGIN_HEADER = 'X-Live-Origin';

    private const ORIGIN_PATTERN = '/^[a-f0-9]{16}$/';

    private static int $muted = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function muted(Closure $fn): mixed
    {
        self::$muted++;

        try {
            return $fn();
        } finally {
            self::$muted--;
        }
    }

    public static function isMuted(): bool
    {
        return self::$muted > 0;
    }

    /** The validated origin nonce of the current request, or null. */
    public static function origin(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $origin = request()->headers->get(self::ORIGIN_HEADER);

        return is_string($origin) && preg_match(self::ORIGIN_PATTERN, $origin) === 1 ? $origin : null;
    }
}
