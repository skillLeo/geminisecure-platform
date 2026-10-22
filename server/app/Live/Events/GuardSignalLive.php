<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveEvent;
use App\Live\Stream;

/**
 * A GUARD ON DUTY HAS GONE QUIET, OR SPOKE AGAIN (guard live tracking, 12 Oct 2026; GUARD_TRACKING.md 5.6).
 *
 * `guard.signal`: a No signal alert was raised or cleared on a shift (`GuardSignal`). To the Gemini dispatch feed only:
 * the alert is the dispatcher's, and it sits in their alert queue. An estate's map judges its own pins' freshness on
 * its own clock and needs no event for it.
 *
 * A REFERENCE: which officer, nothing about where or since when; the queue reads the alerts again through its own read.
 */
final class GuardSignalLive extends LiveEvent
{
    public const TYPE = 'guard.signal';

    private function __construct(
        private readonly int $guardId,
        private readonly string $tenantId,
    ) {}

    public static function changed(int $guardId, string $tenantId): self
    {
        return new self($guardId, $tenantId);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function entity(): string
    {
        return 'guard';
    }

    public function entityId(): string
    {
        return (string) $this->guardId;
    }

    public function stream(): Stream
    {
        return Stream::central($this->tenantId);
    }

    public function audiences(): array
    {
        return [new Audience(Channels::gemini('dispatch', $this->tenantId))];
    }

    public function simulated(): bool
    {
        return false;
    }
}
