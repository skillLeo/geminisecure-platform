<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveEvent;
use App\Live\Stream;
use App\Models\GateEvent;

/**
 * SOMEBODY CAME IN, WENT OUT, WAS LET IN AGAINST THE SYSTEM'S ADVICE, OR WAS TURNED AWAY (M11 Part C, DESIGN.md
 * §2.9 I10–I13). Central stream: the visitor log is the platform's, written by GateLog::record — the one funnel.
 *
 *   ES    the estate's Security page: its visitor log and its refused tile
 *   GW    the Gemini console's Gate activity: the feed and its admits and denies
 *   EGu   every guard of the estate: their own gate activity list, and another gate's
 *
 * A reference: which gate event, and what kind (the type). The visitor's name and the guard's are on the row,
 * read again by each screen through its own gate.
 */
final class GateLive extends LiveEvent
{
    /** The verdicts GateLog writes, each its own type. */
    public const VERDICTS = ['admit', 'override', 'exit', 'deny'];

    /** @return list<string> every type this class emits (App\Live\LiveTypes) */
    public static function types(): array
    {
        return array_map(static fn (string $verdict): string => 'gate.'.$verdict, self::VERDICTS);
    }

    private function __construct(
        private readonly string $verdict,
        private readonly int $eventId,
        private readonly string $tenantId,
        private readonly bool $isSimulated,
    ) {}

    public static function for(GateEvent $event): self
    {
        $verdict = in_array($event->verdict, self::VERDICTS, true) ? $event->verdict : 'admit';

        return new self($verdict, (int) $event->getKey(), (string) $event->tenant_id, (bool) $event->is_simulated);
    }

    public function type(): string
    {
        return 'gate.'.$this->verdict;
    }

    public function entity(): string
    {
        return 'gate';
    }

    public function entityId(): string
    {
        return (string) $this->eventId;
    }

    public function stream(): Stream
    {
        return Stream::central($this->tenantId);
    }

    public function audiences(): array
    {
        return [new Audience([
            Channels::estateFeed($this->tenantId, 'security'),
            ...Channels::gemini('workforce', $this->tenantId),
            Channels::estateGuards($this->tenantId),
        ])];
    }

    public function simulated(): bool
    {
        return $this->isSimulated;
    }
}
