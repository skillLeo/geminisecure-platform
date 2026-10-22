<?php

declare(strict_types=1);

namespace App\Live\Relay;

use App\Live\Access\Evictor;
use App\Live\Auth\EstateScope;
use App\Live\Stream;
use App\Models\Tenant;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * THE LIVE RELAY (DESIGN.md §1.5, §1.6): the one process that turns the log into messages.
 *
 * `php artisan live:relay` runs one of these under supervisor (`gs-live-relay`, numprocs=1), and
 * `GET_LOCK('gs_live_relay')` makes sure it is the only one: a second waits for the lock rather
 * than running beside it. Each turn of its loop:
 *
 *   1  waits on the bell (Wake) for up to a second, less when something is due sooner;
 *   2  drains every stream that was rung, the central one every second whether rung or not, every
 *      estate every 30 s (the sweeps that catch a ring that was lost), and any stream whose
 *      scheduled row, held row's backstop or refused row's retry has come;
 *   3  closes the sockets of principals whose access changed 250 ms ago (Evictor);
 *   4  every 10 s, publishes the pulse on `live-pulse`, and checks it still holds its lock.
 *
 * DRAINING a stream is: sequence (Sequencer), publish (Publisher), stamp `relay_seen_at`, and note
 * when its next scheduled or held row falls due. An estate's stream is drained inside that estate
 * (EstateScope, which always leaves it again). AN ESTATE THAT CANNOT BE OPENED IS SKIPPED, reported
 * once, and listed for `live:health`; it holds up nothing else, because each stream is drained on
 * its own and the next one does not wait for it.
 *
 * `flushNow()` is the other way in: a panic's own request publishing its `alert.raised` ahead of
 * the fan-out, inside the budget the fan-out is waiting on (§1.6).
 */
final class Relay
{
    /** The MySQL user lock only one relay may hold. */
    public const LOCK = 'gs_live_relay';

    public const CENTRAL_SWEEP_SECONDS = 1.0;

    public const ESTATE_SWEEP_SECONDS = 30.0;

    /** How often the estate list is read again, so a new estate is swept without a restart. */
    public const ESTATES_REFRESH_SECONDS = 30.0;

    /** Sequencer and publisher passes per drain before the next stream gets its turn. */
    public const PAGES = 10;

    /** Where `live:health` reads the streams the relay could not drain. */
    public const FAILING_KEY = 'live:relay:failing';

    /** flushNow's lock: this many NOWAIT tries, this far apart, inside the budget (§1.6). */
    public const FLUSH_LOCK_TRIES = 5;

    public const FLUSH_LOCK_GAP_SECONDS = 0.02;

    /** A failing stream is reported again after this long, if it is still failing the same way. */
    private const REPORT_AGAIN_SECONDS = 600.0;

    /** @var array<string, float> stream key => clock time it was last drained */
    private array $drained = [];

    /** @var array<string, float> stream key => clock time its next scheduled or held row falls due */
    private array $timers = [];

    private float $pulsedAt = -INF;

    /** @var list<string>|null */
    private ?array $estateKeys = null;

    private float $estatesReadAt = -INF;

    /** @var array<string, array{since: string, message: string, reportedAt: float}> */
    private array $failing = [];

    private bool $stopping = false;

    private bool $lostLock = false;

    /**
     * @param  (Closure(): list<string>)|null  $estates  the estate stream keys (`e:{tenant}`) to sweep;
     *                                                   every estate when null
     */
    public function __construct(
        private readonly Publisher $publisher,
        private readonly Bell $bell,
        private readonly Evictor $evictor,
        private readonly Clock $clock,
        private readonly ?Closure $estates = null,
    ) {}

    /* ---- the singleton lock -------------------------------------------------------------- */

    /** Take the relay's lock on the central connection, without waiting. */
    public static function acquireLock(): bool
    {
        $row = DB::connection(Stream::central()->connection())->selectOne('SELECT GET_LOCK(?, 0) AS got', [self::LOCK]);

        return (int) ($row->got ?? 0) === 1;
    }

    /** Whether THIS session still holds it: a reconnected session does not. */
    public static function holdsLock(): bool
    {
        $row = DB::connection(Stream::central()->connection())->selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS mine', [self::LOCK]);

        return (int) ($row->mine ?? 0) === 1;
    }

