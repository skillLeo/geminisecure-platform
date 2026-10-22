<?php

declare(strict_types=1);

namespace App\Services\GuardApp;

use App\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Random alertness checks: issued, answered, or missed (13 D2). Board guard-app-04 screen 15.
 *
 * "These random check-ins protect you too — they're your proof that this post
 * was covered tonight." A check is issued to a guard on duty at an interval that
 * varies per guard and per hour — between 30 and 89 minutes — so it cannot be
 * anticipated; it must be answered within two minutes; unanswered, it is
 * recorded missed. `alertness:run` does the issuing and the missing, every five
 * minutes from the scheduler.
 *
 * NO BIOMETRIC LEAVES THE HANDSET. A response is a tap and nothing more; there
 * is no image, template, score or sensor reading anywhere in the table.
 */
class AlertnessChecks
{
    public const RESPOND_WITHIN_SECONDS = 120;

    /** @return array{issued: int, missed: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $missed = DB::connection('mysql')->table('alertness_checks')
            ->where('outcome', 'pending')
            ->where('respond_by', '<', $now)
            ->update(['outcome' => 'missed']);

        $issued = 0;

        $onDuty = Shift::query()
            ->whereNotNull('guard_id')
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->get(['id', 'guard_id', 'actual_start']);

        foreach ($onDuty as $shift) {
            $last = DB::connection('mysql')->table('alertness_checks')
                ->where('guard_id', $shift->guard_id)
                ->max('issued_at');

            $since = $last === null ? $shift->actual_start : Carbon::parse((string) $last);
            $interval = 30 + (crc32($shift->guard_id.'@'.$now->format('YmdH')) % 60);

            $pending = DB::connection('mysql')->table('alertness_checks')
                ->where('guard_id', $shift->guard_id)->where('outcome', 'pending')->exists();

            if ($pending || $since->copy()->addMinutes($interval)->greaterThan($now)) {
                continue;
            }

            $checkId = DB::connection('mysql')->table('alertness_checks')->insertGetId([
                'guard_id' => $shift->guard_id,
                'shift_id' => $shift->id,
                'outcome' => 'pending',
                'issued_at' => $now,
                'respond_by' => $now->copy()->addSeconds(self::RESPOND_WITHIN_SECONDS),
                'server_time' => $now,
            ]);

            // A guard has two minutes, and the app may be closed (M4 §3.2): pushed, high priority, cannot be muted.
            app(\App\Services\Push\PushAnnouncer::class)->alertnessCheck((int) $shift->guard_id, (int) $checkId, self::RESPOND_WITHIN_SECONDS);

            $issued++;
        }

        return ['issued' => $issued, 'missed' => $missed];
    }
}
