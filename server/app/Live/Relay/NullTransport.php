<?php

declare(strict_types=1);

namespace App\Live\Relay;

/**
 * `live.publish` = none: every message is accepted and sent nowhere, the way BROADCAST_CONNECTION
 * = null treats a broadcast. The relay still sequences and marks its rows published, so the log
 * behaves as it does in production and catch-up answers the same.
 */
final class NullTransport implements Transport
{
    public function publish(array $batch, float $connect, float $total): void {}

    public function terminate(string $principalKey, float $connect, float $total): void {}

    public function occupiedChannels(float $connect, float $total): int
    {
        return 0;
    }
}
