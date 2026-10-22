<?php

declare(strict_types=1);

namespace App\Jobs\Tenancy;

use App\Support\GrantedTables;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Layer 1 of invariant 4: the estate's MySQL user must hold no UPDATE or
 * DELETE privilege on an append-only table.
 *
 * WHY THIS INVERTS 06_OVERRIDE §6's SQL
 * -------------------------------------
 * §6 specifies `REVOKE UPDATE, DELETE ON db.journals FROM user`. MySQL rejects
 * that when the privilege was granted at database level (`ON db.*`):
 *
 *     ERROR 1147 (42000): There is no such grant defined for user 'x'
 *                         on host '%' on table 'journals'
 *
 * MySQL supports partial revokes from GLOBAL to database scope, but not from
 * database to table scope. Verified empirically on MySQL 8.4.9, not assumed.
 *
 * So the privilege is never granted at database level in the first place:
 * TenancyServiceProvider strips UPDATE and DELETE from the manager's db-level
 * grant list, and this job grants them back per-table on mutable tables only.
 * The end state is exactly what §6 intended — the estate user simply cannot
 * update or delete a journal — reached by the only route MySQL allows.
 *
 * Layer 2 is the BEFORE UPDATE / BEFORE DELETE triggers created in the tenant
 * migration. Both layers, never one: a grant is per-table and easy to lose in a
 * later migration, while a trigger survives it; conversely a trigger can be
 * dropped by anyone holding TRIGGER privilege, which the grant boundary limits.
 *
 * See DECISIONS.md D-017.
 */
class ApplyAppendOnlyGrants
{
    /**
     * Tables that may only ever be inserted into.
     *
     * A correction is a new record referencing the original, never an edit.
     *
     * `journal_lines` belongs here for the same reason `journals` does, and
     * leaving it out would have made the whole protection ornamental: a header
     * nobody can edit sitting above lines anybody can edit is not an append-only
     * ledger. The amount, the account and the household on an entry all live on
     * the lines, so an estate user with UPDATE on them could restate any entry
     * ever posted while its header stood untouched.
     *
     * `ballot_receipts` and `ballot_marks` belong here for a different reason,
     * and it is invariant 3 rather than invariant 4. Turnout that can be edited
     * proves nothing, and a marks table that can be deleted from row by row
     * de-anonymises itself: remove 317 of 318 and the survivor's choice is
     * attributable by elimination to the one household whose receipt has no
     * partner. A secret ballot needs the crowd to stay intact, so the estate's
     * own MySQL user is given no way to thin it.
     *
     * THE LIST ITSELF NOW LIVES IN `App\Support\GrantedTables`, beside the list of
     * every estate table that is NOT append-only, because a coverage test can only
     * prove a new table was classified if both halves are written down in one
     * place. This constant stays so that every existing caller - `gate:isolation`,
     * `grants:estates`, the estate migrations - keeps working unchanged.
     */
    public const APPEND_ONLY_TABLES = GrantedTables::ESTATE_APPEND_ONLY;

    /** Privileges withheld at database level and granted back per table. */
    public const MUTATING_PRIVILEGES = ['UPDATE', 'DELETE'];

    public function __construct(protected TenantWithDatabase $tenant) {}

    /**
     * Whether a missing per-estate user is a fault or merely nothing to do.
     *
     * True here, because this class runs at PROVISIONING: an estate created
     * without its own MySQL user has no isolation boundary at all, and that is
     * worth stopping the world for. `ReapplyGrantsAfterMigration` overrides it.
     */
    protected function requiresDedicatedUser(): bool
    {
        return true;
    }

    public function handle(): void
    {
        $database = $this->tenant->database()->getName();
        $username = $this->tenant->database()->getUsername();

        if (! $username) {
            /*
             * AT PROVISIONING THIS IS FATAL, AND ON A RE-GRANT IT IS NOT.
             *
             * When a tenant is being created, a missing per-estate user means
             * the manager was swapped for one that does not provision them —
             * tenant isolation is already broken and the run must stop.
             *
             * After a MIGRATION the same absence means something else entirely:
             * the tenant was brought into existence outside the provisioning
             * path — a test fixture inserting a row, a restore, an import — and
             * there is simply no user to top up. Failing there would break every
             * such fixture to enforce a rule that has already been decided
             * elsewhere. See `ReapplyGrantsAfterMigration`.
             */
            if (! $this->requiresDedicatedUser()) {
                return;
            }

            throw new \RuntimeException(
                "Estate [{$this->tenant->getTenantKey()}] has no dedicated database user. ".
                'tenancy.database.managers must be PermissionControlledMySQLDatabaseManager. '.
                'See DECISIONS.md D-002.'
            );
        }

        // Issued as the owner: gs_app may not grant anything, and an estate
        // user may not widen its own privileges.
        $connection = DB::connection('mysql_owner');
        $privileges = implode(', ', self::MUTATING_PRIVILEGES);

        foreach ($this->mutableTables($connection, $database) as $table) {
            $connection->statement(
                "GRANT {$privileges} ON `{$database}`.`{$table}` TO `{$username}`@`%`"
            );
        }

        /*
         * AND TAKE THE PRIVILEGE BACK OFF ANYTHING NOW APPEND-ONLY.
         *
         * This job only ever granted, which was correct exactly once: at
         * provisioning, when the append-only list and the schema were written
         * together. Every table added to `APPEND_ONLY_TABLES` AFTERWARDS kept
         * the grant it had been given while it was ordinary — and three had.
         * `journal_lines` was promoted when the double-entry ledger landed, and
         * `ballot_receipts` and `ballot_marks` when governance did.
         *
         * Nothing broke, because layer 2 held: the triggers refused every edit
         * regardless. But the promise is TWO layers, deliberately — "a grant is
         * per-table and easy to lose in a later migration, while a trigger
         * survives it; conversely a trigger can be dropped by anyone holding
         * TRIGGER privilege" — and the platform had quietly been running the
         * ledger on one of them. `gate:isolation` step 7c is what found it and
         * is what stops it recurring.
         *
         * IF EXISTS, because a table that has never been granted has nothing to
         * revoke and MySQL raises ERROR 1147 rather than shrugging — which would
         * make this job fail on the first estate provisioned after the list
         * changed, which is the one case it most needs to work.
         */
        foreach (self::APPEND_ONLY_TABLES as $table) {
            $connection->statement(
                "REVOKE IF EXISTS {$privileges} ON `{$database}`.`{$table}` FROM `{$username}`@`%`"
            );
        }

        /*
         * Deliberately no FLUSH PRIVILEGES. GRANT applies immediately in
         * MySQL 8 — FLUSH is only needed after direct writes to the mysql.*
         * tables — and it requires RELOAD, which gs_owner has no business
         * holding just to provision an estate.
         */
    }

    /**
     * Every table in the estate database except the append-only ones.
     *
     * Read from information_schema rather than a hardcoded list so a table
     * added by a later migration is mutable by default. Append-only is the
     * exception and must be declared deliberately.
     *
     * @return list<string>
     */
    private function mutableTables(Connection $connection, string $database): array
    {
        $tables = $connection->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$database, 'BASE TABLE'],
        );

        return array_values(array_diff(
            array_map(fn ($row) => $row->name, $tables),
            self::APPEND_ONLY_TABLES,
        ));
    }
}
