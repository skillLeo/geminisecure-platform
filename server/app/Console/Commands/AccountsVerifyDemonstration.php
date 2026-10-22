<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DemonstrationAccounts;
use Illuminate\Console\Command;

/**
 * PRE-VERIFY THE DEMONSTRATION ACCOUNTS ON A SERVER THAT ALREADY HAS THEM (M10 §1.4).
 *
 *     php artisan accounts:verify-demonstration --dry-run    # what it would touch
 *     php artisan accounts:verify-demonstration              # touch it
 *
 * WHY A COMMAND AND NOT ONLY THE SEEDERS. The seeders now pre-verify every
 * demonstration account they create — but production does not run seeders on
 * deploy, and its demonstration committee was seeded before the contact gate
 * existed. Without this, the first thing the client's team would meet after the
 * release is a one-time code sent to a `demo.geminisl.com` address no mailbox
 * answers: the demonstration stopped at the door, which is the one outcome the
 * work order rules out.
 *
 * EXACTLY THE SEEDED DEMONSTRATION ACCOUNTS, identified the way the seeders make
 * them: by the invented domain on the sign-in address (`DemonstrationAccounts`
 * lists each domain beside the code that writes it). Each has its sign-in address
 * recorded as its proven alert address. EVERY OTHER ACCOUNT IS LEFT ALONE — a real
 * person proves their own address at the gate at their next sign-in, and nothing
 * here, including a flag, can wave one through.
 *
 * IDEMPOTENT. An account already verified at its sign-in address is not counted
 * and not written, so running it twice changes nothing the second time.
 *
 * Addresses are printed and nothing else: they are invented, and there is no
 * secret on a demonstration account's row that this command reads.
 */
class AccountsVerifyDemonstration extends Command
{
    protected $signature = 'accounts:verify-demonstration
        {--dry-run : list the accounts it would pre-verify, and change nothing}';

    protected $description = 'Pre-verify the alert address of the seeded demonstration accounts, and only those (M10 §1.4)';

    public function handle(AuditLogger $audit): int
    {
        $dry = (bool) $this->option('dry-run');

        /** @var list<User> $pending */
        $pending = DemonstrationAccounts::query()
            ->orderBy('id')
            ->get()
            // The SQL narrows; the PHP rule decides. They are the same rule, and the
            // second is the one `DemonstrationAccounts::verify()` enforces.
            ->filter(static fn (User $user): bool => DemonstrationAccounts::isDemonstrationAddress((string) $user->email))
            ->filter(static fn (User $user): bool => ! ($user->hasVerifiedContact()
                && $user->contact_email === mb_strtolower(trim((string) $user->email))))
            ->values()
            ->all();

        foreach ($pending as $user) {
            $this->line(sprintf('  %-8s %-10s %s', $user->console->value, $user->status, $user->email));
        }

        if ($dry) {
            $this->info(sprintf(
                'Dry run: %d demonstration %s would be pre-verified. Nothing was changed.',
                count($pending),
                count($pending) === 1 ? 'account' : 'accounts',
            ));

            return self::SUCCESS;
        }

        $done = 0;

        foreach ($pending as $user) {
            if (DemonstrationAccounts::verify($user)) {
                $done++;
            }
        }

        if ($done > 0) {
            $audit->record(
                action: 'user.demonstration_contacts_verified',
                after: ['accounts' => $done, 'by' => 'accounts:verify-demonstration'],
            );
        }

        $this->info(sprintf(
            '%d demonstration %s pre-verified. Every other account meets the contact gate at its next sign-in.',
            $done,
            $done === 1 ? 'account' : 'accounts',
        ));

        return self::SUCCESS;
    }
}
