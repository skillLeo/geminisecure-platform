<?php

declare(strict_types=1);

namespace App\Services\Estate;

use Closure;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A UNIT'S PHASE, WORKED OUT FROM ITS LOT NUMBER IN ONE PLACE (M57.5 §4.2).
 *
 * "Derive the phase from the lot number in one place, using the table in §2. Then all four correct themselves and can
 * never drift again." The one place is the estate's own phase rows: each phase that is a run of lots carries its first
 * and last lot (`estate_phases.first_lot`, `last_lot`), and `units.block` stops being a copy anybody writes and
 * becomes a column MySQL computes from `units.lot_number` with those runs:
 *
 *     block  varchar(32)  AS (case when lot_number between 1 and 356 then 'Phase 1' when … end)  VIRTUAL
 *
 * Every screen, query and rule that reads `units.block` (notices by phase, meetings and positions by phase, dues
 * schedules by phase, the Guard App's phase list, every "Phase 1 · Lot 47") reads the derived phase with no change of
 * its own, and nothing can write a phase onto a unit again: MySQL refuses the value (error 3105). PHP that has a lot
 * number and no unit asks `phaseOf()`, from the same rows.
 *
 * AN ESTATE WHOSE PHASES ARE NOT RUNS OF LOTS (Ocean View's "OV-1A") has no ranges and keeps its stored column: there
 * is no number to work a phase out from. `derived()` says which an estate is.
 *
 * THE CHANGE ITSELF IS SCHEMA (one ALTER, as the schema owner: the estate's own MySQL user may not change its schema),
 * made by `estate:structure-from-plan` after its dry run, and undone by its `--restore`.
 */
final class PhasesFromLots
{
    /** The index the hot paths read the phase through (2026_09_25_100000), rebuilt on the computed column. */
    public const INDEX = 'units_block_status_index';

    /** What `units.block` holds at most, and so what a phase name may be (EstateStructure::PHASE_NAME_MAX). */
    public const NAME_MAX = 32;

    /** Whether this estate's `units.block` is computed by MySQL rather than stored. */
    public static function derived(string $connection = 'tenant'): bool
    {
        // Aliased: MySQL 8 names information_schema's columns in capitals, which an object property would not match.
        $row = DB::connection($connection)->selectOne(
            "select generation_expression as expr from information_schema.columns where table_schema = database() and table_name = 'units' and column_name = 'block'"
        );

        return $row !== null && trim((string) ($row->expr ?? '')) !== '';
    }

    /**
     * The estate's phases that are runs of lots, lowest first.
     *
     * @return list<array{name: string, first: int, last: int}>
     */
    public static function ranges(string $connection = 'tenant'): array
    {
        if (! Schema::connection($connection)->hasColumn('estate_phases', 'first_lot')) {
            return [];
        }

        return DB::connection($connection)->table('estate_phases')
            ->whereNotNull('first_lot')->whereNotNull('last_lot')
            ->orderBy('first_lot')
            ->get(['name', 'first_lot', 'last_lot'])
            ->map(static fn (object $p): array => ['name' => (string) $p->name, 'first' => (int) $p->first_lot, 'last' => (int) $p->last_lot])
            ->values()
            ->all();
    }

    /**
     * The phase a lot number stands in, or null when no phase's run holds it.
     *
     * @param  list<array{name: string, first: int, last: int}>  $ranges
     */
    public static function phaseOf(?int $lot, array $ranges): ?string
    {
        if ($lot === null) {
            return null;
        }

        foreach ($ranges as $range) {
            if ($lot >= $range['first'] && $lot <= $range['last']) {
                return $range['name'];
            }
        }

        return null;
    }

    /** "Lot 47" → 47; anything that is not "Lot" and a whole number → null. MySQL's `lot_number`, in PHP. */
    public static function lotNumberOf(string $reference): ?int
    {
        return preg_match('/^Lot [0-9]{1,9}$/i', $reference) === 1 ? (int) substr($reference, 4) : null;
    }

    /**
     * Refuses runs that cannot describe an estate: a name too long for the column, a run backwards, two runs that
     * share a lot, two phases with one name.
     *
     * @param  list<array{name: string, first: int, last: int}>  $ranges
     *
     * @throws DomainException
     */
    public static function check(array $ranges): void
    {
        if ($ranges === []) {
            throw new DomainException('No phase has a run of lots, so there is nothing to work a phase out from.');
        }

        $names = [];
        $sorted = $ranges;
        usort($sorted, static fn (array $a, array $b): int => $a['first'] <=> $b['first']);

        foreach ($sorted as $i => $range) {
            if ($range['name'] === '' || mb_strlen($range['name']) > self::NAME_MAX) {
                throw new DomainException(sprintf('A phase name runs to %d characters; "%s" does not fit.', self::NAME_MAX, $range['name']));
            }

            if (isset($names[mb_strtolower($range['name'])])) {
                throw new DomainException($range['name'].' is named twice.');
            }

            $names[mb_strtolower($range['name'])] = true;

            if ($range['first'] < 1 || $range['last'] < $range['first']) {
                throw new DomainException(sprintf('%s runs from Lot %d to Lot %d, which is not a run of lots.', $range['name'], $range['first'], $range['last']));
            }

            if ($i > 0 && $range['first'] <= $sorted[$i - 1]['last']) {
                throw new DomainException(sprintf('%s and %s both hold Lot %d.', $sorted[$i - 1]['name'], $range['name'], $range['first']));
            }
        }
    }

    /**
     * The CASE expression the column is computed with.
     *
     * @param  list<array{name: string, first: int, last: int}>  $ranges
     * @param  Closure(string): string  $quote  the connection's string quoting
     */
    public static function expression(array $ranges, Closure $quote): string
    {
        $arms = array_map(
            static fn (array $r): string => sprintf('when `lot_number` between %d and %d then %s', $r['first'], $r['last'], $quote($r['name'])),
            $ranges,
        );

        return 'case '.implode(' ', $arms).' end';
    }

    /**
     * The one statement that makes `units.block` computed from `lot_number` with these runs.
     *
     * @param  list<array{name: string, first: int, last: int}>  $ranges
     */
    public static function derivedSql(array $ranges, Connection $connection): string
    {
        $quote = static fn (string $s): string => $connection->getPdo()->quote($s);
        $drop = Schema::connection($connection->getName())->hasIndex('units', self::INDEX) ? 'drop index `'.self::INDEX.'`, ' : '';

        return 'alter table `units` '.$drop.'drop column `block`, '
            .'add column `block` varchar('.self::NAME_MAX.') as ('.self::expression($ranges, $quote).') virtual after `lot_number`, '
            .'add index `'.self::INDEX.'` (`block`, `status`)';
    }

    /** The statement that turns `units.block` back into a stored column (the values are written back after it). */
    public static function storedSql(Connection $connection): string
    {
        $drop = Schema::connection($connection->getName())->hasIndex('units', self::INDEX) ? 'drop index `'.self::INDEX.'`, ' : '';

        return 'alter table `units` '.$drop.'drop column `block`, '
            .'add column `block` varchar('.self::NAME_MAX.') null after `reference`, '
            .'add index `'.self::INDEX.'` (`block`, `status`)';
    }

    /**
     * Run one schema statement on an estate's database AS THE SCHEMA OWNER (the estate's own user may not change its
     * schema), with tenancy ENDED. The `tenant` connection is pointed at the database with the owner's credentials for
     * this one call and put back, as `OnePrimaryIndex::ensureAsOwner` and `MigrateEstateAsOwner` do.
     *
     * @template T
     *
     * @param  Closure(Connection): T  $work
     * @return T
     */
    public static function asOwner(string $database, Closure $work): mixed
    {
        $previous = config('database.connections.tenant');

        config(['database.connections.tenant' => array_merge((array) config('database.connections.mysql_owner'), ['database' => $database])]);
        DB::purge('tenant');

        try {
            $connection = DB::connection('tenant');

            if ((string) $connection->selectOne('select database() as db')->db !== $database) {
                throw new DomainException('The owner connection did not open '.$database.'. Nothing was changed.');
            }

            return $work($connection);
        } finally {
            config(['database.connections.tenant' => $previous]);
            DB::purge('tenant');
        }
    }

    /**
     * Make `units.block` computed from these runs on this database (as the owner; tenancy ended).
     *
     * @param  list<array{name: string, first: int, last: int}>  $ranges
     */
    public static function derive(string $database, array $ranges): void
    {
        self::check($ranges);

        self::asOwner($database, static function (Connection $owner) use ($ranges): void {
            if (! Schema::connection($owner->getName())->hasColumn('units', 'lot_number')) {
                throw new DomainException('This estate has not had the migration that adds units.lot_number. Run `php artisan estates:migrate` first.');
            }

            $owner->statement(self::derivedSql($ranges, $owner));
        });
    }

    /**
     * Turn `units.block` back into a stored column holding these values, by unit id (as the owner; tenancy ended).
     *
     * @param  array<int, string|null>  $blockById
     */
    public static function store(string $database, array $blockById): void
    {
        self::asOwner($database, static function (Connection $owner) use ($blockById): void {
            $owner->statement(self::storedSql($owner));

            try {
                $owner->transaction(static function () use ($owner, $blockById): void {
                    foreach ($blockById as $id => $block) {
                        $owner->table('units')->where('id', $id)->update(['block' => $block]);
                    }
                });
            } catch (Throwable $failure) {
                throw new DomainException('The phase column is stored again but its values could not all be written back: '.$failure->getMessage());
            }
        });
    }
}
