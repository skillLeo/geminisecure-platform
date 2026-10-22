<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\Relay\Clock;
use App\Live\Relay\Relay;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan live:relay [--max-time=3600]
 *
 * The live relay (DESIGN.md §1.5), run by supervisor as `gs-live-relay` with numprocs=1. It exits
 * after `--max-time` seconds and supervisor starts it again, on whatever release `current` is by
 * then; `80-live.sh --on-deploy` also restarts it straight after a deploy.
 *
 * ONE RELAY, EVER. It takes the MySQL lock `gs_live_relay` before it does anything. When another
 * relay holds it (a restart overlapping a slow stop), this one WAITS for it, checking every five
 * seconds, rather than exiting at once and being restarted in a loop; if the lock never comes free
 * it gives up at `--max-time` and says so. It stops cleanly on SIGTERM and SIGINT where the process
 * can hear them, and when its database session no longer holds the lock (the connection was
 * replaced), in which case supervisor starts a fresh one.
 */
class LiveRelay extends Command
{
    protected $signature = 'live:relay
        {--max-time=3600 : seconds to run before exiting, so supervisor starts it again on the current release}';

    protected $description = 'Publish the live log to Reverb: sequence, publish, retry, sweep and pulse (one relay at a time)';

    public function handle(Relay $relay, Clock $clock): int
    {
        $seconds = max(1, (int) $this->option('max-time'));
        $deadline = $clock->now() + $seconds;
        $waiting = false;

        while (! Relay::acquireLock()) {
            if (! $waiting) {
                $this->line('Another live relay holds the lock ('.Relay::LOCK.'); waiting for it to stop.');
                $waiting = true;
            }

            if ($clock->now() >= $deadline) {
                $this->line('The other relay still holds the lock after '.$seconds.' s. This one exits without having run.');

                return self::FAILURE;
            }

            $clock->sleep(min(5.0, $deadline - $clock->now()));
        }

        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], static fn () => $relay->stop());
        }

        $this->line(sprintf(
            'Live relay started: wake %s, publish %s, for %d s.',
            (string) config('live.wake'),
            (string) config('live.publish'),
            $seconds,
        ));

        try {
            $relay->run(max(0.0, $deadline - $clock->now()));
        } finally {
            try {
                Relay::releaseLock();
            } catch (Throwable) {
                // The session is gone, and the lock with it.
            }
        }

        if ($relay->lostLock()) {
            $this->line('The relay stopped: its database session no longer holds the lock.');

            return self::FAILURE;
        }

        $this->line('Live relay stopped.');

        return self::SUCCESS;
    }
}
