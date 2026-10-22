<?php

declare(strict_types=1);

namespace App\Support\Performance;

/**
 * What one request ran: how many statements, how long they took in the
 * database, and each distinct statement with how often it ran.
 */
final class QueryTally
{
    /**
     * @param  array<string, array{count: int, ms: float}>  $shapes
     */
    public function __construct(
        public readonly int $count,
        public readonly float $databaseMs,
        public readonly array $shapes,
    ) {}

    /**
     * The statements run most often, most first — where an N+1 shows itself.
     *
     * @return list<array{sql: string, count: int, ms: float}>
     */
    public function mostRepeated(int $limit = 3): array
    {
        $rows = [];

        foreach ($this->shapes as $sql => $shape) {
            $rows[] = ['sql' => $sql, 'count' => $shape['count'], 'ms' => round($shape['ms'], 2)];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['count'], $b['ms']] <=> [$a['count'], $a['ms']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * The statements that took longest in total, slowest first.
     *
     * @return list<array{sql: string, count: int, ms: float}>
     */
    public function slowest(int $limit = 3): array
    {
        $rows = [];

        foreach ($this->shapes as $sql => $shape) {
            $rows[] = ['sql' => $sql, 'count' => $shape['count'], 'ms' => round($shape['ms'], 2)];
        }

        usort($rows, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return array_slice($rows, 0, $limit);
    }

    /** The most-repeated statements as lines of text, for an exception or a failed assertion. */
    public function describe(int $limit = 3): string
    {
        $lines = [];

        foreach ($this->mostRepeated($limit) as $row) {
            $lines[] = sprintf('  %s× %s', number_format($row['count']), mb_strimwidth($row['sql'], 0, 300, '…'));
        }

        return implode("\n", $lines);
    }
}
