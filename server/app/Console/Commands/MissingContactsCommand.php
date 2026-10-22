<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Estate\MissingContactDetails;
use Illuminate\Console\Command;
use Throwable;

/**
 * THE BACKFILL REPORT (M18C §5): per estate, how many households have somebody a panic cannot reach — a member or an
 * emergency contact with no phone, no email, or (a contact) no relationship. READ-ONLY: it counts and changes nothing.
 *
 *   php artisan contacts:missing            every estate
 *   php artisan contacts:missing --json     the same, as JSON
 */
final class MissingContactsCommand extends Command
{
    protected $signature = 'contacts:missing {--json : Print JSON instead of a table}';

    protected $description = 'Count, per estate, the household members and emergency contacts missing a phone, an email or a relationship (read-only).';

    public function handle(MissingContactDetails $missing): int
    {
        $rows = [];

        // Read through the cursor, which keeps the Tenant type `get()`'s TenantCollection loses (EstateRepairDomains).
        foreach (Tenant::query()->orderBy('id')->cursor() as $estate) {
            try {
                $counts = $estate->run(static fn (): array => $missing->counts());
                $rows[] = ['estate' => (string) $estate->getTenantKey(), 'name' => (string) $estate->name, ...$counts];
            } catch (Throwable $e) {
                $rows[] = ['estate' => (string) $estate->getTenantKey(), 'name' => (string) $estate->name, 'error' => class_basename($e)];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['Estate', 'Households', 'Members: no phone', 'Members: no email', 'Members: email unreachable', 'Contacts: no phone', 'Contacts: no email', 'Contacts: email unreachable', 'Contacts: no relationship'],
            array_map(static fn (array $r): array => isset($r['error'])
                ? [$r['name'], 'could not be read: '.$r['error'], '', '', '', '', '', '', '']
                : [$r['name'], $r['households'], $r['members_no_phone'], $r['members_no_email'], $r['members_no_email_unreachable'], $r['contacts_no_phone'], $r['contacts_no_email'], $r['contacts_no_email_unreachable'], $r['contacts_no_relationship']], $rows),
        );

        return self::SUCCESS;
    }
}
