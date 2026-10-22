<?php

declare(strict_types=1);

namespace App\Support\Performance;

use RuntimeException;

/**
 * A request ran more queries than its budget allows (M11 A2).
 *
 * Thrown only in local and testing. The message is written for the developer
 * reading it on an error page or in a failed test: the route, the count, the
 * budget, where the budget lives, and the statements the request repeated most
 * — which is almost always the N+1 itself.
 */
final class QueryBudgetExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $route,
        public readonly int $budget,
        public readonly QueryTally $tally,
    ) {
        parent::__construct(sprintf(
            "Query budget exceeded: %s ran %s queries against a budget of %s (config/perf.php).\nMost repeated:\n%s",
            $route,
            number_format($tally->count),
            number_format($budget),
            $tally->describe(),
        ));
    }
}
