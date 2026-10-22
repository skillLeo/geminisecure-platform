<?php

declare(strict_types=1);

namespace App\Support\Seeded;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * EVERY QUERY ON THE TWO CONNECTIONS, MARKED AND SCOPED (M33 §5.2 and §5.4, KI-25).
 *
 * The query builder `UtcMySqlConnection` hands out, for Eloquent and `DB::table` alike. It does two things.
 *
 * THE MARK. While a seeding command runs (`Seeding`), every row it inserts into a tenant-scoped table
 * (`SeededTables`) is written with `seeded_at` and `seeded_by`. Outside one, the two columns are taken out of every
 * insert and every update, so the application can never set them, and an update never changes them — a mark says when
 * a row was created, by whom, once. A query that selects `*` never hands them back either, so a model, an API response
 * or an audit snapshot reads exactly as it did before they existed.
 *
 * THE SCOPE. When an estate has retired its sample data (`Retirements`), every statement that reads, updates or
 * deletes adds, for each table it names, the condition under which a row is shown: in the WHERE for the table it
 * selects from, in the ON of the join that brings in any other — so a LEFT JOIN to a hidden row reads as no row, as
 * though it had never been — and in every sub-select a WHERE EXISTS, WHERE … IN, a sub-query column, a UNION or a
 * derived table holds. The condition is binding-free (a join's bindings are fixed when the join is made). An OR among
 * the query's own conditions is put in brackets first, so nothing joins the hidden rows back in by precedence.
 * Applied at the moment the statement is compiled, every time, so it is right however often a builder is reused.
 *
 * NOTHING CHANGES WHILE NO ESTATE HAS RETIRED: `Retirements::any()` is answered from memory and every query compiles
 * exactly as before.
 */
class SampleAwareBuilder extends Builder
{
    /** The key every condition the scope adds carries, so it is taken out and put back, never added twice. */
    public const ADDED = 'gs_sample_scope';

    public function applyBeforeQueryCallbacks()
    {
        parent::applyBeforeQueryCallbacks();

        $this->scopeSamples();
    }

    /** Add (or add again) the condition for every table this statement names, here and in what it holds. */
    public function scopeSamples(): void
    {
        $this->wheres = self::withoutAdded($this->wheres);

        if (! $this->scopable()) {
            return;
        }

        $retiring = Retirements::any() && ! Retirements::blind();
        $forWhere = [];

        foreach ($this->joins ?? [] as $join) {
            array_push($forWhere, ...$this->scopeJoin($join, $retiring));
        }

        $this->scopeHeld($this->wheres);

        if (! $retiring) {
            return;
        }

        $reference = is_string($this->from) ? $this->reference($this->from) : null;
        $shown = $reference === null ? null : $this->visible($reference);
        $rightJoin = null;

        foreach ($this->joins ?? [] as $join) {
            $rightJoin ??= $join->type === 'right' ? $join : null;
        }

        if ($shown !== null && $rightJoin !== null) {
            // A RIGHT JOIN keeps the joined table's rows and fills this one with nothing: the condition goes in its ON.
            $rightJoin->wheres = self::bracketed($rightJoin, $rightJoin->wheres);
            $rightJoin->wheres[] = self::condition($shown);
        } elseif ($shown !== null) {
            $forWhere[] = $shown;
        }

        if ($forWhere !== []) {
            $this->wheres = self::bracketed($this, $this->wheres);

            foreach ($forWhere as $sql) {
                $this->wheres[] = self::condition($sql);
            }
        }
    }

    /**
     * The condition for a joined table, in its ON — or, for the table a RIGHT JOIN keeps, returned for the WHERE.
     *
     * @return list<string> conditions that belong in the statement's WHERE
     */
    private function scopeJoin(JoinClause $join, bool $retiring): array
    {
        $join->wheres = self::withoutAdded($join->wheres);
        $forWhere = [];

        foreach ($join->joins ?? [] as $nested) {
            array_push($forWhere, ...$this->scopeJoin($nested, $retiring));
        }

        $this->scopeHeld($join->wheres);

        if (! $retiring || ! is_string($join->table)) {
            return $forWhere;
        }

        $reference = $this->reference($join->table);
        $shown = $reference === null ? null : $this->visible($reference);

        if ($shown === null) {
            return $forWhere;
        }

        if ($join->type === 'right') {
            $forWhere[] = $shown;

            return $forWhere;
        }

        $join->wheres = self::bracketed($join, $join->wheres);
        $join->wheres[] = self::condition($shown);

        return $forWhere;
    }

    /**
     * The sub-selects a WHERE holds as builders, compiled later without the callbacks: EXISTS, NOT EXISTS, a
     * comparison with a sub-select. A bracketed group is the same statement's own conditions, so only what it holds
     * is visited. (WHERE … IN, a sub-query column, a derived table and a UNION are compiled through `toSql()`, which
     * scopes them itself.)
     *
     * @param  array<int, array<string, mixed>>  $wheres
     */
    private function scopeHeld(array $wheres): void
    {
        foreach ($wheres as $where) {
            $held = $where['query'] ?? null;

            if (! $held instanceof Builder) {
                continue;
            }

            if (($where['type'] ?? null) === 'Nested') {
                $this->scopeHeld($held->wheres);
            } elseif ($held instanceof self) {
                $held->scopeSamples();
            }
        }
    }

    private function scopable(): bool
    {
        $name = $this->connectionName();

        return $name === 'tenant' || $name === 'mysql';
    }

    /** 'tenant', 'mysql', or the name of a connection the scope leaves alone (the schema owner's). */
    private function connectionName(): string
    {
        return $this->connection instanceof Connection ? (string) $this->connection->getName() : '';
    }

    /**
     * "charges", "charges as c", "gs_estate_x.charges as c" → the table, the database it is in, and how a row is named.
     *
     * @return array{table: string, database: string, qualifier: string}|null
     */
    private function reference(string $from): ?array
    {
        $from = trim($from);
        $alias = null;

        if (preg_match('/^(.+?)\s+as\s+(\S+)$/i', $from, $m) === 1) {
            $from = trim($m[1]);
            $alias = trim($m[2], '`');
        }

        $parts = array_map(static fn (string $p): string => trim($p, '`'), explode('.', $from));
        $table = (string) array_pop($parts);
        $database = $parts === [] ? null : (string) array_pop($parts);

        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return null;
        }

        $named = ($this->connection instanceof Connection ? $this->connection->getTablePrefix() : '').$table;

        return [
            'table' => $table,
            'database' => $database ?? (string) $this->connection->getDatabaseName(),
            'qualifier' => $alias ?? ($database === null ? $named : $database.'.'.$named),
        ];
    }

    /** @param  array{table: string, database: string, qualifier: string}  $reference */
    private function visible(array $reference): ?string
    {
        return Retirements::visibleSql($this->connectionName(), $reference['database'], $reference['table'], $reference['qualifier']);
    }

    /** @return array<string, mixed> */
    private static function condition(string $sql): array
    {
        return ['type' => 'raw', 'sql' => $sql, 'boolean' => 'and', self::ADDED => true];
    }

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     * @return array<int, array<string, mixed>>
     */
    private static function withoutAdded(array $wheres): array
    {
        foreach ($wheres as $where) {
            if (! empty($where[self::ADDED])) {
                return array_values(array_filter($wheres, static fn (array $w): bool => empty($w[self::ADDED])));
            }
        }

        return $wheres;
    }

    /**
     * A query's own conditions, put in brackets when any of them is joined by OR — so the condition added after them
     * applies to all of them, not to the last. The bindings stay where they are: the same conditions, in the same order.
     *
     * @param  array<int, array<string, mixed>>  $wheres
     * @return array<int, array<string, mixed>>
     */
    private static function bracketed(Builder $query, array $wheres): array
    {
        $or = false;

        foreach (array_slice($wheres, 1) as $where) {
            $or = $or || str_starts_with(strtolower((string) ($where['boolean'] ?? 'and')), 'or');
        }

        if (! $or) {
            return $wheres;
        }

        $group = $query->forNestedWhere();
        $group->wheres = $wheres;

        return [['type' => 'Nested', 'query' => $group, 'boolean' => 'and']];
    }

    /* ---------------------------------------------------------------- the mark */

    /** @param  array<mixed>  $values */
    public function insert(array $values)
    {
        return parent::insert($this->marked($values));
    }

    /** @param  array<mixed>  $values */
    public function insertOrIgnore(array $values)
    {
        return parent::insertOrIgnore($this->marked($values));
    }

    /** @param  array<string, mixed>  $values */
    public function insertGetId(array $values, $sequence = null)
    {
        return parent::insertGetId($this->markedRow($values), $sequence);
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null)
    {
        if (! $this->marksThisTable()) {
            return parent::upsert($values, $uniqueBy, $update);
        }

        $rows = $this->marked($values);
        $first = is_array(reset($rows)) ? reset($rows) : $rows;

        // Laravel would otherwise update every column it inserts, the mark among them: a re-seed never re-marks a row.
        $update = $update === null
            ? array_values(array_diff(array_keys($first), SeededTables::MARKS))
            : array_filter($update, static fn (mixed $value, int|string $key): bool => ! in_array(is_int($key) ? $value : $key, SeededTables::MARKS, true), ARRAY_FILTER_USE_BOTH);

        return parent::upsert($rows, $uniqueBy, $update);
    }

    public function update(array $values)
    {
        return parent::update($this->marksThisTable() ? array_diff_key($values, array_flip(SeededTables::MARKS)) : $values);
    }

    /**
     * @param  array<mixed>  $values  one row, or a list of rows
     * @return array<mixed>
     */
    private function marked(array $values): array
    {
        if ($values === [] || ! $this->marksThisTable()) {
            return $values;
        }

        if (! is_array(reset($values))) {
            return $this->markedRow($values);
        }

        foreach ($values as $key => $row) {
            $values[$key] = $this->markedRow($row);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function markedRow(array $row): array
    {
        if (! $this->marksThisTable()) {
            return $row;
        }

        unset($row['seeded_at'], $row['seeded_by']);

        if (Seeding::active()) {
            $row['seeded_at'] = Seeding::at();
            $row['seeded_by'] = Seeding::by();
        }

        return $row;
    }

    private function marksThisTable(): bool
    {
        if (! is_string($this->from) || ! $this->scopable()) {
            return false;
        }

        $reference = $this->reference($this->from);

        return $reference !== null && SeededTables::marked($this->connectionName(), $reference['table']);
    }

    /* ------------------------------------------------- the mark is never read back */

    /** @return array<int, \stdClass> */
    protected function runSelect()
    {
        $rows = parent::runSelect();

        if ($rows !== [] && $this->selectsMarksUnasked()) {
            foreach ($rows as $row) {
                unset($row->seeded_at, $row->seeded_by);
            }
        }

        return $rows;
    }

    public function cursor()
    {
        $rows = parent::cursor();

        return $this->selectsMarksUnasked() ? $rows->map(static function (\stdClass $row): \stdClass {
            unset($row->seeded_at, $row->seeded_by);

            return $row;
        }) : $rows;
    }

    /** A `*` (or `table.*`) over a marked table, which would hand back two columns nobody asked for. */
    private function selectsMarksUnasked(): bool
    {
        if (! $this->scopable()) {
            return false;
        }

        $wildcard = $this->columns === null;

        foreach ($this->columns ?? [] as $column) {
            $wildcard = $wildcard || (is_string($column) && ($column === '*' || str_ends_with($column, '.*')));
        }

        if (! $wildcard) {
            return false;
        }

        $tables = [$this->from];

        foreach ($this->joins ?? [] as $join) {
            $tables[] = $join->table;
        }

        foreach ($tables as $table) {
            $reference = is_string($table) ? $this->reference($table) : null;

            if ($reference !== null && SeededTables::marked($this->connectionName(), $reference['table'])) {
                return true;
            }
        }

        return false;
    }
}
