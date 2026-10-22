<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CentralGrants;
use App\Support\GrantedTables;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Layer 1 of invariant 4 for the CENTRAL database.
 *
 *   php artisan grants:append-only              # apply, then verify
 *   php artisan grants:append-only --dry-run    # verify only, change nothing
 *
 * The estate equivalent runs automatically at provisioning
 * (App\Jobs\Tenancy\ApplyAppendOnlyGrants) and again after every `tenants:migrate`
 * (App\Jobs\Tenancy\ReapplyGrantsAfterMigration). gs_platform has no provisioning
 * event and no migration listener, so this is a command, run once after migrating
 * and again whenever a migration adds a table.
 *
 * WHY THE GRANT IS INVERTED RATHER THAN REVOKED
 * ---------------------------------------------
 * MySQL cannot revoke a table-scoped privilege from a database-scoped grant:
 *
 *     ERROR 1147 (42000): There is no such grant defined for user 'gs_app'
 *                         on host 'localhost' on table 'audit_log'
 *
 * So UPDATE and DELETE are withheld from gs_app's gs_platform.* grant and
 * granted back per table on everything that is not append-only. Verified on
 * MySQL 8.4.9. See DECISIONS.md D-017.
 *
 * WHY IT NOW VERIFIES ITSELF
 * --------------------------
 * The consequence of not running this command is invisible until a user hits it.
 * Reads work, inserts work, and only an UPDATE fails - and the test suite cannot
 * see it, because PHPUnit connects as the schema owner (phpunit.xml), who holds
 * every privilege. The central supervisor-override tables shipped that way: a
 * guard typing a WRONG supervisor PIN would have been shown a database privilege
 * error rather than a refusal, because recording the failed attempt is an UPDATE.
 *
 * So the command reads the grants back out of the server afterwards and says
 * whether they are right, and `--dry-run` does only that - which makes it the
 * post-deploy check in docs/DEPLOY.md section 7.
 *
 * Idempotent, and safe to re-run after every migration.
 */
class ApplyCentralAppendOnlyGrants extends Command
{
    protected $signature = 'grants:append-only {--dry-run : verify the grants and report, without changing anything}';

    protected $description = 'Withhold UPDATE and DELETE on append-only central tables from the application user';

    /**
     * Central tables that may only ever be inserted into.
     *
     * The list lives in `App\Support\GrantedTables`, beside every central table
     * that is NOT append-only, so that one declared list covers both halves and a
     * test can prove a newly migrated table was classified at all. See
     * `tests/Feature/GrantsCoverageTest.php`.
     */
    public const APPEND_ONLY_TABLES = GrantedTables::CENTRAL_APPEND_ONLY;

    private const MUTATING = CentralGrants::MUTATING;

