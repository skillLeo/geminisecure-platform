<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\LiveEvent;
use App\Live\Stream;
use App\Models\Estate\VisitorPass;
use InvalidArgumentException;

/**
 * A VISITOR PASS CHANGED (M11 Part C, DESIGN.md §2.9 I1–I7, I14). Estate stream: the pass lives in the estate.
 *
 *   issued, updated, shared   the household (H): every member's Visitors list, on every phone
 *   cancelled, used           the household, and every guard of the estate (`estate.{t}.passes`): the pass is
 *                             refused on each gate handset from that moment, before any pull
 *   admitted                  the household: its visitor is in — "Arrived 6:42 PM" on the pass, a recurring
 *                             pass as much as a single-use one (the admission is recorded on the pass, §13.3 (1))
 *
 * NAMED BY ITS PUBLIC PASS ID, the one the gate's revoked list already carries, and never by its token or code:
 * a handset that hears `pass.cancelled` for P-7Q2 refuses P-7Q2 on the next scan, signal or not, and nothing on
 * the wire could open a gate. A reference, so the household's screens read the pass again through their own
 * scope; no visitor's name, phone or ID ever goes on a channel.
 */
final class PassLive extends LiveEvent
{
    public const VERBS = ['issued', 'updated', 'shared', 'cancelled', 'used', 'admitted'];

    /** @return list<string> every type this class emits (App\Live\LiveTypes) */
    public static function types(): array
    {
        return array_map(static fn (string $verb): string => 'pass.'.$verb, self::VERBS);
    }

    /** The steps every gate handset must hear, so the pass is refused there. */
    private const TO_THE_GATE = ['cancelled', 'used'];

    /**
     * @param  list<string>  $channels
     */
    private function __construct(
        private readonly string $verb,
        private readonly string $passId,
        private readonly string $tenantId,
        private readonly array $channels,
    ) {}

    /**
     * Record a step of a pass, inside its estate. A pass is written outside any open estate only by a seeder, a
     * demonstration command or a test fixture that points the estate connection at a database by hand; there is no
     * open estate stream to record to then, and nobody watching, so nothing is recorded. Every request that changes a
     * pass — the resident's, the guard's — runs with its estate open (ResolveDevice).
     */
    public static function tell(VisitorPass $pass, string $verb): void
    {
        $open = tenant()?->getTenantKey();

        if ($open === null) {
            return;
        }

        LiveBus::record(self::for($pass, $verb, (string) $open));
    }

    public static function for(VisitorPass $pass, string $verb, string $tenantId): self
    {
        if (! in_array($verb, self::VERBS, true)) {
            throw new InvalidArgumentException("'{$verb}' is not a step of a visitor pass.");
        }

        $channels = [];

        if ($pass->household_id !== null) {
            $channels[] = Channels::household($tenantId, (int) $pass->household_id);
        }

        if (in_array($verb, self::TO_THE_GATE, true)) {
            $channels[] = Channels::estatePasses($tenantId);
        }

        return new self($verb, (string) $pass->pass_id, $tenantId, $channels);
    }

    public function type(): string
    {
        return 'pass.'.$this->verb;
    }

    public function entity(): string
    {
        return 'pass';
    }

    public function entityId(): string
    {
        return $this->passId;
    }

    public function stream(): Stream
    {
        return Stream::estate($this->tenantId);
    }

    public function audiences(): array
    {
        return $this->channels === [] ? [] : [new Audience($this->channels)];
    }
}
