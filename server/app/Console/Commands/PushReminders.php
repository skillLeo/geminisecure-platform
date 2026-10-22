<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Ballot;
use App\Models\Estate\Meeting;
use App\Models\Tenant;
use App\Services\Push\PushAnnouncer;
use App\Support\SiteTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 *   php artisan push:reminders [--estate=phoenixpark]
 *
 * The two things a closed app should hear about that no save announces, because
 * nothing is saved when they come due (M4 §3.2):
 *
 *   a meeting that starts within the next day      "Meeting tomorrow"
 *   an election whose poll closes within the day   "Voting closes soon"
 *
 * Scheduled hourly in `routes/console.php`, and SAID ONCE: `PushAnnouncer::
 * firstTime` records that an estate was reminded of that meeting or that election,
 * so the second run of the hour — or the twenty-third — sends nothing. Everything
 * else reaches a closed app from the record's own save (`PushServiceProvider`).
 */
class PushReminders extends Command
{
    protected $signature = 'push:reminders {--estate= : one estate only}';

    protected $description = 'Push "meeting tomorrow" and "voting closes soon" to each live estate\'s residents, once';

    public function handle(PushAnnouncer $say): int
    {
        $estates = Tenant::estates(function ($query): void {
            $query->where('status', 'active')->orderBy('id');

            if ($this->option('estate') !== null) {
                $query->whereKey((string) $this->option('estate'));
            }
        });

        foreach ($estates as $estate) {
            /*
             * NEVER IN THE ESTATE'S NIGHT (M11 §4.2). The run is hourly for every estate, and a reminder went
             * out the first hour its meeting or poll came within a day — which, for a meeting called at 23:00
             * or a poll closing at 06:00, was a phone buzzing at 3 AM. Now each estate is reminded only while
             * its OWN clock says people are up; the reminder waits for the first waking hour, still well
             * inside the day it is about. Said once all the same (`firstTime`).
             */
            if (! self::awake(SiteTime::now((string) $estate->getTenantKey()))) {
                $this->line("{$estate->name}: night at the estate, reminders wait for the morning.");

                continue;
            }

            try {
                $sent = $estate->run(function () use ($estate, $say): int {
                    $tenant = (string) $estate->getTenantKey();
                    $now = Carbon::now();
                    $sent = 0;

                    $meetings = Meeting::query()->where('status', Meeting::SCHEDULED)->whereNotNull('published_at')
                        ->whereBetween('starts_at', [$now, $now->copy()->addDay()])->get();

                    foreach ($meetings as $meeting) {
                        if ($say->firstTime($tenant, 'meeting_reminder', 'meeting-'.$meeting->id)) {
                            $say->meeting($meeting, 'reminder', $tenant);
                            $sent++;
                        }
                    }

                    $ballots = Ballot::query()->where('stage', Ballot::VOTING_OPEN)->whereNotNull('closes_at')
                        ->whereBetween('closes_at', [$now, $now->copy()->addDay()])->get();

                    foreach ($ballots as $ballot) {
                        if ($say->firstTime($tenant, 'election_open', 'closing-'.$ballot->id)) {
                            $say->election($ballot, 'closing', $tenant);
                            $sent++;
                        }
                    }

                    return $sent;
                });

                $this->info("{$estate->name}: {$sent} reminder(s).");
            } catch (Throwable $failure) {
                $this->error("{$estate->name}: the reminders failed — {$failure->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /** The first hour a reminder may go out at the estate, and the hour after the last. */
    public const WAKES = 8;

    public const SLEEPS = 21;

    /** Whether it is a waking hour on the estate's own clock: 08:00 to 20:59. */
    public static function awake(Carbon $local): bool
    {
        $hour = (int) $local->format('G');

        return $hour >= self::WAKES && $hour < self::SLEEPS;
    }
}
