<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

use App\Api\Catalogue;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One /api/v1 call, made over real HTTP to tools/perf/serve.ps1, and what the server says it cost.
 *
 * THE REQUEST IS A HANDSET'S. Bearer token, Accept: application/json, Accept-Encoding: gzip, and
 * on a write the Idempotency-Key and X-Device-Time the apps send. It goes to a separate PHP
 * process — php -S, one fresh PHP request each time, as php-fpm serves production — so nothing
 * this test has resolved, cached or loaded is shared with the request being measured.
 *
 * THE SERVER'S SIDE IS READ BACK FROM ITS OWN LOG. Every call carries a unique X-Perf-Tag, and
 * tools/perf/router.php writes one line per request under that tag: server time, CPU time, the
 * query count, the queries' own time, the three slowest, and the body's size raw and at gzip
 * level 1. Nothing here estimates any of it.
 *
 * PACED TO STAY UNDER EVERY LIMITER. The API throttles per device (240 reads a minute, 120 writes,
 * 20 clock events, 30 alerts, 10 enrolments per address). A measurement that tripped a limiter
 * would be measuring the 429, so each call waits until its limiter's bucket has room; the wait is
 * not part of any figure.
 */
final class ApiProbe
{
    /** Seconds between two calls on one limiter and one key, a little under each limit. */
    private const PACE = [
        'api-reads' => 0.30,
        'api-writes' => 0.55,
        'api-gate-events' => 0.55,
        'api-alerts' => 2.10,
        'api-shift-clock' => 3.10,
        'api-enrol' => 6.20,
        'api-supervisor-override' => 12.10,
        'api-data-export' => 20.10,
    ];

    /** @var array<string, list<array<string, mixed>>> */
    public array $samples = [];

    /** @var array<string, array{method: string, uri: string, apps: list<string>, write: bool}> */
    public array $entries = [];

    /** @var array<string, float> */
    private array $last = [];

    public function __construct(private readonly string $base, private readonly string $log) {}

    /**
     * @param  array<string, scalar>  $params
     * @param  array<string, mixed>  $body
     * @param  array{0: string, 1: string, 2: string}|null  $file  [field, contents, filename]
     */
    public function call(string $name, ?string $token, array $params = [], array $body = [], ?int $status = null, int $runs = 1, bool $warm = false, string $variant = '', ?array $file = null): Response
    {
        $entry = Catalogue::endpoints()[$name] ?? throw new RuntimeException("No catalogue entry [{$name}].");
        $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($params, $name): string {
            if (! array_key_exists($m[1], $params)) {
                throw new RuntimeException("[{$name}] needs {{$m[1]}}.");
            }

            return (string) $params[$m[1]];
        }, $entry['uri']);

        $key = $variant === '' ? $name : $name.' ['.$variant.']';
        $this->entries[$key] = ['method' => $entry['method'], 'uri' => $entry['uri'], 'apps' => $entry['apps'], 'write' => $entry['write'], 'throttle' => $entry['throttle']];
        $expected = $status ?? $entry['status'];
        $response = null;

        for ($i = $warm ? 0 : 1; $i <= $runs; $i++) {
            $this->pace($entry['throttle'], $token);

            $tag = $key.'#'.$i.'#'.bin2hex(random_bytes(4));
            $headers = ['Accept' => 'application/json', 'Accept-Encoding' => 'gzip', 'X-Perf-Tag' => $tag];

            if ($entry['write']) {
                $headers['Idempotency-Key'] = 'perf-'.bin2hex(random_bytes(8));
                $headers['X-Device-Time'] = now()->toIso8601String();
            }

            $request = Http::withHeaders($headers)->timeout(180)->withOptions(['http_errors' => false]);

            if ($token !== null) {
                $request = $request->withToken($token);
            }

            $started = hrtime(true);

            $response = match (true) {
                $file !== null => $request->attach($file[0], $file[1], $file[2])->post($this->base.$uri, $body),
                $entry['method'] === 'GET' => $request->get($this->base.$uri, $body),
                default => $request->send($entry['method'], $this->base.$uri, $body === [] ? [] : ['json' => $body]),
            };

            $clientMs = (hrtime(true) - $started) / 1e6;
            $this->last[$entry['throttle'].'|'.($token ?? 'address')] = microtime(true);

            if ($response->status() !== $expected) {
                throw new RuntimeException("[{$key}] answered {$response->status()} where {$expected} was expected: ".mb_substr($response->body(), 0, 600));
            }

            $server = $this->serverLine($tag);

            if ($i > 0) {
                $this->samples[$key][] = [
                    'client_ms' => round($clientMs, 2),
                    'server_ms' => $server['server_ms'],
                    'cpu_ms' => $server['cpu_ms'] ?? null,
                    'handle_ms' => $server['handle_ms'],
                    'queries' => $server['queries'],
                    'query_ms' => $server['query_ms'],
                    'bytes' => $server['bytes'],
                    'gzip_bytes' => $server['gzip_bytes'],
                    'route' => $server['route'],
                    'slowest' => $server['slowest'],
                    'all_sql' => $server['all_sql'] ?? null,
                ];
            }
        }

