<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tracking\GuardSignal;
use Illuminate\Console\Command;

/**
 *   php artisan tracking:signal
 *
 * Every minute (guard live tracking, 12 Oct 2026; GUARD_TRACKING.md 5.6): a guard on duty who agreed to share, from
 * whose phone nothing has arrived for longer than their estate's tolerance, raises "No signal from <name> at <site>,
 * last seen HH:MM" in the dispatcher's alert queue; an alert whose shift has ended clears. See `GuardSignal::watch()`.
 */
class TrackingSignal extends Command
{
    protected $signature = 'tracking:signal';

    protected $description = 'Raise a No signal alert for a guard on duty whose phone has gone quiet, and clear the ended ones';

    public function handle(GuardSignal $signal): int
    {
        $done = $signal->watch();

        $this->info(sprintf('tracking:signal: %d No signal alert(s) raised, %d cleared.', $done['raised'], $done['cleared']));

        return self::SUCCESS;
    }
}
