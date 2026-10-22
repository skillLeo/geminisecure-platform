<?php

declare(strict_types=1);

namespace App\Live\Relay;

/**
 * What the relay waits on between passes (DESIGN.md §1.5): the Redis bell (Wake), or a stand-in.
 *
 * `wait()` returns the stream keys rung since the last call (`c`, `e:{tenant}`), or EVERYTHING
 * when there is no bell to hear and every stream should be looked at.
 */
interface Bell
{
    public const EVERYTHING = '*';

    /** @return list<string> */
    public function wait(float $seconds): array;
}
