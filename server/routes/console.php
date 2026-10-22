<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The guard ring's safety net (the guard ring §4.3)
|--------------------------------------------------------------------------
|
| Every fifteen seconds: a ring round, a stop or an answer the panic workers have
| not handled is handled here, inline — so a panic keeps ringing the guards' phones
| with the queue workers dead. See `App\Console\Commands\RingSweep`.
|
| FIRST, AND IN THE BACKGROUND, and so is every entry below it (G27). Tasks due at
| the same moment run one after another unless they run in the background, and a
| sub-minute task's repeats wait for the minute's first pass to finish — so a slow
| `alertness:run` at the top of the minute would hold the net back exactly when the
| workers may be down. `withoutOverlapping` still holds in the background.
|
| A sub-minute task keeps `schedule:run` alive for the whole minute, which is why
| `deploy/deploy.sh`, `rollback.sh` and `switch-domain.sh` run `schedule:interrupt`.
|
*/
Schedule::command('ring:sweep')
    ->everyFifteenSeconds()
    ->withoutOverlapping(1)
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| The automated arrears ladder (13 A4)
|--------------------------------------------------------------------------
|
| Every morning at nine AT EACH ESTATE, in its own zone (M11 §4.2) — office hours,
| so a reminder arrives when somebody at the estate can take the call it prompts.
| It ticks every fifteen minutes and each estate runs once its own clock reaches
| 09:00, once per its own date (App\Support\LocalSchedule); it used to be 09:00 in
| Kingston for everybody. A household the committee has flagged for hardship or
| dispute is skipped, as is one keeping to an agreed plan; see
| `Collections::runAutomated()`.
|
| Nothing here runs unless the scheduler does: `php artisan schedule:work` in
| development, and a scheduled task calling `php artisan schedule:run` every
| minute in production — `docs/DEPLOY.md`.
|
*/
Schedule::command('dunning:run --at=09:00')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Alertness checks (13 D2)
|--------------------------------------------------------------------------
|
| Every five minutes: issue the checks guards on duty are due — at an interval
| that varies per guard and per hour, so none can be anticipated — and record
| as missed the ones not answered within two minutes. The Guard App learns of a
| pending check from `POST /presence/activity` and `GET /sync/pull`.
|
*/
Schedule::command('alertness:run')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Amenity deposits move on (M4 §4, defect 2)
|--------------------------------------------------------------------------
|
| Every morning before the office opens — 06:30 on EACH ESTATE's own clock,
| once per its own date, as the arrears run above (M11 §4.2) — in each estate: a confirmed
| booking whose time has ended is completed; a held deposit whose booking was
| cancelled or declined, or ended three days ago with no claim made, is given
| back (Dr 2200 Resident Deposits Held, Cr 1000 Bank); and a deposit still
| "awaiting" on a booking that is over stops being awaited. It never forfeits —
| that is a person's decision, with a reason. See `Amenities::settle()`.
|
| Safe to run as often as anybody likes: each move happens once.
|
*/
Schedule::command('amenities:settle --at=06:30')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| AutoPay pays on its day (M50 §4.2 #3)
|--------------------------------------------------------------------------
|
| At each estate's own 06:00, every household whose AutoPay day it is pays what
| it owes, up to its limit, with its saved card — through the demonstration
| checkout while DEMO_MODE is on (a real receipt, posted to 1090, no money moved).
| Once per arrangement per day; nothing owed is nothing charged; with card
| payments off it charges nothing and says so. See `App\Services\Estate\Autopay`.
|
*/
Schedule::command('dues:autopay --at=06:00')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| A forgotten clock-out does not follow somebody home (M6 §2.1)
|--------------------------------------------------------------------------
|
| Hourly: close every shift that is more than its site's hours past its
| rostered end and was never clocked out — four hours by default, per site in
| `config/shifts.php` and `tenants.data.shift_auto_close_hours` (D-M122, put to
| the client as Q-M41). The guard is told, and the closure is flagged to the
| Head of Security as an attendance exception rather than silently corrected.
|
| THIS IS A SAFETY RULE, NOT HOUSEKEEPING, WHICH IS WHY IT IS SCHEDULED AND NOT
| LEFT TO ANYBODY. A guard the system still believes is on duty keeps receiving
| that estate's panic alerts at home — and, far worse, stops the panic fallback
| in `App\Services\Push\PanicFanOut` ever descending to the rostered officers or
| the escalation contact, because the system thinks somebody IS on. One forgotten
| tap would otherwise disable a site's whole safety net until a person noticed.
|
| Hourly rather than daily: the rule is "N hours past the rostered end", and a
| nightly run would make it "N hours, or until 3 AM, whichever is later".
|
*/
Schedule::command('shifts:auto-close')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| A phone that stopped recording positions is told (M22 §1.1)
|--------------------------------------------------------------------------
|
| Every minute: an officer on duty who agreed to share, whose phone has taken no
| position for ten minutes, gets a push on that phone — once per gap — saying the
| control room is not receiving it. Found on the emulator: Android could start the
| Guard App in a way that stopped its on-duty positions for the rest of the run,
| while the notification still said "location shared". The dispatcher's alertness
| board shows the same gap, and the officer's record keeps it. See TrackingGaps.
|
*/
Schedule::command('tracking:watch')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
| A guard on duty whose phone has gone quiet is the DISPATCHER's alert (guard live tracking, 12 Oct 2026): "No signal
| from <name> at <site>, last seen HH:MM" in the Gemini alert queue once nothing has arrived for the estate's tolerance,
| cleared when a position arrives or the shift ends. See App\Services\Tracking\GuardSignal.
*/
Schedule::command('tracking:signal')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Push reminders to a closed app (M4 §3.2)
|--------------------------------------------------------------------------
|
| Hourly, in each live estate: "Meeting tomorrow" for a published meeting that
| starts within a day, and "Voting closes soon" for a poll that closes within a
| day. Each is said once per meeting / election however often this runs.
| Everything else a closed app hears about is pushed when its record is saved
| (`App\Providers\PushServiceProvider`). Sending needs the queue worker.
|
*/
Schedule::command('push:reminders')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
| And the bookkeeping behind them: `push_coalescing` holds one row per (recipient,
| type, record), updated in place and never removed, so without this it grows for
| the life of the platform. Nightly, well away from the morning runs, and it never
| deletes inside a live coalescing window (`App\Console\Commands\PrunePushCoalescing`).
*/
Schedule::command('push:prune-coalescing')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| A retried write's answer does not outlive the retry
|--------------------------------------------------------------------------
|
| `api_idempotency_keys` holds one record per write the mobile API has taken —
| every alert, clock-in, report, ticket and uploaded offline queue — and the
| answer each one was given. Without this it keeps all of it for the life of the
| platform, which is neither needed nor defensible: the record exists so that a
| handset which lost the answer can be handed it again, and that happens within
| minutes, not months.
|
| Thirty days (`config/api.php`), central records first and then each estate's
| stored answers. See App\Console\Commands\PruneIdempotencyRecords.
|
*/
Schedule::command('api:prune-idempotency')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Every email sent is listed for sixty days (M18G §7, App\Services\Platform\MailLog), then dropped.
Schedule::call(static fn () => App\Services\Platform\MailLog::prune())
    ->name('mail:prune-log')
    ->dailyAt('03:35')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| The live log keeps a week (M11 Part C)
|--------------------------------------------------------------------------
|
| `live_events` gets a row for every change a screen is shown, in the central database and in
| every estate's, and is kept for `config('live.retention_days')`, seven by default. Nightly,
| after the two prunes above. It deletes only a contiguous prefix of each stream and raises the
| floor first, so a phone coming back after longer is told to resync rather than handed a partial
| list, and it never deletes a row the relay has not finished with: it says so, and fails.
| See App\Console\Commands\LivePrune.
|
| In the background, as every command here is: the ring's 15-second sweep must never wait behind it.
|
*/
/*
 * The front door's handoff tokens (M11 §5.2): each lives 60 seconds; nightly, the ones a day old go. Who was
 * handed where is in `audit_log`, which nothing prunes. See App\Console\Commands\FrontDoorPrune.
 */
Schedule::command('front-door:prune')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * On-duty guard positions (M11 §3.3): kept for `config('tracking.retention_days')`, ninety by default, and this is
 * what makes that true. See App\Console\Commands\GuardPositionsPurge.
 */
Schedule::command('gs:guard-positions-purge')
    ->dailyAt('03:55')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('live:prune')
    ->dailyAt('03:50')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * A deleted account can come back while its estate's windows are open, and not after (M18H §1.2): the night the last
 * one closes, nothing of the person is left on it. See App\Console\Commands\AnonymiseExpiredAccounts.
 */
Schedule::command('accounts:anonymise-expired')
    ->dailyAt('04:05')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * A Resident App sign-in unused for 30 days is ended as "Sign out" ends one, audited (M54 §2): Active sessions lists
 * phones that are in use, not every install ever made. See App\Console\Commands\EndIdleResidentSessions.
 */
Schedule::command('resident-sessions:end-idle')
    ->dailyAt('04:15')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| What changes because time passed (M11 Part C)
|--------------------------------------------------------------------------
|
| A ticket crossing its target, a meeting starting, a ballot lapsing, a shift's rostered edge: no
| write happens, so no event would. Every minute `live:tick` hands each rule in app/Live/Tick the
| window since its stream was last ticked, so a missed minute is caught up. With no rules it opens
| nothing. Never two at once; in the background, so the ring's sweep never waits behind it.
| See App\Console\Commands\LiveTick.
|
*/
Schedule::command('live:tick')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| A permission change nobody announced still reaches an open screen (M11 Part C)
|--------------------------------------------------------------------------
|
| Every five minutes, every live grant of the last 26 hours is asked of the rules again. Anybody now
| refused a channel they hold is told `access.changed` and the relay closes their sockets, so an
| access writer that forgot its event, a hand-run seeder or a row changed by hand cannot leave a
| socket open behind the old permission. See App\Console\Commands\LiveReconcile.
|
*/
Schedule::command('live:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
