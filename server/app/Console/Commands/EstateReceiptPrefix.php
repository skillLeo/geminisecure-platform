<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\ReceiptPrefix;
use DomainException;
use Illuminate\Console\Command;

/**
 *   php artisan estate:receipt-prefix phoenixpark          # show it
 *   php artisan estate:receipt-prefix phoenixpark PPV      # correct it, before the first receipt
 *
 * The prefix is fixed by the estate's first receipt (13 A1). Until then a wrong
 * one can be corrected here; afterwards this refuses, and says why.
 */
class EstateReceiptPrefix extends Command
{
    protected $signature = 'estate:receipt-prefix
        {estate : the estate subdomain}
        {prefix? : the new prefix, at most six characters}';

    protected $description = "Show or correct an estate's receipt prefix, until its first receipt is issued";

    public function handle(ReceiptPrefix $prefixes): int
    {
        $estate = Tenant::query()->find((string) $this->argument('estate'));

        if (! $estate instanceof Tenant) {
            $this->error('No estate ['.$this->argument('estate').'].');

            return self::FAILURE;
        }

        $prefix = $this->argument('prefix');

        if ($prefix === null) {
            $this->line(sprintf(
                '%s numbers its receipts %s-R-00001 upward. %s',
                $estate->name,
                $estate->receipt_prefix ?? '(none set)',
                $prefixes->issued($estate) ? 'It has issued receipts, so the prefix is fixed.' : 'It has issued no receipt, so the prefix can still change.',
            ));

            return self::SUCCESS;
        }

        try {
            $prefixes->change($estate, (string) $prefix);
        } catch (DomainException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info("{$estate->name} will number its receipts {$estate->receipt_prefix}-R-00001 upward.");

        return self::SUCCESS;
    }
}
