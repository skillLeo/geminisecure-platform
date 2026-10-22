<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dispatch\ShiftAutoClose;
use Illuminate\Console\Command;

/**
 *   php artisan shifts:auto-close
 *
 * Close every shift that is more than its site's hours past its rostered end and
 * was never clocked out (M6 §2.1 part 4). Scheduled HOURLY in `routes/console.php`
 * — this is not a thing anybody should have to remember to run, because the failure
 * it prevents is a forgotten clock-out disabling a site's whole panic fallback.
 *
 * Safe to run as often as anybody likes: a shift with an end is never touched again.
 * What is closed, why, and what the guard and the Head of Security are told is
 * `App\Services\Dispatch\ShiftAutoClose`.
 */
class ShiftsAutoClose extends Command
{
    protected $signature = 'shifts:auto-close';

    protected $description = 'Close shifts nobody clocked out of, past their site\'s hours after the rostered end';

    public function handle(ShiftAutoClose $rule): int
    {
        $report = $rule->run();

        $this->info("{$report['closed']} shift(s) closed across {$report['sites']} site(s) with an open shift.");

        return self::SUCCESS;
    }
}
