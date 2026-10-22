<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GuardApp\AlertnessChecks;
use Illuminate\Console\Command;

/**
 *   php artisan alertness:run
 *
 * Issue the random alertness checks guards on duty are due, and record as missed
 * the ones nobody answered in time (13 D2). Scheduled every five minutes in
 * `routes/console.php`; what is issued, when, and why is `AlertnessChecks`.
 */
class AlertnessRun extends Command
{
    protected $signature = 'alertness:run';

    protected $description = 'Issue due alertness checks to guards on duty, and mark unanswered ones missed';

    public function handle(AlertnessChecks $checks): int
    {
        $report = $checks->run();

        $this->info("{$report['issued']} issued, {$report['missed']} recorded missed.");

        return self::SUCCESS;
    }
}
