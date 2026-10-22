<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ring\GuardRing;
use Illuminate\Console\Command;

/**
 * THE RING'S SAFETY NET — every fifteen seconds, from cron, not from the queue workers
 * (the guard ring §4.3).
 *
 * A round is a delayed job on the panic queue, and the two panic workers run it. If they
 * are down — a crash, a deploy gone wrong, a full disk — the rings stop, and nothing else
 * notices. This runs in `schedule:run`, which cron starts every minute independently of
 * the workers, and sends INLINE, in its own process:
 *
 *   1  a round that fell due and nobody claimed (after a grace, so a healthy job wins)
 *   2  a round claimed and never finished — a worker that died mid-round
 *   3  a stop some phone is still owed, once the queued stops have had their chance
 *   4  an answer no hook caught
 *
 * Each is idempotent per phone, so the net never rings anybody twice, and each one it
 * catches is REPORTED — "sent by the safety net 22 s late: is gs-queue-panic running?" —
 * because a net that quietly does the workers' job hides that they are down.
 *
 * It does NOT rescue today's visible push: that is the existing path for every push on
 * the platform, and changing it is not this unit's to do (§19 item 8).
 */
class RingSweep extends Command
{
    protected $signature = 'ring:sweep';

    protected $description = 'The guard ring\'s safety net: send any round, stop or answer the queue workers have not.';

    public function handle(GuardRing $ring): int
    {
        $done = $ring->sweep();

        if (array_sum($done) > 0) {
            $this->line(sprintf('ring:sweep — %d round(s) sent, %d stale claim(s) taken over, %d stop(s) sent, %d missed answer(s) caught.', $done['rounds'], $done['stale'], $done['stops'], $done['missed']));
        }

        return self::SUCCESS;
    }
}
