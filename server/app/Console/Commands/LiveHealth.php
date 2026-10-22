<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\Auth\EstateScope;
use App\Live\Relay\Breaker;
use App\Live\Relay\PublishFailed;
use App\Live\Relay\Relay;
use App\Live\Relay\Transport;
use App\Live\Stream;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use React\EventLoop\Loop;
use Throwable;

/**
 *   php artisan live:health [--require-relay-within=5] [--stream=c] [--stream=e:{estate}]
 *
 * Whether live updates are working, and if not, what is wrong (DESIGN.md §1.9, §9.5). For every
 * database's log: its head and floor, its epoch, how far publishing is behind, the oldest row not
 * published yet and the oldest still held, rows given up on, and when the relay last swept it.
 * Then MySQL's `binlog_format` (the sequencer's READ COMMITTED needs ROW), the event loop Reverb
 * would run on here, whether Reverb answers the relay (a signed GET /apps/{id}/channels), the
 * breaker, and every stream the relay says it could not drain.
 *
 *   FAIL  something is not working: an estate that cannot be opened, a row waiting more than 5 s
 *         to be published, a binlog_format other than ROW, a Reverb that does not answer, and,
 *         with --require-relay-within=N, a central stream the relay has not swept for N seconds.
 *   WARN  worth knowing, and not broken: rows given up on today (clients recovered them by catch-up),
 *         the breaker resting, Reverb's event loop limited to about 1,024 sockets.
 *
 * Exits non-zero on any FAIL. It reads; it changes nothing. 80-live.sh finishes with it, verify.sh
 * prints it, and it is the first thing to run when a screen says live updates are down.
 */
class LiveHealth extends Command
{
    /** A row due this long ago and still not published means the relay is not keeping up. */
    private const BEHIND_SECONDS = 5.0;

    protected $signature = 'live:health
        {--require-relay-within= : fail when the relay has not swept the central stream within this many seconds}
        {--stream=* : c for the central stream, e:{estate} for one estate; every stream when none is given}';

    protected $description = 'Say whether live updates are working: every log, the relay, MySQL and Reverb';

    private int $failures = 0;

