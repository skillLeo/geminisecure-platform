<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Estate\EmergencyContactMerge;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 *   php artisan household:merge-duplicate-contacts [--estate=] [--dry-run]
 *
 * The copies `contacts.store` let through before it refused them (DESIGN §14): per household, a contact listed
 * more than once — the same number, name and relationship (`EmergencyContactMerge::merge`) — keeps its EARLIEST
 * row, the one the household nominated first and a panic texts first, and the later copies go. One number held
 * by two DIFFERENT people is only REPORTED ("needs a person"): nothing is deleted that is not a true copy.
 * Numbers are printed masked and never with a name. Audited per household (`household.contacts_merged`, with
 * each removed row's id, masked number and relationship in `before`). A second run finds nothing to merge. The
 * deploy runs it for real because what it removes says exactly what the row it keeps says.
 */
class MergeDuplicateContacts extends Command
{
    protected $signature = 'household:merge-duplicate-contacts
        {--estate= : one estate (its subdomain); every estate when left out}
        {--dry-run : say what would be merged, and change nothing}';

    protected $description = 'Merge emergency contacts listed more than once on a household, keeping the earliest';

    public function handle(EmergencyContactMerge $merge): int
    {
        $estateKey = trim((string) $this->option('estate'));
        $dryRun = (bool) $this->option('dry-run');

        $estates = Tenant::estates($estateKey === '' ? null : static function (Builder $query) use ($estateKey): void {
            $query->whereKey($estateKey)->orderBy('id');
        });

        if ($estates->isEmpty()) {
            $this->warn($estateKey === '' ? 'No estates provisioned — nothing to merge.' : 'No estate "'.$estateKey.'". Nothing was changed.');

            return $estateKey === '' ? self::SUCCESS : self::INVALID;
        }

        $this->info($dryRun ? 'DRY RUN — nothing is changed. Emergency contacts listed more than once:' : 'Emergency contacts listed more than once:');

        $failed = 0;

        foreach ($estates as $estate) {
            try {
                /** @var array{merged: list<array{household_id: int, number: string, kept: int, removed: list<int>}>, review: list<array{household_id: int, number: string, ids: list<int>}>} $report */
                $report = $estate->run(fn (): array => $merge->merge($dryRun));
            } catch (Throwable $failure) {
                // stancl's `run()` puts nothing back when its callback throws.
                tenancy()->end();
                $failed++;
                $this->line('  <fg=red>FAIL</> '.$estate->getTenantKey().': '.$failure->getMessage());

                continue;
            }

            foreach ($report['review'] as $row) {
                $this->line(sprintf(
                    '  %s · household %d · %s · NEEDS A PERSON: one number, different names or relationships (#%s) — left as it is; the household removes the wrong one in the app',
                    $estate->getTenantKey(),
                    $row['household_id'],
                    $row['number'],
                    implode(', #', $row['ids']),
                ));
            }

            if ($report['merged'] === []) {
                $this->line('  '.$estate->getTenantKey().': none to merge.');

                continue;
            }

            foreach ($report['merged'] as $row) {
                $this->line(sprintf(
                    '  %s · household %d · %s · %s #%d, %s %s',
                    $estate->getTenantKey(),
                    $row['household_id'],
                    $row['number'],
                    $dryRun ? 'would keep' : 'kept',
                    $row['kept'],
                    $dryRun ? 'would remove' : 'removed',
                    implode(', ', array_map(static fn (int $id): string => '#'.$id, $row['removed'])),
                ));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
