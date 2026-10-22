<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Models\DuressAlert;

/**
 * WHETHER A PANIC IS STILL RINGING — the one rule every part of the ring asks (the guard ring §5.1).
 *
 * "Re-ring every 2 minutes until the alert is acknowledged, by a guard or by the control
 * room." Nothing computed that before: the console's siren has its own rule (an
 * acknowledgement only, `PanicWatch`), the status pill has another, and a guard's "on my
 * way" never sets `acknowledged_at` at all. So this is the fourth rule, and the ONLY one
 * the ring uses.
 *
 * IT KEYS ON COLUMNS, NEVER ON THE STATUS VALUE. A console acknowledge on an alert that is
 * already `responding` keeps `responding` and fires no status event (`AlertIntake`'s "never
 * a step backwards"), so `status === 'acknowledged'` would miss exactly the alert an
 * officer is already running to.
 *
 * In order, because the first that holds is the truest thing to tell a guard:
 *
 *   cancelled     the raiser's own ten-second cancel
 *   closed        resolved, or a false alarm, by the control room
 *   responding    an officer is named: dispatched, "on my way" or "on scene" from a
 *                 handset or by radio, or a guard's "I'm responding" on a ring
 *   acknowledged  the control room took it, and nobody has been sent yet
 */
final class RingRule
{
    public const CANCELLED = 'cancelled';

    public const CLOSED = 'closed';

    public const RESPONDING = 'responding';

    public const ACKNOWLEDGED = 'acknowledged';

    /** Why the alert no longer needs its phones ringing, or null while it still does. */
    public static function answered(DuressAlert $alert): ?string
    {
        return match (true) {
            $alert->getAttribute('cancelled_at') !== null => self::CANCELLED,
            in_array($alert->status, ['resolved', 'false_alarm'], true) => self::CLOSED,
            $alert->responding_guard_id !== null => self::RESPONDING,
            $alert->acknowledged_at !== null => self::ACKNOWLEDGED,
            default => null,
        };
    }

    /**
     * Whether a ring of this round must stop — or, not yet sent, must not go.
     *
     * RULING R9 is the one exception. With `ring.ack_cancels_escalation` on (the spec's
     * reading, and the default) every answer stops every round. Off, a bare acknowledge
     * stops the phones ringing but leaves the escalation (round 1) to run, and to keep
     * ringing, until an officer is actually sent or says they are going: "an
     * acknowledgement is a person in the control room pressing a button; nobody has been
     * sent". The re-rings after it still end on the acknowledge.
     */
    public static function stops(DuressAlert $alert, int $round): bool
    {
        $why = self::answered($alert);

        if ($why === null) {
            return false;
        }

        return ! ($why === self::ACKNOWLEDGED && $round === 1 && ! (bool) config('ring.ack_cancels_escalation', true));
    }

    /** Whether this answer tells a guard to stand down — only an alert that is over (§5.4, G3). */
    public static function standsDown(string $why): bool
    {
        return in_array($why, [self::CLOSED, self::CANCELLED], true);
    }
}
