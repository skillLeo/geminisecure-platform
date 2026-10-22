<?php

declare(strict_types=1);

namespace App\Live\Relay;

use App\Live\Channels;
use App\Live\Stream;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;

/**
 * Gives committed live events their gap-free, monotonic seq (DESIGN.md §1.4, C6).
 *
 *     SET TRANSACTION ISOLATION LEVEL READ COMMITTED     -- this one transaction; no gap locks
 *     BEGIN
 *       SELECT head_seq FROM live_streams WHERE stream = ? FOR UPDATE           -- the ONE lock
 *       SELECT id, channels FROM live_events WHERE seq IS NULL AND <due> ORDER BY id LIMIT n
 *       for each row, in id order: seq := ++head; one delivery per channel, prev_seq exact
 *       UPDATE live_events SET seq, sequenced_at;  UPDATE live_streams SET head_seq
 *     COMMIT
 *
 * WHY NO EVENT CAN BE SKIPPED. A seq is given only to a row that has already committed: the read
 * of live_events is a plain one, which sees committed rows and never waits for an open
 * transaction. Sections run one at a time, because each holds the stream row's lock, and each
 * commits its seqs and deliveries together. So the committed seqs are always the contiguous prefix
 * 1..head, and a reader that has seen seq N can never later find a committed seq at or below N.
 * `prev_seq` is worked out inside the same serial section, so it is exact for each channel.
 *
 * WHICH ROWS ARE DUE. A row whose `available_at` has come (a scheduled row waits for it), and a
 * HELD row (available_at NULL) only after the 10-second backstop: that covers a process that died
 * between its commit and its LiveBus::release(). A backstopped row is stamped `available_at` as it
 * is sequenced, so the prune never mistakes it for one still held.
 *
 * WHERE A ROW IS DELIVERED. To the channels it names, each admitted by the rules LiveBus writes by:
 * canonical, of the stream's own estate (or, centrally, of the estate the row is about, or of the
 * platform), and on a stream §3.1 says carries it. A row that fails is delivered nowhere.
 *
 * One stream per call, on its own connection. An estate's stream is sequenced only while that
 * estate is open (the relay runs each one inside EstateScope), and never inside a transaction the
 * caller already holds: the isolation level applies to the next transaction only, and MySQL
 * refuses to change it inside one.
 */
final class Sequencer
{
    public const BATCH = 500;

    /** A held row older than this is treated as released (§1.7). */
    public const HELD_BACKSTOP_SECONDS = 10;

    /** MySQL's answer to FOR UPDATE NOWAIT when the row is locked (ER_LOCK_NOWAIT). */
    private const LOCK_NOWAIT = 3572;

