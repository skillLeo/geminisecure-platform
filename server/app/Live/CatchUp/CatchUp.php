<?php

declare(strict_types=1);

namespace App\Live\CatchUp;

use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\EstateScope;
use App\Live\Auth\Principal;
use App\Live\ChannelName;
use App\Live\Hydrators\Registry as Hydrators;
use App\Live\Relay\Publisher;
use App\Live\Stream;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * "EVERYTHING AFTER N": WHAT A SCREEN MISSED, FROM THE LOG ITSELF (DESIGN.md §5).
 *
 * A socket delivers what happens while it is connected; catch-up delivers everything else. Every
 * subscription starts with one, and so does every reconnect, every return from the background, every
 * gap a message's `p` reveals and every nudge. While the socket is down it is the fallback poll.
 * So it answers from the log, not from what was published: a row the relay has sequenced but not yet
 * sent, or gave up on, is a committed fact and is in the answer.
 *
 * NOTHING THE CLIENT SAYS IS TRUSTED. Every channel it names is decided again, for this principal,
 * by the same registry the socket's grants use; one it may not hold is answered `refused`, with no
 * events, the same answer for a channel that exists and one that does not.
 *
 * PER (CHANNEL, STREAM), from its cursor:
 *   - no cursor: the stream's head, and no events (the client takes the head and resyncs once);
 *   - a cursor below the floor (pruned), above the head, from another epoch (a rebuilt database),
 *     or more than 500 events behind: `reset`, with the heads, and the screen resyncs its data;
 *   - otherwise every delivery with `cursor < seq <= head`, in seq order, merged to the latest event
 *     per record and hydrated once (Merge), as the §2.2 envelope the socket would have sent.
 * THE HEAD IS READ FIRST, so an event sequenced during the answer is never skipped: it has seq > head,
 * and the next catch-up or the socket brings it.
 *
 * Bounded: 200 channels a request, 500 events per (channel, stream), 2,000 in all. A channel cut
 * short by the total says `more`, and its client asks again from where it got to.
 *
 * An estate's stream is read inside that estate (EstateScope). An estate that cannot be opened is
 * left out of the answer for its channels, which the client counts as a failed catch-up and asks
 * again, rather than being answered with nothing as if nothing had happened.
 */
final class CatchUp
{
    public const MAX_CHANNELS = 200;

    public const PER_CHANNEL = 500;

    public const TOTAL = 2000;

    /** `relay.ok` is false once a row due for publishing has waited this long (§5.1). */
    public const RELAY_BEHIND_MS = 5000;

    /** Deliveries read per statement, as OR'd (channel, seq range) conditions. */
    private const CHANNELS_PER_READ = 50;

    public function __construct(private readonly ChannelRegistry $registry) {}

    /**
     * @param  list<array{name: string, c: int|null, e: int|null, ep: array<string, string>}>  $asked
     * @return array{channels: list<array<string, mixed>>, relay: array{ok: bool, lag_ms: int}, build: string|null}
     */
    public function answer(Principal $principal, array $asked): array
    {
        /** @var array<string, array{name: string, c: int|null, e: int|null, ep: array<string, string>}> $byName */
        $byName = [];

        foreach (array_slice($asked, 0, self::MAX_CHANNELS) as $entry) {
            $byName[$entry['name']] ??= $entry;
        }

        $allowed = $this->registry->decideMany($principal, array_keys($byName));

        $central = [];
        $estates = [];
        $refused = [];

        foreach ($byName as $name => $entry) {
            $channel = ($allowed[$name] ?? false) ? ChannelName::parse($name) : null;

            if ($channel === null) {
                $refused[$name] = true;

                continue;
            }

            foreach ($channel->streams() as $stream) {
                if ($stream === 'c') {
                    $central[$name] = $entry['c'];
                } elseif ($channel->tenant !== null) {
                    $estates[$channel->tenant][$name] = $entry['e'];
                }
            }
        }

        $budget = self::TOTAL;
        $reads = ['c' => [], 'e' => []];
        $lagMs = 0;

        if ($central !== []) {
            [$reads['c'], $lag] = $this->read(Stream::central(), $central, $byName, $budget);
            $lagMs = max($lagMs, $lag);
        }

        foreach ($estates as $tenantId => $cursors) {
            try {
                [$read, $lag] = EstateScope::run((string) $tenantId, fn (): array => $this->read(Stream::estate((string) $tenantId), $cursors, $byName, $budget));
                $reads['e'] += $read;
                $lagMs = max($lagMs, $lag);
            } catch (Throwable $e) {
                // Left unanswered, which the client treats as a failed catch-up and asks again.
                report($e);
            }
        }

        $channels = [];

        foreach ($byName as $name => $entry) {
            if (isset($refused[$name])) {
                $channels[] = ['name' => $name, 'status' => 'refused'];

                continue;
            }

            $answer = self::compose($name, $reads);

            if ($answer !== null) {
                $channels[] = $answer;
            }
        }

        $build = config('live.build');

        return [
            'channels' => $channels,
            'relay' => ['ok' => $lagMs <= self::RELAY_BEHIND_MS, 'lag_ms' => $lagMs],
            'build' => is_string($build) && $build !== '' ? $build : null,
        ];
    }

