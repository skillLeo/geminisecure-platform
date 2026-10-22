<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveEvent;
use App\Live\Stream;

/**
 * "Re-read everything": an operator's `live:resync {estate}` (DESIGN.md §5.5, §6.5).
 *
 * One of the three sanctioned whole-screen refetches. A screen that hears `live.resync` resyncs
 * every binding on that channel, exactly as after a catch-up `reset`. It is for a repair made
 * outside the application (a row put right by hand, a restore) that no event describes.
 *
 * Logged and sequenced like any other event, so a screen that was offline when it was recorded
 * still resyncs when it catches up. Two events, one per log, because each channel is read from
 * its own stream (§3.1):
 *   estate()  on the estate's own stream: every estate feed, its guards, its passes, its community;
 *   gemini()  on the central stream: every Gemini feed that carries the estate (never the
 *             demonstration estate, which no Gemini feed carries, `Channels::gemini()`).
 * A household's, a resident's or a guard's own channel is not named: there is no list of them to
 * name, and those screens read through the estate's feeds and community.
 */
final class ResyncLive extends LiveEvent
{
    public const TYPE = 'live.resync';

    /**
     * @param  list<string>  $channels
     */
    private function __construct(
        private readonly string $tenantId,
        private readonly Stream $on,
        private readonly array $channels,
    ) {}

    public static function estate(string $tenantId): self
    {
        $channels = array_map(static fn (string $feed): string => Channels::estateFeed($tenantId, $feed), Channels::ESTATE_FEEDS);

        return new self($tenantId, Stream::estate($tenantId), [
            ...$channels,
            Channels::estateGuards($tenantId),
            Channels::estatePasses($tenantId),
            Channels::community($tenantId),
        ]);
    }

    public static function gemini(string $tenantId): self
    {
        $channels = [];

        foreach (array_diff(Channels::GEMINI_FEEDS, Channels::GEMINI_PLATFORM_ONLY) as $feed) {
            array_push($channels, ...Channels::gemini($feed, $tenantId));
        }

        return new self($tenantId, Stream::central($tenantId), array_values(array_unique($channels)));
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function entity(): string
    {
        return 'estate';
    }

    public function entityId(): string
    {
        return $this->tenantId;
    }

    public function stream(): Stream
    {
        return $this->on;
    }

    public function audiences(): array
    {
        return $this->channels === [] ? [] : [new Audience($this->channels)];
    }
}
