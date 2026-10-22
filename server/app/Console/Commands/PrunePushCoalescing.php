<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan push:prune-coalescing [--dry-run]
 *
 * `push_coalescing` is how one ticket's six changes become one buzz, and how the
 * hourly `push:reminders` says "meeting tomorrow" once. It holds one row per
 * (recipient, type, record), updated in place — so it is never smaller than the
 * number of things an estate has ever told its households about. Left alone it grows
 * for the life of the platform: a five-hundred-home estate with a hundred notices a
 * year is fifty thousand rows a year, from notices alone.
 *
 * WHAT IT WILL NOT DELETE. Two kinds of row live in that table and they need
 * different lifetimes:
 *
 *   PushNotifier::firstInWindow   one per recipient per record. It matters for
 *                                 `push.coalesce_seconds` (five minutes) and never
 *                                 again: deleting it afterwards only means the next
 *                                 change is news, which is what it would have been.
 *   PushAnnouncer::firstTime      one per ESTATE per meeting or election. It is the
 *                                 only thing stopping the hourly reminder command
 *                                 sending "meeting tomorrow" twenty-four times.
 *                                 Delete it while the meeting is still ahead and
 *                                 every household is told again.
 *
 * So the retention is `push.coalesce_retention_seconds` (a week by default — the
 * reminder command only ever looks a DAY ahead, so a week is four times the longest
 * life any row here has), floored at `push.coalesce_seconds` so a misconfigured
 * retention can never reach inside a live window. Nothing else is touched, and a row
 * carries no words, no token and nothing about a person.
 */
class PrunePushCoalescing extends Command
{
    protected $signature = 'push:prune-coalescing {--dry-run : count what would go, and delete nothing}';

    protected $description = 'Delete push coalescing rows older than the retention window, so the table stops growing';

    /** Deleted in batches, so a year of neglect cannot hold a lock across the whole table. */
    private const BATCH = 5000;

    public function handle(): int
    {
        $retention = $this->retentionSeconds();
        $before = Carbon::now()->subSeconds($retention);
        $table = static fn () => DB::connection('mysql')->table('push_coalescing');

        $this->info('Retention: '.$retention.' seconds — deleting rows last pushed before '.$before->toIso8601String().' (UTC).');

        $due = (int) $table()->where('last_pushed_at', '<', $before)->count();

        if ($this->option('dry-run')) {
            $this->line('  dry run — '.$due.' row(s) would be deleted, '.$table()->count().' would remain');

            return self::SUCCESS;
        }

        $deleted = 0;

        while (($gone = $table()->where('last_pushed_at', '<', $before)->limit(self::BATCH)->delete()) > 0) {
            $deleted += $gone;

            if ($gone < self::BATCH) {
                break;
            }
        }

        $this->info('Deleted '.$deleted.' row(s); '.$table()->count().' remain.');

        return self::SUCCESS;
    }

    /**
     * Never shorter than the coalescing window itself. A retention set below it —
     * by an env var, by a mistake — would delete the row a send is about to read
     * and turn six changes to one ticket back into six buzzes.
     */
    private function retentionSeconds(): int
    {
        return max(
            (int) config('push.coalesce_retention_seconds', 7 * 24 * 60 * 60),
            (int) config('push.coalesce_seconds', 300),
        );
    }
}
