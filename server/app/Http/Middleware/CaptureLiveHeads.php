<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Api\DeviceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * THE FIRST CURSOR: WHERE EACH LOG'S HEAD WAS WHEN THIS REQUEST STARTED (DESIGN.md §5.2).
 *
 * A screen's data and its live cursor must meet with nothing between them. So before the controller
 * reads anything, this reads `live_streams.head_seq` of the platform's log and, when an estate is
 * open, of that estate's. Everything sequenced before this read had committed before the request
 * started, so the controller's data includes it; anything sequenced after has a higher seq and is
 * applied again on top, which is harmless. The heads are therefore a correct LOWER bound, and a
 * cursor seeded from them can skip nothing.
 *
 *   - Web pages: kept on the request (`live.heads`), for the page's `live` prop and the shell's
 *     (LivePage, LiveShell). W0 adds this to the console route groups after the estate is resolved.
 *   - The API: after `ResolveDevice` has opened the handset's estate (the priority list, beside the
 *     existing ranking), and every successful authenticated READ carries `X-Live-Head: c=1042;e=88`.
 *     The phone keeps the LOWEST it has seen per stream since the socket last connected.
 *
 * NEVER IN THE WAY. A log that cannot be read (a database built before the live log's migration)
 * gives no head for that stream, and the request carries on: the client then takes the head at its
 * first catch-up and resyncs once, which is the design's rule for a stream with no cursor.
 */
final class CaptureLiveHeads
{
    public const ATTRIBUTE = 'live.heads';

    public const HEADER = 'X-Live-Head';

    public function handle(Request $request, Closure $next): Response
    {
        $api = $request->is('api/*');

        // An API request with no resolved handset (sign-in, the public endpoints) has no channel to seed.
        if ($api && ! app()->bound(DeviceContext::class)) {
            return $next($request);
        }

        /*
         * READS ONLY. A cursor is seeded from the LOWEST head the phone has seen since it connected,
         * and what a screen shows is what its GETs returned, so a read's head is the bound that
         * matters; a write's answer would only ever add a head no lower than the reads around it.
         * Skipping writes keeps two queries off every panic, pass and payment the phone sends. A
         * phone that has made no read since connecting has no head and, by §5.2's own rule, takes
         * the head at its first catch-up and refetches its screens once.
         */
        if ($api && ! $request->isMethodSafe()) {
            return $next($request);
        }

        /*
         * ON THE CONSOLES, A PAGE ONLY (W0): a page load or an Inertia visit, whose `live` prop is what the
         * heads seed. A write redirects back to a page that reads them itself, and a screen's own JSON reads
         * (a poll, a row refetch, /live/*) have no prop to seed; two queries on each of those would be for
         * nothing. A page served with nothing captured still reads them when its prop is built.
         */
        if (! $api && (! $request->isMethodSafe() || ($request->expectsJson() && ! $request->hasHeader('X-Inertia')))) {
            return $next($request);
        }

        $heads = self::read();
        $request->attributes->set(self::ATTRIBUTE, $heads);

        $response = $next($request);

        if ($api && $heads !== [] && $response->isSuccessful()) {
            $response->headers->set(self::HEADER, self::format($heads));
        }

        return $response;
    }

    /**
     * The heads now: the platform's always, the open estate's when there is one.
     *
     * @return array<string, int> `c` and/or `e`
     */
    public static function read(): array
    {
        $heads = [];

        $central = self::head((string) config('tenancy.database.central_connection', 'mysql'), 'central');

        if ($central !== null) {
            $heads['c'] = $central;
        }

        if (tenant() !== null) {
            $estate = self::head('tenant', 'estate');

            if ($estate !== null) {
                $heads['e'] = $estate;
            }
        }

        return $heads;
    }

    /**
     * The heads kept for this request, or read now when nothing captured them.
     *
     * @return array<string, int>
     */
    public static function forRequest(?Request $request = null): array
    {
        $heads = ($request ?? request())->attributes->get(self::ATTRIBUTE);

        return is_array($heads) ? $heads : self::read();
    }

    /** @param  array<string, int>  $heads */
    public static function format(array $heads): string
    {
        return implode(';', array_map(static fn (string $stream, int $q): string => $stream.'='.$q, array_keys($heads), $heads));
    }

    private static function head(string $connection, string $stream): ?int
    {
        try {
            $value = DB::connection($connection)->table('live_streams')->where('stream', $stream)->value('head_seq');
        } catch (Throwable) {
            return null;
        }

        return $value === null ? null : (int) $value;
    }
}
