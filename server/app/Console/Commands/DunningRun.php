<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Estate\Collections;
use App\Services\Platform\OperatorEstates;
use App\Support\LocalSchedule;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan dunning:run                     # every live estate
 *   php artisan dunning:run --estate=phoenixpark --dry-run
 *
 * The automated arrears ladder (13 A4) — scheduled every morning in
 * `routes/console.php`. What it sends, what it skips and why is
 * `Collections::runAutomated()`; this only walks the estates.
 *
 * LIVE ESTATES ONLY. An estate still onboarding has a ledger nobody has
 * reconciled yet, and a final demand drawn from it would be the platform's
 * first message to a resident — about money that may not be owed.
 *
 * ONE ESTATE FAILING DOES NOT STOP THE REST. Each is reported and the command
 * exits non-zero at the end, so the scheduler's log says which estate failed
 * without every other estate's households going unchased that morning.
 */
class DunningRun extends Command
{
    /** Its name in scheduled_local_runs. */
    private const JOB = 'dunning:run';

    protected $signature = 'dunning:run
        {--estate= : one estate only}
        {--dry-run : report what would be sent, and send nothing}
        {--at= : HH:MM — only the estates whose OWN clock has reached this time today and that have not run on their date yet, as the schedule runs it}';

    protected $description = 'Send the automated arrears reminders each live estate is due, skipping flagged households and agreed plans';

    public function handle(): int
    {
        /*
         * NOT THE DEMONSTRATION ESTATE (M5 §3), and this is the scheduled
         * command where that mattered most.
         *
         * `demo:seed` leaves this month's maintenance charge OUTSTANDING on
         * purpose, so a reviewer opening Dues sees a balance the app offers to
         * pay. This command runs at 09:00 every morning against every ACTIVE
         * estate, and the demonstration estate is active — so the arrears ladder
         * was climbing against six invented households, addressing final demands
         * to `@demo.geminisecure.app`, and putting invented arrears into the
         * collections figures somebody at Gemini reads.
         *
         * Excluded HERE rather than by making the estate inactive, because
         * `active` is what the Resident App's pick-list and the reviewer's
         * sign-in both read: the estate has to stay live to be demonstrable.
         *
         * `--estate=demoestate` is still refused by this, deliberately. If the
         * demonstration estate's ladder ever needs exercising, that is what
         * `--dry-run` against a real estate is for; nothing should be able to
         * send that mail by typing one flag.
         */
        $estates = OperatorEstates::estates(function ($query): void {
            $query->where('status', 'active')->orderBy('id');

            if ($this->option('estate') !== null) {
                $query->whereKey((string) $this->option('estate'));
            }
        });

        if ($estates->isEmpty()) {
            $this->warn('No live estate to run.');

            return self::SUCCESS;
        }

        $failed = 0;
        $dryRun = (bool) $this->option('dry-run');

        $at = $this->option('at');

        foreach ($estates as $estate) {
            $id = (string) $estate->getTenantKey();

            /*
             * AT THE ESTATE'S OWN 09:00 (M11 §4.2), not Kingston's for everybody: scheduled every fifteen minutes with
             * --at, each estate is chased once its own clock reaches the hour, once per its own date
             * (App\Support\LocalSchedule). A dry run looks without spending the day.
             */
            if (is_string($at) && $at !== '' && ! ($dryRun ? LocalSchedule::due(self::JOB, $id, $at) : LocalSchedule::claim(self::JOB, $id, $at))) {
                continue;
            }

            try {
                $report = $estate->run(fn (): array => app(Collections::class)->runAutomated(dryRun: $dryRun));
            } catch (Throwable $failure) {
                $failed++;
                $this->error("{$estate->name}: the run failed — {$failure->getMessage()}");

                if (is_string($at) && $at !== '' && ! $dryRun) {
                    LocalSchedule::release(self::JOB, $id);
                }

                continue;
            }

            $this->info(sprintf(
                '%s: %d %s, %d flagged and suppressed, %d on an agreed plan, %d not yet due, %d already sent this step.',
                $estate->name,
                count($report['sent']),
                $dryRun ? 'would be sent' : 'sent',
                $report['suppressed'],
                $report['on_plan'],
                $report['not_due'],
                $report['already_sent'],
            ));

            foreach ($report['sent'] as $sent) {
                $this->line("  {$sent['unit']} · {$sent['step']}");
            }

            /*
             * THE PLANS WAITING ON A RULING (bug hunt B019). The run skips a
             * household on an active plan, and cannot rule its instalments for
             * anybody; this names every plan with one past its date and not ruled
             * met or missed, so the shield is never left standing unseen.
             */
            if ($report['awaiting_ruling'] !== []) {
                $this->warn(sprintf(
                    '  %d %s an instalment past due and not yet ruled met or missed on board 7: %s',
                    count($report['awaiting_ruling']),
                    count($report['awaiting_ruling']) === 1 ? 'plan has' : 'plans have',
                    implode(', ', $report['awaiting_ruling']),
                ));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
