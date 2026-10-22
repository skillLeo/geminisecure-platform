<?php

declare(strict_types=1);

namespace App\Support\Seeded;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WHICH ESTATES HAVE RETIRED THEIR SAMPLE DATA, AND WHAT THAT HIDES (M33 §5, KI-25).
 *
 * `launch:retire-sample-data --confirm` writes one row to `sample_data_retirements` and nothing else of the estate's:
 * no ledger line, payment, invoice or audit row is touched, and nothing is deleted. From then on every query the two
 * connections run — Eloquent and `DB::table` alike — leaves out the estate's sample rows (`SampleAwareBuilder` adds the
 * condition this class writes). A row is SAMPLE when a seeder marked it (`seeded_at`, M33 §5.2), or when it sits in a
 * range the demonstration seed recorded before the mark existed (`sample_data_ranges`, M8 §4.4) or that the retirement
 * recorded for the company seed, which kept no range. It is shown after all when a real record points at it: a real
 * person living in a seeded household keeps the household (the retirement's `kept` ids, worked out when it was made).
 *
 * THE STATE IS READ ONCE, NEVER PER QUERY. Held in the cache (Redis on the server) and in memory for the request, so a
 * page pays no query for it; `forget()` is called by the command once its write has committed. A long-lived worker
 * reads it again after `MEMO_SECONDS`. With no retirement anywhere — every estate today — nothing is added to any query.
 *
 * WHAT NEVER SEES THE SCOPE: a seeding run (`Seeding`), the commands that keep the database rather than show it
 * (`BLIND_COMMANDS`: migrations, grants, gates, the purge, this command itself), and a number generator that must see
 * every number already taken (`seeingEverything()` — a hidden row still holds its reference in a unique index).
 */
final class Retirements
{
    public const TABLE = 'sample_data_retirements';

    public const CACHE_KEY = 'gs:sample-data:retirements:v1';

    /** How long a long-lived process (a queue worker, the socket server) trusts what it read. */
    private const MEMO_SECONDS = 15;

    /** The commands that see every row, retired or not: they keep the database rather than show it. */
    public const BLIND_COMMANDS = [
        'launch:retire-sample-data',
        'phoenix:demo-purge',
        'migrate',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'tenants:migrate',
        'tenants:migrate-fresh',
        'tenants:rollback',
        'tenants:seed',
        'grants:append-only',
        'grants:estates',
    ];

    /** …and every command whose name starts so: the gates check the database itself. */
    public const BLIND_PREFIXES = ['gate:'];

    private const NONE = ['estates' => [], 'central' => null];

    /** @var array{estates: array<string, array{tenant: string, retirement: int, spans: array<string, list<array{0: int, 1: int}>>, kept: array<string, list<int>>}>, central: array{tenants: list<string>, spans: array<string, list<array{0: int, 1: int}>>, kept: array<string, list<int>>}|null}|null */
    private static ?array $state = null;

    private static float $readAt = 0.0;

    private static bool $reading = false;

    private static int $blind = 0;

    public static function blindCommand(string $command): bool
    {
        if (in_array($command, self::BLIND_COMMANDS, true) || in_array($command, Seeding::SEEDING_COMMANDS, true)) {
            return true;
        }

        foreach (self::BLIND_PREFIXES as $prefix) {
            if (str_starts_with($command, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function beginBlind(): void
    {
        self::$blind++;
    }

    public static function endBlind(): void
    {
        self::$blind = max(0, self::$blind - 1);
    }

    /** A queued job, or a test, starts seeing what it should: whatever a command that threw left switched on. */
    public static function resetBlind(): void
    {
        self::$blind = 0;
    }

    /** True while nothing is hidden from this process: a seeding run, a blind command, `seeingEverything()`. */
    public static function blind(): bool
    {
        return self::$blind > 0 || Seeding::active();
    }

    /**
     * Run `$read` seeing every row, retired or not — for a number generator, whose next reference must be past every
     * one a unique index already holds, hidden or shown.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public static function seeingEverything(Closure $read): mixed
    {
        self::beginBlind();

        try {
            return $read();
        } finally {
            self::endBlind();
        }
    }

    /** Is any estate's sample data retired? The question every query asks first, answered from memory. */
    public static function any(): bool
    {
        $state = self::state();

        return $state['estates'] !== [] || $state['central'] !== null;
    }

    /**
     * The retirement of the estate whose database this is, if it has one.
     *
     * @return array{tenant: string, retirement: int, spans: array<string, list<array{0: int, 1: int}>>, kept: array<string, list<int>>}|null
     */
    public static function estate(string $database): ?array
    {
        return self::state()['estates'][$database] ?? null;
    }

    /** "r12" — the active retirement of this estate database, or "r0": a stamp for anything cached from its figures. */
    public static function stamp(string $database): string
    {
        return 'r'.(self::estate($database)['retirement'] ?? 0);
    }

    /**
     * The condition under which a row of this table is SHOWN, as binding-free SQL — or null when nothing of the table is
     * hidden on this connection. Binding-free because it is added to queries whose bindings are already fixed (a join's
     * are merged when the join is made); every value in it is an integer or a validated estate key.
     *
     * @param  string  $connection  'tenant' or 'mysql'
     * @param  string  $database  the database the table is in
     * @param  string  $qualifier  how the query names the row: an alias, or the table itself, unquoted
     */
    public static function visibleSql(string $connection, string $database, string $table, string $qualifier): ?string
    {
        if (self::blind() || ! SeededTables::retired($connection, $table)) {
            return null;
        }

        $state = self::state();

        if ($connection === 'tenant') {
            $estate = $state['estates'][$database] ?? null;

            return $estate === null ? null : self::shownSql('tenant', $table, $qualifier, [], $estate['spans'], $estate['kept'][$table] ?? []);
        }

        $central = $state['central'];

        return $central === null ? null : self::shownSql('mysql', $table, $qualifier, $central['tenants'], $central['spans'], $central['kept'][$table] ?? []);
    }

    /**
     * Is this row SAMPLE: marked by a seeder (on the platform's tables, and these estates' own), inside a range recorded
     * before the mark existed, or — for a table whose rows have no id (`SeededTables::ESTATE_THROUGH_PARENT`) — hanging
     * from a row that is. Binding-free SQL.
     *
     * @param  list<string>  $tenants  the estates, for a platform table (ignored for an estate's own)
     * @param  array<string, list<array{0: int, 1: int}>>  $spans  by table: ranges (from, to], from exclusive
     */
    public static function sampleSql(string $connection, string $table, string $qualifier, array $tenants, array $spans): string
    {
        $q = self::wrap($qualifier);
        $parts = [];

        // A table that carries no mark of its own (a ballot's marks) is sample only through what it hangs from.
        if (SeededTables::marked($connection, $table)) {
            $parts[] = $connection === 'mysql'
                ? "({$q}.`seeded_at` IS NOT NULL AND ".self::ownedSql($table, $qualifier, $tenants).')'
                : "{$q}.`seeded_at` IS NOT NULL";
        }

        foreach ($spans[$table] ?? [] as [$from, $to]) {
            $parts[] = sprintf('%s.`id` BETWEEN %d AND %d', $q, (int) $from + 1, (int) $to);
        }

        if ($connection === 'tenant' && array_key_exists($table, SeededTables::ESTATE_THROUGH_PARENT)) {
            [$key, $parent] = SeededTables::ESTATE_THROUGH_PARENT[$table];
            $parts[] = "{$q}.`{$key}` IN (SELECT `gs_up`.`id` FROM `{$parent}` AS `gs_up` WHERE ".self::sampleSql('tenant', $parent, 'gs_up', [], $spans).')';
        }

        return $parts === [] ? '(FALSE)' : '('.implode(' OR ', $parts).')';
    }

    /**
     * Does this platform row belong to one of these estates: by its column, through the parent it hangs from, or — for
     * a platform pay run — because it has a payslip and every payslip on it is theirs.
     *
     * @param  list<string>  $tenants
     */
    public static function ownedSql(string $table, string $qualifier, array $tenants): string
    {
        $q = self::wrap($qualifier);
        $keys = array_values(array_filter($tenants, static fn (string $t): bool => preg_match('/^[a-z0-9_-]+$/', $t) === 1));

        if ($keys === []) {
            return 'FALSE';
        }

        $in = implode(', ', array_map(static fn (string $t): string => "'{$t}'", $keys));
        $how = SeededTables::CENTRAL_MARKED[$table] ?? null;

        return match ($how[0] ?? null) {
            'column' => "{$q}.`{$how[1]}` IN ({$in})",
            'parent' => "{$q}.`{$how[1]}` IN (SELECT `gs_parent`.`id` FROM `{$how[2]}` AS `gs_parent` WHERE `gs_parent`.`tenant_id` IN ({$in}))",
            'payroll' => "EXISTS (SELECT 1 FROM `payslips` AS `gs_slip` WHERE `gs_slip`.`payroll_run_id` = {$q}.`id` AND `gs_slip`.`tenant_id` IN ({$in}))"
                ." AND NOT EXISTS (SELECT 1 FROM `payslips` AS `gs_slip` WHERE `gs_slip`.`payroll_run_id` = {$q}.`id` AND (`gs_slip`.`tenant_id` IS NULL OR `gs_slip`.`tenant_id` NOT IN ({$in})))",
            default => 'FALSE',
        };
    }

    /**
     * The condition under which a row is SHOWN: it is not sample, or a real record points at it.
     *
     * @param  list<string>  $tenants
     * @param  array<string, list<array{0: int, 1: int}>>  $spans  by table
     * @param  list<int>  $kept  this table's
     */
    public static function shownSql(string $connection, string $table, string $qualifier, array $tenants, array $spans, array $kept): string
    {
        $sql = 'NOT '.self::sampleSql($connection, $table, $qualifier, $tenants, $spans);

        return $kept === []
            ? '('.$sql.')'
            : '('.$sql.' OR '.self::wrap($qualifier).'.`id` IN ('.implode(', ', array_map(intval(...), $kept)).'))';
    }

    /**
     * " AND (…) AND (…)" for a raw statement, or "" when nothing it reads is hidden.
     *
     * @param  array<string, string>  $tables  alias => table, as the statement names them
     */
    public static function and(string $connection, array $tables): string
    {
        $database = (string) DB::connection($connection)->getDatabaseName();
        $sql = '';

        foreach ($tables as $alias => $table) {
            $shown = self::visibleSql($connection, $database, $table, $alias);
            $sql .= $shown === null ? '' : ' AND '.$shown;
        }

        return $sql;
    }

    /** `db`.`table` or `alias`, quoted for MySQL. */
    public static function wrap(string $qualifier): string
    {
        return implode('.', array_map(
            static fn (string $part): string => '`'.str_replace('`', '``', trim($part, '`')).'`',
            explode('.', $qualifier),
        ));
    }

    /**
     * Read once: memory, then the cache, then the table.
     *
     * @return array{estates: array<string, array{tenant: string, retirement: int, spans: array<string, list<array{0: int, 1: int}>>, kept: array<string, list<int>>}>, central: array{tenants: list<string>, spans: array<string, list<array{0: int, 1: int}>>, kept: array<string, list<int>>}|null}
     */
    public static function state(): array
    {
        if (self::$reading) {
            return self::NONE;
        }

        $now = microtime(true);
        if (self::$state !== null && (app()->runningUnitTests() || $now - self::$readAt < self::MEMO_SECONDS || ! app()->runningInConsole())) {
            return self::$state;
        }

        self::$reading = true;

        try {
            $state = null;

            try {
                $cached = Cache::get(self::CACHE_KEY);
                $state = is_array($cached) && array_key_exists('estates', $cached) ? $cached : null;
            } catch (Throwable) {
                $state = null;
            }

            if ($state === null) {
                $state = self::read();

                if ($state !== null) {
                    try {
                        Cache::forever(self::CACHE_KEY, $state);
                    } catch (Throwable) {
                        // The cache is a convenience: the table is the truth, and it was read.
                    }
                }
            }

            self::$state = $state ?? self::NONE;
            self::$readAt = $now;

            return self::$state;
        } finally {
            self::$reading = false;
        }
    }

    /** Forget what was read — after a retirement or its undoing commits, and between tests. */
    public static function forget(): void
    {
        self::$state = null;
        self::$readAt = 0.0;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // As above: the next read goes to the table.
        }
    }

    /** For a test's teardown: forget only if something was retired, so an untouched process keeps its read. */
    public static function forgetIfAny(): void
    {
        if (self::$state !== null && (self::$state['estates'] !== [] || self::$state['central'] !== null)) {
            self::forget();
        }
    }

    /** @return array{estates: array<string, array<string, mixed>>, central: array<string, mixed>|null}|null null when the table cannot be read */
    private static function read(): ?array
    {
        try {
            $rows = DB::connection('mysql')->table(self::TABLE)->whereNull('undone_at')->orderBy('id')->get(['id', 'tenant_id', 'estate_database', 'spans', 'kept']);
        } catch (Throwable) {
            return null;
        }

        $estates = [];
        $central = ['tenants' => [], 'spans' => [], 'kept' => []];

        foreach ($rows as $row) {
            $tenant = (string) $row->tenant_id;
            if (preg_match('/^[a-z0-9_-]+$/', $tenant) !== 1) {
                continue;
            }

            $spans = json_decode((string) $row->spans, true);
            $kept = json_decode((string) $row->kept, true);
            $spans = is_array($spans) ? $spans : [];
            $kept = is_array($kept) ? $kept : [];

            $estates[(string) $row->estate_database] = [
                'tenant' => $tenant,
                'retirement' => (int) $row->id,
                'spans' => self::spansOf($spans['tenant'] ?? []),
                'kept' => self::idsOf($kept['tenant'] ?? []),
            ];

            $central['tenants'][] = $tenant;
            foreach (self::spansOf($spans['mysql'] ?? []) as $table => $list) {
                $central['spans'][$table] = [...($central['spans'][$table] ?? []), ...$list];
            }
            foreach (self::idsOf($kept['mysql'] ?? []) as $table => $ids) {
                $central['kept'][$table] = array_values(array_unique([...($central['kept'][$table] ?? []), ...$ids]));
            }
        }

        return ['estates' => $estates, 'central' => $central['tenants'] === [] ? null : $central];
    }

    /** @return array<string, list<array{0: int, 1: int}>> */
    private static function spansOf(mixed $byTable): array
    {
        $out = [];
        foreach (is_array($byTable) ? $byTable : [] as $table => $spans) {
            foreach (is_array($spans) ? $spans : [] as $span) {
                if (is_array($span) && count($span) === 2) {
                    $out[(string) $table][] = [(int) $span[0], (int) $span[1]];
                }
            }
        }

        return $out;
    }

    /** @return array<string, list<int>> */
    private static function idsOf(mixed $byTable): array
    {
        $out = [];
        foreach (is_array($byTable) ? $byTable : [] as $table => $ids) {
            $out[(string) $table] = array_values(array_map(intval(...), is_array($ids) ? $ids : []));
        }

        return $out;
    }
}
