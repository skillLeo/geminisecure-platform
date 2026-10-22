<?php

declare(strict_types=1);

namespace App\Live;

use App\Live\Relay\Signaller;
use App\Live\Relay\Waker;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;
use Throwable;
use WeakMap;

/**
 * THE ONE WAY ANYTHING BECOMES LIVE (M11 Part C, DESIGN.md §1.3).
 *
 * Call it AFTER the write it describes, INSIDE that write's transaction, while the row lock is held:
 *
 *     DB::transaction(function () use ($alert) {
 *         $alert->update([...]);
 *         LiveBus::record(AlertLive::resolved($alert));
 *     });
 *
 * It writes one `live_events` row per audience to the stream's own database (the outbox). A
 * rollback therefore broadcasts nothing, and a commit cannot be lost between two systems, because
 * the relay's work list is this log itself. Two changes to one record are sequenced in commit
 * order, since the second waits for the first's row lock before it can record.
 *
 * WHAT IT CHECKS, IN ORDER, BEFORE IT WRITES A THING:
 *   muted          inside Live::muted() it writes nothing;
 *   tenancy        an estate stream's event is recorded only while THAT estate is open, because the
 *                  `tenant` connection is whichever estate is open;
 *   transaction    an open transaction on the stream's own connection;
 *   channels       every name is one App\Live\Channels can produce, and every tenant segment is the
 *                  stream's own: an estate event names only its own estate's channels (so it never
 *                  reaches `user.{u}` directly), and a central event about estate t names only t's
 *                  channels and platform ones. THIS IS "AN ESTATE NEVER RECEIVES ANOTHER ESTATE'S
 *                  EVENTS", ENFORCED WHEN THE EVENT IS WRITTEN. An act that concerns two tenants
 *                  records one event per tenant, with recordAll();
 *   audiences      a channel is named by one audience of an event, not two; inline names its
 *                  hydrator and ref does not;
 *   summary        SummaryPolicy, and 1 KB;
 *   urgent         only `alert.raised`, only on the central stream (it is what Relay::flushNow
 *                  sends ahead of the panic fan-out, and nothing else may spend that budget).
 * Every event of a recordAll() is checked before any of them is written.
 *
 * IN LOCAL AND TESTING A BROKEN RULE THROWS. IN PRODUCTION NOTHING HERE MAY BREAK THE CHANGE IT
 * DESCRIBES: a panic that cannot be recorded because its event was mis-addressed is the one
 * outcome worse than a screen that does not move. So in production:
 *   - an event recorded outside a transaction is inserted anyway (autocommit) and reported, so the
 *     live update is not lost for the sake of the assertion (§1.3 step 2);
 *   - an event that breaks any other rule, or whose own methods throw, is NOT written, and is
 *     reported. Isolation holds: nothing mis-addressed reaches the log;
 *   - a live table that is missing (an estate whose migration has not run) is reported and the
 *     event dropped, since MySQL leaves the transaction intact after that error;
 *   - every other database error is the change's own and is rethrown untouched: a deadlock has
 *     already rolled the transaction back, and swallowing it would be a lie.
 *
 * THEN, AFTER THE COMMIT, IT WAKES THE RELAY. The poke is registered on the stream connection's own
 * innermost transaction, so a rollback never pokes and a commit of some other connection's
 * transaction never pokes early. A failing poke is reported and never thrown: the change has
 * committed, and the relay's sweep reads the log whether it was woken or not.
 *
 * THE LOG'S CLOCK IS THE DATABASE'S, IN UTC. `created_at` and `available_at` are UTC_TIMESTAMP(6),
 * and a scheduled event is written as an offset from it, so the sequencer, the backstop and the
 * prune all compare one clock with itself (the migration's note says why never NOW(6)).
 */
final class LiveBus
{
    /** The one type that may be urgent (§1.6). */
    public const URGENT_TYPE = 'alert.raised';

    private const TYPE_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    private const ENTITY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    private const ENTITY_ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    private const HYDRATE_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /** @var WeakMap<LiveEvent, list<int>>|null */
    private static ?WeakMap $written = null;

    public static function record(LiveEvent $event): void
    {
        self::recordAll([$event]);
    }

