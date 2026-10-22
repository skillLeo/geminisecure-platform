<?php

declare(strict_types=1);

namespace App\Live\Relay;

/**
 * The relay's own sense of time (DESIGN.md §1.5): seconds on a clock that only goes forward.
 *
 * The pulse, the sweeps, the breaker's rest, the retry delays, the evictor's 250 ms and the timer
 * for a scheduled row are all "so long after that", never a wall-clock time, so none of them can
 * be thrown by the machine's clock being set. Tests stand in a clock they move by hand.
 */
interface Clock
{
    /** Seconds since some fixed moment in this process. */
    public function now(): float;

    public function sleep(float $seconds): void;
}
