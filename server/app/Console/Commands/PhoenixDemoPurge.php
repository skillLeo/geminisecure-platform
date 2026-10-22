<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Tenant;
use App\Services\Estate\Receipts;
use App\Support\SampleData;
use App\Support\Seeded\SeesEveryRow;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * TAKE THE DEMONSTRATION BACK OUT, AND LEAVE THE ESTATE STANDING (M8 §4.4).
 *
 *     php artisan phoenix:demo-purge --confirm            remove it
 *     php artisan phoenix:demo-purge --dry-run            say what would go, change nothing
 *
 * Phoenix Park Village 1 was filled with invented history so its committee could be shown the
 * product working. None of that history is theirs, and the day real residents arrive it has to be
 * gone — while the estate itself, its roles, its settings and its features stay exactly as they
 * are. Its lots and phases go with the sample households: the real ones are imported after it.
 *
 * WHAT IT KEEPS, AND SAYS SO (M28, KI-24). What the estate needs on launch day stays even where the seed
 * was the first to write it: its posts, its chart of accounts and receipt counter, its arrears ladder and
 * amenities, beside its settings, features, signing key and emergency numbers (`SampleData::PROTECTED_TABLES`
 * says why for each). Every run, dry or not, ends with WHAT STAYS: the estate, its contract, posts, route,
 * books, amenities, settings and homes, and the officers, committee seats and invoices it was never told
 * were sample data, so a person looks at them before pressing the button.
 *
 * WHAT IT DELETES: precisely the rows `phoenix:demo-seed` recorded in `sample_data_ranges`, by id
 * range, table by table, newest table first. Nothing outside those ranges is touched, so a record a
 * real resident created after the seeding is not at risk — the ranges end where the seeding ended.
 *
 * AND IN THE PLATFORM'S TABLES, ONLY THIS ESTATE'S DEMONSTRATION (B096). Those tables are shared,
 * and while the seed ran every other estate went on writing to them: their guards, their panics,
 * their users and handsets all have ids inside the run's range. A platform row is deleted only if
 * `SampleData::ownedRows()` says — now, before anything is deleted — that it is this estate's
 * demonstration; everything else in a range is left alone and counted, so a dry run shows it. That
 * is also what makes a range recorded before the fix, widened across the whole platform, safe to
 * purge from.
 *
 * FOREIGN KEYS ARE OFF FOR THE DELETE ITSELF. A purge is a deliberate maintenance act on an estate
 * that is not yet in use, and the alternative — working out a perfect dependency order across forty
 * tables — would fail silently the first time a new table appeared. They go back on immediately,
 * inside the same connection, whatever happens.
 *
 * WHAT IT CANNOT DELETE, AND SAYS SO: the append-only tables. The gate log, the estate's ledger
 * journals, the ballot receipts and marks, the audit log and the SMS ledger refuse DELETE to every
 * user including the schema owner, by trigger — which is the whole reason they can be trusted
 * (D-M148). Those rows carry `is_simulated`, so every console badges them as sample data for ever
 * after, and this command prints how many are left and in which tables.
 */
class PhoenixDemoPurge extends Command
{
    // It removes what a seed wrote, retired or not (M33 §5.4).
    use SeesEveryRow;

    protected $signature = 'phoenix:demo-purge
        {--confirm : yes, delete the sample records}
        {--dry-run : list what would be deleted and change nothing}
        {--export= : write every row that will be removed to this file (JSON lines) FIRST; nothing is deleted unless the export is whole}
        {--estate=phoenixpark : the estate to clear}
        {--tag= : the seeding run to undo (default: the Phoenix Park demonstration)}';

    protected $description = 'Remove the demonstration records phoenix:demo-seed added, leaving the estate configured (M8 §4.4)';

    /**
     * Where the rows about to be removed are written, during the export pass only (M16 §1.4: "keep an export of
     * them first, in case something is needed later").
     *
     * @var resource|null
     */
    private $export = null;

    private int $exported = 0;

