<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Estate\Autopay;
use App\Support\LocalSchedule;
use App\Support\SiteTime;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan dues:autopay                         # every estate with a database, on its own date
 *   php artisan dues:autopay --estate=phoenixparkvillage1 --dry-run
 *
 * Keeps every household's AutoPay (M50 §4.2 #3): on the day it was given, pay what is owed, up to its limit, with its
 * saved card — through the demonstration checkout, so a real receipt is posted to 1090 and no money moves. What it pays
 * and why is `Autopay::runDue()`; this only walks the estates, at each one's own 06:00 (`App\Support\LocalSchedule`).
 *
 * SAFE TO RUN AS OFTEN AS YOU LIKE: an arrangement runs once on its day (`last_run_on`), and nothing owed is nothing
 * charged. With the demonstration checkout off it charges nothing and says so on the arrangement.
 *
 * ONE ESTATE FAILING DOES NOT STOP THE REST; the command exits non-zero at the end so the scheduler's log says which.
 */
class DuesAutopay extends Command
{
    /** Its name in scheduled_local_runs. */
    private const JOB = 'dues:autopay';

    protected $signature = 'dues:autopay
        {--estate= : one estate only}
        {--dry-run : report what would be paid, and pay nothing}
        {--at= : HH:MM — only the estates whose OWN clock has reached this time today and that have not run on their date yet, as the schedule runs it}';

    protected $description = 'Pay each household\'s AutoPay on its day, up to its limit, with its saved card';

    public function handle(): int
    {
        $estates = Tenant::estates(function ($query): void {
            $query->orderBy('id');

            if ($this->option('estate') !== null) {
                $query->whereKey((string) $this->option('estate'));
            }
        });

        $failed = 0;
        $dryRun = (bool) $this->option('dry-run');
        $at = $this->option('at');

        foreach ($estates as $estate) {
            $id = (string) $estate->getTenantKey();

            try {
                $database = $estate->database();

                if (! $database->manager()->databaseExists($database->getName())) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            if (is_string($at) && $at !== '' && ! ($dryRun ? LocalSchedule::due(self::JOB, $id, $at) : LocalSchedule::claim(self::JOB, $id, $at))) {
                continue;
            }

            try {
                $report = $estate->run(fn (): array => app(Autopay::class)->runDue(SiteTime::today($id), $dryRun));
            } catch (Throwable $failure) {
                $failed++;
                $this->error("{$estate->name}: the run failed — {$failure->getMessage()}");

                if (is_string($at) && $at !== '' && ! $dryRun) {
                    LocalSchedule::release(self::JOB, $id);
                }

                continue;
            }

            $this->info(sprintf('%s: %d paid, %d with nothing owed, %d not charged.', $estate->name, count($report['paid']), count($report['nothing']), count($report['refused'])));

            foreach (['paid', 'nothing', 'refused'] as $kind) {
                foreach ($report[$kind] as $line) {
                    $this->line("  {$kind} · {$line}");
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
