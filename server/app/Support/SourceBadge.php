<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Says, on a web screen, that the data below it came off a mobile device — and
 * whether a real one produced it.
 *
 * WHY THE CLIENT ASKED FOR THIS. Roughly forty web screens display data the
 * Guard App and the Resident App generate, and neither app exists yet. The
 * simulator drives the same /api/v1 endpoints the real apps will call, which is
 * the right way to build against them — and it means a reviewer looking at the
 * live map cannot tell, from the screen alone, whether the alert in front of
 * them came from a handset at a gate or from `simulate:alerts`. The badge says
 * so, at a glance and without asking.
 *
 * IT IS COMPUTED FROM THE ROWS ON THE SCREEN, NEVER FROM THE ENVIRONMENT. The
 * tempting shortcut is `app()->isLocal()`, and it would be wrong twice: a
 * demonstration on a staging host would claim its simulated data was real, and
 * a locally-run integration test against a real handset would claim the
 * opposite. Only the rows know, and every table a handset writes carries
 * `is_simulated` for exactly this — `duress_alerts`, `alertness_checks`,
 * `checkpoint_scans`, `gate_events`, `shifts`, `guard_requests`,
 * `security_incidents`, and in each estate `maintenance_tickets`,
 * `amenity_bookings`, `unit_claims` and `ballot_receipts` (13 C3).
 *
 * MIXED COUNTS AS SIMULATED. A queue holding nine real alerts and one simulated
 * one is not a real queue: the figure a reviewer is about to quote includes a
 * number nobody dialled. Any is enough.
 *
 * WHAT `is_simulated` DOES NOT MEAN, AND WHY THE DEMONSTRATION ESTATE DOES NOT
 * CARRY IT (M5 §3).
 *
 * It answers exactly one question: was this row fabricated by `simulate:*`, or
 * did a device produce it? An app-store reviewer driving the demonstration
 * estate is a real person, holding a real handset, calling the real /api/v1
 * endpoint through the real intake — so a panic they raise is the opposite of
 * simulated, and writing `is_simulated = true` on it would be a lie told by the
 * one column whose whole job is to stop the console lying about its own data.
 * (`demo:seed` agrees: every row it writes sets the flag FALSE explicitly.)
 *
 * The honest mark for those rows is the estate they are in — `tenants.demo`,
 * which every one of them already carries through `tenant_id`, which no handset
 * can set and which cannot drift out of step with the row because it is not on
 * the row. `App\Services\Platform\OperatorEstates` is what reads it: the
 * demonstration estate is out of the operational and financial defaults
 * entirely, and labelled `OperatorEstates::LABEL` wherever one is deliberately
 * shown. A second boolean meaning "not real, but a different kind of not real"
 * would have to be set correctly by every future write, and the first one it
 * was forgotten on would be the one a dispatcher believed.
 */
final class SourceBadge
{
    /** Data the Guard App produces: alerts, scans, gate decisions, shifts. */
    public const GUARD = 'guard';

    /** Data the Resident App produces: panic, passes, bookings, requests. */
    public const RESIDENT = 'resident';

    /**
     * The badge for a Guard App screen.
     *
     * @param  bool  $simulated  whether ANY row on the screen was simulated
     * @return array{source: string, simulated: bool}
     */
    public static function guard(bool $simulated): array
    {
        return ['source' => self::GUARD, 'simulated' => $simulated];
    }

    /**
     * The badge for a Resident App screen.
     *
     * @return array{source: string, simulated: bool}
     */
    public static function resident(bool $simulated): array
    {
        return ['source' => self::RESIDENT, 'simulated' => $simulated];
    }

    /**
     * Whether anything in this collection was simulated.
     *
     * Takes what the screen has ALREADY FETCHED rather than issuing a second
     * query. A badge that ran its own `exists()` against the same table would
     * be answering about the table rather than about the rows in front of the
     * reader — and on a filtered screen those are different questions.
     *
     * @param  iterable<int, object|array<string, mixed>>  $rows
     */
    public static function anySimulated(iterable $rows): bool
    {
        foreach ($rows as $row) {
            $flag = is_array($row) ? ($row['is_simulated'] ?? false) : ($row->is_simulated ?? false);

            if ((bool) $flag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any of THESE rows was simulated — for a screen whose payload is
     * already mapped for display and no longer carries the flag.
     *
     * Asked of the ids on the screen, never of the table: a filtered queue with
     * one simulated row off-page is not a simulated screen.
     *
     * @param  list<int|string>  $ids
     */
    public static function anySimulatedIn(string $connection, string $table, array $ids, string $key = 'id'): bool
    {
        if ($ids === []) {
            return false;
        }

        return DB::connection($connection)->table($table)->whereIn($key, $ids)->where('is_simulated', true)->exists();
    }
}
