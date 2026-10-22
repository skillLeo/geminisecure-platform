<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tracking\GuardTracking;
use Illuminate\Console\Command;

/**
 *   php artisan gs:guard-positions-purge
 *
 * On-duty guard positions (M11 §3.3; D-174) are kept for `config('tracking.retention_days')` — ninety days unless
 * the environment says otherwise — and deleted after. Nightly. A retention period nobody enforces is a sentence in
 * a privacy notice, not a retention period; this is the enforcement, and its test proves a fix one day past the
 * window goes and one a day inside it stays.
 */
class GuardPositionsPurge extends Command
{
    protected $signature = 'gs:guard-positions-purge';

    protected $description = 'Delete on-duty guard positions older than the retention period (config tracking.retention_days, 90 by default)';

    public function handle(GuardTracking $tracking): int
    {
        $days = (int) config('tracking.retention_days', 90);

        $this->info($tracking->purge().' guard position(s) older than '.$days.' days deleted.');

        return self::SUCCESS;
    }
}
