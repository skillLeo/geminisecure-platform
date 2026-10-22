<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\Stream;
use App\Models\Tenant;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 *   php artisan live:prune [--stream=c] [--stream=e:{estate}] [--batch=5000]
 *
 * Keeps `config('live.retention_days')` of the live log in every database and deletes the rest
 * (DESIGN.md §1.8), daily at 03:50 (routes/console.php).
 *
 * THE CONTRACT WITH CATCH-UP IS WHAT THIS PROTECTS: EVERY SEQ ABOVE `floor_seq` IS STILL IN THE LOG.
 * A client cursor at or above the floor is answered exactly; one below it is told `reset` and its
 * screen resyncs. So the prune deletes a CONTIGUOUS PREFIX of the stream, the rows sequenced before
 * the cut-off, and it stops at the first one it must keep:
 *   - a row that was never published (the relay may still send it) is kept, and so is everything
 *     after it, because deleting past a kept row would leave a hole above the floor;
 *   - a row the relay gave up on (`publish_failed_at`) is finished, and pruned like a published one;
 *     clients recovered it through the gap, and a reset covers anyone who did not.
 *
 * FLOOR FIRST, THEN DELETE. The floor is raised before a single row goes, so a catch-up that reads
 * the floor after this command starts is told `reset` rather than handed a list with rows missing
 * from it. A run killed half way leaves rows at or below the floor, and the next run deletes them.
 *
 * WHAT IT RAISES AN ALARM FOR, AND NEVER DELETES: rows past the cut-off that were never published,
 * rows still held (never released, and somehow not backstopped), and rows due but never sequenced.
 * Each means the relay is not doing its job. A row SCHEDULED for later (`available_at` still ahead)
 * is waiting for its own time and is neither deleted nor alarmed. Any alarm, and any stream that
 * could not be opened, makes the run exit non-zero; every other stream is still pruned.
 */
class LivePrune extends Command
{
    protected $signature = 'live:prune
        {--stream=* : c for the central stream, e:{estate} for one estate; every stream when none is given}
        {--batch=5000 : rows deleted per statement}';

    protected $description = 'Delete the live log older than the retention window in every database, and raise the floor catch-up reads';