    public function handle(): int
    {
        $tag = (string) ($this->option('tag') ?: SampleData::PHOENIX_TAG);
        $key = (string) $this->option('estate');
        $dry = (bool) $this->option('dry-run');
        $exportTo = (string) ($this->option('export') ?? '');

        if (! $dry && ! $this->option('confirm')) {
            $this->error('Refused: this deletes records. Re-run with --confirm, or with --dry-run to see what would go.');

            return self::FAILURE;
        }

        $estate = Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error("There is no estate `{$key}` on this platform.");

            return self::FAILURE;
        }

        $ranges = array_values(array_filter(SampleData::ranges($tag), static fn (object $r): bool => $r->tenant_id === null || $r->tenant_id === $key));

        if ($ranges === []) {
            $this->info("Nothing recorded for `{$tag}` on {$key}. Nothing to remove.");

            return self::SUCCESS;
        }

        $central = array_values(array_filter($ranges, static fn (object $r): bool => $r->connection === 'mysql'));
        $tenant = array_values(array_filter($ranges, static fn (object $r): bool => $r->connection === 'tenant'));

        /*
         * THE EXPORT, BEFORE ANYTHING IS DELETED. A first pass changes nothing: it writes every row the purge will
         * remove — the estate's and the platform's alike — and counts them. Only if the file holds exactly as many
         * rows as the purge would delete does the real pass run. A disk that fills, a path that cannot be written,
         * a count that disagrees: the purge refuses, and nothing is gone.
         */
        if ($exportTo !== '') {
            $handle = @fopen($exportTo, 'xb');

            if ($handle === false) {
                $this->error("Refused: cannot create the export file `{$exportTo}` (it must not exist already). Nothing was deleted.");

                return self::FAILURE;
            }

            $this->export = $handle;
            $this->exported = 0;
            $wouldGo = 0;
            $ignored = [];

            // The export pass says nothing: the real pass below prints each table once.
            $spoken = $this->output;
            $this->output = new OutputStyle(new ArrayInput([]), new NullOutput);

            try {
                $wouldGo += $estate->run(function () use ($tenant, &$ignored): int {
                    return $this->sweepEstate($tenant, true, $ignored);
                });
                $wouldGo += $this->sweepPlatform($central, $key, (string) $estate->name, true, $ignored);
            } finally {
                $this->output = $spoken;
                $this->export = null;
                $ok = fflush($handle) && fclose($handle);
            }

            if (! $ok || $this->exported !== $wouldGo) {
                $this->error(sprintf('Refused: the export holds %d row(s) where %d would be removed. Nothing was deleted.', $this->exported, $wouldGo));

                return self::FAILURE;
            }

            $this->info(sprintf('Exported %d row(s) to %s — keep it: it is the only copy once the purge runs.', $this->exported, $exportTo));
            $this->newLine();
        }

        $deleted = 0;

        /** @var array<string, int> $kept */
        $kept = [];

        // The estate's own database first: its rows reference the platform's, never the other way.
        // `&$kept` by reference THROUGH the closure as well: captured by value, the estate's own
        // append-only tables — its ledger journals above all — were kept correctly and then reported
        // to nobody, which is the half of this command that matters most.
        $deleted += $estate->run(function () use ($tenant, $dry, &$kept): int {
            return $this->sweepEstate($tenant, $dry, $kept);
        });
        $deleted += $this->sweepPlatform($central, $key, (string) $estate->name, $dry, $kept);

        $this->newLine();

        if ($dry) {
            $this->info("{$deleted} record(s) would be removed from {$estate->name}.");
        } else {
            SampleData::forget($tag, $key);
            $this->info("{$deleted} record(s) removed from {$estate->name}.");
        }

        $this->keeping($estate, $tenant, $central, $dry);

        if ($kept !== []) {
            $this->newLine();
            $this->warn('Left in place, because these tables refuse DELETE to everyone (that refusal is what makes them evidence):');

            foreach ($kept as $table => $count) {
                $this->line("    {$table}: {$count} row(s), each marked as simulated so every console badges it");
            }

            $this->line('    To start a gate log and a ledger with nothing invented in them at all, provision the estate afresh.');
        }

