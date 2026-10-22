<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\EstateProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan estate:provision phoenixpark "Phoenix Park Village" --receipt-prefix=PPV
 *
 * The receipt prefix is suggested from the name's initials when omitted, and is
 * fixed by the estate's first receipt — check it in the table this prints.
 */
class EstateProvision extends Command
{
    protected $signature = 'estate:provision
        {subdomain : becomes the tenant id and the gs_estate_ database suffix}
        {name : display name, e.g. "Phoenix Park"}
        {--status=onboarding}
        {--receipt-prefix= : the PPV in PPV-R-00001, at most six characters; suggested from the name when omitted}';

    protected $description = 'Provision an estate: database, dedicated MySQL user, migrations, append-only grants';

    public function handle(EstateProvisioner $provisioner): int
    {
        $subdomain = (string) $this->argument('subdomain');

        try {
            $tenant = $provisioner->provision(
                $subdomain,
                (string) $this->argument('name'),
                (string) $this->option('status'),
                $this->option('receipt-prefix') === null ? null : (string) $this->option('receipt-prefix'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Provisioned {$tenant->name}");
        $this->table(['Property', 'Value'], [
            ['tenant id / subdomain', $tenant->getTenantKey()],
            ['database', $tenant->database()->getName()],
            ['database user', $tenant->database()->getUsername()],
            ['domain', $tenant->domains()->first()->domain ?? '—'],
            ['status', $tenant->status],
            ['receipt numbers', $tenant->receipt_prefix.'-R-00001 upward'],
        ]);

        return self::SUCCESS;
    }
}
