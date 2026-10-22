<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DuressAlert;
use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\DeliveryReport;
use App\Services\Platform\OperatorEstates;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * THE PANIC SYNTHETIC MONITOR (M16 §6) — life-safety performance observed, not assumed.
 *
 *   php artisan monitor:panic-synthetic
 *
 * Raise a DRILL panic on a NON-PRODUCTION estate every fifteen minutes (cron, see
 * MONITORING.md), read back how fast the platform handed the critical leg off, and FAIL —
 * exit non-zero and log at error level — if it took longer than the threshold or reached
 * nobody. A monitoring wrapper (the cron's own alerting, or an uptime service watching the
 * exit code) turns that failure into a page.
 *
 * WHY THE DEMONSTRATION ESTATE. On it, the M5 §3 rules hold: a drill reaches only that
 * estate's own console channel over the live socket and wakes nobody real, so this can run
 * unattended every quarter hour. What it measures is the sub-second intake → live-broadcast
 * path — the first and most important leg of every real panic — and that it is alive at all.
 *
 * IT CLEANS UP AFTER ITSELF, unlike `panic:walk` (which leaves the drill open on purpose for
 * a human to see). Running every fifteen minutes, an open drill left behind would be a
 * hundred a day; this closes the one it raised the moment it has timed it.
 *
 * SINGLE-LINE, MACHINE-READABLE OUTPUT, so a cron log or a wrapper can parse it:
 *   panic-synthetic estate=demoestate status=OK reached=1/1 max_handoff_ms=74 threshold_ms=3000 alert=DRILL#123
 */
class MonitorPanicSynthetic extends Command
{
    protected $signature = 'monitor:panic-synthetic
        {--estate=demoestate : the non-production estate to drill}
        {--threshold-ms=3000 : fail if the slowest reached hand-off is slower than this}
        {--force : allow a non-demonstration estate (real people are told, marked DRILL) — never in a monitor}';

    protected $description = 'Raise a DRILL panic on a non-production estate, time the critical hand-off, and fail if it is slow or unreached (M16 §6).';

    public const RAISED_BY = 'Synthetic monitor (monitor:panic-synthetic)';

    public function handle(AlertIntake $intake, DeliveryReport $report): int
    {
        $estateId = (string) $this->option('estate');
        $threshold = max(1, (int) $this->option('threshold-ms'));
        $force = (bool) $this->option('force');

        $estate = Tenant::query()->find($estateId);

        if (! $estate instanceof Tenant) {
            return $this->refuse($estateId, 'no such estate', $threshold);
        }

        if (! OperatorEstates::isDemonstration($estateId) && ! $force) {
            return $this->refuse($estateId, 'not a demonstration estate — a monitor must never wake real people; pass --force only by hand', $threshold);
        }

        try {
            $alert = $estate->run(function () use ($intake, $estateId): DuressAlert {
                // A lot if the estate has one, else none — a panic from a resident with no unit.
                $lot = Unit::query()->orderBy('id')->value('reference');

                $alert = $intake->record(
                    tenantId: $estateId,
                    kind: 'panic',
                    raisedByName: self::RAISED_BY,
                    unitReference: is_string($lot) ? $lot : null,
                    deviceTime: now(),
                    idempotencyKey: 'panic-synthetic-'.Str::uuid()->toString(),
                    isDrill: true,
                    fromPanicButton: true,
                );

                $alert->forceFill(['mode' => 'silent', 'raised_by_account_id' => null])->save();

                return $alert;
            });
        } catch (Throwable $e) {
            return $this->refuse($estateId, 'the drill could not be raised: '.class_basename($e), $threshold);
        }

        $alert->refresh();
        $walk = $report->forAlert($alert);

        // The slowest hand-off among the legs that were reached, in ms.
        $reachedMs = [];

        foreach ($walk['destinations'] as $destination) {
            foreach ($destination['legs'] as $leg) {
                if (($leg['reached'] ?? false) === true && is_int($leg['elapsed_ms'] ?? null)) {
                    $reachedMs[] = (int) $leg['elapsed_ms'];
                }
            }
        }

        $reachedCount = (int) $walk['reached'];
        $ofCount = (int) $walk['of'];
        $maxMs = $reachedMs === [] ? null : max($reachedMs);

        // Close the drill we raised, so nothing piles up. Bookkeeping on our own row.
        $this->closeDrill($estate, $alert);

        $slow = $maxMs !== null && $maxMs > $threshold;
        $unreached = $reachedCount < 1;

        $line = sprintf(
            'panic-synthetic estate=%s status=%s reached=%d/%d max_handoff_ms=%s threshold_ms=%d alert=DRILL#%d',
            $estateId,
            ($slow || $unreached) ? 'FAIL' : 'OK',
            $reachedCount,
            $ofCount,
            $maxMs === null ? 'none' : (string) $maxMs,
            $threshold,
            $alert->id,
        );

        if ($slow || $unreached) {
            Log::error($line, ['reason' => $unreached ? 'no destination reached' : 'hand-off over threshold']);
            $this->error($line);

            return self::FAILURE;
        }

        $this->info($line);

        return self::SUCCESS;
    }

    private function closeDrill(Tenant $estate, DuressAlert $alert): void
    {
        try {
            $estate->run(function () use ($alert): void {
                // Not through AlertIntake::resolve(), which wants a User actor a headless
                // monitor has none of — this is our own drill, and closing it is bookkeeping.
                $alert->forceFill([
                    'status' => 'false_alarm',
                    'resolved_at' => Carbon::now(),
                    'resolution_note' => 'Synthetic monitor drill, closed automatically.',
                ])->save();
            });
        } catch (Throwable $e) {
            // A drill left open is a nuisance, not an outage; do not turn cleanup into a page.
            Log::warning('panic-synthetic could not close DRILL#'.$alert->id.': '.class_basename($e));
        }
    }

    private function refuse(string $estateId, string $why, int $threshold): int
    {
        $line = sprintf('panic-synthetic estate=%s status=FAIL reached=0/0 max_handoff_ms=none threshold_ms=%d reason="%s"', $estateId, $threshold, $why);
        Log::error($line);
        $this->error($line);

        return self::FAILURE;
    }
}
