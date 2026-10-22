<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Models\DuressAlert;
use App\Models\DuressAlertRing;
use App\Models\DuressAlertRingRound;
use Illuminate\Support\Carbon;

/**
 * HOW MANY OFFICER PHONES THE CALL-STYLE RING HAS BEEN SENT TO (M18 §1.6).
 *
 * The raise's own count (`alerted_handsets`) is what the panic's first push reached. When that is 0 the ring rings
 * every active officer of the estate at once, and until M18 nothing told the resident: their screen said "No
 * officer's phone was reached" while an officer's phone was ringing. This is the number that corrects it.
 *
 * The LARGEST round, never a sum: a re-ring goes to the same phones again, and three rounds to one phone are one
 * phone. Only rounds recorded as sent count — a round skipped, not yet due, or that found no phone adds nothing.
 * Null while the ring has not begun for this alert (a demonstration estate's raise with nobody on shift, a drill,
 * the ring switched off, or the moment before `GuardRing::begin` has run): "not known", never 0.
 */
final class RingReach
{
    public static function rang(DuressAlert $alert): ?int
    {
        if (! DuressAlertRing::query()->where('duress_alert_id', $alert->id)->exists()) {
            return null;
        }

        return (int) DuressAlertRingRound::query()
            ->where('duress_alert_id', $alert->id)
            ->where('outcome', DuressAlertRingRound::SENT)
            ->max('phones');
    }

    /**
     * WHEN THE RING FIRST WENT OUT (M18A §3.3: every tick carries a time) — the first round recorded as sent, or null
     * when none has been. The resident's "Officers alerted" line is ticked with it when the ring is what alerted them.
     */
    public static function rangAt(DuressAlert $alert): ?string
    {
        $at = DuressAlertRingRound::query()
            ->where('duress_alert_id', $alert->id)
            ->where('outcome', DuressAlertRingRound::SENT)
            ->whereNotNull('finished_at')
            ->min('finished_at');

        return $at === null ? null : Carbon::parse((string) $at)->toIso8601String();
    }
}
