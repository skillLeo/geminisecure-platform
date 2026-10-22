<?php

declare(strict_types=1);

namespace App\Support\Performance;

use Illuminate\Support\Str;

/**
 * Which ceiling a route is held to — `config/perf.php`.
 *
 * AN EXACT NAME FIRST, THEN THE PATTERNS IN THE ORDER WRITTEN, THEN THE
 * DEFAULT. A route with no name (a closure, a 404 before routing) is held to the
 * default: an unnamed route is not an exempt one.
 *
 * `null` means exempt, and is only ever returned for a route the config names
 * with null on purpose.
 */
final class QueryBudget
{
    /** Is the guard on here? Never outside local and testing, whatever the config says. */
    public static function enabled(): bool
    {
        return (bool) config('perf.query_budget.enabled', false)
            && app()->environment(['local', 'testing']);
    }

    public static function for(?string $routeName): ?int
    {
        /** @var array<string, int|null> $routes */
        $routes = (array) config('perf.query_budget.routes', []);
        $default = (int) config('perf.query_budget.default', 60);

        if ($routeName === null || $routeName === '') {
            return $default;
        }

        if (array_key_exists($routeName, $routes)) {
            return $routes[$routeName] === null ? null : (int) $routes[$routeName];
        }

        foreach ($routes as $pattern => $budget) {
            if (str_contains((string) $pattern, '*') && Str::is((string) $pattern, $routeName)) {
                return $budget === null ? null : (int) $budget;
            }
        }

        return $default;
    }
}
