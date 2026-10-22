<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Give an estate back the domain row its console is resolved by.
 *
 * WHY THIS EXISTS. `InitializeTenancyBySubdomain` strips the central domain off
 * the host and looks the remainder up in `domains`. A tenant with no row there
 * cannot be identified, so every request to <estate>.geminisl.com answers 500.
 *
 * `ClientOnboarding::start()` — the path behind "Onboard new client", and the
 * only one reachable from a screen — never wrote that row, while
 * `EstateProvisioner::provision()` and `DemoSeed` always did. So every client
 * taken on through the console is listed Active, has a database, a subscription
 * and an invited president, and no console at all. The service is fixed; this
 * repairs the estates created before the fix, which no deployment can do on its
 * own because the missing row is data rather than code.
 *
 * IT IS A REPAIR AND NOT A MIGRATION, deliberately. A migration runs itself on
 * every deploy, against every environment, and this writes a row that decides
 * which hostname reaches which tenant — an operator should be the one to ask for
 * that, see the list first, and read back what changed.
 *
 * Idempotent: an estate that already has its row is reported and left alone.
 * Safe to run with no arguments, which reports without writing.
 */
class EstateRepairDomains extends Command
{
    protected $signature = 'estate:repair-domains
        {subdomain? : one estate; omit to report on every estate}
        {--fix : actually write the missing rows; without it this only reports}';

    protected $description = 'Report the domains rows an estate console is resolved by, and restore the missing ones with --fix';

    public function handle(): int
    {
        $subdomain = $this->argument('subdomain');

        $query = Tenant::query()->orderBy('id');

        if ($subdomain !== null) {
            $query->whereKey($subdomain);
        }

        /*
         * READ AS TENANTS, not through `get()`. A tenant's collection is stancl's `TenantCollection`,
         * which declares no item type, so every estate came back from it as a bare model — no key,
         * no name, no domains, and an emptiness check the analyser called impossible. The builder's
         * own cursor keeps the type it was asked for.
         */
        $estates = $query->cursor()->all();

        if ($estates === []) {
            $this->error($subdomain === null
                ? 'There are no estates on this platform.'
                : "No estate [{$subdomain}] on this platform.");

            return self::FAILURE;
        }

        $rows = [];
        $missing = [];

        foreach ($estates as $estate) {
            $key = (string) $estate->getTenantKey();
            $held = $estate->domains()->pluck('domain')->all();
            $ok = in_array($key, $held, true);

            if (! $ok) {
                $missing[] = $estate;
            }

            $rows[] = [
                $key,
                (string) $estate->name,
                (string) $estate->status,
                $held === [] ? '—' : implode(', ', $held),
                $ok ? 'reachable' : 'NO CONSOLE',
            ];
        }

        $this->table(['Estate', 'Name', 'Status', 'Domains held', 'Verdict'], $rows);

        if ($missing === []) {
            $this->info('Every estate has the domain row its console is resolved by.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(count($missing).' estate(s) cannot be reached at their own hostname.');

        if (! $this->option('fix')) {
            $this->line('Re-run with --fix to write the missing rows.');

            return self::SUCCESS;
        }

        foreach ($missing as $estate) {
            $key = (string) $estate->getTenantKey();

            /*
             * The BARE SUBDOMAIN, never the full hostname. The resolver searches
             * for what is left once the central domain is removed, so a row
             * holding "skillleoestats.geminisl.com" leaves the tenant exactly as
             * unfindable as holding nothing at all.
             */
            $estate->domains()->firstOrCreate(['domain' => $key]);

            $this->info("  {$key} → domain row written");
        }

        $this->newLine();
        $this->line('The certificate is a separate step, because nginx serves an explicit list of names:');

        foreach ($missing as $estate) {
            $this->line('  sudo bash /opt/gs-deploy/add-estate-host.sh '
                .$estate->getTenantKey().' '.config('app.estate_domain', 'geminisl.com'));
        }

        return self::SUCCESS;
    }
}
