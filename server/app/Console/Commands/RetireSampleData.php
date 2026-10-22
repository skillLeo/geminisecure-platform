<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\SampleData;
use App\Support\Seeded\Retirements;
use App\Support\Seeded\SeededTables;
use App\Support\Seeded\SeesEveryRow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 *   php artisan launch:retire-sample-data --tenant=phoenixparkvillage1                  what it would hide, table by table
 *   php artisan launch:retire-sample-data --tenant=phoenixparkvillage1 --confirm        write the invoices out, then hide it
 *   php artisan launch:retire-sample-data --tenant=phoenixparkvillage1 --undo           what would be shown again
 *   php artisan launch:retire-sample-data --tenant=phoenixparkvillage1 --undo --confirm show it again
 *
 * LAUNCH DAY, WITHOUT DELETING ANYTHING (M33 §5, KI-25 — the user's decision). An estate's sample data stops appearing
 * in every list, count, report and total, and every row of it stays in the database:
 *
 *   SAMPLE      a row a seeder marked (`seeded_at`), or one inside a range the demonstration seed recorded before the
 *               mark existed (`sample_data_ranges`), or one `company:demo-seed` wrote, known by what it says
 *               (`CompanyDemoSeed::rowsAt`) because that command recorded no range.
 *   KEPT SHOWN  a sample row a real record points at — a real person in a seeded household keeps the household — found
 *               through every foreign key the two databases declare, until nothing more is found.
 *   NEVER TOUCHED  what the estate needs on launch day whoever wrote it first (`SeededTables::ESTATE_KEPT`,
 *               `CENTRAL_KEPT`), and who may sign in: that is DEMO_MODE's switch, not this command's.
 *
 * WHAT IT WRITES: one row in `sample_data_retirements` and one audit entry. Not a ledger line, a payment, an invoice
 * or an audit row of the estate's; nothing is updated or deleted. The scope that hides the rows is
 * `App\Support\Seeded\SampleAwareBuilder`, reading `Retirements`.
 *
 * DRY RUN UNLESS --confirm. With it, the invoices — and the estate's bills and charges beside them — are written out
 * FIRST (M33 §5.6), and nothing is hidden unless that file is whole. REVERSIBLE: --undo --confirm shows every row again,
 * for this estate only, and the retirement stays on record. The demonstration estate is refused: all of it is sample.
 */
final class RetireSampleData extends Command
{
    // It reads and counts the very rows a retirement hides.
    use SeesEveryRow;

    protected $signature = 'launch:retire-sample-data
        {--tenant= : the estate (phoenixparkvillage1 on production)}
        {--confirm : make the change; without it, only say what it would be}
        {--undo : show the estate\'s sample data again}
        {--export-to= : where the invoices are written before anything is hidden (default: storage/app/private/sample-data-exports)}';

    protected $description = "Hide an estate's sample data from every list, count, report and total, keeping every row. A dry run unless --confirm; --undo shows it again.";

    /** What is written out before anything is hidden: the invoices, as M33 §5.6 asks, and the estate's billing beside them. */
    private const EXPORTED = [
        ['mysql', 'invoices'],
        ['mysql', 'invoice_lines'],
        ['mysql', 'credit_notes'],
        ['tenant', 'bills'],
        ['tenant', 'charges'],
    ];

    /** A column that names a sample row well enough to recognise it, tried in this order. */
    private const LABELS = ['name', 'full_name', 'reference', 'employee_number', 'title', 'number', 'code', 'slug'];

    /** Unique keys a hidden row goes on holding, which a person could choose again: [connection, table, columns]. */
    private const HELD_KEYS = [
        ['tenant', 'payroll_runs', ['slug']],
        ['tenant', 'statutory_filings', ['form_code', 'period_label']],
        ['tenant', 'ballots', ['year', 'code']],
        ['tenant', 'bank_reconciliations', ['statement_date']],
        ['mysql', 'invoices', ['reference']],
        ['mysql', 'guards', ['employee_number']],
        ['mysql', 'patrol_checkpoints', ['code']],
    ];

    public function handle(AuditLogger $audit): int
    {
        $key = (string) ($this->option('tenant') ?? '');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error($key === '' ? 'Say which estate: --tenant=phoenixparkvillage1. Nothing was changed.' : "No estate with the id \"{$key}\". Nothing was changed.");

            return self::FAILURE;
        }

        if ((bool) $estate->demo) {
            $this->error("{$estate->name} is the demonstration estate: every row of it is sample data, and the app stores' reviewers sign in to it. There is nothing real to keep apart from it, so it is never retired. Nothing was changed.");

            return self::FAILURE;
        }

        if (! Schema::connection('mysql')->hasTable(Retirements::TABLE) || ! Schema::connection('mysql')->hasColumn('guards', 'seeded_at')) {
            $this->error('The platform database has not been migrated for this yet (M33 §5): run the migrations first. Nothing was changed.');

            return self::FAILURE;
        }

        $active = DB::connection('mysql')->table(Retirements::TABLE)->where('tenant_id', $key)->whereNull('undone_at')->orderByDesc('id')->first();

        return $this->option('undo') ? $this->undo($estate, $active, $audit) : $this->retire($estate, $active, $audit);
    }

    private function retire(Tenant $estate, ?object $active, AuditLogger $audit): int
    {
        $key = (string) $estate->getTenantKey();

        if ($active !== null) {
            $this->error("{$estate->name}'s sample data has been retired since {$active->retired_at} UTC, by {$active->retired_by}. To show it again: --undo --confirm. Nothing was changed.");

            return self::FAILURE;
        }

        $plan = $estate->run(function () use ($key): ?array {
            if (! Schema::connection('tenant')->hasColumn('households', 'seeded_at')) {
                return null;
            }

            return $this->plan($key);
        });

        if ($plan === null) {
            $this->error("{$estate->name}'s database has not been migrated for this yet (M33 §5): run the estate migrations first. Nothing was changed.");

            return self::FAILURE;
        }

        $database = (string) $estate->database()->getName();
        $this->report($estate, $database, $plan);
        $hidden = array_sum(array_column($plan['counts'], 'hidden'));

        if ($hidden === 0) {
            $this->info("Nothing of {$estate->name}'s is sample data, so there is nothing to retire. Nothing was changed.");

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->info('Nothing was changed. Run again with --confirm to retire it: the invoices are written out first, and nothing is hidden unless that file is whole.');

            return self::SUCCESS;
        }

        try {
            [$path, $sha, $rows] = $estate->run(fn (): array => $this->export($key, $plan));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage().' Nothing was hidden.');

            return self::FAILURE;
        }

        $by = $this->by();
        $now = Carbon::now('UTC');

        DB::connection('mysql')->transaction(function () use ($key, $database, $plan, $path, $sha, $rows, $by, $now, $hidden, $audit): void {
            $id = DB::connection('mysql')->table(Retirements::TABLE)->insertGetId([
                'tenant_id' => $key,
                'estate_database' => $database,
                'retired_at' => $now,
                'retired_by' => $by,
                'export_path' => $path,
                'export_sha256' => $sha,
                'export_rows' => $rows,
                'spans' => json_encode($plan['spans'], JSON_THROW_ON_ERROR),
                'kept' => json_encode($plan['kept'], JSON_THROW_ON_ERROR),
                'counts' => json_encode($plan['counts'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $audit->record(
                action: 'sample_data.retired',
                entityType: 'Tenant',
                entityId: $key,
                after: [
                    'retirement' => $id,
                    'hidden' => $hidden,
                    'kept_shown' => array_sum(array_column($plan['counts'], 'kept')),
                    'export' => ['path' => $path, 'sha256' => $sha, 'rows' => $rows],
                    'by' => 'launch:retire-sample-data',
                    'why' => 'launch day: sample data hidden, every row kept (M33 §5, KI-25)',
                ],
                tenantId: $key,
                actorName: $by,
            );
        });

        Retirements::forget();

        $this->info("Retired: {$hidden} rows of {$estate->name}'s sample data are hidden from every list, count, report and total. Every row is still in the database.");
        $this->line("  The export, written first: {$path} ({$rows} rows, sha256 {$sha}).");
        $this->line('  To show it all again: php artisan launch:retire-sample-data --tenant='.$key.' --undo --confirm');

        return self::SUCCESS;
    }

    private function undo(Tenant $estate, ?object $active, AuditLogger $audit): int
    {
        $key = (string) $estate->getTenantKey();

        if ($active === null) {
            $this->error("{$estate->name}'s sample data is not retired, so there is nothing to show again. Nothing was changed.");

            return self::FAILURE;
        }

        $counts = json_decode((string) $active->counts, true);
        $hidden = is_array($counts) ? array_sum(array_column($counts, 'hidden')) : 0;

        $this->line("{$estate->name}'s sample data was retired at {$active->retired_at} UTC by {$active->retired_by}: {$hidden} rows hidden.");
        $this->line('  Undoing shows every one of them again, at this estate only. The retirement stays on record, and the export '.($active->export_path ?? '(none)').' stays where it is.');

        if (! $this->option('confirm')) {
            $this->info('Nothing was changed. Run again with --undo --confirm to show it again.');

            return self::SUCCESS;
        }

        $by = $this->by();

        DB::connection('mysql')->transaction(function () use ($active, $key, $hidden, $by, $audit): void {
            DB::connection('mysql')->table(Retirements::TABLE)->where('id', $active->id)->whereNull('undone_at')
                ->update(['undone_at' => Carbon::now('UTC'), 'undone_by' => $by, 'updated_at' => Carbon::now('UTC')]);

            $audit->record(
                action: 'sample_data.retirement_undone',
                entityType: 'Tenant',
                entityId: $key,
                before: ['retirement' => (int) $active->id, 'retired_at' => (string) $active->retired_at, 'hidden' => $hidden],
                after: ['shown_again' => $hidden, 'by' => 'launch:retire-sample-data --undo'],
                tenantId: $key,
                actorName: $by,
            );
        });

        Retirements::forget();

        $this->info("Shown again: {$estate->name}'s {$hidden} sample rows are back in every list, count, report and total.");

        return self::SUCCESS;
    }

    /**
     * Everything the retirement would hide and keep, worked out from the two databases as they are. Inside the estate.
     *
     * @return array{spans: array{tenant: array<string, list<array{0: int, 1: int}>>, mysql: array<string, list<array{0: int, 1: int}>>}, kept: array{tenant: array<string, list<int>>, mysql: array<string, list<int>>}, why: array{tenant: array<string, array<string, int>>, mysql: array<string, array<string, int>>}, counts: list<array{connection: string, table: string, rows: int, sample: int, kept: int, hidden: int}>}
     */
    private function plan(string $key): array
    {
        $spans = $this->spans($key);
        [$keptEstate, $whyEstate] = $this->closure('tenant', [], $spans['tenant']);
        [$keptCentral, $whyCentral] = $this->closure('mysql', [$key], $spans['mysql']);
        $kept = ['tenant' => $keptEstate, 'mysql' => $keptCentral];

        return [
            'spans' => $spans,
            'kept' => $kept,
            'why' => ['tenant' => $whyEstate, 'mysql' => $whyCentral],
            'counts' => [
                ...$this->counts('tenant', [], $spans['tenant'], $kept['tenant']),
                ...$this->counts('mysql', [$key], $spans['mysql'], $kept['mysql']),
            ],
        ];
    }

    /**
     * The ranges recorded before the mark existed: the demonstration seed's own, and the company seed's rows found by
     * what they say. Only tables that are retired; ranges of a kept table (the lots, the phases) stay what they are.
     *
     * @return array{tenant: array<string, list<array{0: int, 1: int}>>, mysql: array<string, list<array{0: int, 1: int}>>}
     */
    private function spans(string $key): array
    {
        $spans = ['tenant' => [], 'mysql' => []];

        foreach (DB::connection('mysql')->table('sample_data_ranges')->where('tenant_id', $key)->orderBy('id')->get(['connection', 'table_name', 'from_id', 'to_id']) as $range) {
            $connection = (string) $range->connection;
            $table = (string) $range->table_name;

            if (($connection === 'tenant' || $connection === 'mysql') && SeededTables::retired($connection, $table)) {
                $spans[$connection][$table][] = [(int) $range->from_id, (int) $range->to_id];
            }
        }

        foreach (CompanyDemoSeed::rowsAt($key) as $table => $ids) {
            $found = $ids->orderBy('id')->pluck('id')->map(intval(...))->all();

            if ($found !== [] && SeededTables::retired('mysql', $table)) {
                $spans['mysql'][$table] = [...($spans['mysql'][$table] ?? []), ...SampleData::spans($found)];
            }
        }

        foreach ($spans as $connection => $byTable) {
            foreach ($byTable as $table => $list) {
                $spans[$connection][$table] = self::merged($list);
            }
        }

        return $spans;
    }

    /**
     * The sample rows a real record points at, through every foreign key the database declares, until nothing more is
     * found: a row kept shown keeps what it points at shown too. A sample row in a kept table keeps nothing — a seeded
     * phone binding does not keep a seeded officer — and machinery (a delivery log, a sign-in code) keeps nothing.
     *
     * @param  list<string>  $tenants
     * @param  array<string, list<array{0: int, 1: int}>>  $spans
     * @return array{0: array<string, list<int>>, 1: array<string, array<string, int>>} kept ids, and what kept them
     */
    private function closure(string $connection, array $tenants, array $spans): array
    {
        $db = DB::connection($connection);
        $schema = (string) $db->getDatabaseName();

        $keys = [];
        foreach ($db->select(
            'SELECT TABLE_NAME AS child, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS parent, REFERENCED_COLUMN_NAME AS pcol
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY TABLE_NAME, COLUMN_NAME',
            [$schema, $schema],
        ) as $fk) {
            if (SeededTables::retired($connection, (string) $fk->parent) && SeededTables::marked($connection, (string) $fk->child)) {
                $keys[] = [(string) $fk->child, (string) $fk->col, (string) $fk->parent, (string) $fk->pcol];
            }
        }

        $kept = [];
        $why = [];

        for ($round = 0; $round < 25; $round++) {
            $found = 0;

            foreach ($keys as [$child, $column, $parent, $parentColumn]) {
                $candidate = Retirements::sampleSql($connection, $parent, 'gs_p', $tenants, $spans);
                if (($kept[$parent] ?? []) !== []) {
                    $candidate .= ' AND `gs_p`.`id` NOT IN ('.implode(', ', $kept[$parent]).')';
                }

                $keeper = 'NOT '.Retirements::sampleSql($connection, $child, 'gs_c', $tenants, $spans);
                if (SeededTables::retired($connection, $child) && ($kept[$child] ?? []) !== []) {
                    $keeper = '('.$keeper.' OR `gs_c`.`id` IN ('.implode(', ', $kept[$child]).'))';
                }

                foreach ($db->select(
                    "SELECT `gs_p`.`id` AS id, COUNT(*) AS n FROM `{$parent}` AS `gs_p` JOIN `{$child}` AS `gs_c` ON `gs_c`.`{$column}` = `gs_p`.`{$parentColumn}`"
                    ." WHERE {$candidate} AND {$keeper} GROUP BY `gs_p`.`id`",
                ) as $row) {
                    $kept[$parent][] = (int) $row->id;
                    $why[$parent][$child] = ($why[$parent][$child] ?? 0) + (int) $row->n;
                    $found++;
                }
            }

            if ($found === 0) {
                break;
            }
        }

        foreach ($kept as $table => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            $kept[$table] = $ids;
        }

        return [$kept, $why];
    }

    /**
     * Table by table: the estate's rows, how many are sample, how many of those stay shown, and how many are hidden.
     *
     * @param  list<string>  $tenants
     * @param  array<string, list<array{0: int, 1: int}>>  $spans
     * @param  array<string, list<int>>  $kept
     * @return list<array{connection: string, table: string, rows: int, sample: int, kept: int, hidden: int}>
     */
    private function counts(string $connection, array $tenants, array $spans, array $kept): array
    {
        $db = DB::connection($connection);
        $tables = $connection === 'tenant' ? SeededTables::estateRetired() : SeededTables::centralRetired();
        $out = [];

        foreach ($tables as $table) {
            if (! $db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $sample = Retirements::sampleSql($connection, $table, 'gs_t', $tenants, $spans);
            $theirs = $connection === 'tenant' ? 'TRUE' : '('.Retirements::ownedSql($table, 'gs_t', $tenants).' OR '.$sample.')';
            $row = $db->selectOne("SELECT COALESCE(SUM({$theirs}), 0) AS rows_, COALESCE(SUM({$sample}), 0) AS sample FROM `{$table}` AS `gs_t`");
            $keptHere = count($kept[$table] ?? []);

            $out[] = [
                'connection' => $connection,
                'table' => $table,
                'rows' => (int) $row->rows_,
                'sample' => (int) $row->sample,
                'kept' => $keptHere,
                'hidden' => max(0, (int) $row->sample - $keptHere),
            ];
        }

        return $out;
    }

    /** @param  array{spans: array<string, mixed>, kept: array<string, mixed>, why: array<string, mixed>, counts: list<array<string, mixed>>}  $plan */
    private function report(Tenant $estate, string $database, array $plan): void
    {
        $key = (string) $estate->getTenantKey();

        $this->line("{$estate->name} ({$key}) — the estate's database {$database}, and its rows in the platform's.");
        $this->line('What it hides. Every row stays in the database; only lists, counts, reports and totals stop showing them.');

        $rows = [];
        foreach ($plan['counts'] as $count) {
            if ($count['rows'] > 0 || $count['sample'] > 0) {
                $rows[] = [$count['connection'] === 'tenant' ? 'estate' : 'platform', $count['table'], number_format($count['rows']), number_format($count['sample']), number_format($count['kept']), number_format($count['hidden'])];
            }
        }
        $this->table(['Database', 'Table', 'Rows', 'Sample', 'Kept shown', 'Hidden'], $rows);
        $this->line(sprintf(
            '  In all: %s rows hidden, %s sample rows kept shown, in %d tables. Tables with no row of this estate are left out.',
            number_format(array_sum(array_column($plan['counts'], 'hidden'))),
            number_format(array_sum(array_column($plan['counts'], 'kept'))),
            count($rows),
        ));

        $this->keptReport($estate, $plan);
        $estate->run(fn () => $this->moneyReport($key, $plan));
        $estate->run(fn () => $this->heldReport($key, $plan));

        $this->line('Never touched, whoever wrote them first: the lots and phases, the chart of accounts and receipt counter, the fee schedule and its rate changes, the arrears ladder, the amenities and their slots, the settings and features; on the platform, the gates, the pass-signing key, the emergency numbers, the site, the contract, the adoption figures — and who may sign in (a seat at the estate, a resident\'s sign-in address, a bound phone). Switching the demonstration\'s accounts off is DEMO_MODE\'s, in LAUNCH_CHECKLIST.');

        $exported = 0;
        foreach ($plan['counts'] as $count) {
            if (in_array([$count['connection'], $count['table']], self::EXPORTED, true)) {
                $exported += $count['hidden'];
            }
        }
        $this->line('Written out first, with --confirm: '.number_format($exported).' rows — the invoices, their lines and credit notes, and the estate\'s bills and charges that are hidden — to '.$this->exportDirectory().'. Nothing is hidden unless that file is whole.');
        $this->line('Reversible: --undo --confirm shows every row again, at this estate only; the retirement and the export stay on record.');
    }

    /** @param  array{kept: array<string, array<string, list<int>>>, why: array<string, array<string, array<string, int>>>}  $plan */
    private function keptReport(Tenant $estate, array $plan): void
    {
        if ($plan['kept']['tenant'] === [] && $plan['kept']['mysql'] === []) {
            $this->line('Kept shown: none. No real record points at a sample row.');

            return;
        }

        $this->line('Kept shown, because a real record points at them (the real records are counted, never printed):');

        foreach (['tenant', 'mysql'] as $connection) {
            foreach ($plan['kept'][$connection] as $table => $ids) {
                $labels = $connection === 'tenant'
                    ? $estate->run(fn (): array => $this->labels('tenant', $table, $ids))
                    : $this->labels('mysql', $table, $ids);
                $reasons = implode(', ', array_map(
                    static fn (string $child, int $n): string => $n.' row'.($n === 1 ? '' : 's').' in '.$child,
                    array_keys($plan['why'][$connection][$table] ?? []),
                    $plan['why'][$connection][$table] ?? [],
                ));

                $this->line(sprintf('  %s %s: %d kept (%s) — %s', $connection === 'tenant' ? 'estate' : 'platform', $table, count($ids), $reasons, implode('; ', $labels)));
            }
        }
    }

    /**
     * "#101 Lot 413 household" — sample rows only, which are the seed's invented content.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function labels(string $connection, string $table, array $ids): array
    {
        $schema = DB::connection($connection)->getSchemaBuilder();
        $label = null;

        foreach (self::LABELS as $column) {
            if ($schema->hasColumn($table, $column)) {
                $label = $column;
                break;
            }
        }

        $shown = array_slice($ids, 0, 10);
        $rows = DB::connection($connection)->table($table)->whereIn('id', $shown)->orderBy('id')->get(array_filter(['id', $label]));
        $out = array_map(static fn (object $row): string => '#'.$row->id.($label === null ? '' : ' '.$row->{$label}), $rows->all());

        if (count($ids) > count($shown)) {
            $out[] = 'and '.(count($ids) - count($shown)).' more';
        }

        return $out;
    }

    /**
     * What the hidden rows were worth on screen, and that the books stay whole: a journal is hidden with every line of
     * it, and the hidden lines balance. Inside the estate.
     *
     * @param  array{spans: array<string, array<string, list<array{0: int, 1: int}>>>, kept: array<string, array<string, list<int>>>}  $plan
     */
    private function moneyReport(string $key, array $plan): void
    {
        $tenant = DB::connection('tenant');
        $hidden = fn (string $table, string $q): string => 'NOT '.Retirements::shownSql('tenant', $table, $q, [], $plan['spans']['tenant'], $plan['kept']['tenant'][$table] ?? []);

        $charges = $tenant->selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(`c`.`amount_minor`), 0) AS minor FROM `charges` AS `c` WHERE '.$hidden('charges', 'c'));
        $payments = $tenant->selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(`p`.`amount_minor`), 0) AS minor FROM `payments` AS `p` WHERE '.$hidden('payments', 'p'));
        $households = $tenant->selectOne('SELECT COUNT(*) AS n FROM `households` AS `h` WHERE '.$hidden('households', 'h'));

        $this->line(sprintf(
            'Money it takes out of the estate\'s figures: %s charges (J$%s) and %s payments (J$%s) — J$%s billed and not paid, across the %s households it hides. Seeded figures, not anybody\'s debt: they were written by the demonstration seed.',
            number_format((int) $charges->n),
            number_format(((int) $charges->minor) / 100, 2),
            number_format((int) $payments->n),
            number_format(((int) $payments->minor) / 100, 2),
            number_format(((int) $charges->minor - (int) $payments->minor) / 100, 2),
            number_format((int) $households->n),
        ));

        $split = $tenant->selectOne(
            'SELECT COUNT(*) AS n FROM `journal_lines` AS `l` JOIN `journals` AS `j` ON `j`.`reference` = `l`.`entry_ref`'
            .' WHERE ('.$hidden('journal_lines', 'l').') <> ('.$hidden('journals', 'j').')',
        );
        $lines = $tenant->selectOne(
            'SELECT COUNT(*) AS n, COALESCE(SUM(`l`.`debit_minor`), 0) AS debit, COALESCE(SUM(`l`.`credit_minor`), 0) AS credit FROM `journal_lines` AS `l` WHERE '.$hidden('journal_lines', 'l'),
        );

        $this->line(sprintf(
            'The books: %s hidden journal lines, debits J$%s and credits J$%s (%s); %s line%s hidden apart from %s journal (%s).',
            number_format((int) $lines->n),
            number_format(((int) $lines->debit) / 100, 2),
            number_format(((int) $lines->credit) / 100, 2),
            (int) $lines->debit === (int) $lines->credit ? 'they balance, so every report still balances' : 'THEY DO NOT BALANCE — do not confirm; report this',
            number_format((int) $split->n),
            (int) $split->n === 1 ? '' : 's',
            (int) $split->n === 1 ? 'its' : 'their',
            (int) $split->n === 0 ? 'none: a journal goes with every line of it' : 'do not confirm; report this',
        ));

        $invoices = DB::connection('mysql')->selectOne(
            'SELECT COUNT(*) AS n, COALESCE(SUM(`i`.`total_minor`), 0) AS minor FROM `invoices` AS `i` WHERE '
            .'NOT '.Retirements::shownSql('mysql', 'invoices', 'i', [$key], $plan['spans']['mysql'], $plan['kept']['mysql']['invoices'] ?? []),
        );
        $this->line(sprintf('On the platform: %s of Gemini\'s invoices to this estate (J$%s) are hidden from Billing.', number_format((int) $invoices->n), number_format(((int) $invoices->minor) / 100, 2)));
    }

    /**
     * The unique keys a hidden row goes on holding: a real record cannot take one while the row is retired. Inside the
     * estate.
     *
     * @param  array{spans: array<string, array<string, list<array{0: int, 1: int}>>>, kept: array<string, array<string, list<int>>>}  $plan
     */
    private function heldReport(string $key, array $plan): void
    {
        $lines = [];

        foreach (self::HELD_KEYS as [$connection, $table, $columns]) {
            $tenants = $connection === 'mysql' ? [$key] : [];
            $hidden = 'NOT '.Retirements::shownSql($connection, $table, 'gs_t', $tenants, $plan['spans'][$connection], $plan['kept'][$connection][$table] ?? []);
            $select = implode(", ' ', ", array_map(static fn (string $c): string => "COALESCE(`gs_t`.`{$c}`, '')", $columns));
            $values = array_map(static fn (object $r): string => (string) $r->v, DB::connection($connection)->select(
                "SELECT DISTINCT CONCAT({$select}) AS v FROM `{$table}` AS `gs_t` WHERE {$hidden} ORDER BY v",
            ));

            if ($values !== []) {
                $lines[] = sprintf('  %s %s (%s): %s', $connection === 'tenant' ? 'estate' : 'platform', $table, implode(', ', $columns), implode('; ', array_slice($values, 0, 12)).(count($values) > 12 ? '; and '.(count($values) - 12).' more' : ''));
            }
        }

        if ($lines === []) {
            return;
        }

        $this->line('Held by hidden rows, so a real record cannot reuse them while they are retired (the database\'s unique index still has them; the office would be told it already exists, and --undo shows the row again). The generated numbers — charges, receipts, journals, bookings, tickets, plans, bills, officers\' numbers — always count past them:');

        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    /**
     * Write every hidden invoice — and the estate's hidden bills and charges — to one file, whole or not at all.
     * Inside the estate.
     *
     * @param  array{spans: array<string, array<string, list<array{0: int, 1: int}>>>, kept: array<string, array<string, list<int>>>, counts: list<array<string, mixed>>}  $plan
     * @return array{0: string, 1: string, 2: int}
     */
    private function export(string $key, array $plan): array
    {
        $directory = $this->exportDirectory();

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("The export directory {$directory} could not be made.");
        }

        $path = $directory.DIRECTORY_SEPARATOR.$key.'-'.Carbon::now('UTC')->format('Ymd-His').'.jsonl';
        $part = $path.'.part';
        $handle = fopen($part, 'xb');

        if ($handle === false) {
            throw new RuntimeException("The export {$part} could not be opened.");
        }

        $written = 0;
        $expected = 0;

        try {
            foreach (self::EXPORTED as [$connection, $table]) {
                $tenants = $connection === 'mysql' ? [$key] : [];
                $hidden = 'NOT '.Retirements::shownSql($connection, $table, 'gs_t', $tenants, $plan['spans'][$connection], $plan['kept'][$connection][$table] ?? []);

                foreach ($plan['counts'] as $count) {
                    if ($count['connection'] === $connection && $count['table'] === $table) {
                        $expected += (int) $count['hidden'];
                    }
                }

                foreach (DB::connection($connection)->cursor("SELECT `gs_t`.* FROM `{$table}` AS `gs_t` WHERE {$hidden} ORDER BY `gs_t`.`id`") as $row) {
                    $line = json_encode(['estate' => $key, 'database' => $connection === 'tenant' ? 'estate' : 'platform', 'table' => $table, 'row' => $row], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

                    if (fwrite($handle, $line) !== strlen($line)) {
                        throw new RuntimeException("The export {$part} could not be written in full.");
                    }

                    $written++;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($written !== $expected) {
            throw new RuntimeException("The export {$part} holds {$written} rows and {$expected} were to be written.");
        }

        if (! rename($part, $path)) {
            throw new RuntimeException("The export {$part} could not be renamed to {$path}.");
        }

        return [$path, (string) hash_file('sha256', $path), $written];
    }

    private function exportDirectory(): string
    {
        $chosen = (string) ($this->option('export-to') ?? '');

        return rtrim($chosen !== '' ? $chosen : storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'sample-data-exports'), '\\/');
    }

    /** Who ran it: the server account and the machine — for the record, never a credential. */
    private function by(): string
    {
        $account = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '')
            : (string) (getenv('USERNAME') ?: getenv('USER') ?: '');

        return mb_substr(($account !== '' ? $account : 'an operator').' on '.(gethostname() ?: 'this server').' (launch:retire-sample-data)', 0, 191);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $spans
     * @return list<array{0: int, 1: int}>
     */
    private static function merged(array $spans): array
    {
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];

        foreach ($spans as [$from, $to]) {
            $last = array_key_last($out);

            if ($last !== null && $from <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $to);

                continue;
            }

            $out[] = [$from, $to];
        }

        return $out;
    }
}
