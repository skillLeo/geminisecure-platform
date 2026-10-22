<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A JOB AT EACH ESTATE'S OWN TIME OF DAY (M11 §4.2): "the arrears reminders at 09:00" means 09:00 where the estate
 * is, in whatever IANA zone it keeps, through its daylight-saving changes — not one server hour for everybody.
 *
 * The scheduler ticks the job every fifteen minutes (so a zone thirty minutes off the hour is still on time), and
 * for each estate the job asks `claim()`: has this estate's own clock reached the hour today, and has the job not
 * run on this estate's date yet? The claim is one insert on a unique key, so two workers or two ticks can never
 * both run it; a run that failed calls `release()`, and the next tick that day tries again. A server that was down
 * at 09:00 runs it when it comes back, that same local day; a day missed entirely is not run twice the next.
 */
final class LocalSchedule
{
    private const TABLE = 'scheduled_local_runs';

    /** Claims older than this are pruned as the job runs: nothing asks about a date that far back. */
    private const KEEP_DAYS = 90;

    /** Whether `$job` is due at this estate now, claiming its local date if it is. `$at` is "HH:MM", the estate's own time. */
    public static function claim(string $job, string $tenantId, string $at, ?Carbon $now = null): bool
    {
        $local = ($now?->copy() ?? Carbon::now())->setTimezone(SiteTime::zone($tenantId));

        if ($local->lessThan(self::moment($local, $at))) {
            return false;
        }

        $claimed = self::db()->table(self::TABLE)->insertOrIgnore([
            'job' => $job,
            'tenant_id' => $tenantId,
            'local_date' => $local->toDateString(),
            'claimed_at' => Carbon::now(),
        ]);

        if ($claimed === 1) {
            self::db()->table(self::TABLE)->where('job', $job)->where('tenant_id', $tenantId)
                ->where('local_date', '<', $local->copy()->subDays(self::KEEP_DAYS)->toDateString())->delete();
        }

        return $claimed === 1;
    }

    /** Whether `$job` would be claimed now — for a dry run, which must not spend the day. */
    public static function due(string $job, string $tenantId, string $at, ?Carbon $now = null): bool
    {
        $local = ($now?->copy() ?? Carbon::now())->setTimezone(SiteTime::zone($tenantId));

        return $local->greaterThanOrEqualTo(self::moment($local, $at))
            && ! self::db()->table(self::TABLE)->where('job', $job)->where('tenant_id', $tenantId)->where('local_date', $local->toDateString())->exists();
    }

    /** The run failed: give the day back, so the next tick tries again. */
    public static function release(string $job, string $tenantId, ?Carbon $now = null): void
    {
        $local = ($now?->copy() ?? Carbon::now())->setTimezone(SiteTime::zone($tenantId));

        self::db()->table(self::TABLE)->where('job', $job)->where('tenant_id', $tenantId)->where('local_date', $local->toDateString())->delete();
    }

    /** "09:00" on the local day `$local` is on — in its zone, so a daylight-saving day is still that wall-clock time. */
    private static function moment(Carbon $local, string $at): Carbon
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $at, $m) !== 1) {
            throw new InvalidArgumentException("A local time is HH:MM, 00:00 to 23:59; '{$at}' is not one.");
        }

        return $local->copy()->setTime((int) $m[1], (int) $m[2]);
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection', 'mysql'));
    }
}
