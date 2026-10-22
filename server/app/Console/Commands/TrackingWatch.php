<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tracking\TrackingGaps;
use Illuminate\Console\Command;

/**
 *   php artisan tracking:watch
 *
 * Every minute (M22 §1.1): an officer on duty who agreed to share, whose phone has recorded no position for
 * `tracking.gap_after_seconds`, is told on that phone, once per gap. See `TrackingGaps::watch()`.
 */
class TrackingWatch extends Command
{
    protected $signature = 'tracking:watch';

    protected $description = 'Tell an on-duty officer whose phone has stopped recording positions, once per gap';

    public function handle(TrackingGaps $gaps): int
    {
        $done = $gaps->watch();

        $this->info(sprintf('tracking:watch — %d officer(s) sharing on duty, %d with no position for %d s, %d told now.', $done['watched'], $done['open'], TrackingGaps::threshold(), $done['told']));

        return self::SUCCESS;
    }
}
