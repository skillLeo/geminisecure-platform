<?php

declare(strict_types=1);

namespace App\Jobs\Ring;

use App\Models\DuressAlertRingRound;
use App\Services\Ring\GuardRing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ONE ROUND OF A PANIC'S RING, WHEN IT FALLS DUE (the guard ring §4.2).
 *
 * Queued at the raise with a delay — round 1 for sixty seconds, each later one by the round
 * before it — on the central connection and the panic queue. It carries two numbers and
 * nothing else; everything it needs is read again when it runs.
 *
 * ONE TRY, BECAUSE THE SWEEP IS THE RETRY. A round that dies part-way is taken over by
 * `ring:sweep` once its claim is ninety seconds stale, and every send inside it is
 * idempotent per phone, so nobody is rung twice for one round. A queue retry on top of
 * that would only race the sweep.
 *
 * ITS CONSTRUCTOR NEVER GROWS A PROMOTED PARAMETER (G2): a later field is an ordinary
 * property with a default, so a round queued by the previous release still runs.
 */
class RingRound implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** Under central's retry_after of 90, so a round is never handed to a second worker while it runs. */
    public int $timeout = 80;

    public function __construct(
        public readonly int $alertId,
        public readonly int $round,
    ) {}

    public function handle(GuardRing $ring): void
    {
        $ring->runRound($this->alertId, $this->round, DuressAlertRingRound::BY_JOB);
    }
}
