<?php

declare(strict_types=1);

namespace App\Live\Relay;

/** The monotonic clock (hrtime), and a real sleep. */
final class SystemClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1e9;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
