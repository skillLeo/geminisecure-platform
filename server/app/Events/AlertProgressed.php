<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An officer was sent to an alert, is on the way, or is there (M10 §1.3).
 *
 * WHY IT IS BROADCAST AT ALL. The panic response screen stops polling while its socket is live
 * (`useLiveDispatch`), and until the Guard App could say "en route" every change to an alert was
 * made on that same screen. Now a guard's handset changes the alert too — and a dispatcher holding
 * the incident open would not see "Marcus is on scene" until they reloaded, which on this screen
 * looks exactly like an officer who never arrived.
 *
 * THINNER THAN `AlertRaised`, like `ShiftClocked`: which alert, which step, whether simulated. Not
 * the officer's name, not the lot, not a time — the screen asks the server for all of that, and
 * the estate's pop-up (`useEstatePanic`) does not listen for this event at all. It only makes the
 * dispatch screens re-read.
 *
 * Now, not queued, for `AlertRaised`'s reason: a worker being down must not decide whether a
 * dispatcher sees that help arrived.
 */
class AlertProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public string $tenantId,
        public int $alertId,
        public string $stage,
        public bool $isSimulated,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("estate.{$this->tenantId}.alerts")];
    }

    public function broadcastAs(): string
    {
        return 'alert.progressed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->alertId,
            'stage' => $this->stage,
            'estate' => $this->tenantId,
            'is_simulated' => $this->isSimulated,
        ];
    }
}