    public function handle(): int
    {
        $days = max(0, (int) config('live.retention_days', 7));
        $batch = max(1, (int) $this->option('batch'));

        /** @var list<string> $keys */
        $keys = array_values(array_map(strval(...), (array) $this->option('stream')));

        if ($keys === []) {
            $keys = ['c', ...Tenant::estates()->map(static fn (Tenant $estate): string => 'e:'.$estate->getTenantKey())->all()];
        }

        $this->info("Retention: {$days} day(s), by the database's UTC clock.");

        $failed = 0;
        $alarms = 0;

        foreach ($keys as $key) {
            try {
                $stream = Stream::fromKey($key);

                $alarms += $stream->isEstate()
                    ? $this->inEstate($stream, fn (): int => $this->prune($stream, $days, $batch))
                    : $this->prune($stream, $days, $batch);
            } catch (Throwable $failure) {
                $failed++;
                $this->line("  <fg=red>FAIL</> {$key}: ".$failure->getMessage());
            }
        }

        return $failed === 0 && $alarms === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** How many alarms one stream raised. */
    private function prune(Stream $stream, int $days, int $batch): int
    {
        $db = DB::connection($stream->connection());
        $label = $stream->isCentral() ? 'central' : 'estate '.$stream->tenantId;
        $cutoff = 'UTC_TIMESTAMP(6) - INTERVAL '.$days.' DAY';
        $alarms = 0;

        $floor = (int) $db->table('live_streams')->where('stream', $stream->name())->value('floor_seq');
        $bound = $db->table('live_events')->whereNotNull('seq')->whereRaw("sequenced_at < {$cutoff}")->max('seq');
        $target = $floor;

        if ($bound !== null) {
            $bound = (int) $bound;

            $blocker = $db->table('live_events')
                ->whereNotNull('seq')
                ->where('seq', '<=', $bound)
                ->whereNull('published_at')
                ->whereNull('publish_failed_at')
                ->min('seq');

            $target = $blocker === null ? $bound : (int) $blocker - 1;

            if ($blocker !== null) {
                $stuck = $db->table('live_events')->whereBetween('seq', [(int) $blocker, $bound])->whereNull('published_at')->whereNull('publish_failed_at')->count();
                $behind = $db->table('live_events')->whereBetween('seq', [(int) $blocker, $bound])->count() - $stuck;

                $this->line("  <fg=red>ALARM</> {$label}: {$stuck} row(s) sequenced before the cut-off were never published, the oldest at seq {$blocker}. The prune stops there and keeps {$behind} finished row(s) behind it. Is the relay running?");
                $alarms++;
            }
        }

        $held = $db->table('live_events')->whereNull('seq')->whereNull('available_at')->whereRaw("created_at < {$cutoff}")->count();

        if ($held > 0) {
            $this->line("  <fg=red>ALARM</> {$label}: {$held} held row(s) older than the cut-off were never released. Kept.");
            $alarms++;
        }

        $due = $db->table('live_events')
            ->whereNull('seq')
            ->whereNotNull('available_at')
            ->whereRaw('available_at <= UTC_TIMESTAMP(6)')
            ->whereRaw("created_at < {$cutoff}")
            ->count();

        if ($due > 0) {
            $this->line("  <fg=red>ALARM</> {$label}: {$due} row(s) older than the cut-off are due and were never sequenced. Kept.");
            $alarms++;
        }

        if ($target > $floor) {
            $db->update(
                'UPDATE live_streams SET floor_seq = GREATEST(floor_seq, ?), updated_at = UTC_TIMESTAMP(6) WHERE stream = ?',
                [$target, $stream->name()],
            );
        }

        $upTo = max($target, $floor);
        $deliveries = $this->deleteUpTo($db, 'live_deliveries', $upTo, $batch);
        $events = $this->deleteUpTo($db, 'live_events', $upTo, $batch);

        $this->line($target > $floor
            ? "  <fg=green>OK</> {$label}: floor {$floor} -> {$target}; deleted {$events} event row(s) and {$deliveries} delivery row(s)"
            : "  <fg=green>OK</> {$label}: nothing new to prune; the floor stays at {$floor}".($events + $deliveries > 0 ? " (swept {$events} event and {$deliveries} delivery row(s) left at or below it)" : ''));

        return $alarms;
    }

    private function deleteUpTo(Connection $db, string $table, int $upTo, int $batch): int
    {
        if ($upTo < 1) {
            return 0;
        }

        $total = 0;

        do {
            /*
             * Each batch is its own READ COMMITTED statement. Under the default REPEATABLE READ a
             * range delete also locks the gap above the last row it reads, and when that is the top
             * of the table it is exactly where the sequencer is about to write the next seq, so the
             * next event (a panic's included) would wait for this batch. Read committed locks only
             * the rows it deletes, and they are rows nobody else writes.
             */
            if ($db->transactionLevel() === 0) {
                $db->unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }

            $deleted = $db->table($table)->where('seq', '<=', $upTo)->orderBy('seq')->limit($batch)->delete();
            $total += $deleted;
        } while ($deleted === $batch);

        return $total;
    }

    /**
     * Run `$work` inside one estate, and leave tenancy exactly as it was found, whatever happens.
     *
     * @param  Closure(): int  $work
     */
    private function inEstate(Stream $stream, Closure $work): int
    {
        $estate = Tenant::query()->find($stream->tenantId);

        if (! $estate instanceof Tenant) {
            throw new RuntimeException("there is no estate '{$stream->tenantId}'");
        }

        $previous = tenant();

        try {
            tenancy()->initialize($estate);

            return $work();
        } finally {
            if ($previous instanceof Tenant) {
                tenancy()->initialize($previous);
            } else {
                tenancy()->end();
            }
        }
    }
}
