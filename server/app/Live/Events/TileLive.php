<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveEvent;
use App\Live\Stream;
use LogicException;

/**
 * A count moved: "re-read this tile" (DESIGN.md §2.1, §4.4).
 *
 * A generic REF event whose record is the tile itself: entity `tile`, id the tile's key (for
 * example `duress_open`). IT CARRIES NO RECORD ID, so an audience that may see a count learns only
 * that the count moved, never which record moved it. Its client refetches the tile through
 * `GET /live/tiles`, which re-applies the tile's own gate.
 *
 * It is logged and sequenced like any other event, so a missed tile update is recovered by
 * catch-up. It is recorded beside the record's own event, in the same transaction, with
 * LiveBus::recordAll(), and it goes to FEED channels only: a record or a person's own channel
 * would name the record the count is about. Used for the estate console's ungated tiles and the
 * Gemini workforce duress tiles; the tables are tracked by the classes that emit it.
 */
final class TileLive extends LiveEvent
{
    public const TYPE = 'tile.changed';

    /** Starts with a letter, so no tile key can be read as a record id. */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param  list<string>  $channels
     */
    private function __construct(
        private readonly string $key,
        private readonly Stream $on,
        private readonly array $channels,
    ) {}

    /**
     * @param  list<string>  $channels  feed channels, from App\Live\Channels
     */
    public static function changed(string $key, Stream $stream, array $channels): self
    {
        return new self($key, $stream, $channels);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function entity(): string
    {
        return 'tile';
    }

    public function entityId(): string
    {
        if (preg_match(self::KEY_PATTERN, $this->key) !== 1) {
            throw new LogicException("'{$this->key}' is not a tile key: lowercase, starting with a letter, so it can never be a record id.");
        }

        return $this->key;
    }

    public function stream(): Stream
    {
        return $this->on;
    }

    public function audiences(): array
    {
        foreach ($this->channels as $channel) {
            if (! Channels::isFeed($channel)) {
                throw new LogicException("A tile update goes to feed channels only. {$channel} is a record or personal channel, and naming it would say which record moved the count.");
            }
        }

        return [new Audience($this->channels)];
    }
}
