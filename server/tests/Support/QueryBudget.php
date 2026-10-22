<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\PlatformPricing;
use App\Support\SiteTime;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * WHAT ONE REQUEST ASKED OF THE DATABASE (M11 A2), counted rather than guessed.
 *
 * Every statement on EVERY connection — the platform's and whichever estate's tenancy
 * opened during the request — is caught by `DB::listen`, with its SQL shape and its time.
 *
 * THE SHAPE IS WHAT FINDS AN N+1. A query that runs once per row runs with the same SQL
 * and different bindings, so its shape repeats as many times as the list has rows; a
 * `whereIn` over a list is one shape however long the list. `IN (?, ?, ?)` is folded to
 * `IN (?…)` so two eager loads of different sizes still read as one statement.
 *
 * This is a MEASURING instrument for the budget tests. The permanent development guard
 * that fails a request over a sane count is `m11-n1-estate`'s, not this.
 */
final class QueryBudget
{
    /** @var list<array{connection: string, sql: string, ms: float}> */
    private static array $log = [];

    private static bool $recording = false;

    private static ?int $listening = null;

    /**
     * Run the work and report what it asked for.
     *
     * @return array{result: mixed, count: int, ms: float, db_ms: float, repeats: array<string, int>, slowest: list<array{sql: string, ms: float}>, statements: list<array{connection: string, sql: string, ms: float}>}
     */
    public static function measure(callable $work): array
    {
        self::listen();

        self::$log = [];
        self::$recording = true;
        $cpu = self::cpuMs();
        $started = hrtime(true);

        try {
            $result = $work();
        } finally {
            $elapsed = (hrtime(true) - $started) / 1e6;
            $cpu = self::cpuMs() - $cpu;
            self::$recording = false;
        }

        $log = self::$log;
        self::$log = [];

        $shapes = [];
        foreach ($log as $entry) {
            $shape = self::shape($entry['sql']);
            $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
        }

        arsort($shapes);

        $slowest = $log;
        usort($slowest, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return [
            'result' => $result,
            'count' => count($log),
            'ms' => round($elapsed, 2),
            // This process's own CPU, user and system: the part of the time a busy machine
            // cannot inflate by making the request wait its turn for a core.
            'cpu_ms' => round($cpu, 2),
            'db_ms' => round(array_sum(array_column($log, 'ms')), 2),
            'repeats' => array_filter($shapes, static fn (int $n): bool => $n > 1),
            'slowest' => array_map(static fn (array $e): array => ['sql' => $e['sql'], 'ms' => $e['ms']], array_slice($slowest, 0, 3)),
            'statements' => $log,
        ];
    }

    /**
     * FORGET WHAT THIS PROCESS REMEMBERS BETWEEN REQUESTS, AS THE SERVER DOES.
     *
     * Production answers every request from a PHP-FPM worker that starts it with nothing in its
     * static memory; a test answers many requests from one process. Whatever one request leaves
     * in a static — every estate's time zone (`SiteTime`), the per-guard rate
     * (`PlatformPricing`) — makes the next request in a test cheaper than it is on the server,
     * and a query asked once per ESTATE is exactly the kind that hides there: the warm-up
     * request asks it for every client and the counted one asks it for none. So every counted
     * request is made as a new one. The shared cache is left alone on purpose: Redis keeps it
     * between requests in production too.
     */
    public static function asANewRequest(): void
    {
        SiteTime::flush();
        PlatformPricing::forgetGuardRate();
    }

    /** The statement with its bindings' positions kept and every list folded to one. */
    public static function shape(string $sql): string
    {
        $folded = preg_replace('/\(\s*\?(\s*,\s*\?)*\s*\)/', '(?…)', $sql) ?? $sql;

        return preg_replace('/\s+/', ' ', trim($folded)) ?? $folded;
    }

    /** The largest number of times one shape ran in a measurement. */
    public static function worstRepeat(array $measurement): int
    {
        return $measurement['repeats'] === [] ? 1 : max($measurement['repeats']);
    }

    private static function cpuMs(): float
    {
        $usage = getrusage();

        return (($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_sec'] ?? 0)) * 1000
            + (($usage['ru_utime.tv_usec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0)) / 1000;
    }

    private static function listen(): void
    {
        $app = spl_object_id(app());

        if (self::$listening === $app) {
            return;
        }

        self::$listening = $app;

        DB::listen(static function (QueryExecuted $query): void {
            if (! self::$recording) {
                return;
            }

            self::$log[] = [
                'connection' => (string) $query->connectionName,
                'sql' => $query->sql,
                'ms' => (float) $query->time,
            ];
        });
    }
}