    /**
     * How many rows the pass sequenced.
     *
     * `$nowait` is Relay::flushNow's: inside a panic's request the stream row is taken with
     * FOR UPDATE NOWAIT, so a pass the relay is running at that moment costs nothing to wait for;
     * StreamBusy says so, and the caller decides whether to try again inside its budget. Without it
     * the lock waits, as the relay's own passes do (they hold it for milliseconds).
     *
     * @throws StreamBusy with `$nowait`, when another pass holds the stream
     */
    public static function run(Stream $stream, int $limit = self::BATCH, bool $nowait = false): int
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The sequencer takes at least one row per pass.');
        }

        if ($stream->isEstate() && tenant()?->getTenantKey() !== $stream->tenantId) {
            throw new LogicException("The sequencer was asked for estate {$stream->tenantId}'s stream while that estate is not the one open. Run it inside the estate.");
        }

        $db = DB::connection($stream->connection());

        if ($db->transactionLevel() > 0) {
            throw new LogicException('The sequencer runs its own READ COMMITTED transaction and cannot run inside one that is already open.');
        }

        $db->unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

        try {
            return $db->transaction(static fn (): int => self::pass($db, $stream, $limit, $nowait));
        } catch (QueryException $e) {
            if ($nowait && (int) ($e->errorInfo[1] ?? 0) === self::LOCK_NOWAIT) {
                throw new StreamBusy("The {$stream->name()} stream is being sequenced by another pass.", 0, $e);
            }

            throw $e;
        }
    }

    private static function pass(Connection $db, Stream $stream, int $limit, bool $nowait): int
    {
        $row = $db->selectOne('SELECT head_seq FROM live_streams WHERE stream = ? FOR UPDATE'.($nowait ? ' NOWAIT' : ''), [$stream->name()]);

        if ($row === null) {
            throw new RuntimeException("live_streams has no '{$stream->name()}' row. The live log's migration writes it; this database was not built by it.");
        }

        $head = (int) $row->head_seq;

        $due = $db->select(
            'SELECT id, tenant_id, channels FROM live_events
              WHERE seq IS NULL
                AND ((available_at IS NOT NULL AND available_at <= UTC_TIMESTAMP(6))
                  OR (available_at IS NULL AND created_at <= UTC_TIMESTAMP(6) - INTERVAL '.self::HELD_BACKSTOP_SECONDS.' SECOND))
              ORDER BY id
              LIMIT '.$limit,
        );

        if ($due === []) {
            return 0;
        }

        $events = [];
        $names = [];

        foreach ($due as $event) {
            $channels = self::channelsOf($stream, (int) $event->id, $event->tenant_id === null ? null : (string) $event->tenant_id, (string) $event->channels);
            $events[] = [(int) $event->id, $channels];

            foreach ($channels as $channel) {
                $names[$channel] = true;
            }
        }

        $previous = self::lastSeqs($db, array_map(strval(...), array_keys($names)));

        $deliveries = [];
        $cases = [];
        $bindings = [];
        $ids = [];

        foreach ($events as [$id, $channels]) {
            $seq = ++$head;

            foreach ($channels as $channel) {
                $deliveries[] = [
                    'channel' => $channel,
                    'seq' => $seq,
                    'prev_seq' => $previous[$channel] ?? null,
                    'event_id' => $id,
                ];

                $previous[$channel] = $seq;
            }

            $cases[] = 'WHEN ? THEN ?';
            array_push($bindings, $id, $seq);
            $ids[] = $id;
        }

        foreach (array_chunk($deliveries, 500) as $chunk) {
            $db->table('live_deliveries')->insert($chunk);
        }

        $db->update(
            'UPDATE live_events
                SET seq = CASE id '.implode(' ', $cases).' END,
                    sequenced_at = UTC_TIMESTAMP(6),
                    available_at = COALESCE(available_at, UTC_TIMESTAMP(6))
              WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
            [...$bindings, ...$ids],
        );

        $db->update(
            'UPDATE live_streams SET head_seq = ?, updated_at = UTC_TIMESTAMP(6) WHERE stream = ?',
            [$head, $stream->name()],
        );

        return count($ids);
    }

    /**
     * The last seq delivered on each of these channels, from the table.
     *
     * @param  list<string>  $channels
     * @return array<string, int>
     */
    private static function lastSeqs(Connection $db, array $channels): array
    {
        $last = [];

        foreach (array_chunk($channels, 500) as $chunk) {
            $rows = $db->table('live_deliveries')
                ->select('channel')
                ->selectRaw('MAX(seq) AS last_seq')
                ->whereIn('channel', $chunk)
                ->groupBy('channel')
                ->get();

            foreach ($rows as $row) {
                $last[(string) $row->channel] = (int) $row->last_seq;
            }
        }

        return $last;
    }

    /**
     * A row's channels, admitted by the same rules LiveBus writes by (§1.3, §3.1).
     *
     * LiveBus refuses a mis-addressed event before a byte is written, but this is the one writer of
     * live_deliveries, so it does not take a row's word for its channels: a row written some other
     * way (a raw insert, a buggy migration) that names another estate's channel, a channel its
     * stream does not carry, or a name no builder makes would otherwise be published to people it
     * was never meant for. Each channel must be canonical; on an estate stream it must be that
     * estate's; on the central stream it must be a platform channel or one of the estate the row
     * is about (`tenant_id`); and the stream must be one §3.1 says carries it.
     *
     * A row that fails, like one whose channels cannot be read, is still given its seq, so the
     * stream stays contiguous behind it, and is delivered nowhere; it is reported rather than
     * allowed to stop every event after it. The report names the channel's family and estate,
     * never the channel, so no record id reaches the log.
     *
     * @return list<string>
     */
    private static function channelsOf(Stream $stream, int $id, ?string $rowTenant, string $json): array
    {
        try {
            $channels = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            report(new RuntimeException("live_events row {$id} has unreadable channels; it is sequenced and delivered nowhere.", 0, $e));

            return [];
        }

        if (! is_array($channels) || ! array_is_list($channels)) {
            report(new RuntimeException("live_events row {$id} holds no channel list; it is sequenced and delivered nowhere."));

            return [];
        }

        $names = [];
        $where = $stream->isEstate() ? "estate {$stream->tenantId}'s stream" : 'the central stream'.($rowTenant === null ? ' (a platform event)' : " (an event about estate {$rowTenant})");

        foreach ($channels as $channel) {
            try {
                if (! is_string($channel) || strlen($channel) > 160) {
                    throw new InvalidArgumentException('not a string of at most 160 characters');
                }

                $family = Channels::familyOf($channel);
                $tenant = Channels::tenantOf($channel);
            } catch (InvalidArgumentException) {
                report(new RuntimeException("live_events row {$id} names a channel that is not one App\\Live\\Channels can produce; it is sequenced and delivered nowhere."));

                return [];
            }

            $ownEstate = $stream->isEstate()
                ? $tenant === $stream->tenantId
                : $tenant === null || $tenant === $rowTenant;

            $whose = $tenant === null ? "a platform channel ({$family})" : "a channel of estate {$tenant} ({$family})";

            if (! $ownEstate) {
                report(new RuntimeException("live_events row {$id} on {$where} names {$whose}; it is sequenced and delivered nowhere, because an estate never receives another estate's events (DESIGN.md §1.3)."));

                return [];
            }

            if (! in_array($stream->code(), Channels::STREAMS[$family], true)) {
                report(new RuntimeException("live_events row {$id} on {$where} names {$whose}, which that stream does not carry (DESIGN.md §3.1); it is sequenced and delivered nowhere, because catch-up would never read it there."));

                return [];
            }

            $names[$channel] = true;
        }

        return array_map(strval(...), array_keys($names));
    }
}
