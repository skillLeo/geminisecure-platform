<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHAT A DEMONSTRATION SEED PUT INTO A REAL ESTATE, AND HOW TO TAKE IT OUT (M8 §1 rule 2, §4.4).
 *
 * Phoenix Park Village 1 is a real client's estate. Before its committee can be shown the product
 * working it has to be full of history, and none of that history is theirs — so every row of it is
 * recorded here as it is written, and `phoenix:demo-purge` removes exactly those rows and nothing
 * else.
 *
 * THE MECHANISM IS AN ID RANGE PER TABLE PER RUN, taken before and after the seeding run. Ids only
 * ever go up, so "everything in this table above id N" describes what was added while the run
 * lasted, with no column on forty tables and no flag an insert could forget to set.
 *
 * IN THE ESTATE'S OWN DATABASE THAT IS THE WHOLE ANSWER. Nothing but the estate writes there, and
 * an estate that is being seeded is not yet in use.
 *
 * IN THE PLATFORM'S DATABASE IT IS NOT, AND NEVER WAS (B096). Those tables are SHARED: while one
 * estate is seeded — two thousand lots take minutes — every other estate goes on writing to them.
 * A guard pings presence, an officer is hired, somebody accepts an invitation, a handset signs in,
 * a real panic is raised. Their ids fall inside the run's range exactly as the demonstration's do,
 * and a purge of the range deleted them, with foreign keys off. So a platform range holds only the
 * rows `ownedRows()` can show are THIS estate's demonstration, and the purge asks the same question
 * again before it deletes — which is also what keeps a range recorded before this fix, already on
 * the server, from reaching anybody else's rows.
 *
 * AND ONE RUN IS ONE RANGE. A re-seed used to widen the first run's range to its own end, so the
 * days between the two runs — and everything every estate wrote in them — became "sample data".
 *
 * WHAT CANNOT BE UNDONE, and is reported instead of being quietly skipped: the append-only tables.
 * The gate log, the estate's journals, the ballot receipts and marks, the audit log and the SMS
 * ledger refuse DELETE to every user including the schema owner, by trigger — which is exactly what
 * makes them worth anything (D-M148). Rows there carry `is_simulated` where the column exists, so
 * every console badges them as simulated from then on.
 */
final class SampleData
{
    /** The Phoenix Park demonstration seeding run. */
    public const PHOENIX_TAG = 'phoenix-demo';

    /**
     * Tables whose rows can never be deleted, by anyone.
     *
     * Read from `GrantedTables` rather than listed twice: that file is what `grants:append-only`
     * and `grants:estates` actually enforce, so a table added there is append-only here in the same
     * commit, and one removed stops being.
     *
     * @return list<string>
     */
    public static function appendOnly(string $connection): array
    {
        return $connection === 'tenant' ? GrantedTables::ESTATE_APPEND_ONLY : GrantedTables::CENTRAL_APPEND_ONLY;
    }

