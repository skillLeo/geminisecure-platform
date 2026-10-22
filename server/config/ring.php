<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A panic rings every guard's phone like a call, until somebody answers
|--------------------------------------------------------------------------
|
| `App\Services\Ring\GuardRing`. Round 0 is the raise: today's visible push has
| already gone, untouched, and round 0 adds the call-style ring to the phones that
| can take one. Round 1 is the escalation, 60 seconds later (or at once when the
| raise reached nobody): every active officer of the estate, off duty too, and the
| estate's own Head of Security by text and mail. Rounds 2 to 5 ring everybody
| rung so far again, every two minutes, until the alert is answered.
|
| Every number here is a ruling, not a mechanism; each says which.
|
*/

return [

    /*
     * THE KILL SWITCH. False restores exactly the platform as it was before the
     * ring: today's visible push at the raise and nothing after it.
     *
     * OFF IN THE TEST SUITE UNLESS A TEST TURNS IT ON, for `push.enabled`'s reason
     * (config/push.php): a test that did not ask for a ring must not have one
     * written into its transaction. Every ring test turns it on for itself.
     */
    'enabled' => (bool) env('RING_ENABLED', env('APP_ENV') !== 'testing'),

    // Seconds from the raise to round 1, the escalation (the spec's "within 60 s").
    'escalate_after' => (int) env('RING_ESCALATE_AFTER', 60),

    // Seconds between re-rings after the escalation (the spec's "every 2 minutes").
    'interval' => (int) env('RING_INTERVAL', 120),

    /*
     * THE LAST ROUND. "Up to 5 rounds" read as five rounds after the first ring:
     * R0 at 0 s, R1 at 60 s, R2 to R5 at 180, 300, 420 and 540 s — six rings at
     * most (ruling R1). Four would be five rings in all.
     */
    'max_round' => (int) env('RING_MAX_ROUND', 5),

    /*
     * A DRILL RINGS R0 AND R1 ONLY (ruling R2). `panic:walk` leaves its drill open on
     * purpose, and without this cap real off-duty officers would be re-rung for nine
     * minutes about a test.
     */
    'drill_max_round' => 1,

    /*
     * THE DEMONSTRATION ESTATE RINGS R0 ONLY (G26). A reviewer's panic rings the
     * on-shift demo phones once, as today's push does, and never escalates, never
     * re-rings, never mails or texts a Head of Security.
     */
    'demonstration_max_round' => 0,

    /*
     * HOW LONG EACH MESSAGE IS WORTH DELIVERING, in seconds. Always under the gap to
     * the next round, so a phone that was offline gets the latest ring rather than a
     * pile of stale ones.
     */
    'first_ring_ttl' => 55,
    'ring_ttl' => 110,
    'stop_ttl' => 600,
    'test_ttl' => 60,

    // Seconds the phone rings before it becomes a silent, persistent notification (spec: 45 s).
    'hold_seconds' => 45,
    'test_hold_seconds' => 15,

    /*
     * HOW LONG A RING TICKET CAN ANSWER, in minutes. The ticket is the phone's proof
     * of who it is when the Guard App's own session has timed out (`RingTicket`).
     */
    'ticket_minutes' => 30,

    /*
     * THE SAFETY NET (`ring:sweep`). A round nobody has claimed this many seconds
     * after it fell due is sent by the sweep, inline — so the rings keep going with
     * the queue workers dead. The grace is there so a healthy job always wins.
     */
    'sweep_grace' => 10,

    // A claimed round that has not finished after this many seconds is taken over (under central's retry_after of 90).
    'stale_claim' => 90,

    // How far back the sweep looks, in minutes. The last round is at nine.
    'sweep_window_minutes' => 30,

    /*
     * A GUARD UNDER THEIR OWN OPEN DURESS IS NEVER RUNG (R14, the server's half): a
     * loud phone can betray a silent duress. Open, uncancelled, and raised within
     * this many minutes — the sync window's figure (SyncController::OPEN_ALERT_MINUTES).
     */
    'own_duress_minutes' => 60,

    /*
     * "I'M RESPONDING" TAPPED THIS LONG AGO IS REFUSED (G10). Measured on the phone by
     * its monotonic clock, so a wrong phone clock cannot make it fresh. A claim that
     * sat in a pocket with no signal for twenty minutes must not tell the resident an
     * officer is on the way.
     */
    'answer_stale_seconds' => 120,

    /*
     * RULING R9. True is the spec's reading: a bare control-room acknowledge ends the
     * ringing AND the later rounds, the escalation included. False stops the phones
     * ringing but still runs the escalation at 60 s unless an officer is by then
     * dispatched or responding. Either way an acknowledge never tells guards to stand
     * down: "an acknowledgement is a person in the control room pressing a button".
     */
    'ack_cancels_escalation' => (bool) env('RING_ACK_CANCELS_ESCALATION', true),

    // Batches of 100 a round sends in its own process before the rest go to queued jobs.
    'inline_batches' => 3,
];