    /**
     * One channel's answer from its streams' reads, or null when one of them could not be read.
     *
     * @param  array{c: array<string, array<string, mixed>>, e: array<string, array<string, mixed>>}  $reads
     * @return array<string, mixed>|null
     */
    private static function compose(string $name, array $reads): ?array
    {
        $channel = ChannelName::parse($name);

        if ($channel === null) {
            return null;
        }

        $parts = [];

        foreach ($channel->streams() as $stream) {
            $part = $reads[$stream][$name] ?? null;

            if ($part === null) {
                return null;
            }

            $parts[$stream] = $part;
        }

        $head = array_map(static fn (array $part): int => $part['head'], $parts);
        $ep = array_map(static fn (array $part): string => $part['ep'], $parts);

        foreach ($parts as $part) {
            if ($part['status'] === 'reset') {
                return ['name' => $name, 'status' => 'reset', 'head' => $head, 'ep' => $ep];
            }
        }

        $events = [];
        $more = false;

        foreach ($parts as $part) {
            array_push($events, ...$part['events']);
            $more = $more || $part['more'];
        }

        return ['name' => $name, 'status' => 'ok', 'events' => $events, 'head' => $head, 'ep' => $ep, 'more' => $more];
    }

    /**
     * Read one stream for every channel that follows it. The stream must be open.
     *
     * @param  array<string, int|null>  $cursors  channel => the client's cursor on this stream
     * @param  array<string, array{name: string, c: int|null, e: int|null, ep: array<string, string>}>  $asked
     * @return array{0: array<string, array{status: string, head: int, ep: string, events: list<array<string, mixed>>, more: bool}>, 1: int}
     */
    private function read(Stream $stream, array $cursors, array $asked, int &$budget): array
    {
        $db = DB::connection($stream->connection());

        // THE HEAD FIRST: nothing sequenced after this read is skipped, it is simply the next answer's.
        $row = $db->selectOne('SELECT head_seq, floor_seq, epoch FROM live_streams WHERE stream = ?', [$stream->name()]);

        if ($row === null) {
            return [[], 0];
        }

        $head = (int) $row->head_seq;
        $floor = (int) $row->floor_seq;
        $epoch = (string) $row->epoch;
        $ep = substr($epoch, 0, 8);
        $code = $stream->code();

        $out = [];
        $toRead = [];

        foreach ($cursors as $name => $cursor) {
            $clientEpoch = $asked[$name]['ep'][$code] ?? null;
            $base = ['head' => $head, 'ep' => $ep, 'events' => [], 'more' => false];

            if ($cursor === null) {
                $out[$name] = ['status' => 'ok'] + $base;
            } elseif (($clientEpoch !== null && $clientEpoch !== $ep) || $cursor < $floor || $cursor > $head) {
                $out[$name] = ['status' => 'reset'] + $base;
            } elseif ($cursor === $head) {
                $out[$name] = ['status' => 'ok'] + $base;
            } else {
                $toRead[$name] = $cursor;
            }
        }

        $deliveries = $this->deliveries($db, $toRead, $head);
        $kept = [];

        foreach ($toRead as $name => $cursor) {
            $mine = $deliveries[$name] ?? [];

            if (count($mine) > self::PER_CHANNEL) {
                $out[$name] = ['status' => 'reset', 'head' => $head, 'ep' => $ep, 'events' => [], 'more' => false];

                continue;
            }

            $take = min(count($mine), $budget);
            $budget -= $take;
            $kept[$name] = ['deliveries' => array_slice($mine, 0, $take), 'more' => $take < count($mine)];
        }

        $events = $this->events($db, $kept);
        $merged = [];

        foreach ($kept as $name => $slice) {
            $rows = [];

            foreach ($slice['deliveries'] as $delivery) {
                $event = $events[(int) $delivery->event_id] ?? null;

                if ($event !== null) {
                    $rows[] = (object) ['delivery' => $delivery, 'entity' => $event->entity, 'entity_id' => $event->entity_id, 'seq' => (int) $delivery->seq, 'event' => $event];
                }
            }

            $merged[$name] = Merge::latestPerRecord($rows);
        }

        $hydrated = Hydrators::hydrate(array_map(
            static fn (stdClass $item): stdClass => $item->event,
            array_merge([], ...array_values($merged)),
        ));

        foreach ($merged as $name => $rows) {
            $envelopes = [];
            $unhydrated = false;

            foreach ($rows as $item) {
                $event = $item->event;
                $inline = $event->mode === 'inline';

                if ($inline && ! array_key_exists((string) $event->hydrate, $hydrated)) {
                    $unhydrated = true;

                    break;
                }

                $envelopes[] = Publisher::envelope(
                    $stream,
                    $epoch,
                    $event,
                    (int) $item->delivery->seq,
                    $item->delivery->prev_seq === null ? null : (int) $item->delivery->prev_seq,
                    $inline,
                    $inline ? ($hydrated[(string) $event->hydrate][(string) $event->entity_id] ?? null) : null,
                );
            }

            // Data that cannot be read now is never sent as nothing: the screen resyncs from its own endpoints.
            $out[$name] = $unhydrated
                ? ['status' => 'reset', 'head' => $head, 'ep' => $ep, 'events' => [], 'more' => false]
                : ['status' => 'ok', 'head' => $head, 'ep' => $ep, 'events' => $envelopes, 'more' => $kept[$name]['more']];
        }

        return [$out, $this->lag($db)];
    }

