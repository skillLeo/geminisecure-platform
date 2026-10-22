<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveEvent;
use App\Live\Stream;

/**
 * `live.smoke`: the one event live:smoke records, to see it come back through the socket.
 *
 * A platform event on the platform settings feed, a ref with no summary, named by a random nonce so
 * the smoke test knows its own. Every client ignores a type it does not handle, so a console that
 * happens to be open on Platform settings receives it and does nothing (DESIGN.md section 10.3).
 */
final class SmokeLive extends LiveEvent
{
    public const TYPE = 'live.smoke';

    private function __construct(private readonly string $nonce) {}

    public static function now(string $nonce): self
    {
        return new self($nonce);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function entity(): string
    {
        return 'smoke';
    }

    public function entityId(): string
    {
        return $this->nonce;
    }

    public function stream(): Stream
    {
        return Stream::central();
    }

    public function audiences(): array
    {
        return [new Audience([Channels::geminiFanIn('settings')])];
    }
}
