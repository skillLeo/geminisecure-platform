<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * WHAT AN ESTATE IS TOLD ABOUT ITS GUARDS' CONSENT: ASSURANCE, NOT RECORDS (Hassam, 12 October 2026).
 *
 * "A guard's consent is employment data between the guard and Gemini. The estate is a different company. It can see
 * WHERE a guard is while on duty at its own site, because that is operational, but whether that guard agreed to a
 * tracking policy is not the estate's business. What the estate legitimately needs is assurance, not records." So the
 * estate is given one line, with no names and no dates; the full record stays on the Gemini console
 * (`GuardTrackRecord`, the guard's own page).
 *
 * THE LINE IS ONLY EVER TRUE. "All guards on duty at this estate have agreed to position recording" is said when every
 * officer clocked in here now has agreed to the wording in force; when nobody is on duty, or somebody on duty has not,
 * the line says so instead, still with no names, no dates and no count. One query.
 */
final class ConsentAssurance
{
    public const ALL_AGREED = 'All guards on duty at this estate have agreed to position recording.';

    public const NOBODY_ON_DUTY = 'No guard is on duty at this estate right now.';

    public const NOT_ALL_AGREED = 'Not every guard on duty at this estate has agreed to position recording, and a guard who has not is not recorded.';

    public static function lineFor(string $tenantId): string
    {
        $current = GuardTracking::policyVersion();

        $counts = DB::connection('mysql')->table('shifts as s')
            ->where('s.tenant_id', $tenantId)
            ->whereNotNull('s.actual_start')
            ->whereNull('s.actual_end')
            ->leftJoin('guard_tracking_consents as c', function (JoinClause $join) use ($current): void {
                // The officer's latest answer to the wording in force: an answer to earlier words does not count.
                $join->on('c.guard_id', '=', 's.guard_id')
                    ->whereRaw('c.id = (select max(latest.id) from guard_tracking_consents as latest where latest.guard_id = s.guard_id and latest.policy_version = ?)', [$current]);
            })
            ->selectRaw('count(distinct s.guard_id) as on_duty')
            ->selectRaw('count(distinct case when c.decision = ? then s.guard_id end) as agreed', [GuardTracking::GRANTED])
            ->first();

        $onDuty = (int) ($counts->on_duty ?? 0);
        $agreed = (int) ($counts->agreed ?? 0);

        return match (true) {
            $onDuty === 0 => self::NOBODY_ON_DUTY,
            $agreed === $onDuty => self::ALL_AGREED,
            default => self::NOT_ALL_AGREED,
        };
    }
}
