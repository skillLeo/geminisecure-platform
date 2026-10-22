<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\Auth\EstateScope;
use App\Live\Stream;
use App\Live\Tick\Rule;
use App\Live\Tick\RuleRegistry;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 *   php artisan live:tick [--stream=c] [--stream=e:{estate}]
 *
 * What changes because time passed (DESIGN.md §2.13), every minute (routes/console.php). For each
 * stream it hands every rule of that stream (app/Live/Tick/*Rule.php, found by RuleRegistry) the
 * window `(ticked_at, now]`, by that database's own UTC clock, so a minute the scheduler missed is
 * caught up and not lost; then it moves `ticked_at` to now.
 *
 *   - A first tick, or one after a long outage, looks back one minute, and never more than a day:
 *     what fell due before that is a day old, and the screens have long since read it for themselves.
 *   - A rule that fails is reported and the others still run; the stream's `ticked_at` then stays
 *     where it was, so the whole window is handed over again next minute. Every rule records a
 *     "re-read now" hint, which is harmless twice.
 *   - With no rule for a kind of stream, that kind is not opened at all: no estate is entered to do
 *     nothing, sixty times an hour.
 */
class LiveTick extends Command
{
    /** The window a first tick looks back over. */
    private const FIRST_WINDOW_SECONDS = 60;

    /** The furthest back any tick looks. */
    private const LONGEST_WINDOW_SECONDS = 86400;

    protected $signature = 'live:tick
        {--stream=* : c for the central stream, e:{estate} for one estate; every stream with rules when none is given}';

    protected $description = 'Record the live events that time itself causes, for the window since the last tick';

    public function handle(): int
    {
        $central = RuleRegistry::for('central');
        $estate = RuleRegistry::for('estate');

        /** @var list<string> $keys */
        $keys = array_values(array_map(strval(...), (array) $this->option('stream')));

        if ($keys === []) {
            $keys = [
                ...($central === [] ? [] : ['c']),
                ...($estate === [] ? [] : Tenant::estates()->map(static fn (Tenant $t): string => 'e:'.$t->getTenantKey())->all()),
            ];
        }

        $failed = 0;

        foreach ($keys as $key) {
            try {
                $stream = Stream::fromKey($key);
                $rules = $stream->isCentral() ? $central : $estate;

                if ($rules === []) {
                    continue;
                }

                $failed += $stream->isEstate()
                    ? EstateScope::run((string) $stream->tenantId, fn (): int => $this->tick($stream, $rules))
                    : $this->tick($stream, $rules);
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->line("  <fg=red>FAIL</> {$key}: ".$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<Rule>  $rules
     * @return int how many rules failed
     */
    private function tick(Stream $stream, array $rules): int
    {
        $db = DB::connection($stream->connection());
        $row = $db->selectOne('SELECT ticked_at, UTC_TIMESTAMP(6) AS now FROM live_streams WHERE stream = ?', [$stream->name()]);

        if ($row === null) {
            $this->line("  <fg=red>FAIL</> {$stream->key()}: no live_streams row; this database was not built by the live log's migration.");

            return 1;
        }

        $now = CarbonImmutable::parse((string) $row->now, 'UTC');
        $from = $row->ticked_at === null ? $now->subSeconds(self::FIRST_WINDOW_SECONDS) : CarbonImmutable::parse((string) $row->ticked_at, 'UTC');
        $from = $from->max($now->subSeconds(self::LONGEST_WINDOW_SECONDS));

        if ($from >= $now) {
            return 0;
        }

        $failed = 0;

        foreach ($rules as $rule) {
            try {
                $rule->run($stream, $from, $now);
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->line('  <fg=red>FAIL</> '.$stream->key().' '.class_basename($rule).': '.$e->getMessage());
            }
        }

        if ($failed === 0) {
            $db->update('UPDATE live_streams SET ticked_at = ?, updated_at = UTC_TIMESTAMP(6) WHERE stream = ?', [$now->format('Y-m-d H:i:s.u'), $stream->name()]);
        }

        return $failed;
    }
}