        return $response;
    }

    /** @return array<string, mixed> the log line the server wrote under this tag */
    private function serverLine(string $tag): array
    {
        $needle = '"tag":'.json_encode($tag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $deadline = microtime(true) + 5.0;

        do {
            $handle = @fopen($this->log, 'rb');

            // The server appends under LOCK_EX, and on Windows a locked region cannot be read at
            // all: take a shared lock, which waits for the writer, and try again if the read fails.
            if ($handle !== false) {
                $tail = '';

                if (flock($handle, LOCK_SH)) {
                    $size = (int) fstat($handle)['size'];
                    fseek($handle, max(0, $size - 2_000_000));
                    $tail = (string) @stream_get_contents($handle);
                    flock($handle, LOCK_UN);
                }

                fclose($handle);

                foreach (array_reverse(explode("\n", $tail)) as $line) {
                    if (str_contains($line, $needle)) {
                        return (array) json_decode($line, true);
                    }
                }
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("The server wrote no log line for [{$tag}] — is tools/perf/serve.ps1 running with PERF_LOG = {$this->log}?");
    }

    private function pace(string $throttle, ?string $token): void
    {
        $gap = self::PACE[$throttle] ?? 0.6;
        $last = $this->last[$throttle.'|'.($token ?? 'address')] ?? null;

        if ($last !== null && ($wait = $gap - (microtime(true) - $last)) > 0) {
            usleep((int) ($wait * 1e6));
        }
    }

    /**
     * Every endpoint's figures: n, median and 95th percentile (nearest rank) of each time, and
     * the median of each count and size.
     *
     * @return array<string, array<string, mixed>>
     */
    public function summary(): array
    {
        $out = [];

        foreach ($this->samples as $key => $rows) {
            $pick = static fn (string $field): array => array_values(array_filter(array_column($rows, $field), static fn ($v): bool => $v !== null));
            $median = self::median(...);
            $p95 = self::p95(...);

            // The slowest three of the run whose server time is the median one.
            $byServer = $rows;
            usort($byServer, static fn (array $a, array $b): int => $a['server_ms'] <=> $b['server_ms']);
            $middle = $byServer[intdiv(count($byServer) - 1, 2)];

            $out[$key] = [
                ...$this->entries[$key],
                'route' => $rows[0]['route'] ?? null,
                'n' => count($rows),
                'server_ms_median' => $median($pick('server_ms')),
                'server_ms_p95' => $p95($pick('server_ms')),
                'client_ms_median' => $median($pick('client_ms')),
                'client_ms_p95' => $p95($pick('client_ms')),
                'cpu_ms_median' => $median($pick('cpu_ms')),
                'queries_median' => $median($pick('queries')),
                'queries_min' => min($pick('queries') ?: [null]),
                'queries_max' => max($pick('queries') ?: [null]),
                'query_ms_median' => $median($pick('query_ms')),
                'bytes_median' => $median($pick('bytes')),
                'gzip_bytes_median' => $median($pick('gzip_bytes')),
                'slowest' => $middle['slowest'],
                'all_sql' => $middle['all_sql'],
            ];
        }

        ksort($out);

        return $out;
    }

    /** @param list<int|float> $values */
    public static function median(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** @param list<int|float> $values nearest-rank: the smallest value at or above 95% of the sample */
    public static function p95(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return $values[max(0, (int) ceil(0.95 * count($values)) - 1)];
    }
}