    /**
     * Up to PER_CHANNEL + 1 deliveries per channel in (cursor, head], in seq order: the one extra
     * is how "more than 500 behind" is known.
     *
     * @param  array<string, int>  $cursors
     * @return array<string, list<stdClass>>
     */
    private function deliveries(Connection $db, array $cursors, int $head): array
    {
        $out = [];

        foreach (array_chunk($cursors, self::CHANNELS_PER_READ, true) as $chunk) {
            $conditions = [];
            $bindings = [];

            foreach ($chunk as $name => $cursor) {
                $conditions[] = '(d.channel = ? AND d.seq > ?)';
                array_push($bindings, $name, $cursor);
            }

            $rows = $db->select(
                'SELECT channel, seq, prev_seq, event_id FROM (
                    SELECT d.channel, d.seq, d.prev_seq, d.event_id,
                           ROW_NUMBER() OVER (PARTITION BY d.channel ORDER BY d.seq) AS n
                      FROM live_deliveries d
                     WHERE d.seq <= ? AND ('.implode(' OR ', $conditions).')
                ) AS due
                WHERE n <= ?
                ORDER BY channel, seq',
                [$head, ...$bindings, self::PER_CHANNEL + 1],
            );

            foreach ($rows as $row) {
                $out[(string) $row->channel][] = $row;
            }
        }

        return $out;
    }

    /**
     * The event rows behind the deliveries kept, by id.
     *
     * @param  array<string, array{deliveries: list<stdClass>, more: bool}>  $kept
     * @return array<int, stdClass>
     */
    private function events(Connection $db, array $kept): array
    {
        $ids = [];

        foreach ($kept as $slice) {
            foreach ($slice['deliveries'] as $delivery) {
                $ids[(int) $delivery->event_id] = true;
            }
        }

        $events = [];

        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            $rows = $db->table('live_events')
                ->select(['id', 'seq', 'type', 'entity', 'entity_id', 'mode', 'hydrate', 'summary', 'origin', 'simulated', 'created_at'])
                ->whereIn('id', $chunk)
                ->get();

            foreach ($rows as $row) {
                $events[(int) $row->id] = $row;
            }
        }

        return $events;
    }

    /** How long, in ms, the oldest row due for publishing has waited on this stream. */
    private function lag(Connection $db): int
    {
        $micros = $db->selectOne(
            'SELECT TIMESTAMPDIFF(MICROSECOND, MIN(available_at), UTC_TIMESTAMP(6)) AS micros
               FROM live_events
              WHERE published_at IS NULL AND publish_failed_at IS NULL
                AND available_at IS NOT NULL AND available_at <= UTC_TIMESTAMP(6)',
        )?->micros;

        return $micros === null ? 0 : max(0, intdiv((int) $micros, 1000));
    }
}
