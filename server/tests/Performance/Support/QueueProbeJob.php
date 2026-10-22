<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * M17 §1.4 — one unit of queued work that says it ran. DEV ONLY (tools/perf/load/queue-*.php).
 *
 * It takes `workMs` (a busy queue is a queue whose jobs take time) and then writes one row naming its number and the
 * worker process that ran it. After a worker is killed mid-run and another takes over, the table answers the two
 * questions that matter: was any job LOST (a number missing), and was any run TWICE (a number there twice — the
 * database queue is at-least-once: a job reserved by a worker that died is released after `retry_after` and run again).
 */
final class QueueProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $n, public int $workMs = 30) {}

    public function handle(): void
    {
        usleep($this->workMs * 1000);

        DB::connection('mysql')->table('perf_queue_probe')->insert([
            'job_no' => $this->n,
            'pid' => (int) getmypid(),
            'ran_at' => now(),
        ]);
    }
}
