<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Estate\Amenities;
use App\Support\LocalSchedule;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan amenities:settle                     # every estate with a database
 *   php artisan amenities:settle --estate=phoenixpark --dry-run
 *
 * Moves every booking's deposit on, so none sits on 2200 Resident Deposits Held
 * for good (M4 §4, defect 2) — scheduled every morning in `routes/console.php`.
 * What it completes, releases and clears, and why, is `Amenities::settle()`;
 * this only walks the estates.
 *
 * IT NEVER FORFEITS. Keeping a resident's money is a person's decision, with a
 * reason, behind `estate.facilities.approve`. All this does on its own is give
 * money BACK — and say so in the ledger and in the audit log.
 *
 * EVERY ESTATE, NOT ONLY THE ONES CURRENTLY PAYING US.
 *
 * This used to select `status = 'active'` alone, copied from `dunning:run` and
 * `push:reminders`, where it is right — you do not chase a suspended client's
 * residents for dues, and you do not push notifications into an estate that has
 * stopped. Copying it here left a suspended or `dunning` client's households out
 * of pocket: their deposits stayed on 2200 for as long as the commercial dispute
 * lasted, which is the exact shape of the defect M4 §4 sent us to fix.
 *
 * The platform's own rule says why that is wrong. `dunning` and `suspended`
 * "gate BILLING features and never entry or a safety function" (`ClientDirectory`,
 * `Subscription`). A deposit is not a feature we sell. It is a household's money
 * that the ESTATE is holding, and the estate owes it back whether or not it has
 * paid OUR invoice. Widening this can cost nobody anything either, because giving
 * money back is the only thing this command can do: it never charges, never
 * notifies, never forfeits, and an `onboarding` estate with no bookings yet simply
 * reports nothing.
 *
 * AN ESTATE WITH NO DATABASE IS SKIPPED, NOT FAILED. Every estate the provisioner
 * makes has one, but the widened selection can now reach a row recorded without
 * the pipeline having run. That is not something to wake anybody at 6:30 AM for,
 * so it is named and passed over and the exit code stays clean.
 *
 * SAFE TO RUN AS OFTEN AS YOU LIKE. Each move happens once: a deposit already
 * released, kept or cleared is not selected again, and the service re-reads the
 * state under a row lock before it posts.
 *
 * ONE ESTATE FAILING DOES NOT STOP THE REST; the command exits non-zero at the
 * end so the scheduler's log says which.
 */
class AmenitiesSettle extends Command
{
    /** Its name in scheduled_local_runs. */
    private const JOB = 'amenities:settle';

    protected $signature = 'amenities:settle
        {--estate= : one estate only}
        {--dry-run : report what would move, and move nothing}
        {--at= : HH:MM — only the estates whose OWN clock has reached this time today and that have not run on their date yet, as the schedule runs it}';

    protected $description = 'Complete finished bookings and release the deposits nobody has a claim on, in every estate';

    public function handle(): int
    {
        $estates = Tenant::estates(function ($query): void {
            $query->orderBy('id');

            if ($this->option('estate') !== null) {
                $query->whereKey((string) $this->option('estate'));
            }
        });

        if ($estates->isEmpty()) {
            $this->warn('No estate to run.');

            return self::SUCCESS;
        }

        $failed = 0;
        $dryRun = (bool) $this->option('dry-run');

        $at = $this->option('at');

        foreach ($estates as $estate) {
            if (! $this->hasADatabase($estate)) {
                $this->line("{$estate->name}: no database yet — nothing to settle.");

                continue;
            }

            $id = (string) $estate->getTenantKey();

            // At the estate's own 06:30 (M11 §4.2), once per its own date — App\Support\LocalSchedule.
            if (is_string($at) && $at !== '' && ! ($dryRun ? LocalSchedule::due(self::JOB, $id, $at) : LocalSchedule::claim(self::JOB, $id, $at))) {
                continue;
            }

            try {
                $report = $estate->run(fn (): array => app(Amenities::class)->settle(dryRun: $dryRun));
            } catch (Throwable $failure) {
                $failed++;
                $this->error("{$estate->name}: the run failed — {$failure->getMessage()}");

                if (is_string($at) && $at !== '' && ! $dryRun) {
                    LocalSchedule::release(self::JOB, $id);
                }

                continue;
            }

            $this->info(sprintf(
                '%s: %d %s, %d %s, %d %s.',
                $estate->name,
                count($report['completed']),
                $dryRun ? 'bookings would be completed' : 'bookings completed',
                count($report['released']),
                $dryRun ? 'deposits would be released' : 'deposits released',
                count($report['cleared']),
                $dryRun ? 'unpaid deposits would stop being awaited' : 'unpaid deposits no longer awaited',
            ));

            // M53 §2: requests nobody decided before their time, said on a line of their own when there are any.
            if ($report['expired'] !== []) {
                $this->info(sprintf('%s: %d %s.', $estate->name, count($report['expired']), $dryRun ? 'requests nobody decided would expire' : 'requests nobody decided expired'));
            }

            foreach (['expired', 'completed', 'released', 'cleared'] as $kind) {
                foreach ($report[$kind] as $line) {
                    $this->line("  {$kind} · {$line}");
                }
            }

            foreach ($report['failed'] as $line) {
                $failed++;
                $this->error("  failed · {$line}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Whether this estate's database has actually been built.
     *
     * Asked of MySQL through tenancy's own manager rather than guessed from a
     * status or from `provisioned_at`, so the answer is the one that matters:
     * is there something to open. A row with no database is a half-recorded
     * estate, not a failed settlement.
     */
    private function hasADatabase(Tenant $estate): bool
    {
        try {
            $database = $estate->database();

            return $database->manager()->databaseExists($database->getName());
        } catch (Throwable) {
            return false;
        }
    }
}
