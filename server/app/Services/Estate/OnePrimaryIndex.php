<?php

declare(strict_types=1);

namespace App\Services\Estate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ONE HOUSEHOLD, ONE PRIMARY — HELD BY THE DATABASE ITSELF (M47 §14a: "a unique constraint per household, so it cannot
 * happen again by any route").
 *
 * `residents.primary_of_household` is a VIRTUAL generated column (a stored one is refused by MySQL while household_id
 * carries a cascading foreign key — error 1215): the household's id on that household's CURRENT primary
 * resident, and null on everybody else — a member, or a primary who has been removed. A unique index on it means a
 * second current primary in one household cannot be written by anything: the model's guard, a seeder, a raw insert, a
 * console form, an import. (Null is never a duplicate, so members and removed people are unconstrained.)
 *
 * AN ESTATE STILL HOLDING A PAIR GETS ITS INDEX WHEN IT HAS BEEN REPAIRED, NEVER BEFORE. Adding the index there would fail,
 * and a failed estate migration stops the deploy (`estates:migrate`) — while which of the two stays primary is a person's
 * decision (`residents:repair-primaries --keep`), never a migration's. So:
 *   - the migration adds the column everywhere, and the index wherever no pair stands;
 *   - the repair's real run, once an estate's last pair is gone, adds the index there at once (as the schema owner);
 *   - and every `estates:migrate` puts it on any estate that has been repaired since and does not have it yet.
 * The schema owner adds it, because the estate's own MySQL user may not change its schema (MigrateEstateAsOwner).
 *
 * This replaces DESIGN §13.4's "no unique index": its three reasons were a migration failing on a pair (it no longer can),
 * a suite unable to make a pair to prove the repair (the repair's tests take the index off around the pair they make),
 * and the guard and the lock closing every path (the model's paths — not a raw write, which the index now closes too).
 */
final class OnePrimaryIndex
{
    public const COLUMN = 'primary_of_household';

    public const INDEX = 'residents_one_primary_per_household';

    /** The household's id on its current primary; null on a member, and on a primary who has been removed. */
    public const EXPRESSION = 'case when `is_primary` = 1 and `removed_at` is null then `household_id` end';

    /** Whether the database on this connection already refuses a second current primary. */
    public static function inPlace(string $connection = 'tenant'): bool
    {
        return Schema::connection($connection)->hasIndex('residents', self::INDEX);
    }

    /** How many households on this connection still hold more than one current primary. */
    public static function pairs(string $connection = 'tenant'): int
    {
        return DB::connection($connection)->table('residents')
            ->whereNull('removed_at')
            ->where('is_primary', true)
            ->whereNotNull('household_id')
            ->groupBy('household_id')
            ->havingRaw('COUNT(*) > 1')
            ->get(['household_id'])
            ->count();
    }

    /**
     * The index, put on when it is missing and no pair stands in its way. True when the database refuses a second
     * primary afterwards; false while a pair still waits for `residents:repair-primaries` (or before the column exists).
     */
    public static function ensure(string $connection = 'tenant'): bool
    {
        $schema = Schema::connection($connection);

        if (! $schema->hasColumn('residents', self::COLUMN)) {
            return false;
        }

        if (self::inPlace($connection)) {
            return true;
        }

        if (self::pairs($connection) > 0) {
            return false;
        }

        $schema->table('residents', static function (Blueprint $table): void {
            $table->unique(self::COLUMN, self::INDEX);
        });

        return true;
    }

    /**
     * `ensure()` on one estate's database AS THE SCHEMA OWNER — the estate's own user holds no right to change its schema.
     * The `tenant` connection is pointed at the database with the owner's credentials for this one statement and put back
     * (MigrateEstateAsOwner does the same for a whole migration run). Call it with tenancy ENDED: opening the estate
     * points `tenant` at the estate's own user again.
     *
     * @return bool|null true: enforced; false: a pair still stands; null: the owner could not be reached (said by the caller)
     */
    public static function ensureAsOwner(string $database): ?bool
    {
        $previous = config('database.connections.tenant');

        config(['database.connections.tenant' => array_merge((array) config('database.connections.mysql_owner'), ['database' => $database])]);
        DB::purge('tenant');

        try {
            return self::ensure('tenant');
        } catch (Throwable) {
            return null;
        } finally {
            config(['database.connections.tenant' => $previous]);
            DB::purge('tenant');
        }
    }

    /** The migration's `down()`: the index first, then nothing refuses a pair. */
    public static function drop(string $connection = 'tenant'): void
    {
        if (self::inPlace($connection)) {
            Schema::connection($connection)->table('residents', static function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }
    }
}
