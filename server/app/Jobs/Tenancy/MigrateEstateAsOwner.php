<?php

declare(strict_types=1);

namespace App\Jobs\Tenancy;

use App\Services\Estate\OnePrimaryIndex;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Run one estate's migrations AS THE SCHEMA OWNER, never as the estate's own user (M7).
 *
 * WHY THE PACKAGE'S OWN STEP COULD NOT PROVISION AN ESTATE ANY MORE
 * ----------------------------------------------------------------
 * `Stancl\Tenancy\Jobs\MigrateDatabase` runs `tenants:migrate`, which opens the estate and
 * migrates it with the estate's OWN MySQL user. That user is created without UPDATE and
 * DELETE, and is granted them back table by table only AFTER the migrations have run
 * (D-017, `ApplyAppendOnlyGrants`). Three estate migrations backfill a column with an
 * UPDATE — and one of them backfills `payments`, an append-only table whose user never
 * gets UPDATE at all. So every NEW estate stopped part-way through provisioning:
 *
 *     1142 UPDATE command denied to user '…'@'localhost' for table 'accounts'
 *
 * Found preparing the production deployment, by provisioning a probe estate through
 * `estate:provision` on the development server. Phoenix Park and Ocean View were provisioned
 * before any backfill migration existed, which is why nobody had seen it; `demo:seed` had
 * met it and worked around it privately.
 *
 * A schema change is the owner's act — the central database already migrates with
 * `--database=mysql_owner` for the same reason. This points the `tenant` connection at this
 * estate's database WITH THE OWNER'S CREDENTIALS for the length of the migration, puts it
 * back, and leaves the estate user exactly as restricted as before: `ApplyAppendOnlyGrants`,
 * which runs next in the provisioning pipeline (and after `estates:migrate`), is what decides
 * what the estate's own user may do. Nothing about the application's privileges is widened —
 * only who ran the DDL.
 *
 * TENANCY IS NOT INITIALISED. Opening the estate would re-point `tenant` at the estate user's
 * credentials, which is the whole problem. No estate migration reads the tenant context.
 */
class MigrateEstateAsOwner
{
    public function __construct(protected TenantWithDatabase $tenant) {}

    public function handle(): void
    {
        $database = $this->tenant->database()->getName();

        $previous = config('database.connections.tenant');

        config([
            'database.connections.tenant' => array_merge(
                (array) config('database.connections.mysql_owner'),
                ['database' => $database],
            ),
        ]);

        DB::purge('tenant');

        try {
            $exit = Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant',
                '--database' => 'tenant',
                '--force' => true,
            ]);

            if ($exit !== 0) {
                throw new RuntimeException("Migrating estate database [{$database}] as the schema owner failed:\n".Artisan::output());
            }

            // One household, one primary, held by the database (M47 §14a): an estate repaired since its migration ran
            // gets the index now. One that still holds a pair is left as it is — never a failed deploy (OnePrimaryIndex).
            OnePrimaryIndex::ensure('tenant');
        } finally {
            config(['database.connections.tenant' => $previous]);
            DB::purge('tenant');
        }
    }
}
