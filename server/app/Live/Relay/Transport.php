<?php

declare(strict_types=1);

namespace App\Live\Relay;

/**
 * What the relay hands its messages to (DESIGN.md §1.5, §7.1): Reverb's HTTP API in production
 * (PusherTransport), nowhere with `live.publish` = none, and a recording fake in the tests.
 *
 * Every call either succeeds or throws PublishFailed, which says whether Reverb could not be
 * reached (or answered 5xx), and so is DOWN, or answered and REFUSED the message (4xx).
 */
interface Transport
{
    /**
     * One batch: each item `{name, channel, data}`, data already JSON, the whole body at most
     * Publisher::BATCH_BYTES. `$connect` and `$total` bound this one call, in seconds.
     *
     * @param  list<array{name: string, channel: string, data: string}>  $batch
     *
     * @throws PublishFailed
     */
    public function publish(array $batch, float $connect, float $total): void;

    /**
     * Close every socket whose subscriptions carry this principal key (`u-12`, `g-5`, `r-9`).
     *
     * @throws PublishFailed
     */
    public function terminate(string $principalKey, float $connect, float $total): void;

    /**
     * How many channels are occupied: the signed `GET /apps/{id}/channels` that proves Reverb
     * answers the relay and accepts its credentials (`live:health`).
     *
     * @throws PublishFailed
     */
    public function occupiedChannels(float $connect, float $total): int;
}
