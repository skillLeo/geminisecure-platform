<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\LiveEvent;
use App\Live\Stream;
use InvalidArgumentException;

/**
 * A WALK-UP VISITOR, ASKED ABOUT AT THE GATE (M11 Part C, DESIGN.md §2.9 I14–I17). Estate stream.
 *
 *   requested   the household (H): a guard is asking, now, on every member's phone
 *   answered    the guard waiting at the gate (`approval.{t}.{id}`) and the household: the waiting screen moves
 *               the moment a member approves or declines, rather than at its next poll
 *   expired     the same two: nobody answered in time
 *   admitted    the household: the visitor they approved is in
 *
 * A reference, never the visitor's name, ID or plate: each screen reads the request again through its own scope.
 */
final class ApprovalLive extends LiveEvent
{
    public const VERBS = ['requested', 'answered', 'expired', 'admitted'];

    /** @return list<string> every type this class emits (App\Live\LiveTypes) */
    public static function types(): array
    {
        return array_map(static fn (string $verb): string => 'approval.'.$verb, self::VERBS);
    }

    /**
     * @param  list<string>  $channels
     */
    private function __construct(
        private readonly string $verb,
        private readonly int $approvalId,
        private readonly string $tenantId,
        private readonly array $channels,
    ) {}

    /** Record a step of a walk-up inside its estate; nothing outside one (a fixture), as PassLive::tell says. */
    public static function tell(int $approvalId, ?int $householdId, string $verb): void
    {
        $open = tenant()?->getTenantKey();

        if ($open === null) {
            return;
        }

        LiveBus::record(self::for($approvalId, $householdId, $verb, (string) $open));
    }

    public static function for(int $approvalId, ?int $householdId, string $verb, string $tenantId): self
    {
        if (! in_array($verb, self::VERBS, true)) {
            throw new InvalidArgumentException("'{$verb}' is not a step of a walk-up approval.");
        }

        $channels = [];

        if ($householdId !== null) {
            $channels[] = Channels::household($tenantId, $householdId);
        }

        if (in_array($verb, ['answered', 'expired'], true)) {
            $channels[] = Channels::approval($tenantId, $approvalId);
        }

        return new self($verb, $approvalId, $tenantId, $channels);
    }

    public function type(): string
    {
        return 'approval.'.$this->verb;
    }

    public function entity(): string
    {
        return 'approval';
    }

    public function entityId(): string
    {
        return (string) $this->approvalId;
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
