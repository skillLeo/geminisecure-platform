<?php

declare(strict_types=1);

namespace App\Live\Relay;

/**
 * What one publish pass came to (DESIGN.md §1.5).
 *
 *   read       rows the pass took from the log (at most Publisher::PASS_LIMIT)
 *   published  rows that reached Reverb whole, and are marked published
 *   gaveUp     rows Reverb refused for the last time, marked publish_failed_at, their channels nudged
 *   waiting    rows left for a later pass: refused and resting, or not reached before an outage
 *   outage     Reverb did not answer, or the breaker was already open; nothing more was tried
 */
final class PublishOutcome
{
    public function __construct(
        public readonly int $read = 0,
        public readonly int $published = 0,
        public readonly int $gaveUp = 0,
        public readonly int $waiting = 0,
        public readonly bool $outage = false,
    ) {}

    /** Every row the pass read is finished with: published, or given up and nudged. */
    public function complete(): bool
    {
        return ! $this->outage && $this->waiting === 0;
    }
}
