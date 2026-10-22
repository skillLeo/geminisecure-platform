<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Auth\Principal;
use App\Live\Channels;
use App\Live\LiveEvent;
use App\Live\Stream;
use InvalidArgumentException;

/**
 * "What you may see has changed": `access.changed` on a principal's own channel (DESIGN.md §7).
 *
 * The open console reloads its page (every gate is asked again); the phone re-reads what it may
 * hold. The relay then asks Reverb to close every socket the principal holds, 250 ms after it
 * publishes this (App\Live\Access\Evictor), so each one is authorised again under the new rules.
 *
 * The summary says only how it changed, `{effect: ended | reduced | changed}`, never why.
 *
 * On the central stream, about the principal's estate when it has one: `user.{u}` for a console
 * user (a platform channel), `guard.{t}.{g}` and `resident.{t}.{a}` for a handset. The reconcile
 * backstop (live:reconcile) records it for a principal whose grant no longer holds; W10 adds the
 * business transactions that record it at the moment access changes, held until the permission
 * cache is flushed where RoleEditor is the writer (§1.7).
 */
final class AccessLive extends LiveEvent
{
    public const TYPE = 'access.changed';

    public const EFFECTS = ['ended', 'reduced', 'changed'];

    private function __construct(
        private readonly string $principalKey,
        private readonly Stream $on,
        private readonly string $channel,
        private readonly string $effect,
        private readonly bool $isHeld,
    ) {}

    public static function for(Principal $principal, string $effect, bool $held = false): self
    {
        if (! in_array($effect, self::EFFECTS, true)) {
            throw new InvalidArgumentException("An access change is ended, reduced or changed, not '{$effect}'.");
        }

        [$stream, $channel] = match (true) {
            $principal->user !== null => [Stream::central(), Channels::user($principal->user->id)],
            $principal->guard !== null => [Stream::central((string) $principal->guard->tenant_id), Channels::guard((string) $principal->guard->tenant_id, $principal->guard->id)],
            $principal->resident !== null => [Stream::central($principal->resident->tenant_id), Channels::resident($principal->resident->tenant_id, $principal->resident->id)],
            default => throw new InvalidArgumentException('A principal with nobody in it.'),
        };

        return new self($principal->key(), $stream, $channel, $effect, $held);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function entity(): string
    {
        return 'access';
    }

    public function entityId(): string
    {
        return $this->principalKey;
    }

    public function stream(): Stream
    {
        return $this->on;
    }

    public function audiences(): array
    {
        return [new Audience([$this->channel], summary: ['effect' => $this->effect])];
    }

    public function held(): bool
    {
        return $this->isHeld;
    }
}