        return self::SUCCESS;
    }

    /**
     * The platform's half: this estate's demonstration rows inside the recorded ranges, and only those.
     *
     * DECIDED BEFORE ANYTHING IS DELETED. Whether a payslip is the demonstration's depends on its pay
     * run, and a checkpoint scan's on its checkpoint; asked table by table while deleting, the answer
     * would change under the purge as the parents went first. So every row to go is chosen against
     * the platform as it stands, and then they are deleted.
     *
     * @param  list<object>  $ranges
     * @param  array<string, int>  $kept
     */
    private function sweepPlatform(array $ranges, string $key, string $estateName, bool $dry, array &$kept): int
    {
        $db = DB::connection('mysql');

        /** @var array<string, array{ids: list<int>, left: int, append_only: bool}> $plan */
        $plan = [];

        foreach ($ranges as $range) {
            $table = (string) $range->table_name;

            if (! $db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $inRange = static fn (Builder $query): Builder => $query
                ->where('id', '>', (int) $range->from_id)
                ->where('id', '<=', (int) $range->to_id);

            $total = $inRange($db->table($table))->count();

            if ($total === 0) {
                continue;
            }

            $owned = SampleData::ownedRows($table, $key);
            $ids = $owned === null ? [] : $inRange($owned)->pluck('id')->map(intval(...))->values()->all();

            $plan[$table] ??= ['ids' => [], 'left' => 0, 'append_only' => false];
            $plan[$table]['ids'] = [...$plan[$table]['ids'], ...$ids];
            $plan[$table]['left'] += $total - count($ids);
            $plan[$table]['append_only'] = $plan[$table]['append_only'] || (bool) $range->append_only;
        }

        $deleted = 0;

        $db->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($plan as $table => $step) {
                $ids = array_values(array_unique($step['ids']));
                $count = count($ids);

                if ($step['left'] > 0) {
                    $this->line(sprintf("  %-8s %-34s %d left alone — inside the recorded range, but not %s's demonstration", 'mysql', $table, $step['left'], $estateName));
                }

                if ($count === 0) {
                    continue;
                }

                if ($step['append_only']) {
                    $kept[$table] = ($kept[$table] ?? 0) + $count;

                    continue;
                }

                // The estate's posts above all (KI-24): the seed made the Main Gate the client confirmed is real.
                if (in_array($table, SampleData::PROTECTED_TABLES, true)) {
                    $this->line(sprintf('  %-8s %-34s %d kept — the estate needs it, whoever wrote it first', 'mysql', $table, $count));

                    continue;
                }

                $this->line(sprintf('  %-8s %-34s %d', 'mysql', $table, $count));

                if ($this->export !== null) {
                    foreach (array_chunk($ids, 1000) as $chunk) {
                        foreach ($db->table($table)->whereIn('id', $chunk)->orderBy('id')->get() as $row) {
                            $this->write('mysql', $table, $row);
                        }
                    }
                }

                if (! $dry) {
                    foreach (array_chunk($ids, 1000) as $chunk) {
                        $db->table($table)->whereIn('id', $chunk)->delete();
                    }
                }

                $deleted += $count;
            }
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $deleted;
    }

    /**
     * The estate's own half: every row inside its recorded ranges, which nobody but the estate writes.
     *
     * @param  list<object>  $ranges
     * @param  array<string, int>  $kept
     */
    private function sweepEstate(array $ranges, bool $dry, array &$kept): int
    {
        if ($ranges === []) {
            return 0;
        }

        $connection = 'tenant';
        $deleted = 0;

        DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($ranges as $range) {
                $table = (string) $range->table_name;

                if (! DB::connection($connection)->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $query = DB::connection($connection)->table($table)
                    ->where('id', '>', (int) $range->from_id)
                    ->where('id', '<=', (int) $range->to_id);

                $count = (clone $query)->count();

                if ($count === 0) {
                    continue;
                }

                if ((bool) $range->append_only) {
                    $kept[$table] = ($kept[$table] ?? 0) + $count;

                    continue;
                }

                // Deleting these would break the estate rather than empty it — the signing key every
                // issued pass was signed with, the estate's own settings and features, its chart of accounts
                // and receipt counter, its arrears ladder and amenities (SampleData::PROTECTED_TABLES says why).
                if (in_array($table, SampleData::PROTECTED_TABLES, true)) {
                    $this->line(sprintf('  %-8s %-34s %d kept — the estate needs it, whoever wrote it first', $connection, $table, $count));

                    continue;
                }

                $this->line(sprintf('  %-8s %-34s %d', $connection, $table, $count));

                if ($this->export !== null) {
                    foreach ((clone $query)->orderBy('id')->cursor() as $row) {
                        $this->write($connection, $table, $row);
                    }
                }

                if (! $dry) {
                    $query->delete();
                }

                $deleted += $count;
            }
        } finally {
            DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $deleted;
    }

    /**
     * WHAT STAYS (M28). At seven in the morning the list an operator needs before pressing the button is what the
     * estate keeps: its contract, its gates, its books, its people. Counted the way the sweep decides, so a dry
     * run's list is what the real run leaves, and after a real run it is what did stay.
     *
     * It also names what the purge cannot judge: officers, committee seats, households and invoices nobody recorded
     * as sample data stay, and are listed so that somebody looks at each (KI-25).
     *
     * @param  list<object>  $tenantRanges
     * @param  list<object>  $centralRanges
     */
    private function keeping(Tenant $estate, array $tenantRanges, array $centralRanges, bool $dry): void
    {
        $key = (string) $estate->getTenantKey();
        $db = DB::connection('mysql');

        // The platform rows this purge takes: this estate's demonstration inside the ranges, unless kept on purpose.
        $going = [];
        foreach ($centralRanges as $range) {
            $table = (string) $range->table_name;
            $owned = SampleData::ownedRows($table, $key);

            if ($owned === null || (bool) $range->append_only || in_array($table, SampleData::PROTECTED_TABLES, true)) {
                continue;
            }

            $going[$table] = [...($going[$table] ?? []), ...$owned->where('id', '>', (int) $range->from_id)->where('id', '<=', (int) $range->to_id)->pluck('id')->map(intval(...))->all()];
        }
        $stays = static fn (Builder $query, string $table, string $column = 'id'): Builder => $query->whereNotIn($column, $going[$table] ?? []);

        // The estate's own rows outside every recorded range of their table: what no run of the seed wrote.
        $outside = static function (Builder $query, string $table) use ($tenantRanges): Builder {
            foreach ($tenantRanges as $range) {
                if ((string) $range->table_name === $table && ! (bool) $range->append_only) {
                    $query->where(static fn (Builder $q): Builder => $q->where('id', '<=', (int) $range->from_id)->orWhere('id', '>', (int) $range->to_id));
                }
            }

            return $query;
        };
        $plural = static fn (int $n, string $one, string $many): string => number_format($n).' '.($n === 1 ? $one : $many);
        $some = static fn (iterable $names, int $count): string => collect($names)->take(6)->implode(', ').($count > 6 ? ', and '.($count - 6).' more' : '');

        $lines = [];
        $row = $db->table('tenants')->where('id', $key)->first(['name', 'timezone', 'status']);
        $lines[] = ['the estate', sprintf('%s (%s), %s, %s', $row->name ?? $key, $key, $row->timezone ?? 'NO TIME ZONE', $row->status ?? '?')];

        $contract = $db->table('subscriptions')->where('tenant_id', $key)->value('unit_count');
        $lines[] = ['its contract', $contract === null ? 'no contract figure' : $plural((int) $contract, 'unit', 'units')];

        $posts = Post::query()->where('tenant_id', $key)->where('is_active', true)->orderBy('name')->get();
        $lines[] = ['its posts', $posts->isEmpty() ? 'NONE' : $posts->map(static fn (Post $p): string => $p->label().($p->isPlaced() ? ', placed' : ', not placed'))->implode('; ')];

        $route = $stays($db->table('patrol_checkpoints')->where('tenant_id', $key)->where('is_active', true), 'patrol_checkpoints')->count();
        $lines[] = ['its route', $plural($route, 'checkpoint', 'checkpoints').', each due '.PatrolCheckpoint::cadenceFor($key).' minutes after the last'];

        $numbers = $db->table('site_emergency_numbers')->where('tenant_id', $key)->count();
        $signing = $db->table('pass_signing_keys')->where('site_id', $key)->whereNull('retired_at')->count();

        $estate->run(function () use (&$lines, $outside, $plural, $numbers, $signing): void {
            $t = DB::connection('tenant');
            $receipts = app(Receipts::class);
            $last = (int) ($t->table('receipt_sequences')->where('prefix', $receipts->prefix())->value('last_no') ?? 0);
            $lines[] = ['its books', sprintf('%s in the chart; the next receipt is %s; an arrears ladder of %s', $plural($t->table('accounts')->count(), 'account', 'accounts'), $receipts->format($last + 1), $plural($t->table('dunning_templates')->where('is_active', true)->count(), 'step', 'steps'))];

            $money = static fn (mixed $minor): string => $minor === null ? 'none' : 'J$'.number_format(((int) $minor) / 100, 2);
            $amenities = $t->table('amenities')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['name', 'booking_fee_minor', 'deposit_minor']);
            $lines[] = ['its amenities', $amenities->isEmpty() ? 'none' : $amenities->map(static fn (object $a): string => sprintf('%s (fee %s, deposit %s)', $a->name, $money($a->booking_fee_minor), $money($a->deposit_minor)))->implode('; ')];

            $lines[] = ['its settings', sprintf('%s; %s on; %s; %s', $t->table('estate_settings')->exists() ? 'the estate\'s settings' : 'NO ESTATE SETTINGS', $plural($t->table('estate_features')->where('enabled', true)->count(), 'feature', 'features'), $plural($signing, 'pass signing key', 'pass signing keys'), $plural($numbers, 'emergency number', 'emergency numbers'))];

            $lines[] = ['its homes', sprintf('%s and %s; the real homes are imported after this', $plural($outside($t->table('units'), 'units')->count(), 'lot', 'lots'), $plural($outside($t->table('households'), 'households')->count(), 'household', 'households'))];
        });

        $officers = $stays($db->table('guards')->where('tenant_id', $key), 'guards')->orderBy('employee_number')->get(['employee_number', 'full_name']);
        $lines[] = ['its officers', $officers->isEmpty()
            ? 'none: Gemini\'s real officers are added in Guard workforce before any shift is posted'
            : $plural($officers->count(), 'officer', 'officers').': '.$some($officers->map(static fn (object $g): string => trim($g->employee_number.' '.$g->full_name)), $officers->count()).'. Only the officers the seed recorded go; check every one of these is real'];

        $seats = $stays($db->table('estate_assignments')->where('estate_assignments.tenant_id', $key)->where('estate_assignments.is_active', true), 'estate_assignments', 'estate_assignments.id')
            ->join('users', 'users.id', '=', 'estate_assignments.user_id')->orderBy('users.name')->pluck('users.name');
        $lines[] = ['its committee', $seats->isEmpty()
            ? 'nobody: give the real committee its first account (estate:add-committee-account); that person invites the rest'
            : $plural($seats->count(), 'seat', 'seats').': '.$some($seats, $seats->count())];

        $invoices = $db->table('invoices')->where('tenant_id', $key)->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')->pluck('n', 'status');
        $lines[] = ['its billing', $invoices->isEmpty()
            ? 'no invoices'
            : $plural((int) $invoices->sum(), 'invoice', 'invoices').' from Gemini ('.$invoices->map(static fn (mixed $n, mixed $status): string => $n.' '.$status)->implode(', ').'). Invoices are never purged; check they are real'];

        $this->newLine();
        $this->info($dry ? 'What stays — read this before you confirm:' : 'What stayed:');

        foreach ($lines as [$what, $text]) {
            $this->line(sprintf('  %-14s %s', $what, $text));
        }
    }

    /** One removed row, as a JSON line: which database, which table, and the row exactly as it stood. */
    private function write(string $connection, string $table, object $row): void
    {
        if ($this->export === null) {
            return;
        }

        $line = json_encode(['connection' => $connection, 'table' => $table, 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($line === false || fwrite($this->export, $line."\n") === false) {
            throw new \RuntimeException('The export could not be written; nothing has been deleted.');
        }

        $this->exported++;
    }
}
