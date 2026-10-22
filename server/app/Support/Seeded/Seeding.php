<?php

declare(strict_types=1);

namespace App\Support\Seeded;

use Closure;
use Illuminate\Support\Carbon;

/**
 * WHILE A SEEDER RUNS, AND WHO IS SEEDING (M33 §5, KI-25).
 *
 * Every row a seeder writes into a tenant's tables carries `seeded_at` and `seeded_by`, set at the moment it is created;
 * a row a person creates has them null, forever. This is the switch that says which is happening. It is turned on only
 * by the seeding commands themselves (`SEEDING_COMMANDS`, each running inside `run()` through `SeedsMarkedRows`), and
 * never by anything a request can reach — so the application cannot mark a row, and `SampleAwareBuilder` strips the
 * two columns from anything else it writes.
 *
 * THE OUTERMOST COMMAND NAMES THE ROWS. `phoenix:demo-seed` calls `db:seed` for the estate's records; every row of the
 * run says `phoenix:demo-seed`, which is the act a person would recognise.
 */
final class Seeding
{
    /**
     * The commands that write demonstration content. `db:seed` on its own includes the reference data
     * (`RbacMatrixSeeder`, run by every deploy): its tables are not a tenant's, so nothing of it is marked.
     */
    public const SEEDING_COMMANDS = ['db:seed', 'demo:seed', 'phoenix:demo-seed', 'company:demo-seed'];

    private static ?string $by = null;

    private static ?string $at = null;

    private static int $depth = 0;

    public static function begin(string $by): void
    {
        if (self::$depth === 0) {
            self::$by = mb_substr($by, 0, 64);
            self::$at = Carbon::now('UTC')->toDateTimeString();
        }

        self::$depth++;
    }

    public static function end(): void
    {
        self::$depth = max(0, self::$depth - 1);

        if (self::$depth === 0) {
            self::$by = null;
            self::$at = null;
        }
    }

    public static function active(): bool
    {
        return self::$by !== null;
    }

    public static function by(): ?string
    {
        return self::$by;
    }

    public static function at(): ?string
    {
        return self::$at;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $seed
     * @return T
     */
    public static function run(string $by, Closure $seed): mixed
    {
        self::begin($by);

        try {
            return $seed();
        } finally {
            self::end();
        }
    }

    /** For a test that must leave the switch as it found it, whatever happened. */
    public static function reset(): void
    {
        self::$by = null;
        self::$at = null;
        self::$depth = 0;
    }
}
