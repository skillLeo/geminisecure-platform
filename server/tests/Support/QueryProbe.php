<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Performance\QueryMeter;
use App\Support\Performance\QueryTally;
use App\Support\Seeded\Retirements;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;

/**
 * How many queries one request ran, and how long it took — M11 A2.
 *
 * ITS OWN METER, NOT THE APPLICATION'S. The application's `QueryMeter` is the
 * one `EnforceQueryBudget` brackets each request with; if this probe opened a
 * bracket on it too, the request's own bracket would become an inner one and
 * the middleware would never see a finished tally — the probe would switch the
 * guard off for exactly the request it was measuring. So the probe listens with
 * a meter of its own and both count the same statements side by side.
 *
 * The listener outlives the call (the dispatcher has no way to remove one
 * listener), but its meter is idle once `measure()` returns, and the
 * application is rebuilt before the next test.
 */
final class QueryProbe
{
    /**
     * @template T
     *
     * @param  Closure(): T  $request
     * @return array{result: T, tally: QueryTally, ms: float}
     */
    public static function measure(Closure $request): array
    {
        $meter = new QueryMeter;

        // Which estates have retired their sample data is read once per process (M33 §5), and is not the request's work.
        Retirements::state();

        Event::listen(QueryExecuted::class, static fn (QueryExecuted $query) => $meter->record($query));

        $meter->begin();
        $started = hrtime(true);

        try {
            $result = $request();
        } finally {
            $ms = (hrtime(true) - $started) / 1e6;
            $tally = $meter->end();
        }

        return ['result' => $result, 'tally' => $tally, 'ms' => $ms];
    }

    /**
     * Run `request` and fail unless it stayed within `budget` queries.
     *
     * The failure names the count, the budget and the statements repeated most,
     * so a broken budget reads as the N+1 that broke it.
     *
     * @template T
     *
     * @param  Closure(): T  $request
     * @return T whatever the closure returned — a `TestResponse`, to chain assertions on
     */
    public static function assertWithin(int $budget, Closure $request, string $label = 'The request'): mixed
    {
        $measured = self::measure($request);

        Assert::assertLessThanOrEqual(
            $budget,
            $measured['tally']->count,
            sprintf(
                "%s ran %d queries against a budget of %d.\nMost repeated:\n%s",
                $label,
                $measured['tally']->count,
                $budget,
                $measured['tally']->describe(5),
            ),
        );

        return $measured['result'];
    }
}
