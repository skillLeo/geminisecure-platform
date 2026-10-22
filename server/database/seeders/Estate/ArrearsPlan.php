<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use RuntimeException;

/**
 * Works out what every unit owes, so that two independent totals both land
 * exactly.
 *
 * THE PROBLEM. Three boards state the same J$1,840,000 of arrears, split three
 * different ways, and all three have to be true of one set of unit ledgers:
 *
 *   board 5   by AGE     Current 612,000 · 30 days 498,000 · 60 days 421,000
 *                        · 90+ days 309,000
 *   board 2   by PHASE   Phase 1 412,000 · Phase 2 589,000 · Phase 3 293,000
 *                        · Phase 4 474,000 · Phase 5 72,000
 *   boards    by UNIT    Lot 47 12,400 (30 days) · Lot 31 31,600 (90+) ·
 *   5 and 6              Lot 55 6,200 (Current) · Lot 9 18,600 (60 days) ·
 *                        Lot 21 44,900 (90+)
 *
 * That is a transportation problem: a five-by-four grid whose row sums are the
 * phase totals, whose column sums are the ageing totals, and five of whose
 * cells already have a named unit sitting in them. It is solved here rather
 * than by hand because hand-fitting thirty numbers to eleven constraints is how
 * a demonstration dataset ends up almost right, and "almost" in a ledger is
 * wrong.
 *
 * NOTHING HERE INVENTS A TOTAL. Every figure it is fitting to is drawn on a
 * board. What it works out is the only thing the boards do not say: which of
 * the other 445 units make up the difference, and by how much each.
 *
 * WHAT A BALANCE MEANS, and why the buckets constrain it. Ageing runs off the
 * OLDEST CHARGE A UNIT HAS NOT WORKED OFF, and payments settle oldest first. So
 * a unit whose oldest open charge is September owes September and nothing else
 * — at most one month's dues. One whose oldest open charge is August owes all
 * of September plus whatever is left of August: more than one month, at most
 * two. Each bucket therefore admits a band of balances and no others, and the
 * fit has to respect it or the seeded data would contradict its own ageing.
 */
final class ArrearsPlan
{
    /** Dues per unit per month, in minor units. Client-confirmed (D-042). */
    public const MONTHLY_DUES = 6_200_00;

    /** How many months of dues the estate has billed. Matches the seeder. */
    public const MONTHS_BILLED = 6;

    /**
     * How many months of dues are open in each bucket.
     *
     * A unit's bucket is decided by the OLDEST charge it has not worked off, and
     * payments settle oldest first. A unit whose oldest open charge is this
     * month owes one month of dues; one whose oldest open charge is last month
     * owes two, and so on. That sets a FLOOR on the balance — a unit in the
     * 60-day bucket owes more than two months, or its oldest open charge would
     * be younger.
     *
     * IT SETS NO CEILING, and an earlier version of this file wrongly assumed
     * one. Dues are not the only thing a unit can owe: an unpaid special levy
     * dated in the same month sits behind the same oldest open charge and puts
     * the unit in the same bucket with a far larger balance. Board 5 shows it
     * outright — Lot 21 owes J$44,900, which is not a multiple of the monthly
     * fee and never could be. Assuming a ceiling left whole ranges of cell
     * totals representable by no number of units at all, and pushed a seventh
     * of the arrears into the wrong buckets.
     */
    public const MONTHS_OPEN = [
        'current' => 1,
        'd30' => 2,
        'd60' => 3,
        'd90' => 4,
    ];

    /**
     * The ageing totals board 5 draws, in minor units.
     *
     * @var array<string, int>
     */
    public const AGEING = [
        'current' => 612_000_00,
        'd30' => 498_000_00,
        'd60' => 421_000_00,
        'd90' => 309_000_00,
    ];

    /**
     * The per-phase arrears board 2 draws, in minor units.
     *
     * @var array<string, int>
     */
    public const BY_PHASE = [
        'Phase 1' => 412_000_00,
        'Phase 2' => 589_000_00,
        'Phase 3' => 293_000_00,
        'Phase 4' => 474_000_00,
        'Phase 5' => 72_000_00,
    ];

    /**
     * The units boards 5 and 6 name, with the phase, balance and bucket drawn
     * for each. These are fixed points the fit works around.
     *
     * @var array<int, array{phase: string, balance: int, bucket: string, resident: string, household: string}>
     */
    public const NAMED = [
        47 => ['phase' => 'Phase 2', 'balance' => 12_400_00, 'bucket' => 'd30', 'resident' => 'Andrea Fletcher', 'household' => 'Fletcher household'],
        31 => ['phase' => 'Phase 2', 'balance' => 31_600_00, 'bucket' => 'd90', 'resident' => 'Omar Brown', 'household' => 'Brown household'],
        55 => ['phase' => 'Phase 3', 'balance' => 6_200_00, 'bucket' => 'current', 'resident' => 'Michelle Palmer', 'household' => 'Palmer household'],
        9 => ['phase' => 'Phase 1', 'balance' => 18_600_00, 'bucket' => 'd60', 'resident' => 'Ricardo Hall', 'household' => 'Hall household'],
        21 => ['phase' => 'Phase 4', 'balance' => 44_900_00, 'bucket' => 'd90', 'resident' => 'Tanya Simms', 'household' => 'Simms household'],
    ];

