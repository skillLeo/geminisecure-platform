<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shifts — the rules that run without anybody pressing anything (M6 §2.1)
|--------------------------------------------------------------------------
|
| `App\Services\Dispatch\ShiftAutoClose` is the one place a shift is closed
| by the platform rather than by a person, and `php artisan shifts:auto-close`
| (scheduled hourly in `routes/console.php`) is the one thing that calls it.
|
*/

return [

    /*
     * HOW LONG PAST ITS ROSTERED END A STARTED SHIFT IS CLOSED BY THE PLATFORM.
     *
     * A guard who forgets to clock out stays "on duty" for ever, and the panic
     * fan-out (M6 §2.1 parts 2 and 3) reads exactly that: it pushes to whoever is
     * clocked on. Without this rule a forgotten clock-out follows somebody home
     * for days — they are woken at 3 AM for an estate they left — and, worse, the
     * fallback tiers never fire, because the system believes a guard IS on duty.
     *
     * FOUR HOURS IS THE CLIENT'S NUMBER (M6 §2.1 part 4), recorded as D-M122 and
     * asked back for confirmation as Q-M41. Four is long enough that an overrun,
     * a late relief or a handover that ran on is not "corrected" out from under a
     * guard who is genuinely still standing there, and short enough that a
     * forgotten clock-out does not survive into the next day's roster.
     *
     * PER SITE. An estate may carry its own number in `tenants.data` under
     * `shift_auto_close_hours` (stancl keeps unpromoted attributes there, so this
     * needs no migration); `ShiftAutoClose::hoursFor()` reads it, falls back to
     * this, and clamps to 1..72 so a typo cannot switch the rule off or leave a
     * shift open for a week.
     *
     * THIS IS A DURATION, SO IT IS ZONE-FREE. "Four hours past a rostered end" is
     * arithmetic on two instants and never touches a calendar — unlike "the roster
     * for that day", which is a date on the estate's own clock and goes through
     * `App\Support\SiteTime`.
     */
    'auto_close_hours' => (int) env('SHIFT_AUTO_CLOSE_HOURS', 4),

    /*
     * How long an auto-closed shift stays on the Head of Security's attention list.
     * The same fourteen days a supervisor override gets, for the same reason: it is
     * an exception to review, not an alarm to answer.
     */
    'auto_close_report_days' => (int) env('SHIFT_AUTO_CLOSE_REPORT_DAYS', 14),

];
