<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Performance\QueryBudget;
use App\Support\Performance\QueryBudgetExceeded;
use App\Support\Performance\QueryMeter;
use App\Support\Performance\QueryTally;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * A REQUEST OVER ITS QUERY BUDGET FAILS, LOUDLY — M11 A2.
 *
 * FIRST IN THE GLOBAL STACK, so it brackets everything the request does:
 * resolving the estate, reading the session and the signed-in user, their
 * roles, the route's own work and the props every Inertia page shares. A guard
 * placed after any of those would pass a page whose cost was in the part it
 * could not see.
 *
 * THE CHECK IS MADE ONCE THE RESPONSE EXISTS. Over budget, `throw` mode turns
 * the request into a `QueryBudgetExceeded` — a 500 with the route, the count
 * and the statements it repeated most — and `log` mode writes the same as a
 * warning and lets the page through.
 *
 * A REQUEST THAT HAS ALREADY FAILED IS LEFT TO SAY SO. A 5xx keeps its own
 * exception: replacing "the controller threw" with "and it ran too many
 * queries first" would bury the more important of the two.
 *
 * WHAT IT CANNOT SEE: a streamed download's body runs after the response
 * leaves this middleware, so the queries an export makes while streaming are
 * not in its count. The export's own reads before the first byte are.
 *
 * NEVER IN PRODUCTION. `QueryBudget::enabled()` is false outside local and
 * testing whatever the configuration says.
 */
final class EnforceQueryBudget
{
    public function __construct(private readonly QueryMeter $meter) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! QueryBudget::enabled()) {
            return $next($request);
        }

        $started = hrtime(true);
        $this->meter->begin();

        try {
            $response = $next($request);
        } finally {
            $tally = $this->meter->end();
        }

        if ($tally === null) {
            return $response;
        }

        $route = $request->route()?->getName();
        $budget = QueryBudget::for($route);
        $label = $request->method().' '.($route ?? '/'.ltrim($request->path(), '/'));

        $this->report($request, $response, $route, $budget, $tally, (hrtime(true) - $started) / 1e6);

        if ($budget === null || $tally->count <= $budget || $response->getStatusCode() >= 500) {
            return $response;
        }

        $exceeded = new QueryBudgetExceeded($label, $budget, $tally);

        if (config('perf.query_budget.mode') === 'log') {
            Log::warning($exceeded->getMessage());

            return $response;
        }

        throw $exceeded;
    }

    /** One JSON line per request, only when a report file is configured. */
    private function report(Request $request, Response $response, ?string $route, ?int $budget, QueryTally $tally, float $ms): void
    {
        $path = config('perf.query_budget.report');

        if (! is_string($path) || $path === '') {
            return;
        }

        $line = json_encode([
            'method' => $request->method(),
            'route' => $route,
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'queries' => $tally->count,
            'budget' => $budget,
            'db_ms' => round($tally->databaseMs, 2),
            'ms' => round($ms, 2),
            'top' => $tally->mostRepeated(1)[0] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
    }
}