    public function handle(Transport $transport, Breaker $breaker): int
    {
        $within = $this->option('require-relay-within');
        $within = is_numeric($within) ? (float) $within : null;

        /** @var list<string> $keys */
        $keys = array_values(array_map(strval(...), (array) $this->option('stream')));

        if ($keys === []) {
            $keys = ['c', ...Tenant::estates()->map(static fn (Tenant $t): string => 'e:'.$t->getTenantKey())->all()];
        }

        $this->line('<options=bold>The live logs</>');

        foreach ($keys as $key) {
            try {
                $stream = Stream::fromKey($key);

                if ($stream->isEstate()) {
                    EstateScope::run((string) $stream->tenantId, fn () => $this->stream($stream, null));
                } else {
                    $this->stream($stream, $within);
                }
            } catch (Throwable $e) {
                $this->problem("{$key}: could not be read (".class_basename($e).': '.mb_strimwidth($e->getMessage(), 0, 200, '…').')');
            }
        }

        $this->line('');
        $this->line('<options=bold>The relay, MySQL and Reverb</>');

        $this->binlog();
        $this->eventLoop();
        $this->reverb($transport);

        if ($breaker->isOpen()) {
            $this->caution('publishing is resting after Reverb failed to answer (the breaker, 10 s at a time); sequencing and catch-up carry on');
        }

        try {
            $failing = Cache::store()->get(Relay::FAILING_KEY);
        } catch (Throwable) {
            $failing = null;
        }

        foreach (is_array($failing) ? $failing : [] as $key => $failure) {
            $this->problem("the relay cannot drain {$key} (since {$failure['since']}): {$failure['message']}");
        }

        $this->line('');
        $this->line($this->failures === 0 ? '<fg=green>Live updates are healthy.</>' : "<fg=red>{$this->failures} problem(s) above.</> No event is lost meanwhile: clients catch up from the log once they are put right.");

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function stream(Stream $stream, ?float $within): void
    {
        $db = DB::connection($stream->connection());
        $label = $stream->isCentral() ? 'central' : 'estate '.$stream->tenantId;

        $row = $db->selectOne(
            'SELECT head_seq, floor_seq, epoch, ticked_at, relay_seen_at,
                    TIMESTAMPDIFF(MICROSECOND, relay_seen_at, UTC_TIMESTAMP(6)) AS seen_ago
               FROM live_streams WHERE stream = ?',
            [$stream->name()],
        );

        if ($row === null) {
            $this->problem("{$label}: no live_streams row; this database was not built by the live log's migration");

            return;
        }

        $waiting = $db->selectOne(
            'SELECT COUNT(*) AS n, TIMESTAMPDIFF(MICROSECOND, MIN(COALESCE(available_at, created_at)), UTC_TIMESTAMP(6)) AS oldest
               FROM live_events
              WHERE published_at IS NULL AND publish_failed_at IS NULL
                AND (available_at IS NULL OR available_at <= UTC_TIMESTAMP(6))',
        );

        $held = (int) $db->table('live_events')->whereNull('seq')->whereNull('available_at')->count();
        $published = $db->table('live_events')->where(static fn ($q) => $q->whereNotNull('published_at')->orWhereNotNull('publish_failed_at'))->max('seq');
        $givenUp = (int) $db->table('live_events')->whereRaw('publish_failed_at > UTC_TIMESTAMP(6) - INTERVAL 1 DAY')->count();

        $head = (int) $row->head_seq;
        $lag = $head - (int) ($published ?? 0);
        $oldest = $waiting?->oldest === null ? null : max(0, (int) $waiting->oldest) / 1e6;
        $seen = $row->seen_ago === null ? null : max(0, (int) $row->seen_ago) / 1e6;

        $this->line(sprintf(
            '  %s: head %d · floor %d · epoch %s · %d behind · %s · %s',
            $label,
            $head,
            (int) $row->floor_seq,
            substr((string) $row->epoch, 0, 8),
            $lag,
            $oldest === null ? 'nothing waiting' : sprintf('%d waiting, the oldest %s', (int) $waiting->n, self::seconds($oldest)),
            $seen === null ? 'never swept by the relay' : 'swept '.self::seconds($seen).' ago',
        ));

        if ($oldest !== null && $oldest > self::BEHIND_SECONDS) {
            $this->problem("{$label}: a row has waited ".self::seconds($oldest).' to be published. Is gs-live-relay running, and does Reverb answer?');
        }

        if ($held > 0) {
            $this->line("    {$held} held row(s): access changes waiting for their permission-cache flush, released within 10 s");
        }

        if ($givenUp > 0) {
            $this->caution("{$label}: {$givenUp} row(s) given up on in the last day (Reverb refused them); clients recovered them by catch-up");
        }

        if ($within !== null && ($seen === null || $seen > $within)) {
            $this->problem("{$label}: the relay has not swept this stream within {$within} s".($seen === null ? ' (never)' : ' (last '.self::seconds($seen).' ago)'));
        }
    }

    private function binlog(): void
    {
        try {
            $format = (string) (DB::connection(Stream::central()->connection())->selectOne('SELECT @@GLOBAL.binlog_format AS f')->f ?? '');
        } catch (Throwable $e) {
            $this->problem('binlog_format could not be read: '.class_basename($e));

            return;
        }

        $format === 'ROW'
            ? $this->healthy('binlog_format ROW')
            : $this->problem("binlog_format is {$format}; the sequencer's READ COMMITTED needs ROW (DESIGN.md §1.4)");
    }

    private function eventLoop(): void
    {
        $class = class_basename(Loop::get());

        $class === 'StreamSelectLoop'
            ? $this->caution('event loop StreamSelectLoop: Reverb on this PHP holds about 1,024 sockets at most; 80-live.sh installs ext-ev')
            : $this->healthy("event loop {$class}");
    }

    private function reverb(Transport $transport): void
    {
        if (config('live.publish') !== 'reverb') {
            $this->line('  Reverb: not checked (live.publish is '.(string) config('live.publish').', so nothing is published)');

            return;
        }

        try {
            $channels = $transport->occupiedChannels(0.5, 2.0);
            $this->healthy("Reverb answers the relay on {$this->relayAddress()} ({$channels} occupied channel(s))");
        } catch (PublishFailed $e) {
            $this->problem("Reverb does not answer the relay on {$this->relayAddress()}: ".$e->getMessage());
        }
    }

    private function relayAddress(): string
    {
        return (string) config('broadcasting.connections.reverb_relay.options.host').':'.(string) config('broadcasting.connections.reverb_relay.options.port');
    }

    private static function seconds(float $seconds): string
    {
        return $seconds < 10 ? sprintf('%.1f s', $seconds) : sprintf('%d s', (int) round($seconds));
    }

    private function healthy(string $message): void
    {
        $this->line("  <fg=green>OK</>   {$message}");
    }

    private function caution(string $message): void
    {
        $this->line("  <fg=yellow>WARN</> {$message}");
    }

    private function problem(string $message): void
    {
        $this->failures++;
        $this->line("  <fg=red>FAIL</> {$message}");
    }
}