    /**
     * Tables a seeding run is never judged on: they are machinery, not the estate's records, and an
     * id range across them would say nothing about what a demonstration added.
     *
     * @var list<string>
     */
    public const NOT_RECORDS = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'api_idempotency_keys', 'api_idempotency_responses',
        'sample_data_ranges', 'statutory_rate_versions', 'permissions', 'roles', 'role_has_permissions',
        'model_has_roles', 'model_has_permissions', 'modules', 'package_features', 'plans', 'plan_features',
        // The live delivery log (M11 Part C): only `live:prune` removes it, and only as a contiguous
        // prefix. Purged by id range it would leave holes above floor_seq that no catch-up can see.
        'live_events',
    ];

    /**
     * Tables a purge must never delete from, however the ids fall.
     *
     * NOT BECAUSE THEY ARE PRECIOUS — because deleting them would break the estate rather than
     * empty it, which is the opposite of what a purge is for:
     *
     *   pass_signing_keys   the estate's Ed25519 key pair. Every visitor pass ever issued carries a
     *                       signature made with it; delete the key and the gate stops being able to
     *                       verify passes that are still in people's phones. A key is not sample
     *                       data even when it was first created during a seeding run.
     *   estate_settings     the estate's configuration — thresholds, contacts, the receipt prefix.
     *   estate_features     which modules the estate has. M8 §4.4 says configuration stays standing.
     *   tenants, domains    the estate itself.
     *   site_emergency_numbers  the client's own four panic numbers (Q-M09). Real data, never ours.
     *
     * AND WHAT THE ESTATE NEEDS ON LAUNCH DAY, WHICH THE SEED HAPPENED TO BE THE FIRST TO WRITE (M28, KI-24). The
     * estate's own database is recorded whole (every table the run wrote), and provisioning seeds nothing, so whatever
     * an estate needs that the seed created first sat inside the range and went with the sample data:
     *
     *   accounts            the chart of accounts. The kept journals post to it, the first real payment posts to it
     *                       (1200 Dues Receivable, 4000 Maintenance Fee Income), and the Build Spec's rule is that an
     *                       account with posted journals is archived, never deleted. Without it the books could take
     *                       no payment and `gate:ledger` failed on launch day.
     *   receipt_sequences   the receipt counter. A kept journal reads "Payment received — receipt PSX-R-00042", and a
     *                       counter recreated at nought would give that number to a real resident's payment.
     *   dunning_templates   the arrears ladder the office sends from: configuration, edited on the Dunning screen.
     *   amenities           what the estate lets people book, and the fees. Provisional until the client confirms them
     *                       (QUESTIONS PPV-01), and corrected in the console, never by losing them.
     *   posts               the estate's gates. The seed made the Main Gate, and the client confirmed it is real
     *                       (D-225). A post the office does not want is removed in Sites & geofences.
     *
     * @var list<string>
     */
    public const PROTECTED_TABLES = [
        'pass_signing_keys',
        'estate_settings',
        'estate_features',
        'tenants',
        'domains',
        'site_emergency_numbers',
        'accounts',
        'receipt_sequences',
        'dunning_templates',
        'amenities',
        'posts',
    ];

    /**
     * THE PLATFORM TABLES A DEMONSTRATION WRITES, AND WHICH OF THEIR ROWS ARE THE ESTATE'S OWN.
     *
     * Tables whose rows carry the estate in `tenant_id`, and are the demonstration's when they do
     * and fall inside a run's range. The seed writes each of them for its estate (`PhoenixDemoSeed`);
     * `audit_log` is here only so the rows it cannot delete are counted, never deleted.
     *
     * A TABLE ON NONE OF THESE LISTS IS NEVER PURGED, whoever its rows belong to. A new platform
     * table the seed starts writing has to be added here, or its rows stay behind after a purge —
     * the safe way round for a command that deletes.
     *
     * @var list<string>
     */
    public const CENTRAL_BY_ESTATE = [
        'audit_log',
        'dispatch_messages',
        'guards',
        'patrol_checkpoints',
        'posts',
        'shifts',
        'standing_order_sets',
    ];

    /**
     * …and those where the estate is not enough, because the estate's OWN real records land in the
     * same table: an incident a real officer logged, a visitor really let in. Every row the seed
     * writes to these is marked `is_simulated`, so only those are its.
     *
     * NOT `duress_alerts`, ANY MORE: NO PANIC OR DURESS ROW IS EVER DELETED, "not for any reason, not
     * ever" (the user, 8 Oct 2026, M52 §4.5 — M41 found this list contradicting the rule). A panic the
     * seed wrote stays, badged simulated by its `is_simulated` and hidden by the seeded marks when the
     * sample data is retired; on no list here, the purge never takes one and reports it as kept.
     *
     * @var list<string>
     */
    public const CENTRAL_SIMULATED = [
        'gate_events',
        'guard_requests',
        'security_incidents',
    ];

    /**
     * Tables with no estate of their own, whose rows belong to one through a parent that has it.
     *
     * @var array<string, array{0: string, 1: string}> table => [foreign key, parent table]
     */
    public const CENTRAL_THROUGH_PARENT = [
        'checkpoint_scans' => ['patrol_checkpoint_id', 'patrol_checkpoints'],
        'standing_order_acknowledgements' => ['standing_order_set_id', 'standing_order_sets'],
        'standing_order_versions' => ['standing_order_set_id', 'standing_order_sets'],
    ];

    /**
     * The rows of one platform table that are this estate's demonstration, or null for a table the
     * demonstration does not write — whose rows a purge therefore never takes, whoever they belong to.
     *
     * THE SAME QUESTION AT BOTH ENDS. `record()` asks it of a run's range as the run ends, and the
     * purge asks it again, of the rows still there, before it deletes one.
     *
     * Beyond the three lists above:
     *   users               an address on this estate's own demonstration domain — the one the seed
     *                       gives its committee. A person who accepted an invitation while the seed
     *                       ran is on a real domain, and stays.
     *   estate_assignments  a seat at this estate held by one of those accounts. The real president's
     *                       seat, written in the same minutes, is not the demonstration's.
     *   payroll_runs        a run every payslip on which is this estate's, and which has one. A run
     *                       the platform paid across estates is not a demonstration's to delete.
     *   payslips            this estate's payslip on such a run — never a real officer's payslip on
     *                       the platform's real run.
     *   checkpoint_scans    also marked `is_simulated`, as the seed writes every one.
     *
     * @return Builder|null a query on `mysql`, still to be narrowed to an id range
     */
    public static function ownedRows(string $table, string $tenantId): ?Builder
    {
        $db = DB::connection('mysql');

        if (in_array($table, self::CENTRAL_BY_ESTATE, true)) {
            return $db->table($table)->where('tenant_id', $tenantId);
        }

        if (in_array($table, self::CENTRAL_SIMULATED, true)) {
            return $db->table($table)->where('tenant_id', $tenantId)->where('is_simulated', true);
        }

        if (array_key_exists($table, self::CENTRAL_THROUGH_PARENT)) {
            [$key, $parent] = self::CENTRAL_THROUGH_PARENT[$table];

            return $db->table($table)
                ->whereIn($key, $db->table($parent)->select('id')->where('tenant_id', $tenantId))
                ->when($table === 'checkpoint_scans', static fn (Builder $query) => $query->where('is_simulated', true));
        }

        return match ($table) {
            'users' => $db->table('users')->where('email', 'like', self::committeeAddresses($tenantId)),

            'estate_assignments' => $db->table('estate_assignments')
                ->where('tenant_id', $tenantId)
                ->whereIn('user_id', $db->table('users')->select('id')->where('email', 'like', self::committeeAddresses($tenantId))),

            'payroll_runs' => self::demonstrationPayRuns($tenantId),

            'payslips' => $db->table('payslips')
                ->where('tenant_id', $tenantId)
                ->whereIn('payroll_run_id', self::demonstrationPayRuns($tenantId)->select('id')),

            default => null,
        };
    }

    /** `LIKE` for every address on the estate's own demonstration domain, wildcards in the key escaped. */
    private static function committeeAddresses(string $tenantId): string
    {
        return '%@'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tenantId.DemonstrationAccounts::DEMO_DOMAIN_SUFFIX);
    }

    /** Pay runs that have a payslip, every one of them this estate's. */
    private static function demonstrationPayRuns(string $tenantId): Builder
    {
        return DB::connection('mysql')->table('payroll_runs')
            ->whereExists(static fn (Builder $query) => $query->selectRaw('1')->from('payslips')
                ->whereColumn('payslips.payroll_run_id', 'payroll_runs.id')
                ->where('payslips.tenant_id', $tenantId))
            ->whereNotExists(static fn (Builder $query) => $query->selectRaw('1')->from('payslips')
                ->whereColumn('payslips.payroll_run_id', 'payroll_runs.id')
                ->where(static fn (Builder $other) => $other->where('payslips.tenant_id', '!=', $tenantId)->orWhereNull('payslips.tenant_id')));
    }

    /**
     * The highest id in every table of this connection's database that has one.
     *
     * A table with no integer `id` (a pivot keyed on two columns, a settings row keyed by name) is
     * skipped rather than guessed at: nothing can be said about "what came after" without an
     * ordering, and a wrong answer here would delete a real row.
     *
     * @return array<string, int>
     */
    public static function highWater(string $connection): array
    {
        $database = DB::connection($connection)->getDatabaseName();

        $tables = DB::connection($connection)->select(
            'SELECT t.TABLE_NAME AS name FROM information_schema.TABLES t
             JOIN information_schema.COLUMNS c
               ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.COLUMN_NAME = \'id\'
             WHERE t.TABLE_SCHEMA = ? AND t.TABLE_TYPE = \'BASE TABLE\' AND c.DATA_TYPE IN (\'int\',\'bigint\')',
            [$database],
        );

        $marks = [];

        foreach ($tables as $row) {
            $table = (string) $row->name;

            if (in_array($table, self::NOT_RECORDS, true)) {
                continue;
            }

            $marks[$table] = (int) DB::connection($connection)->table($table)->max('id');
        }

        return $marks;
    }

    /**
     * Record what a run added: for every table, the ids above its previous high-water mark.
     *
     * ONE RUN, ITS OWN ROWS (B096). Each run adds ranges of its own and never widens an earlier
     * run's: the two runs together are one body of sample data and the purge takes both, but what
     * lies BETWEEN them was written by somebody else. In the platform's database the range is cut
     * down further to the rows `ownedRows()` shows are this estate's demonstration, stored as the
     * unbroken runs of ids they form — another estate's row in the middle splits a range in two
     * rather than being swept up with it.
     *
     * @param  array<string, int>  $before
     * @return int how many tables gained rows of this run's
     */
    public static function record(string $tag, string $tenantId, string $connection, array $before): int
    {
        $after = self::highWater($connection);
        $appendOnly = self::appendOnly($connection);
        $touched = 0;

        foreach ($after as $table => $high) {
            $low = $before[$table] ?? 0;

            if ($high <= $low) {
                continue;
            }

            if ($connection === 'tenant') {
                $spans = [[$low, $high]];
            } else {
                $owned = self::ownedRows($table, $tenantId);

                $spans = $owned === null ? [] : self::spans(
                    $owned->where('id', '>', $low)->where('id', '<=', $high)->orderBy('id')->pluck('id')->map(intval(...))->values()->all(),
                );
            }

            if ($spans === []) {
                continue;
            }

            $touched++;

            foreach ($spans as [$from, $to]) {
                DB::connection('mysql')->table('sample_data_ranges')->insert([
                    'tag' => $tag,
                    'tenant_id' => $tenantId,
                    'connection' => $connection,
                    'table_name' => $table,
                    'from_id' => $from,
                    'to_id' => $to,
                    'append_only' => in_array($table, $appendOnly, true),
                    'seeded_at' => Carbon::now(),
                ]);
            }
        }

        return $touched;
    }

    /**
     * Ascending ids as the unbroken runs they form, each as a range's (from, to] — `from` exclusive.
     *
     * @param  list<int>  $ids
     * @return list<array{0: int, 1: int}>
     */
    public static function spans(array $ids): array
    {
        $spans = [];

        foreach ($ids as $id) {
            $open = array_key_last($spans);

            // The next id carries on the open run; anything else starts a new one.
            if ($open !== null && $spans[$open][1] === $id - 1) {
                $spans[$open][1] = $id;

                continue;
            }

            $spans[] = [$id - 1, $id];
        }

        return $spans;
    }

    /**
     * Every recorded range for a tag, newest table first — the order a purge deletes in.
     *
     * NEWEST FIRST IS THE DEPENDENCY ORDER, near enough: a row that references another was written
     * after it. Foreign keys are switched off for the delete itself (a purge is a deliberate
     * maintenance act, not application traffic), so this only has to be approximately right.
     *
     * @return list<object>
     */
    public static function ranges(string $tag): array
    {
        return DB::connection('mysql')->table('sample_data_ranges')
            ->where('tag', $tag)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * Forget one estate's ranges for a tag, once its purge has run.
     *
     * ONLY THAT ESTATE'S. The tag is the seeding run's name, not the estate's, and the seed can be
     * pointed at any estate under it; forgetting every range for the tag left a second estate's
     * sample data with nothing saying which rows it was, and no way to take it out again. The ranges
     * with no estate are forgotten with it, because the purge read them as this estate's.
     */
    public static function forget(string $tag, string $tenantId): void
    {
        DB::connection('mysql')->table('sample_data_ranges')
            ->where('tag', $tag)
            ->where(static fn (Builder $query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->delete();
    }
}
