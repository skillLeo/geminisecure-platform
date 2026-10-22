<?php

declare(strict_types=1);

namespace App\Live\Relay;

use InvalidArgumentException;

/**
 * THE ONE CLOCK EVERY PUBLISH BEFORE A PANIC'S FAN-OUT RUNS ON (DESIGN.md §1.6, §13.2).
 *
 * A panic's request publishes before it pushes the guards, mails and texts, so everything it
 * publishes first comes out of the guards' time. Today's bound is 2 s in all with 0.5 s to
 * connect (config/broadcasting.php, `reverb`), and live updates may not make it longer. So the
 * request starts ONE budget before its first publish, and every publish before the fan-out, the
 * legacy AlertRaised and Relay::flushNow, spends from it:
 *
 *     $budget = PanicPublishBudget::start(2.0);
 *     $accepted = <the legacy broadcast>;  $budget->recordLegacy($accepted);
 *     Relay::flushNow(Stream::central(), urgentOnly: true, budget: $budget);
 *
 * flushNow connects with min(0.5, left()) and gives up at left(). With less than 0.2 s left, or
 * after an earlier publish on this budget failed (Reverb down or hung), it does not try at all: it
 * only rings the relay's bell and returns false, and the relay publishes as soon as Reverb answers.
 * The worst case before the fan-out is therefore today's, and never more.
 *
 * The clock is monotonic (hrtime) and starts at start(), before the first publish, so it covers
 * flushNow's lock tries too.
 */
final class PanicPublishBudget
{
    /** Today's connect limit, the most any one publish on this budget may wait to connect. */
    public const CONNECT_SECONDS = 0.5;

    /** Below this, a publish is not worth starting: it rings the bell instead. */
    public const FLOOR_SECONDS = 0.2;

    private bool $failed = false;

    private function __construct(
        private readonly int $startedAt,
        private readonly float $seconds,
    ) {}

    public static function start(float $seconds = 2.0): self
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('A publish budget is a positive number of seconds.');
        }

        return new self(hrtime(true), $seconds);
    }

    /** Seconds spent since start(). */
    public function spent(): float
    {
        return (hrtime(true) - $this->startedAt) / 1e9;
    }

    /** Seconds left, never below zero. */
    public function left(): float
    {
        return max(0.0, $this->seconds - $this->spent());
    }

    /** How long the next publish may wait to connect. */
    public function connectTimeout(): float
    {
        return min(self::CONNECT_SECONDS, $this->left());
    }

    /** Whether there is enough left to start a publish at all. */
    public function allowsPublish(): bool
    {
        return ! $this->failed && $this->left() >= self::FLOOR_SECONDS;
    }

    /** What the legacy broadcast, run first on this budget, came to. */
    public function recordLegacy(bool $accepted): void
    {
        if (! $accepted) {
            $this->failed = true;
        }
    }

    /** A publish on this budget failed: nothing after it on this budget tries again. */
    public function markFailed(): void
    {
        $this->failed = true;
    }

    public function failed(): bool
    {
        return $this->failed;
    }
}
