<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\CentralGrants;
use App\Support\GrantedTables;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE CENTRAL HALF OF THE RE-GRANT, WHICH DID NOT EXIST.
 *
 * Every estate database has re-granted itself since `DatabaseMigrated` was wired
 * to `ReapplyGrantsAfterMigration` (see `TenancyServiceProvider`). `gs_platform`
 * had no such event and no verification of any kind, so the ONE database that
 * holds guards, shifts, supervisor PINs and the audit trail was the one relying
 * on a person remembering a command. A step a human must remember is a step a
 * human eventually forgets, and forgetting it is the entire defect: UPDATE and
 * DELETE are withheld at database level and granted back per table (D-017), so a
 * migration that adds a table leaves it unwritable until somebody re-runs
 * `grants:append-only`.
 *
 * WHY THIS TOPS UP RATHER THAN REBUILDING
 * ---------------------------------------
 * `grants:append-only` rebuilds from scratch: it REVOKEs the whole
 * database-level grant and re-issues it. That is right for a deploy, where the
 * application is already down, and it is the only ordering that can also take a
 * privilege AWAY. But between the REVOKE and the re-GRANT the application user
 * holds nothing at all, and every live query fails.
 *
 * Automating a rebuild would therefore introduce a new failure of its own: an
 * administrator running `php artisan migrate` on a live server, without
 * maintenance mode, would black the site out for the duration. So this listener
 * only ever ADDS — it grants the tables that are missing a grant and revokes
 * nothing that was not already meant to be revoked. There is no window in which
 * the application user can do less than it could a moment earlier. It is exactly
 * the shape of the estate job, for exactly the same reason.
 *
 * Taking a privilege away — promoting a table to append-only — remains a
 * deliberate act, `grants:append-only`, run by a person who meant it.
 *
 * A FAILURE HERE NEVER FAILS THE MIGRATION. The schema change has already
 * committed by the time this runs; throwing now would report a migration as
 * failed when it succeeded, and send somebody to re-run it. It logs instead, and
 * `grants:append-only --dry-run` is what reports the state honestly afterwards.
 */
final class ReapplyCentralGrantsAfterMigration
{
    public function handle(MigrationsEnded $event): void
    {
        $skip = self::skipReason(
            (string) $event->method,
            $event->options,
            (string) app()->environment(),
            tenancy()->initialized,
        );

        if ($skip !== null) {
            return;
        }

        try {
            $this->topUp();
        } catch (Throwable $e) {
            Log::warning('Central grants could not be re-applied after a migration. Run `php artisan grants:append-only`.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Why this run must be left alone, or null to go ahead.
     *
     * Pure, and public, so every guard below is provable without a database —
     * which matters here more than usual, because the suite connects as the
     * schema owner and so can never exercise the grant itself.
     *
     * @param  array<string, mixed>  $options
     */
    public static function skipReason(
        string $method,
        array $options,
        string $environment,
        bool $tenancyInitialized,
    ): ?string {
        /*
         * A TENANT MIGRATION, NOT A CENTRAL ONE.
         *
         * `tenants:migrate` extends Laravel's own migrate command and calls it
         * inside `tenancy()->runForMultiple()`, so this same event fires once per
         * estate with that estate's tenancy initialized. The estates re-grant
         * themselves through `DatabaseMigrated`; re-granting the CENTRAL database
         * once per estate would be wasted work at best, and at worst it would run
         * while the default connection points somewhere this has no business
         * touching.
         */
        if ($tenancyInitialized) {
            return 'a tenant migration; the estates re-grant through DatabaseMigrated';
        }

        /*
         * THE TEST SUITE, WHERE GRANTS ARE MEANINGLESS BY CONSTRUCTION.
         *
         * RefreshDatabase rebuilds the schema for test after test, and each
         * rebuild would issue a grant per table against `gs_platform_test` — a
         * database only ever connected to as `gs_owner` (phpunit.xml), who holds
         * every privilege regardless. It would be pure cost, continuously
         * rewriting MySQL's grant tables to no effect. `skipReason` itself is
         * what the suite tests instead.
         */
        if ($environment === 'testing') {
            return 'the test suite connects as the schema owner; grants there mean nothing';
        }

        /*
         * A ROLLBACK REMOVES TABLES; there is nothing new to grant, and a grant
         * naming a table that no longer exists is an error, not a no-op.
         */
        if ($method !== 'up') {
            return "a '{$method}' run, not a migration up";
        }

        // `--pretend` printed SQL and changed nothing, so there is nothing to grant.
        if (($options['pretend'] ?? false) === true) {
            return 'a --pretend run changed no schema';
        }

        return null;
    }

    /**
     * Grant UPDATE and DELETE on every mutable table that is missing them.
     *
     * Driven by `information_schema` rather than by `GrantedTables`, exactly as
     * the command is: a table added by a migration must be writable even when
     * nobody has declared it yet. The declaration is what
     * `tests/Feature/GrantsCoverageTest.php` insists on, and it is about somebody
     * having CLASSIFIED the table; this is about the application not being
     * broken in the meantime.
     */
    private function topUp(): void
    {
        $connection = DB::connection('mysql_owner');
        $database = (string) config('database.connections.mysql.database');
        $appUser = (string) config('database.connections.mysql.username');

        $shouldBeMutable = array_values(array_diff(
            CentralGrants::baseTables($connection, $database),
            GrantedTables::CENTRAL_APPEND_ONLY,
        ));

        $privileges = implode(', ', CentralGrants::MUTATING);
        $granted = [];

        foreach (CentralGrants::HOSTS as $host) {
            $missing = array_diff(
                $shouldBeMutable,
                CentralGrants::grantedMutation($connection, $appUser, $host, $database),
            );

            foreach ($missing as $table) {
                $connection->statement(
                    "GRANT {$privileges} ON `{$database}`.`{$table}` TO `{$appUser}`@`{$host}`"
                );

                $granted[] = $table;
            }

            /*
             * And take the privilege back off anything now append-only, for the
             * case the estate job was written for: a table promoted to the
             * append-only list AFTER it had already been granted while ordinary.
             * IF EXISTS, because MySQL raises ERROR 1147 for a table that was
             * never granted rather than shrugging.
             */
            foreach (GrantedTables::CENTRAL_APPEND_ONLY as $table) {
                $connection->statement(
                    "REVOKE IF EXISTS {$privileges} ON `{$database}`.`{$table}` FROM `{$appUser}`@`{$host}`"
                );
            }
        }

        if ($granted !== []) {
            Log::info('Central grants topped up after a migration.', [
                'tables' => array_values(array_unique($granted)),
            ]);
        }
    }
}
