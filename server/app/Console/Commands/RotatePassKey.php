<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Passes\PassSigningKeys;
use Illuminate\Console\Command;

/**
 *   php artisan passes:rotate-key phoenixpark
 *
 * Retires a site's current Ed25519 signing key and activates a new version (13 D1).
 * New visitor passes are signed with the new key; the old key's PUBLIC half stays
 * published for `PassSigningKeys::RETIRED_KEY_GRACE_DAYS`, so passes it already
 * signed still verify on a handset. Handsets pick up the new version at their
 * next sync. The secret key is never printed.
 */
class RotatePassKey extends Command
{
    protected $signature = 'passes:rotate-key {estate : the estate subdomain}';

    protected $description = 'Rotate a site\'s visitor-pass signing key';

    public function handle(PassSigningKeys $keys): int
    {
        $estate = Tenant::query()->find((string) $this->argument('estate'));

        if (! $estate instanceof Tenant) {
            $this->error('No estate ['.$this->argument('estate').'].');

            return self::FAILURE;
        }

        $version = $keys->rotate((string) $estate->getTenantKey());

        $this->info("{$estate->name} now signs visitor passes with key version {$version}.");
        $this->line('Earlier versions stay published for '.PassSigningKeys::RETIRED_KEY_GRACE_DAYS.' days, so passes already issued still verify.');

        return self::SUCCESS;
    }
}
