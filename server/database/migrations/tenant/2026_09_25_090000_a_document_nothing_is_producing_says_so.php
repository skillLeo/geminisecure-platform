<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE DOCUMENTS THAT WERE ASKED FOR AND NEVER COULD BE MADE, SAID SO (B097).
 *
 * Until the fix, every statement, receipt, remittance, minutes and certificate
 * press on a `database` queue wrote its row as QUEUED and then failed to queue
 * the job that renders it: the job went to the estate's own database, which has
 * no `jobs` table. The press answered 500, and the row stayed QUEUED — so every
 * screen that lists documents has since said "Being produced" about a file that
 * nothing, anywhere, is producing. The table is retained for seven years, so it
 * would have gone on saying it.
 *
 * AN HOUR IS FAR PAST ANY REAL RENDER. A document is a page of HTML turned into a
 * PDF, done in seconds, and a job the worker loses is retried within
 * `retry_after` (90 seconds). A row still QUEUED an hour after it was asked for
 * is one no worker holds, and saying it could not be produced is the truth;
 * "Asking again is safe" is what the screen then offers, and it is.
 *
 * ONLY QUEUED ROWS. An issued document is retained as issued, and the trigger on
 * this table would refuse the change anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('documents')
            ->where('status', 'queued')
            ->where('created_at', '<', Carbon::now()->subHour())
            ->update([
                'status' => 'failed',
                'failure_reason' => 'it was never handed to the worker that produces documents',
                'updated_at' => Carbon::now(),
            ]);
    }

    /** A repair is not undone: those rows were never going to be produced. */
    public function down(): void {}
};
