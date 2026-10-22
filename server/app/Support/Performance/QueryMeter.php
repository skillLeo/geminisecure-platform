<?php

declare(strict_types=1);

namespace App\Support\Performance;

use Illuminate\Database\Events\QueryExecuted;

/**
 * COUNTS THE STATEMENTS ONE REQUEST RUNS — M11 A2.
 *
 * One per application, bound as a singleton, fed by a `QueryExecuted` listener
 * that `AppServiceProvider` registers only while the guard is on. The listener
 * is on the event dispatcher every connection shares, so the count covers the
 * platform database, the estate's own database and the schema owner alike —
 * an estate page's N+1 on the `tenant` connection is counted exactly as a
 * central one is.
 *
 * NOTHING IS COUNTED BETWEEN REQUESTS. `begin()` and `end()` bracket one
 * request; a test's own setup, a seeder or a queued job outside a request runs
 * with the meter idle and adds nothing. Nested brackets — a request dispatched
 * from inside another — keep counting into the outer one, which is the request
 * the reader is waiting on.
 *
 * EVERY STATEMENT IS REMEMBERED BY ITS SHAPE, not only counted, because "1,998
 * queries" says there is a problem and "1,998× select … from `households` where
 * `unit_id` = ?" says where it is. The shape folds bindings and inlined integer
 * lists together, so the same lookup for 1,998 different lots is one line.
 */
final class QueryMeter
{
    private int $depth = 0;

    private int $count = 0;

    private float $databaseMs = 0.0;

    /** @var array<string, array{count: int, ms: float}> */
    private array $shapes = [];

    public function begin(): void
    {
        if ($this->depth === 0) {
            $this->count = 0;
            $this->databaseMs = 0.0;
            $this->shapes = [];
        }

        $this->depth++;
    }

    /** Close a bracket. Returns the tally when the outermost one closes, and null for an inner one. */
    public function end(): ?QueryTally
    {
        if ($this->depth === 0) {
            return null;
        }

        $this->depth--;

        if ($this->depth > 0) {
            return null;
        }

        return new QueryTally($this->count, $this->databaseMs, $this->shapes);
    }

    public function isCounting(): bool
    {
        return $this->depth > 0;
    }

    public function record(QueryExecuted $query): void
    {
        if ($this->depth === 0) {
            return;
        }

        $this->count++;
        $this->databaseMs += (float) $query->time;

        $shape = self::shape($query->connectionName, $query->sql);

        $this->shapes[$shape] ??= ['count' => 0, 'ms' => 0.0];
        $this->shapes[$shape]['count']++;
        $this->shapes[$shape]['ms'] += (float) $query->time;
    }

    /**
     * One statement with its particulars folded away.
     *
     * Bindings are already `?`. What is not: the integer lists eager loading
     * inlines (`in (4, 9, 17)`), a `?` list whose length is the page size, and
     * a literal number written into raw SQL. All three become `?` so the same
     * statement run for different rows is recognised as the same statement.
     */
    public static function shape(string $connection, string $sql): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));
        $sql = (string) preg_replace('/\b\d+\b/', '?', $sql);
        $sql = (string) preg_replace('/\(\s*\?(\s*,\s*\?)*\s*\)/', '(?…)', $sql);

        return $connection.': '.$sql;
    }
}