    public static function releaseLock(): void
    {
        DB::connection(Stream::central()->connection())->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK]);
    }

    /* ---- the loop ------------------------------------------------------------------------ */

    /**
     * Turn the loop until `$seconds` have passed, stop() is called, or the lock is lost. The lock
     * must already be held.
     */
    public function run(float $seconds): void
    {
        $until = $this->clock->now() + $seconds;

        while (! $this->stopping && ! $this->lostLock && $this->clock->now() < $until) {
            $this->tick();
        }
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    /** Whether the loop ended because this session no longer held the lock. */
    public function lostLock(): bool
    {
        return $this->lostLock;
    }

    /**
     * One turn of the loop.
     *
     * @return list<string> the stream keys it drained, in order
     */
    public function tick(): array
    {
        $rung = $this->bell->wait($this->waitFor($this->clock->now()));
        $now = $this->clock->now();

        $keys = [];

        foreach (in_array(Bell::EVERYTHING, $rung, true) ? ['c', ...$this->estateKeys()] : $rung as $key) {
            $keys[$key] = true;
        }

        if ($now - ($this->drained['c'] ?? -INF) >= self::CENTRAL_SWEEP_SECONDS) {
            $keys['c'] = true;
        }

        foreach ($this->estateKeys() as $key) {
            if ($now - ($this->drained[$key] ?? -INF) >= self::ESTATE_SWEEP_SECONDS) {
                $keys[$key] = true;
            }
        }

        foreach ([...$this->timers, ...$this->publisher->retriesDue()] as $key => $at) {
            if ($at <= $now) {
                $keys[$key] = true;
            }
        }

        // The central stream first: it is where a panic is.
        $order = array_keys($keys);
        usort($order, static fn (string $a, string $b): int => ($a === 'c' ? 0 : 1) <=> ($b === 'c' ? 0 : 1));

        foreach ($order as $key) {
            $this->drain((string) $key);
        }

        $this->evictor->runDue();

        if ($this->clock->now() - $this->pulsedAt >= max(1, (int) config('live.pulse_seconds', 10))) {
            $this->beat();
        }

        return array_map(strval(...), $order);
    }

    /**
     * How long the bell may be waited on: until the next thing falls due, and never more than the
     * central sweep.
     */
    private function waitFor(float $now): float
    {
        $due = [
            ($this->drained['c'] ?? -INF) + self::CENTRAL_SWEEP_SECONDS,
            $this->pulsedAt + max(1, (int) config('live.pulse_seconds', 10)),
            ...array_values($this->timers),
            ...array_values($this->publisher->retriesDue()),
        ];

        foreach ($this->estateKeys() as $key) {
            $due[] = ($this->drained[$key] ?? -INF) + self::ESTATE_SWEEP_SECONDS;
        }

        $evict = $this->evictor->nextDueIn();

        if ($evict !== null) {
            $due[] = $now + $evict;
        }

        return max(0.0, min(self::CENTRAL_SWEEP_SECONDS, min($due) - $now));
    }

    /** Drain one stream; a stream that fails is skipped and listed, and nothing else waits on it. */
    private function drain(string $key): void
    {
        try {
            $stream = Stream::fromKey($key);
        } catch (InvalidArgumentException $e) {
            // Something rang a key that names no stream. Nothing to drain; say so once.
            $this->failed($key, $e);

            return;
        }

        $this->drained[$key] = $this->clock->now();

        try {
            if ($stream->isEstate()) {
                EstateScope::run((string) $stream->tenantId, fn () => $this->drainOpen($stream));
            } else {
                $this->drainOpen($stream);
            }

            $this->recovered($key);
        } catch (Throwable $e) {
            $this->failed($key, $e);
        }
    }

    private function drainOpen(Stream $stream): void
    {
        for ($page = 0; $page < self::PAGES; $page++) {
            if (Sequencer::run($stream) < Sequencer::BATCH) {
                break;
            }
        }

        for ($page = 0; $page < self::PAGES; $page++) {
            $outcome = $this->publisher->run($stream);

            if ($outcome->outage || $outcome->read < Publisher::PASS_LIMIT) {
                break;
            }
        }

        $db = DB::connection($stream->connection());

        $db->update('UPDATE live_streams SET relay_seen_at = UTC_TIMESTAMP(6) WHERE stream = ?', [$stream->name()]);

        /*
         * THE TIMER. A scheduled row (available_at ahead, §1.3) and a held row whose 10-second
         * backstop is ahead (§1.7) are not due yet, and an estate is otherwise only swept every
         * 30 s. So the relay notes when the first of them falls due, by the database's own clock,
         * and drains the stream again then.
         */
        $micros = $db->selectOne(
            'SELECT TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(6), MIN(due)) AS micros FROM (
                SELECT MIN(available_at) AS due FROM live_events WHERE seq IS NULL AND available_at > UTC_TIMESTAMP(6)
                UNION ALL
                SELECT MIN(created_at) + INTERVAL '.Sequencer::HELD_BACKSTOP_SECONDS.' SECOND FROM live_events WHERE seq IS NULL AND available_at IS NULL
            ) AS pending',
        )?->micros;

        if ($micros === null) {
            unset($this->timers[$stream->key()]);
        } else {
            // A hundredth of a second late rather than early: a row drained a moment before its time waits for the next.
            $this->timers[$stream->key()] = $this->clock->now() + max(0, (int) $micros) / 1e6 + 0.01;
        }
    }

    /** The pulse, the failing list's refresh, and the lock check, every `live.pulse_seconds`. */
    private function beat(): void
    {
        $this->pulsedAt = $this->clock->now();

        try {
            if (! self::holdsLock()) {
                report(new RuntimeException('The live relay no longer holds its lock (its database session was replaced); it stops, and supervisor starts it again.'));
                $this->lostLock = true;

                return;
            }
        } catch (Throwable $e) {
            report($e);
            $this->lostLock = true;

            return;
        }

        $this->publishFailing();
        $this->publisher->pulse();
    }

    /* ---- what could not be drained -------------------------------------------------------- */

    private function failed(string $key, Throwable $e): void
    {
        $message = mb_strimwidth(class_basename($e).': '.$e->getMessage(), 0, 300, '…');
        $known = $this->failing[$key] ?? null;
        $now = $this->clock->now();

        $this->failing[$key] = [
            'since' => $known['since'] ?? Carbon::now('UTC')->toIso8601String(),
            'message' => $message,
            'reportedAt' => $known['reportedAt'] ?? -INF,
        ];

        if ($known === null || $known['message'] !== $message || $now - $known['reportedAt'] >= self::REPORT_AGAIN_SECONDS) {
            $this->failing[$key]['reportedAt'] = $now;
            report(new RuntimeException("The live relay could not drain the stream {$key}. It is skipped and tried again at its next sweep; every other stream carries on.", 0, $e));
        }

        if ($known === null) {
            $this->publishFailing();
        }
    }

    private function recovered(string $key): void
    {
        if (isset($this->failing[$key])) {
            unset($this->failing[$key]);
            $this->publishFailing();
        }
    }

    /** @return array<string, array{since: string, message: string}> */
    public function failing(): array
    {
        return array_map(static fn (array $f): array => ['since' => $f['since'], 'message' => $f['message']], $this->failing);
    }

    private function publishFailing(): void
    {
        try {
            // Two minutes: a relay that has stopped leaves no stale alarm behind for long.
            Cache::store()->put(self::FAILING_KEY, $this->failing(), 120);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return list<string> */
    private function estateKeys(): array
    {
        $now = $this->clock->now();

        if ($this->estateKeys !== null && $now - $this->estatesReadAt < self::ESTATES_REFRESH_SECONDS) {
            return $this->estateKeys;
        }

        try {
            $keys = $this->estates !== null
                ? ($this->estates)()
                : Tenant::estates()->map(static fn (Tenant $estate): string => 'e:'.$estate->getTenantKey())->all();

            $this->estateKeys = array_values(array_map(strval(...), $keys));
            $this->estatesReadAt = $now;
        } catch (Throwable $e) {
            // The central database is not answering: keep sweeping the estates already known.
            report($e);
            $this->estatesReadAt = $now;
        }

        return $this->estateKeys ?? [];
    }

    /* ---- a panic's own flush --------------------------------------------------------------- */

    /**
     * Publish the urgent rows NOW, from inside the request that recorded them (DESIGN.md §1.6).
     *
     * A panic's `alert.raised` is recorded in the alert's own transaction; its after-commit hook
     * calls this before the guards' push, the mail and the texts, with ONE budget shared by every
     * publish before that fan-out (PanicPublishBudget: today's 2 s, 0.5 s to connect). It:
     *
     *   - does not try at all, and only rings the relay's bell, while the breaker is open, when an
     *     earlier publish on the budget failed, or with less than 0.2 s of it left;
     *   - sequences EVERYTHING due on the stream, taking the stream's row with FOR UPDATE NOWAIT, up
     *     to five tries 20 ms apart inside the budget, because the relay holds that row only for
     *     milliseconds and a lock wait could otherwise be a second (innodb_lock_wait_timeout);
     *   - publishes the urgent rows over `reverb_relay`, connecting in min(0.5, left) and giving up
     *     at left, and never an access event (only the relay evicts, 250 ms later);
     *   - answers whether nothing it was asked for is left unpublished. Anything that is left, for
     *     whatever reason, the relay publishes on its next pass, and the bell has been rung for it.
     *
     * It never throws: at commit time there is no caller left to catch it.
     */
    public static function flushNow(Stream $stream, bool $urgentOnly = true, ?PanicPublishBudget $budget = null): bool
    {
        $budget ??= PanicPublishBudget::start(2.0);

        try {
            if (app(Breaker::class)->isOpen() || ! $budget->allowsPublish()) {
                self::ring($stream);

                return false;
            }

            if ($stream->isEstate() && tenant()?->getTenantKey() !== $stream->tenantId) {
                throw new RuntimeException("Relay::flushNow was asked for estate {$stream->tenantId}'s stream outside that estate.");
            }

            $db = DB::connection($stream->connection());

            if ($db->transactionLevel() > 0) {
                // Inside a transaction the rows it would publish are not committed yet.
                self::ring($stream);

                return false;
            }

            if (! self::sequenceWithin($stream, $budget)) {
                self::ring($stream);

                return false;
            }

            $outcome = app(Publisher::class)->run($stream, $urgentOnly, $budget, evicts: false);

            $left = $db->table('live_events')
                ->whereNotNull('seq')
                ->whereNull('published_at')
                ->whereNull('publish_failed_at')
                ->when($urgentOnly, static fn ($query) => $query->where('urgent', true))
                ->when(! $urgentOnly, static fn ($query) => $query->where('type', 'not like', 'access.%'))
                ->exists();

            if ($outcome->outage || $left) {
                self::ring($stream);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            report($e);
            self::ring($stream);

            return false;
        }
    }

    /**
     * Sequence the stream with NOWAIT: up to five tries inside one window of four 20 ms gaps, and
     * only while the budget allows. The window is kept by the clock, not by counting sleeps, because
     * a sleep can last longer than it was asked for (a coarse timer), and the tries must still end
     * on time.
     */
    private static function sequenceWithin(Stream $stream, PanicPublishBudget $budget): bool
    {
        $window = (self::FLUSH_LOCK_TRIES - 1) * self::FLUSH_LOCK_GAP_SECONDS;
        $closes = hrtime(true) + (int) ($window * 1e9);

        for ($try = 1; $try <= self::FLUSH_LOCK_TRIES; $try++) {
            try {
                Sequencer::run($stream, Sequencer::BATCH, nowait: true);

                return true;
            } catch (StreamBusy) {
                $left = ($closes - hrtime(true)) / 1e9;

                if ($try === self::FLUSH_LOCK_TRIES || $left <= 0 || $budget->left() < PanicPublishBudget::FLOOR_SECONDS + self::FLUSH_LOCK_GAP_SECONDS) {
                    return false;
                }

                usleep((int) (min(self::FLUSH_LOCK_GAP_SECONDS, $left) * 1_000_000));
            }
        }

        return false;
    }

    private static function ring(Stream $stream): void
    {
        if (! app()->bound(Waker::class)) {
            return;
        }

        try {
            app(Waker::class)->poke($stream);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