    /**
     * Several events from one act, in order: one per tenant where the act concerns two (§1.3).
     *
     * @param  list<LiveEvent>  $events
     */
    public static function recordAll(array $events): void
    {
        if (Live::isMuted()) {
            return;
        }

        $plans = [];

        foreach ($events as $event) {
            $plan = self::plan($event);

            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        foreach ($plans as $plan) {
            self::write($plan);
        }
    }

    /**
     * Release events recorded HELD (§1.7): called straight after the permission-cache flush, so the
     * relay can never publish an access change before the cache that decides it has moved.
     *
     * Only rows that are still held and unsequenced are touched; anything else is left alone.
     *
     * @param  list<int>  $eventIds  from LiveBus::idsOf()
     */
    public static function release(array $eventIds, Stream $stream): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $eventIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        if ($stream->isEstate() && tenant()?->getTenantKey() !== $stream->tenantId) {
            self::refuse(new LogicException("LiveBus::release() for estate {$stream->tenantId}'s stream was called while that estate is not the one open."));

            return;
        }

        $connection = DB::connection($stream->connection());

        $released = $connection->table('live_events')
            ->whereIn('id', $ids)
            ->whereNull('seq')
            ->whereNull('available_at')
            ->update(['available_at' => $connection->raw('UTC_TIMESTAMP(6)')]);

        if ($released > 0) {
            self::afterCommit($connection, static fn () => self::poke($stream));
        }
    }

    /**
     * The `live_events` ids written for one event by this process: what a held event's release
     * needs (§1.7). Empty for an event that was muted, dropped or never recorded.
     *
     * @return list<int>
     */
    public static function idsOf(LiveEvent $event): array
    {
        return self::written()[$event] ?? [];
    }

    /**
     * An ephemeral signal: never logged, no seq, handed to the publisher after the innermost open
     * transaction commits (or at once, outside one). Turnout only (§2.11 K13): `{ballot_id}`.
     *
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $data
     */
    public static function signal(array $channels, string $type, array $data): void
    {
        if (Live::isMuted()) {
            return;
        }

        try {
            if (preg_match(self::TYPE_PATTERN, $type) !== 1 || strlen($type) > 64) {
                throw new LogicException("'{$type}' is not a live event type: {entity}.{verb}, lowercase.");
            }

            $unique = [];
            $tenants = [];

            foreach ($channels as $channel) {
                $tenants[Channels::tenantOf($channel) ?? ''] = true;
                $unique[$channel] = true;
            }

            if ($unique === []) {
                throw new LogicException("The signal {$type} names no channel.");
            }

            if (count($tenants) > 1) {
                throw new LogicException("The signal {$type} names channels of more than one estate (".implode(', ', array_keys($unique)).'). A signal goes to one estate, or to the platform.');
            }

            SummaryPolicy::assert($type, $data);
        } catch (Throwable $e) {
            self::refuse($e);

            return;
        }

        $names = array_map(strval(...), array_keys($unique));

        $send = static function () use ($names, $type, $data): void {
            if (! app()->bound(Signaller::class)) {
                return;
            }

            try {
                app(Signaller::class)->signal($names, $type, $data);
            } catch (Throwable $e) {
                report($e);
            }
        };

        app('db.transactions')->addCallback($send);
    }

