<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A guard has clocked on or off (13 C1).
 *
 * The coverage board and the alertness board turn on exactly this — a post is
 * covered once somebody has clocked in across it — and until it was broadcast
 * they learned of it only by reloading. Pushed on the same private per-estate
 * channel as `AlertRaised`, so a dispatcher subscribes to the estates they can
 * see and no others.
 *
 * THINNER THAN AN ALERT. Which shift, which way, whether simulated. Not the
 * guard's name, not the post, not a time from the handset: the screen asks the
 * server for all of that, and a channel open on every dispatch screen is not a
 * roll call.
 *
 * Now, not queued, for `AlertRaised`'s reason: a worker being down should not
 * decide whether a supervisor sees a post become covered.
 */
class ShiftClocked implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public const IN = 'in';

    public const OUT = 'out';

    public function __construct(
        public string $tenantId,
        public int $shiftId,
        public string $direction,
        public bool $isSimulated,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("estate.{$this->tenantId}.alerts")];
    }

    public function broadcastAs(): string
    {
        return 'shift.clocked';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'shift_id' => $this->shiftId,
            'direction' => $this->direction,
            'estate' => $this->tenantId,
            'is_simulated' => $this->isSimulated,
        ];
    }
}
