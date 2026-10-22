<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Estate\HouseholdRemoval;
use App\Services\ResidentApp\AccountDeletion;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan accounts:anonymise-expired
 *
 * A deleted Resident App account can come back for as long as its estate's windows say — the person for 7 days, the
 * office for 30 (M18H §1.2). The day the last one closes, this leaves nothing of the person on the account or on the
 * register entry it pointed at: "Removed resident" and the deletion's reference, every ledger line, gate event and
 * incident still there under the unit. Nightly. Irreversible, and audited per account (`resident_account.anonymised`).
 *
 * A sign-in to an address whose window closed before tonight's run does the same thing there and then, so nobody is
 * told "you can restore this" about an account that the schedule simply had not reached yet.
 */
class AnonymiseExpiredAccounts extends Command
{
    protected $signature = 'accounts:anonymise-expired';

    protected $description = 'Anonymise every deleted Resident App account whose estate\'s restore window has closed';

    public function handle(AccountDeletion $deletion): int
    {
        $this->info($deletion->anonymiseExpired().' deleted account(s) past their restore window anonymised.');

        // A removed household (M21 §3.1) closes on the same office window: its name and its members without an app
        // account are cleared in each estate's own database. One estate that cannot be reached does not stop the rest.
        $households = 0;
        foreach (Tenant::estates() as $estate) {
            try {
                $households += (int) $estate->run(static fn (): int => app(HouseholdRemoval::class)->anonymiseExpired());
            } catch (Throwable $failed) {
                $this->warn($estate->getTenantKey().': removed households not anonymised — '.$failed->getMessage());
            }
        }
        $this->info($households.' removed household(s) past their restore window anonymised.');

        return self::SUCCESS;
    }
}