    /**
     * Everything one event will write, checked; or null when production drops it.
     *
     * @return array{event: LiveEvent, stream: Stream, connection: string, inTransaction: bool, available: string|null, rows: list<array<string, mixed>>}|null
     */
    private static function plan(LiveEvent $event): ?array
    {
        try {
            $stream = $event->stream();
            $type = $event->type();

            if ($stream->isEstate()) {
                $open = tenant()?->getTenantKey();

                if ($open !== $stream->tenantId) {
                    throw new LogicException("{$type} belongs to estate {$stream->tenantId}'s own stream, but tenancy "
                        .($open === null ? 'is not initialised' : "is open for estate {$open}")
                        .'. An estate event is recorded inside that estate, in its own database (DESIGN.md §1.1).');
                }
            }

            $connection = DB::connection($stream->connection());
            $inTransaction = $connection->transactionLevel() > 0;

            if (! $inTransaction && self::strict()) {
                throw new LogicException("LiveBus recorded {$type} outside a transaction on the '{$stream->connection()}' connection. Record an event inside the transaction of the change it describes, after the write (DESIGN.md §1.3).");
            }

            $entity = $event->entity();
            $entityId = $event->entityId();

            if (preg_match(self::TYPE_PATTERN, $type) !== 1 || strlen($type) > 64) {
                throw new LogicException("'{$type}' is not a live event type: {entity}.{verb}, lowercase, at most 64 characters.");
            }

            if (preg_match(self::ENTITY_PATTERN, $entity) !== 1) {
                throw new LogicException("'{$entity}' is not a live entity name ({$type}).");
            }

            if (preg_match(self::ENTITY_ID_PATTERN, $entityId) !== 1) {
                throw new LogicException("'{$entityId}' is not a live entity id ({$type}).");
            }

            if ($event->urgent() && ($type !== self::URGENT_TYPE || ! $stream->isCentral())) {
                throw new LogicException("{$type} is marked urgent. Only ".self::URGENT_TYPE.' on the central stream is: urgent rows are what Relay::flushNow sends ahead of the panic fan-out (DESIGN.md §1.6).');
            }

            $rows = [];
            $claimed = [];
            $withheld = [];

            foreach ($event->audiences() as $audience) {
                $channels = self::channels($stream, $type, $audience->channels, $claimed, $withheld);

                if ($channels === []) {
                    continue;
                }

                if (! in_array($audience->mode, ['ref', 'inline'], true)) {
                    throw new LogicException("{$type} has an audience of mode '{$audience->mode}'; the mode is ref or inline.");
                }

                if ($audience->mode === 'inline' && ($audience->hydrate === null || preg_match(self::HYDRATE_PATTERN, $audience->hydrate) !== 1 || strlen($audience->hydrate) > 40)) {
                    throw new LogicException("{$type} has an inline audience with no valid hydrator key; inline data is hydrated when published and never stored (DESIGN.md §4.1).");
                }

                if ($audience->mode === 'ref' && $audience->hydrate !== null) {
                    throw new LogicException("{$type} has a ref audience that names the hydrator '{$audience->hydrate}'; only an inline audience is hydrated.");
                }

                SummaryPolicy::assert($type, $audience->summary);

                $rows[] = [
                    'tenant_id' => $stream->tenantId,
                    'type' => $type,
                    'entity' => $entity,
                    'entity_id' => $entityId,
                    'mode' => $audience->mode,
                    'hydrate' => $audience->hydrate,
                    'summary' => $audience->summary === [] ? null : SummaryPolicy::encode($audience->summary),
                    'channels' => json_encode($channels, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'urgent' => $event->urgent(),
                    'simulated' => $event->simulated(),
                ];
            }

            if ($withheld !== []) {
                // Thrown in local and testing. In production the rest of the event is written,
                // exactly as gemini() would have addressed it, and the mistake is reported.
                self::refuse(new LogicException("{$type} is about the demonstration estate {$stream->tenantId} and names "
                    .implode(', ', array_keys($withheld)).'. No Gemini feed carries the demonstration estate: the fan-in reaches every control room and nobody may subscribe to its per-estate copies. Name the Gemini audience with Channels::gemini(), which leaves it out (DESIGN.md §2.1).'));
            }

            $available = self::availability($event);
        } catch (QueryException|PDOException $e) {
            // The change's own database failing is the change's own business.
            throw $e;
        } catch (Throwable $e) {
            self::refuse($e);

            return null;
        }

        return [
            'event' => $event,
            'stream' => $stream,
            'connection' => $stream->connection(),
            'inTransaction' => $inTransaction,
            'available' => $available,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{event: LiveEvent, stream: Stream, connection: string, inTransaction: bool, available: string|null, rows: list<array<string, mixed>>}  $plan
     */
    private static function write(array $plan): void
    {
        if ($plan['rows'] === []) {
            self::written()[$plan['event']] = [];

            return;
        }

        $connection = DB::connection($plan['connection']);
        $origin = Live::origin();
        $ids = [];

        foreach ($plan['rows'] as $row) {
            $row['origin'] = $origin;
            $row['available_at'] = $plan['available'] === null ? null : $connection->raw($plan['available']);
            $row['created_at'] = $connection->raw('UTC_TIMESTAMP(6)');

            try {
                $ids[] = (int) $connection->table('live_events')->insertGetId($row);
            } catch (QueryException $e) {
                if (! self::strict() && self::isMissingTable($e)) {
                    report($e);

                    return;
                }

                throw $e;
            }
        }

        self::written()[$plan['event']] = $ids;

        if (! $plan['inTransaction']) {
            report(new LogicException('LiveBus recorded '.$plan['rows'][0]['type'].' outside a transaction on the \''.$plan['connection'].'\' connection. It was written on its own commit so the update is not lost; the change it describes may not have been (DESIGN.md §1.3).'));
        }

        $stream = $plan['stream'];

        self::afterCommit($connection, static fn () => self::poke($stream));
    }

    /**
     * One audience's channels: each checked and placed, deduplicated in order.
     *
     * A channel is admitted when it is a name App\Live\Channels can produce, its tenant segment is
     * the stream's own (or none, on the central stream), the stream is one §3.1 says carries it,
     * and it is no Gemini channel of an event about the demonstration estate. That last one is
     * collected in `$withheld` rather than thrown here, so production can still write the rest.
     *
     * @param  array<array-key, mixed>  $channels
     * @param  array<string, true>  $claimed  the channels earlier audiences of this event named
     * @param  array<string, true>  $withheld  Gemini channels of a demonstration-estate event
     * @return list<string>
     */
    private static function channels(Stream $stream, string $type, array $channels, array &$claimed, array &$withheld): array
    {
        $unique = [];

        foreach ($channels as $channel) {
            if (! is_string($channel)) {
                throw new LogicException("{$type} names a channel that is not a string.");
            }

            $tenant = Channels::tenantOf($channel);

            if ($stream->isEstate() && $tenant !== $stream->tenantId) {
                throw new LogicException("{$type} is an event of estate {$stream->tenantId}'s own stream and names {$channel}, "
                    .($tenant === null ? 'a platform channel' : "estate {$tenant}'s channel")
                    .'. An estate event names only its own estate\'s channels; an estate write that must reach a user records a central event after its commit (DESIGN.md §1.3).');
            }

            if ($stream->isCentral() && $tenant !== null && $tenant !== $stream->tenantId) {
                throw new LogicException($stream->tenantId === null
                    ? "{$type} is a platform event and names {$channel}, estate {$tenant}'s channel. An event about an estate says which estate: Stream::central('{$tenant}') (DESIGN.md §1.3)."
                    : "{$type} is a central event about estate {$stream->tenantId} and names {$channel}, estate {$tenant}'s channel. An act that concerns two estates records one event per estate, with LiveBus::recordAll() (DESIGN.md §1.3).");
            }

            if (! in_array($stream->code(), Channels::streamsOf($channel), true)) {
                throw new LogicException($stream->isEstate()
                    ? "{$type} is an event of estate {$stream->tenantId}'s own stream and names {$channel}, a channel only the central stream carries (DESIGN.md §3.1). Catch-up reads it from the central log alone, so an event written here would be sent live once and never recovered after a drop (C6). Record it on Stream::central('{$stream->tenantId}')."
                    : "{$type} is a central event and names {$channel}, a channel only estate {$tenant}'s own stream carries (DESIGN.md §3.1). Catch-up reads it from the estate's log alone, so an event written here would be sent live once and never recovered after a drop (C6). Record it on Stream::estate('{$tenant}'), inside the estate; where the act commits centrally, from the central transaction's afterCommit (DESIGN.md §2.9).");
            }

            if ($stream->isCentral() && $stream->tenantId !== null && str_starts_with($channel, 'gemini.') && Channels::isDemonstrationEstate($stream->tenantId)) {
                $withheld[$channel] = true;

                continue;
            }

            if (isset($unique[$channel])) {
                continue;
            }

            if (isset($claimed[$channel])) {
                throw new LogicException("{$type} names {$channel} in two of its audiences. A channel hears an event once, in one mode.");
            }

            $unique[$channel] = true;
        }

        $names = array_map(strval(...), array_keys($unique));

        foreach ($names as $name) {
            $claimed[$name] = true;
        }

        return $names;
    }

    /** The SQL for `available_at`: now, an offset from now, or null when held. */
    private static function availability(LiveEvent $event): ?string
    {
        if ($event->held()) {
            return null;
        }

        $at = $event->availableAt();

        if ($at === null) {
            return 'UTC_TIMESTAMP(6)';
        }

        $now = Carbon::now();
        $micros = ($at->getTimestamp() - $now->getTimestamp()) * 1_000_000 + ((int) $at->format('u') - (int) $now->format('u'));

        return $micros > 0 ? 'UTC_TIMESTAMP(6) + INTERVAL '.$micros.' MICROSECOND' : 'UTC_TIMESTAMP(6)';
    }

    /**
     * Run `$callback` after the innermost open transaction ON THIS CONNECTION commits, or now if
     * none is open. Laravel's own afterCommit attaches to the newest transaction of ANY connection,
     * which could poke before this one commits and leave an estate event waiting for the sweep.
     */
    private static function afterCommit(Connection $connection, Closure $callback): void
    {
        $manager = app()->bound('db.transactions') ? app('db.transactions') : null;

        if ($manager instanceof DatabaseTransactionsManager) {
            $name = $connection->getName();

            $record = $manager->callbackApplicableTransactions()
                ->last(static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $name);

            if ($record instanceof DatabaseTransactionRecord) {
                $record->addCallback($callback);

                return;
            }
        }

        $callback();
    }

    private static function poke(Stream $stream): void
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

    /** Throw in local and testing; report and carry on in production. */
    private static function refuse(Throwable $e): void
    {
        if (self::strict()) {
            throw $e;
        }

        report($e);
    }

    private static function strict(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private static function isMissingTable(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '42S02' || (string) $e->getCode() === '42S02';
    }

    /** @return WeakMap<LiveEvent, list<int>> */
    private static function written(): WeakMap
    {
        /** @var WeakMap<LiveEvent, list<int>> $written */
        $written = self::$written ??= new WeakMap;

        return $written;
    }
}
