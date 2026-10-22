<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DuressAlert;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Services\Platform\OperatorEstates;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A panic, duress, medical, fire or intrusion alert has been raised.
 *
 * Broadcast so a dispatcher sees it arrive without refreshing. This is the
 * life-safety path: a queue that only updates on page load means a panic alert
 * can sit unseen for as long as nobody happens to reload.
 *
 * WHAT THIS PAYLOAD DELIBERATELY OMITS
 * ------------------------------------
 * No monetary value of any kind, and nothing about a household's financial
 * standing. Invariant 2 constrains this event exactly as it constrains the
 * guard endpoints: a broadcast is just another way for a figure to escape, and
 * it would escape to every connected client at once.
 *
 * It also omits the raiser's precise location. The dispatcher opens the alert
 * to see that, which keeps a continuously-broadcast channel from carrying a
 * live position for everyone with the console open.
 *
 * WHAT IT DOES CARRY, SINCE M10: whether Gemini's control room receives this
 * estate's alerts at all, and the site's own dispatch line to call when nobody's
 * phone is reached — facts the estate's pop-up needs to tell a committee member
 * the truth rather than a reassurance. Counts and an office number; never a
 * person's.
 *
 * IT IS SENT FIRST (M10 §1.2: "in the same instant, with no step gated behind
 * another"), ahead of the guards' push, the mail and the texts, from
 * `PushServiceProvider`'s `created` hook once the row has committed. So it does not
 * know yet how many officers' phones were reached; `AlertReachKnown`, on the same
 * channel, says so the moment the fan-out has counted.
 */
/*
 * ShouldBroadcastNow, not ShouldBroadcast.
 *
 * ShouldBroadcast pushes the broadcast onto the queue. On this path that adds
 * a queue worker to the list of things that must be alive for a panic alert to
 * reach a dispatcher — and if the worker is down the alert does not arrive
 * late, it does not arrive at all, while the raiser's app has already said it
 * was sent. Verified the hard way: the socket connected, authorization
 * admitted the subscription, the event dispatched, and nothing came, because
 * two broadcast jobs were sitting in an unattended queue.
 *
 * Broadcasting inline costs one synchronous HTTP call to Reverb on the intake
 * request. That is the right trade here and nowhere else: ordinary events
 * should still queue.
 */
class AlertRaised implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * What the fan-out had reached WHEN THIS WAS SENT — taken here, not in
     * `broadcastWith()`, so the payload is a fact about the moment of sending and not
     * whatever the model says by the time somebody reads it. Null on every alert
     * `PushServiceProvider` announces, because it sends this BEFORE the fan-out runs
     * (M10 §1.2); the count follows in `AlertReachKnown`.
     */
    private readonly ?int $handsetsWhenSent;

    private readonly ?string $viaWhenSent;

    public function __construct(public DuressAlert $alert)
    {
        $handsets = $alert->getAttribute('alerted_handsets');
        $via = $alert->getAttribute('alerted_via');

        $this->handsetsWhenSent = $handsets === null ? null : (int) $handsets;
        $this->viaWhenSent = $via === null ? null : (string) $via;
    }

    /**
     * Per-estate channels, so a dispatcher restricted to assigned sites is not
     * simply trusted to ignore what arrives for estates they do not cover.
     *
     * A single global channel would push every estate's alerts to every
     * connected console and rely on the client to filter, which is not a
     * boundary at all.
     *
     * PRIVATE, not public. This was a plain Channel, which meant the per-estate
     * boundary described above did not exist: a public channel is subscribable
     * by anyone who can guess its name, and the names are estate subdomains.
     * Only PrivateChannel sends the subscription through the callback in
     * routes/channels.php, which is where the boundary is actually decided.
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
        return 'alert.raised';
    }

    /**
     * Enough to act on, because somebody now has to act on it alone.
     *
     * THIS USED TO BE DELIBERATELY THIN — id, kind, estate, urgency, time — on
     * the reasoning that the dispatcher opens the alert for detail and this only
     * needed to make the row appear and the count move. That was right while the
     * only subscriber was a dispatcher sitting in front of the queue.
     *
     * M10 §1 adds a second kind of subscriber with a different job. The client's
     * own words: "It must go to that community's main dashboard where the
     * president and all the people can see it: a pop-up, and something blinking
     * on it… they must know there is an emergency at a specific lot." A committee
     * member is not watching a queue — they are somewhere else in the console,
     * and the pop-up has to tell them WHERE before they do anything. A pop-up
     * that said only "panic alert" would send them hunting for the address.
     *
     * So the lot, the person and a link travel with it.
     *
     * THE CHANNEL IS WHAT MAKES THAT SAFE, and it was already private per estate:
     * `broadcastOn()` publishes to `estate.{tenant}.alerts`, and
     * routes/channels.php admits only a Gemini dispatcher or a user who
     * `canAccessEstate()`. Nobody outside the estate has ever been able to
     * subscribe, so adding the lot does not widen the audience — it gives the
     * audience that already existed the one fact it needed.
     *
     * NOT THE MEDICAL DETAIL, and not the household's composition. A lot, a name,
     * a time and a kind are what a responder needs; anything more is a record
     * that belongs behind the alert screen's own permission.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->alert->id,
            'kind' => $this->alert->kind,
            'kind_label' => $this->alert->kindLabel(),
            'estate' => $this->alert->tenant_id,
            // A PANIC, however it was labelled (bug hunt B086): what sounds every dispatch
            // screen's siren. A resident's "Medical" press is as urgent as an unlabelled one.
            // NOT for a drill raised in drill mode (M53 §3A.1): it is on every screen, labelled DRILL, and sounds nothing.
            'is_urgent' => $this->alert->isPanic() && ! $this->alert->wakesNobody(),
            'server_time' => $this->alert->server_time->toIso8601String(),
            'is_simulated' => $this->alert->is_simulated,

            /*
             * A DRILL (M10 §1.3) — `panic:walk` proving who a panic reaches. The
             * pop-up and the queue must say DRILL before anything else, and they can
             * only do that on the push itself if the push carries it.
             */
            'is_drill' => (bool) $this->alert->is_drill,

            // Raised in drill mode: nobody was told, so no screen should wait on a count of who was.
            'drill_mode' => $this->alert->wakesNobody(),

            /*
             * WHAT THE FAN-OUT ACTUALLY REACHED, so the estate's pop-up can say it
             * instead of asserting it (M10 §1).
             *
             * The pop-up said "The control room has this alert and officers have been
             * notified" whatever had happened — on an estate with nobody on shift, and
             * on the demonstration estate, whose alerts Gemini's consoles never see
             * (M5 §3). A committee member reading that at the moment it matters would
             * stand down on the strength of a sentence nobody had checked.
             *
             * `alerted_handsets` is `PanicFanOut`'s own count, passed through exactly as
             * `DuressController::shape()` passes it — including NULL, which means "not
             * known yet". And it is now null every time: this event is sent FIRST, before
             * the fan-out has tried a single phone, because §1.2 will not have the
             * consoles wait on it. The pop-up says "Alerting officers…" and never turns
             * null into a 0 or a 1; `AlertReachKnown` brings the real number moments
             * later. Kept in the payload so a listener that reads it is never wrong.
             */
            'alerted_handsets' => $this->handsetsWhenSent,
            'alerted_via' => $this->viaWhenSent,

            /*
             * Whether Gemini's control room receives this estate's alerts at all — false
             * on the demonstration estate. Null if that could not be read, and the pop-up
             * then makes no claim either way.
             */
            'control_room' => rescue(fn (): bool => ! OperatorEstates::isDemonstration((string) $this->alert->tenant_id), null, false),

            /*
             * WHO TO CALL WHEN NO OFFICER'S PHONE WAS REACHED: the site's own Gemini
             * Dispatch line, from `site_emergency_numbers`, as the office typed it. An
             * office number — the `dispatch` role and nothing else, never the
             * President's mobile — and one every recipient of this channel is already
             * texted with. Null when the site has none; the pop-up then gives 119.
             */
            'call' => rescue(function (): ?array {
                $dispatch = app(SiteEmergencyNumbers::class)->forSite((string) $this->alert->tenant_id)->firstWhere('role', 'dispatch');

                return $dispatch === null ? null : ['label' => (string) $dispatch->label, 'number' => (string) $dispatch->number];
            }, null, false),

            /*
             * THE LOT, WHICH IS THE WHOLE POINT OF THE POP-UP. Null only when the
             * handset sent no unit at all — the screen then says "Lot not
             * supplied" rather than leaving a gap, because a blank where an
             * address belongs reads as a broken screen rather than as a fact.
             */
            'unit' => $this->alert->unit_reference,
            'who' => $this->alert->raised_by_name,
        ];
    }
}
