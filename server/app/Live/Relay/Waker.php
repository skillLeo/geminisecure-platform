<?php

declare(strict_types=1);

namespace App\Live\Relay;

use App\Live\Stream;

/**
 * What LiveBus rings after a live event commits, so the relay publishes it at once (DESIGN.md §1.3).
 *
 * UNBOUND UNTIL THE RELAY EXISTS. f-web-relay binds this to its Redis bell (`Wake::poke`: SADD
 * live:dirty, LPUSH/LTRIM live:bell). Until then, and whenever it is unbound, LiveBus rings nothing
 * and loses nothing: the relay's sweep reads the log itself, a poke only saves it the wait.
 *
 * A poke must never throw into the write that caused it. LiveBus reports a failing poke and carries
 * on, because the change it follows has already committed.
 */
interface Waker
{
    public function poke(Stream $stream): void;
}