    public function handle(): int
    {
        $connection = DB::connection('mysql_owner');
        $database = config('database.connections.mysql.database');
        $appUser = config('database.connections.mysql.username');
        $dryRun = (bool) $this->option('dry-run');

        $tables = collect($connection->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$database, 'BASE TABLE'],
        ))->pluck('name');

        $mutable = $tables->reject(fn (string $t) => in_array($t, self::APPEND_ONLY_TABLES, true));
        $privileges = implode(', ', self::MUTATING);

        $this->info("Database: {$database}   Application user: {$appUser}");
        $this->line('  append-only: '.implode(', ', self::APPEND_ONLY_TABLES));
        $this->line('  mutable    : '.$mutable->count().' tables');

        /*
         * A table nobody declared. It IS granted below, because this command reads
         * information_schema rather than a fixed list, so nothing is left broken.
         * But nobody decided whether it should have been append-only, and that
         * decision is much harder to take later: once an estate or the platform has
         * been running with the grant, promoting the table means revoking a
         * privilege somebody's code may already depend on.
         */
        $undeclared = GrantedTables::undeclaredCentral($tables->all());

        if ($undeclared !== []) {
            $this->warn('  UNDECLARED: '.implode(', ', $undeclared));
            $this->warn('              add each to App\Support\GrantedTables::CENTRAL_MUTABLE');
            $this->warn('              (or to CENTRAL_APPEND_ONLY if it must never be edited)');
        }

        if ($dryRun) {
            $this->line('  dry run - nothing applied');

            return $this->verify($connection, $database, $appUser, $mutable->all(), $undeclared);
        }

        foreach (CentralGrants::HOSTS as $host) {
            /*
             * Drop the database-wide grant first, then rebuild it: SELECT and
             * INSERT everywhere, UPDATE and DELETE only on mutable tables.
             * Revoking the whole grant and re-granting is the only ordering
             * MySQL permits, since the narrow revoke is what raises 1147.
             *
             * THIS IS ALSO WHY ONLY A PERSON RUNS IT. Between the REVOKE and the
             * GRANT on the next line the application user holds nothing at all.
             * On a deploy that is harmless, because `artisan down` came first.
             * `ReapplyCentralGrantsAfterMigration`, which runs unattended, only
             * ever ADDS a missing grant for exactly this reason.
             */
            $connection->statement("REVOKE ALL PRIVILEGES ON `{$database}`.* FROM `{$appUser}`@`{$host}`");
            $connection->statement("GRANT SELECT, INSERT ON `{$database}`.* TO `{$appUser}`@`{$host}`");

            foreach ($mutable as $table) {
                $connection->statement(
                    "GRANT {$privileges} ON `{$database}`.`{$table}` TO `{$appUser}`@`{$host}`"
                );
            }

            $this->line("  <fg=green>OK</> {$appUser}@{$host}");
        }

        $this->info('Applied. audit_log is now insert-only for the application user.');

        return $this->verify($connection, $database, $appUser, $mutable->all(), $undeclared);
    }

    /**
     * Read the grants back out of the server and say whether they are right.
     *
     * This is the half the test suite structurally cannot do. PHPUnit connects as
     * gs_owner, so it can never observe what gs_app may or may not write; only a
     * command run against the real server can, and only by asking MySQL.
     *
     * @param  list<string>  $shouldBeMutable
     * @param  list<string>  $undeclared
     */
    private function verify(
        Connection $connection,
        string $database,
        string $appUser,
        array $shouldBeMutable,
        array $undeclared,
    ): int {
        $this->newLine();
        $this->line('Verifying against the server:');

        $wrong = 0;

        foreach (CentralGrants::HOSTS as $host) {
            $granted = CentralGrants::grantedMutation($connection, $appUser, $host, $database);

            $ungranted = array_values(array_diff($shouldBeMutable, $granted));
            $wronglyGranted = array_values(array_intersect(self::APPEND_ONLY_TABLES, $granted));

            if ($ungranted !== []) {
                $wrong++;
                $this->line("  <fg=red>FAIL</> {$appUser}@{$host}: ".count($ungranted).' table(s) the application cannot update or delete from');
                $this->line('         '.implode(', ', $ungranted));
                $this->line('         these were added by a migration and never granted - run: php artisan grants:append-only');
            }

            if ($wronglyGranted !== []) {
                $wrong++;
                $this->line("  <fg=red>FAIL</> {$appUser}@{$host}: append-only table(s) carry a mutation grant");
                $this->line('         '.implode(', ', $wronglyGranted));
            }

            if ($ungranted === [] && $wronglyGranted === []) {
                $this->line("  <fg=green>PASS</> {$appUser}@{$host}: ".count($shouldBeMutable).' mutable tables writable, '
                    .count(self::APPEND_ONLY_TABLES).' append-only tables locked');
            }
        }

        if ($wrong > 0 || $undeclared !== []) {
            $this->newLine();

            if ($undeclared !== []) {
                $this->error('Undeclared table(s): '.implode(', ', $undeclared));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /*
     * The SHOW GRANTS parser that used to live here is now
     * `App\Support\CentralGrants::grantedMutation()`, shared with
     * `ReapplyCentralGrantsAfterMigration`. Two copies of "which tables may this
     * login write" is how the attended and unattended halves drift apart.
     */
}
