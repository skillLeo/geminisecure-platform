<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DuressAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * WHAT A PANIC REACHED, ONCE THE PLATFORM KNOWS — the second of an alert's two broadcasts (M10 §1).
 *
 * §1.2 says every destination is told "in the same instant, with no step gated behind
 * another". So `AlertRaised` goes FIRST, before the fan-out has looked up a single
 * guard: the dispatcher's queue and the estate's pop-up must not wait for push tokens to
 * be read, mail to be queued and texts to be counted. The price is that `AlertRaised`
 * cannot say how many officers' phones were reached — at the moment it is sent, none
 * has been tried.
 *
 * THIS IS THAT NUMBER, sent the moment the fan-out has written it down. Same channel, so
 * exactly the same audience — `routes/channels.php` decides it once for both — and the
 * estate's pop-up turns "Alerting officers…" into "Officers' phones alerted: 3", or into
 * who to call when it is none.
 *
 * NOT A DESTINATION. It tells the people already told something they did not know yet;
 * it reaches nobody new. So it is never a row in `duress_alert_deliveries` and never
 * moves a hand-off time.
 *
 * IT MAY NEVER ARRIVE, and every listener is written for that: a socket that dropped for
 * a second, a Reverb restart, a fan-out that failed before it counted anything. The
 * pop-up says the count has not come through after a few seconds rather than saying
 * "Alerting officers…" for ever, and the dispatcher's queue re-reads the server once.
 * Nothing here is sent for a simulated alert, whose fan-out never runs.
 *
 * THE NUMBERS ARE TAKEN WHEN THE EVENT IS MADE, not when it is serialised, so the payload
 * is a fact about the moment it was sent — never a later value that happened to be on
 * the model by the time somebody read it.
 */
class AlertReachKnown implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /** `PanicFanOut`'s own count of officer handsets pushed. Never a person count, never a text. */
    public readonly ?int $handsets;

    /** `on_duty`, `rostered`, `escalation` or `none` — the strongest thing the alert reached. */
    public readonly ?string $via;

    public function __construct(public DuressAlert $alert)
    {
        $handsets = $alert->getAttribute('alerted_handsets');
        $via = $alert->getAttribute('alerted_via');

        $this->handsets = $handsets === null ? null : (int) $handsets;
        $this->via = $via === null ? null : (string) $via;
    }

    /**
     * `AlertRaised`'s channel, and only it — the same per-estate private boundary.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("estate.{$this->alert->tenant_id}.alerts"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'alert.reach';
    }

    /**
     * Which alert, and the count. Nothing a listener did not already have, except the count.
     *
     * @return array{id: int, estate: string, alerted_handsets: int|null, alerted_via: string|null, is_drill: bool}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => (int) $this->alert->id,
            'estate' => (string) $this->alert->tenant_id,
            'alerted_handsets' => $this->handsets,
            'alerted_via' => $this->via,

            // Carried again so a listener that missed `AlertRaised` still never shows a drill as real.
            'is_drill' => (bool) $this->alert->is_drill,
        ];
    }
}