    /**
     * Assign a balance and a bucket to every unit in arrears.
     *
     * @param  array<string, list<int>>  $lotsByPhase  phase name => the lot numbers in it
     * @return array<int, array{balance: int, bucket: string}> lot number => what it owes
     */
    public function solve(array $lotsByPhase): array
    {
        $grid = $this->fitGrid();
        $assignment = [];

        foreach (self::NAMED as $lot => $named) {
            $assignment[$lot] = ['balance' => $named['balance'], 'bucket' => $named['bucket']];
        }

        foreach ($grid as $phase => $buckets) {
            $available = array_values(array_filter(
                $lotsByPhase[$phase] ?? [],
                static fn (int $lot): bool => ! isset(self::NAMED[$lot]),
            ));

            $cursor = 0;

            foreach ($buckets as $bucket => $target) {
                // What the named units in this cell already account for.
                foreach (self::NAMED as $lot => $named) {
                    if ($named['phase'] === $phase && $named['bucket'] === $bucket) {
                        $target -= $named['balance'];
                    }
                }

                if ($target <= 0) {
                    continue;
                }

                foreach ($this->split($target, $bucket) as $balance) {
                    if (! isset($available[$cursor])) {
                        throw new RuntimeException(
                            "Phase [{$phase}] has run out of units to place arrears on. The phase unit ".
                            'counts and the arrears totals disagree; one of them is wrong.'
                        );
                    }

                    $assignment[$available[$cursor++]] = ['balance' => $balance, 'bucket' => $bucket];
                }
            }
        }

        $this->assertTotals($assignment, $lotsByPhase);

        return $assignment;
    }

    /**
     * The five-by-four grid, fitted so both margins hold exactly.
     *
     * Proportional first — every phase owes its share of every bucket, which is
     * what an estate with no particular pattern to its arrears looks like — then
     * the rounding residue is pushed into the last cell of each row and the last
     * row of each column, so no total is ever out by the cent that rounding
     * costs.
     *
     * @return array<string, array<string, int>>
     */
    private function fitGrid(): array
    {
        /*
         * The two margins must describe the same amount of arrears. They are
         * both read off boards, so if they ever disagree one of the boards is
         * wrong and it is not this code's to guess — but the check belongs in a
         * test rather than here, because both are constants and a runtime
         * comparison of two constants is dead code that reads as a safeguard.
         * `EstateArrearsTest` asserts it.
         */
        $total = array_sum(self::BY_PHASE);
        $phases = array_keys(self::BY_PHASE);
        $buckets = array_keys(self::AGEING);
        $grid = [];

        foreach ($phases as $phase) {
            foreach ($buckets as $bucket) {
                $grid[$phase][$bucket] = intdiv(self::BY_PHASE[$phase] * self::AGEING[$bucket], $total);
            }
        }

        // Row residue into each phase's last bucket.
        foreach ($phases as $phase) {
            $grid[$phase][end($buckets)] += self::BY_PHASE[$phase] - array_sum($grid[$phase]);
        }

        // Column residue into the last phase, which leaves both margins exact.
        foreach ($buckets as $bucket) {
            $column = array_sum(array_column($grid, $bucket));
            $grid[end($phases)][$bucket] += self::AGEING[$bucket] - $column;
        }

        return $this->makeFeasible($grid, $phases, $buckets);
    }

    /**
     * Empty out any cell too small for its bucket to hold, without disturbing
     * either margin.
     *
     * A cell of J$12,092 in the 90-plus column cannot exist: a unit in that
     * bucket owes at least three months, so the smallest 90-plus cell is one
     * month over three. Proportional fitting produces such cells wherever a
     * small phase meets a small bucket — Phase 5 against 90 days, here — and
     * leaving one in place is what put a seventh of the arrears in the wrong
     * bucket on the first run.
     *
     * THE REPAIR IS A ROTATION, which is the standard move on a transportation
     * grid and the only one that changes nothing else. Take the offending
     * amount out of (p, b) and put it into (q, b); take the same amount out of
     * (q, c) and put it into (p, c). Row p loses and gains it, row q gains and
     * loses it, and both columns do the same — so all nine other totals are
     * exactly where they were.
     *
     * @param  array<string, array<string, int>>  $grid
     * @param  list<string>  $phases
     * @param  list<string>  $buckets
     * @return array<string, array<string, int>>
     */
    private function makeFeasible(array $grid, array $phases, array $buckets): array
    {
        foreach ($phases as $phase) {
            foreach ($buckets as $bucket) {
                $value = $grid[$phase][$bucket];
                $floor = (self::MONTHS_OPEN[$bucket] - 1) * self::MONTHLY_DUES;

                if ($value === 0 || $value > $floor) {
                    continue;
                }

                if (! $this->rotateOut($grid, $phase, $bucket, $value, $phases, $buckets)) {
                    throw new RuntimeException(sprintf(
                        'Phase [%s] holds J$%s in the [%s] bucket, which cannot hold less than J$%s, '.
                        'and no rotation repairs it. The board figures do not describe an estate that '.
                        'can exist.',
                        $phase,
                        number_format($value / 100, 2),
                        $bucket,
                        number_format(($floor + 1) / 100, 2),
                    ));
                }
            }
        }

        return $grid;
    }

