<?php

declare(strict_types=1);

namespace App\Live\Testing;

use App\Live\Live;
use App\Live\Registry;
use Closure;
use FilesystemIterator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * THE FORGOTTEN-EMITTER GUARD (DESIGN.md §2.15).
 *
 * A writer that changes a live record and forgets LiveBus::record() leaves every open screen
 * quietly wrong, and nothing else would notice. So, in the test suite only (tests/TestCase.php
 * registers it for every test), this watches every INSERT, UPDATE and DELETE on the tables the event
 * classes track (App\Live\Registry), and when a transaction COMMITS such a write with no
 * `live_events` insert on the same connection in it, the test that made it fails.
 *
 * WHAT COUNTS:
 *   - a write whose nearest caller outside vendor/ is APPLICATION code (app/, database/, routes/):
 *     a test's own fixture rows are not a writer that forgot anything;
 *   - `table` entries: every insert and delete, and every update except one that changes only
 *     columns the classes declare exempt (`table.column`), plus `updated_at`;
 *   - `table.column` entries: an update, or an upsert, that sets that column;
 *   - the transaction as it finally commits: a record in a nested transaction that commits counts
 *     for the outer one, and one that rolls back does not; a rolled-back write owes nothing;
 *   - a tracked write made outside any transaction of the application's own fails at once, since no
 *     record() can share its commit (the suite's own wrapping transaction does not count).
 * WHAT IS SKIPPED: Live::muted() scopes, and code run inside EmitterCoverage::exempt('reason', …).
 *
 * AND ITS STATIC TWIN, `writeSites()`, reads source for the writes a test never walks: query-builder
 * inserts, updates, upserts and deletes (which fire no model event) and quiet or forced model
 * saves on tracked tables. tests/Feature/Live/EveryWriterEmitsTest.php fails on any site not on its
 * list of known emitter sites.
 */
final class EmitterCoverage
{
    /**
     * Open transactions per connection, keyed by level.
     *
     * @var array<string, array<int, array{writes: list<string>, recorded: bool}>>
     */
    private static array $frames = [];

    /** @var list<string> */
    private static array $violations = [];

    /** @var list<string> */
    private static array $roots = [];

    private static int $exempt = 0;

    /** Begin watching one application's queries, forgetting anything seen before. */
    public static function watch(Application $app): void
    {
        self::$frames = [];
        self::$violations = [];
        self::$exempt = 0;
        self::$roots = array_map(self::normalise(...), [base_path('app'), base_path('database'), base_path('routes')]);

        $events = $app->make('events');

        $events->listen(TransactionBeginning::class, static fn (TransactionBeginning $event) => self::began($event->connection));
        $events->listen(TransactionCommitted::class, static fn (TransactionCommitted $event) => self::committed($event->connection));
        $events->listen(TransactionRolledBack::class, static fn (TransactionRolledBack $event) => self::rolledBack($event->connection));
        $events->listen(QueryExecuted::class, static fn (QueryExecuted $query) => self::query($query));
    }

    /** Count writes made from this directory as application writes too (a test's pretend writer). */
    public static function countWritesFrom(string $directory): void
    {
        self::$roots[] = self::normalise($directory);
    }

    /**
     * Run `$fn` with the guard stood down, for a write that is silent on purpose.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function exempt(string $reason, Closure $fn): mixed
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('An exempt write says why it is silent.');
        }

        self::$exempt++;

        try {
            return $fn();
        } finally {
            self::$exempt--;
        }
    }

    /** @return list<string> */
    public static function violations(): array
    {
        return self::$violations;
    }

    public static function forget(): void
    {
        self::$violations = [];
    }

    /**
     * Every write to a tracked table in the PHP source under `$root`.
     *
     * @param  list<string>  $tracked  `table` or `table.column` entries
     * @param  array<string, string>|null  $models  model short name => table; app/Models when null
     * @return list<array{file: string, line: int, table: string, kind: string}>
     */
    public static function writeSites(array $tracked, string $root, ?array $models = null): array
    {
        $tables = array_values(array_unique(array_map(static fn (string $entry): string => explode('.', $entry, 2)[0], $tracked)));

        if ($tables === []) {
            return [];
        }

        $models ??= self::modelTables();
        $trackedModels = array_filter($models, static fn (string $table): bool => in_array($table, $tables, true));
        $self = self::normalise(__FILE__);
        $sites = [];

        foreach (self::phpFiles($root) as $path) {
            if (self::normalise($path) === $self) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $file = self::relative($path);

            foreach ($tables as $table) {
                preg_match_all('/(?:->|::)table\(\s*[\'"]'.preg_quote($table, '/').'[\'"]\s*\)/', $source, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as [$text, $offset]) {
                    $statement = self::statementFrom($source, $offset + strlen($text));

                    if (preg_match('/->(insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|increment|decrement|incrementEach|decrementEach|truncate)\s*\(/', $statement) === 1) {
                        $sites[] = ['file' => $file, 'line' => self::lineAt($source, $offset), 'table' => $table, 'kind' => 'query'];
                    }
                }
            }

            foreach ($trackedModels as $model => $table) {
                if (preg_match('/\b'.preg_quote($model, '/').'\b/', $source) !== 1) {
                    continue;
                }

                preg_match_all('/->(saveQuietly|forceFill)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as $index => [, $offset]) {
                    // From the arrow itself, so the forceFill's own arguments are read through.
                    if ($matches[1][$index][0] === 'forceFill' && preg_match('/->save\s*\(/', self::statementFrom($source, $offset)) !== 1) {
                        continue;
                    }

                    $sites[] = ['file' => $file, 'line' => self::lineAt($source, $offset), 'table' => $table, 'kind' => 'model'];
                }
            }
        }

        usort($sites, static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['table']] <=> [$b['file'], $b['line'], $b['table']]);

        return array_values(array_unique($sites, SORT_REGULAR));
    }

    /**
     * Every model under app/Models, by short name, and the table it writes.
     *
     * @return array<string, string>
     */
    public static function modelTables(): array
    {
        $tables = [];

        foreach (self::phpFiles(app_path('Models')) as $path) {
            $name = basename($path, '.php');
            $source = (string) file_get_contents($path);

            $tables[$name] ??= preg_match('/protected\s+\$table\s*=\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $source, $match) === 1
                ? $match[1]
                : Str::snake(Str::pluralStudly($name));
        }

        ksort($tables);

        return $tables;
    }

    private static function began(Connection $connection): void
    {
        $name = $connection->getName();
        $level = $connection->transactionLevel();

        self::drop($name, $level - 1);
        self::$frames[$name][$level] = ['writes' => [], 'recorded' => false];
    }

    private static function committed(Connection $connection): void
    {
        $name = $connection->getName();
        $level = $connection->transactionLevel();
        $closed = self::$frames[$name][$level + 1] ?? null;

        self::drop($name, $level);

        if ($closed === null) {
            return;
        }

        if (isset(self::$frames[$name][$level])) {
            self::$frames[$name][$level]['writes'] = [...self::$frames[$name][$level]['writes'], ...$closed['writes']];
            self::$frames[$name][$level]['recorded'] = self::$frames[$name][$level]['recorded'] || $closed['recorded'];

            return;
        }

        if ($closed['writes'] !== [] && ! $closed['recorded']) {
            self::$violations[] = "A transaction on the '{$name}' connection committed a write to ".implode('; ', array_unique($closed['writes']))
                .' with no LiveBus::record() in it. Record the event in the same transaction, after the write (DESIGN.md §2.15).';
        }
    }

    private static function rolledBack(Connection $connection): void
    {
        self::drop($connection->getName(), $connection->transactionLevel());
    }

    /** Forget every frame above `$level` on one connection. */
    private static function drop(string $name, int $level): void
    {
        foreach (array_keys(self::$frames[$name] ?? []) as $open) {
            if ($open > $level) {
                unset(self::$frames[$name][$open]);
            }
        }
    }

    private static function query(QueryExecuted $query): void
    {
        $tracked = Registry::tracked();

        if ($tracked === []) {
            return;
        }

        $write = self::parse($query->sql);

        if ($write === null) {
            return;
        }

        $name = $query->connectionName;
        $level = $query->connection->transactionLevel();

        if ($write['table'] === 'live_events' && $write['verb'] === 'insert') {
            if (isset(self::$frames[$name][$level])) {
                self::$frames[$name][$level]['recorded'] = true;
            }

            return;
        }

        $entry = self::entryFor($write, $tracked, Registry::exempt());

        if ($entry === null || Live::isMuted() || self::$exempt > 0) {
            return;
        }

        $origin = self::origin();

        if ($origin === null) {
            return;
        }

        $what = "{$entry} ({$write['verb']}, at {$origin})";

        if (isset(self::$frames[$name][$level])) {
            self::$frames[$name][$level]['writes'][] = $what;

            return;
        }

        self::$violations[] = "A write to {$what} on the '{$name}' connection ran outside any transaction, so no LiveBus::record() can share its commit (DESIGN.md §1.3, §2.15).";
    }

    /**
     * @return array{verb: string, table: string, columns: list<string>|null}|null
     */
    private static function parse(string $sql): ?array
    {
        if (preg_match('/^\s*delete\s+`?(\w+)`?\s+from\s/i', $sql, $match) === 1) {
            return ['verb' => 'delete', 'table' => strtolower($match[1]), 'columns' => null];
        }

        if (preg_match('/^\s*(insert(?:\s+ignore)?\s+into|replace\s+into|update(?:\s+ignore)?|delete\s+from)\s+(?:`?\w+`?\.)?`?(\w+)`?/i', $sql, $match) !== 1) {
            return null;
        }

        $verb = strtolower((string) strtok($match[1], " \t\n"));
        $verb = $verb === 'replace' ? 'insert' : $verb;
        $columns = null;

        if ($verb === 'update') {
            $columns = preg_match('/\sset\s(.*?)(?:\swhere\s|\sorder\s+by\s|\slimit\s|$)/is', substr($sql, strlen($match[0])), $set) === 1
                ? self::assigned($set[1])
                : [];
        } elseif ($verb === 'insert' && preg_match('/\son\s+duplicate\s+key\s+update\s(.*)$/is', $sql, $upsert) === 1) {
            $verb = 'upsert';
            $columns = self::assigned($upsert[1]);
        }

        return ['verb' => $verb, 'table' => strtolower($match[2]), 'columns' => $columns];
    }

    /** @return list<string> */
    private static function assigned(string $clause): array
    {
        preg_match_all('/(?:`\w+`\.)?`(\w+)`\s*=/', $clause, $columns);

        return array_values(array_unique(array_map(strtolower(...), $columns[1])));
    }

    /**
     * The tracked entry a write falls under, or null.
     *
     * @param  array{verb: string, table: string, columns: list<string>|null}  $write
     * @param  list<string>  $tracked
     * @param  array<string, string>  $exempt
     */
    private static function entryFor(array $write, array $tracked, array $exempt): ?string
    {
        foreach ($tracked as $entry) {
            [$table, $column] = array_pad(explode('.', $entry, 2), 2, null);

            if ($table !== $write['table']) {
                continue;
            }

            if ($column === null) {
                if ($write['verb'] === 'update' && self::onlyExempt($table, $write['columns'] ?? [], $exempt)) {
                    continue;
                }

                return $entry;
            }

            if (in_array($write['verb'], ['update', 'upsert'], true) && in_array($column, $write['columns'] ?? [], true)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $exempt
     */
    private static function onlyExempt(string $table, array $columns, array $exempt): bool
    {
        $changed = array_values(array_diff($columns, ['updated_at']));

        if ($changed === []) {
            return false;
        }

        foreach ($changed as $column) {
            if (! isset($exempt[$table.'.'.$column])) {
                return false;
            }
        }

        return true;
    }

    /**
     * `path:line` of the nearest caller that is application code, or null when the nearest caller
     * of our own is a test.
     *
     * Everything else is walked past: the framework, Pest, and any package, wherever it lives. A
     * package's files are not always under this checkout's vendor/ (a worktree's vendor/ can be a
     * directory of junctions into another), so "not ours" is decided by what IS ours, not by
     * where vendor/ happens to be. So is the query builder of our own that every query is built by
     * (M33 §5) and the connection that hands it out: the framework's in all but where they live.
     */
    private static function origin(): ?string
    {
        $self = self::normalise(__FILE__);
        $tests = self::normalise(base_path('tests'));
        $machinery = [
            self::normalise(app_path('Support/Seeded/SampleAwareBuilder.php')),
            self::normalise(app_path('Support/UtcMySqlConnection.php')),
        ];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }

            $file = self::normalise($frame['file']);

            if ($file === $self || in_array($file, $machinery, true)) {
                continue;
            }

            foreach (self::$roots as $root) {
                if (str_starts_with($file, $root.'/')) {
                    return self::relative($frame['file']).':'.($frame['line'] ?? 0);
                }
            }

            if (str_starts_with($file, $tests.'/')) {
                return null;
            }
        }

        return null;
    }

    /** The source from `$offset` to the end of its statement, or of the call it is an argument of. */
    private static function statementFrom(string $source, int $offset): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($i = $offset; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                if ($depth === 0) {
                    return substr($source, $offset, $i - $offset);
                }

                $depth--;
            } elseif ($char === ';' && $depth === 0) {
                return substr($source, $offset, $i - $offset);
            }
        }

        return substr($source, $offset);
    }

    private static function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /** @return list<string> */
    private static function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $path): string
    {
        $base = self::normalise(base_path());
        $path = self::normalise($path);

        return str_starts_with($path, $base.'/') ? substr($path, strlen($base) + 1) : $path;
    }

    /** One spelling for a path: resolved, forward slashes, no trailing slash. */
    private static function normalise(string $path): string
    {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');
    }
}
