<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Jobs\Tenancy\MigrateEstateAsOwner;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Migrate every estate AS THE SCHEMA OWNER, then put each estate's grants back.
 *
 *   php artisan estates:migrate
 *
 * The deploy-time counterpart of the provisioning pipeline's `MigrateEstateAsOwner` (M7).
 * `tenants:migrate` migrates each estate as the estate's OWN user, which holds no UPDATE on a
 * table created in the same run and none at all on an append-only one — so any future estate
 * migration that backfills a column would stop the deploy half-way through the estates. This
 * runs the same migrations with the owner's credentials, one estate at a time, and then runs
 * `ApplyAppendOnlyGrants` on that estate, so every table a migration added is granted before
 * the next estate is touched.
 *
 * Every estate is attempted even when one fails, and the command exits non-zero if any did, so
 * a deploy script stops rather than switching traffic onto half-migrated estates.
 */
class MigrateEstates extends Command
{
    protected $signature = 'estates:migrate';

    protected $description = 'Run every estate\'s migrations as the schema owner, then re-apply its append-only grants';

    public function handle(): int
    {
        $estates = Tenant::estates();

        if ($estates->isEmpty()) {
            $this->warn('No estates provisioned.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($estates as $estate) {
            try {
                (new MigrateEstateAsOwner($estate))->handle();
                (new ApplyAppendOnlyGrants($estate))->handle();
                $this->line("  <fg=green>OK</> {$estate->getTenantKey()} ({$estate->database()->getName()})");
            } catch (Throwable $e) {
                $failed++;
                $this->line("  <fg=red>FAIL</> {$estate->getTenantKey()}: ".$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