    /**
     * Move one infeasible cell's amount out, compensating so every margin holds.
     *
     * @param  array<string, array<string, int>>  $grid
     * @param  list<string>  $phases
     * @param  list<string>  $buckets
     */
    private function rotateOut(array &$grid, string $phase, string $bucket, int $value, array $phases, array $buckets): bool
    {
        foreach ($phases as $donor) {
            if ($donor === $phase) {
                continue;
            }

            foreach ($buckets as $column) {
                if ($column === $bucket) {
                    continue;
                }

                $remainder = $grid[$donor][$column] - $value;
                $columnFloor = (self::MONTHS_OPEN[$column] - 1) * self::MONTHLY_DUES;

                // The donor cell must be able to give the amount up and still
                // be either empty or large enough for its own bucket.
                if ($remainder < 0 || ($remainder !== 0 && $remainder <= $columnFloor)) {
                    continue;
                }

                // And the receiving cell must end up large enough for its.
                if ($grid[$donor][$bucket] + $value <= (self::MONTHS_OPEN[$bucket] - 1) * self::MONTHLY_DUES) {
                    continue;
                }

                $grid[$phase][$bucket] = 0;
                $grid[$donor][$bucket] += $value;
                $grid[$donor][$column] = $remainder;
                $grid[$phase][$column] += $value;

                return true;
            }
        }

        return false;
    }

    /**
     * Break one cell's total into per-unit balances the bucket can actually
     * hold.
     *
     * A bucket admits balances in a band — more than the months below it, at
     * most the months in it — because that is what "the oldest charge still
     * open is N months back" means. Units are filled at the top of the band and
     * the last one takes the remainder, which is what a real arrears list looks
     * like: a run of units owing the same few months and one owing an odd
     * amount because they part-paid.
     *
     * @return list<int>
     */
    private function split(int $target, string $bucket): array
    {
        $whole = self::MONTHS_OPEN[$bucket] * self::MONTHLY_DUES;
        $floor = $whole - self::MONTHLY_DUES;

        if ($target <= $floor) {
            throw new RuntimeException(sprintf(
                'A cell of J$%s cannot sit in the [%s] bucket, where a single unit already owes more '.
                'than J$%s. It should have been rotated out before it got here.',
                number_format($target / 100, 2),
                $bucket,
                number_format($floor / 100, 2),
            ));
        }

        /*
         * Units owing a clean run of months, and one carrying the remainder.
         *
         * The remainder unit is the interesting one and the realistic one: it
         * owes the same months as the rest plus an unpaid levy, or it part-paid
         * and owes an odd amount. Both are how board 5's balances actually look.
         */
        $count = max(1, intdiv($target, $whole));
        $balances = array_fill(0, $count - 1, $whole);
        $last = $target - ($count - 1) * $whole;

        /*
         * The last unit must still clear the floor, or it belongs in a younger
         * bucket. It cannot fail to: it is at least the remainder plus a whole
         * run of months, and a whole run already exceeds the floor by one
         * month's dues.
         */
        if ($last <= $floor) {
            throw new RuntimeException(sprintf(
                'Splitting J$%s across the [%s] bucket left a unit owing J$%s, which belongs in a '.
                'younger bucket.',
                number_format($target / 100, 2),
                $bucket,
                number_format($last / 100, 2),
            ));
        }

        $balances[] = $last;

        return $balances;
    }

    /**
     * Prove the fit before a single row is written.
     *
     * The whole point of this class is that two independent totals land exactly.
     * Asserting it here means a seeded estate can never quietly be a few
     * thousand out — which is precisely the kind of error nobody finds by
     * looking at an arrears screen.
     *
     * @param  array<int, array{balance: int, bucket: string}>  $assignment
     * @param  array<string, list<int>>  $lotsByPhase
     */
    private function assertTotals(array $assignment, array $lotsByPhase): void
    {
        $byBucket = array_fill_keys(array_keys(self::AGEING), 0);

        foreach ($assignment as $row) {
            $byBucket[$row['bucket']] += $row['balance'];
        }

        foreach (self::AGEING as $bucket => $expected) {
            if ($byBucket[$bucket] !== $expected) {
                throw new RuntimeException(sprintf(
                    'Ageing bucket [%s] came out at %d against a board figure of %d.',
                    $bucket, $byBucket[$bucket], $expected,
                ));
            }
        }

        foreach (self::BY_PHASE as $phase => $expected) {
            $actual = 0;

            foreach ($lotsByPhase[$phase] ?? [] as $lot) {
                $actual += $assignment[$lot]['balance'] ?? 0;
            }

            if ($actual !== $expected) {
                throw new RuntimeException(sprintf(
                    'Phase [%s] arrears came out at %d against a board figure of %d.',
                    $phase, $actual, $expected,
                ));
            }
        }
    }
}
