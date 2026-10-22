<?php

declare(strict_types=1);

namespace App\Live\Relay;

use App\Live\Access\Evictor;
use App\Live\ChannelName;
use App\Live\Hydrators\Registry as Hydrators;
use App\Live\Stream;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Publishes what the sequencer has numbered, in seq order, to Reverb (DESIGN.md §1.5, §2.2, §4.1).
 *
 * ONE PASS, per stream, in that stream's scope (the relay opens the estate first):
 *   1  read up to 200 rows `seq IS NOT NULL AND published_at IS NULL AND publish_failed_at IS NULL`,
 *      oldest seq first, and their deliveries (one per channel, with that channel's `prev_seq`);
 *   2  hydrate the inline ones: ONE call per hydrator key for every id the pass holds, so a burst
 *      of a hundred alerts is one query and not a hundred. The data goes into the envelope's `d`
 *      and nowhere else; the stored row keeps only its safe summary;
 *   3  send each (event x channel) as a batch item, event `live` on `private-live.<channel>`, data
 *      the envelope, in batches of at most 9,000 bytes (Reverb takes 10,000 in all);
 *   4  mark what Reverb took `published_at`.
 *
 * WHEN REVERB IS DOWN (no answer, a timeout, a 5xx): the breaker opens for 10 s and the pass stops.
 * The rows stay unpublished and are sent whole when Reverb answers; a client that already had some
 * of them drops the duplicates by (stream, seq). Sequencing carries on meanwhile, so catch-up keeps
 * answering. A long outage never gives a row up: only refusals count towards that.
 *
 * WHEN REVERB REFUSES (4xx): a batch of several events is re-sent one event at a time, so one bad
 * message never holds up the good ones beside it. The refused event rests (0.5, 1, 2, 5 s, then
 * every 5 s) while later seqs, on its channels too, are published past it: a client sees the gap
 * (`p` > its cursor) and fetches the missing one from catch-up, which reads the log, not the
 * socket. After the fifth refusal it is given up: `publish_failed_at` is set and, because it may
 * have been its channel's LAST event with nothing after it to reveal the gap, an ephemeral
 * `live.nudge` (q null, no data) goes to each of its channels, and every client there catches up.
 * An envelope too big for any batch can never be sent, and is given up at once the same way.
 *
 * The refusal count and the rests live in this object, which in the relay lives as long as the
 * process; `publish_attempts` counts every try, outages included, for `live:health` to show.
 *
 * AFTER AN `access.*` EVENT IS PUBLISHED, the evictor closes that principal's sockets 250 ms later
 * (§7.1). A request's own flush (Relay::flushNow) passes `evicts: false` and leaves access events
 * to the relay, the only process still running 250 ms later.
 *
 * It is also the relay's sender of the pulse (`live-pulse`) and of ephemeral signals (§1.3).
 */
final class Publisher implements Signaller
{
    /** The one event name every live message is sent under (§2.2). */
    public const EVENT = 'live';

    public const PULSE_EVENT = 'pulse';

    public const NUDGE = 'live.nudge';

    /** Reverb's max_request_size is 10,000 bytes; this leaves room for the request line and headers. */
    public const BATCH_BYTES = 9000;

    /** §4.1: an envelope is meant to stay under this. One that does not is reported, and still sent if it fits a batch. */
    public const ENVELOPE_BYTES = 4096;

    public const PASS_LIMIT = 200;

    /** `{"batch":[` and `]}`. */
    private const WRAPPER_BYTES = 12;

    /** The most `publish_attempts` (SMALLINT UNSIGNED) holds; a week-long outage must not overflow it. */
    private const MAX_ATTEMPTS = 65535;

    /** @var array<string, array<int, float>> stream key => event id => the clock time it may be tried again */
    private array $resting = [];

    /** @var array<string, array<int, int>> stream key => event id => refusals so far */
    private array $refusals = [];

    public function __construct(
        private readonly Transport $transport,
        private readonly Breaker $breaker,
        private readonly Evictor $evictor,
        private readonly Clock $clock,
    ) {}

    /**
     * One publish pass over `$stream`, which must be open (the estate initialised for an estate's).
     *
     * `$urgentOnly` publishes only urgent rows (`alert.raised`, §1.6). With a `$budget` every send
     * is bounded by what it has left (connect min(0.5, left), total left) and the pass stops when
     * it no longer allows a publish. `$evicts: false` leaves `access.*` rows for the relay.
     */
    public function run(Stream $stream, bool $urgentOnly = false, ?PanicPublishBudget $budget = null, bool $evicts = true): PublishOutcome
    {
        if ($this->breaker->isOpen()) {
            return new PublishOutcome(outage: true);
        }

        if ($budget !== null && ! $budget->allowsPublish()) {
            return new PublishOutcome(outage: $budget->failed());
        }

        $db = DB::connection($stream->connection());
        $key = $stream->key();
        $resting = $this->restingIds($key);

        $query = $db->table('live_events')
            ->select(['id', 'seq', 'type', 'entity', 'entity_id', 'mode', 'hydrate', 'summary', 'origin', 'simulated', 'created_at'])
            ->whereNotNull('seq')
            ->whereNull('published_at')
            ->whereNull('publish_failed_at');

        if ($urgentOnly) {
            $query->where('urgent', true);
        }

        if (! $evicts) {
            $query->where('type', 'not like', 'access.%');
        }

        if ($resting !== []) {
            $query->whereNotIn('id', $resting);
        }

        /** @var Collection<int, stdClass> $rows */
        $rows = $query->orderBy('seq')->limit(self::PASS_LIMIT)->get();

        if ($rows->isEmpty()) {
            return new PublishOutcome;
        }

        $epoch = (string) $db->table('live_streams')->where('stream', $stream->name())->value('epoch');

        $deliveries = $db->table('live_deliveries')
            ->select(['channel', 'seq', 'prev_seq'])
            ->whereIn('seq', $rows->pluck('seq')->map(static fn ($seq): int => (int) $seq)->all())
            ->orderBy('seq')
            ->orderBy('channel')
            ->get()
            ->groupBy(static fn (stdClass $delivery): string => (string) $delivery->seq);

        $hydrated = $this->hydrate($rows, $deliveries);

        /** @var array<int, array{row: stdClass, items: list<array{name: string, channel: string, data: string}>, bytes: int, channels: list<string>}> $plans */
        $plans = [];
        $nowhere = [];
        $unsendable = [];
        $unhydrated = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            /** @var Collection<int, stdClass> $mine */
            $mine = $deliveries->get((string) $row->seq) ?? collect();

            if ($mine->isEmpty()) {
                // Sequenced and delivered nowhere (the sequencer refused its channels): nothing to send.
                $nowhere[] = $id;

                continue;
            }

            $inline = $row->mode === 'inline';

            if ($inline && ! array_key_exists((string) $row->hydrate, $hydrated)) {
                $unhydrated[] = $id;

                continue;
            }

            $data = $inline ? ($hydrated[(string) $row->hydrate][(string) $row->entity_id] ?? null) : null;
            $plan = $this->plan($stream, $epoch, $row, $mine, $inline, $data);

            if ($plan === null) {
                $unsendable[] = $id;

                continue;
            }

            $plans[$id] = $plan;
        }

        $this->markPublished($stream, $nowhere);

        [$sent, $refused, $tried, $outage] = $this->send($plans, $budget);

        $this->markPublished($stream, $sent);
        $this->countAttempts($stream, array_values(array_diff($tried, $sent)));

        foreach ($sent as $id) {
            unset($this->refusals[$key][$id], $this->resting[$key][$id]);

            if ($evicts) {
                $this->evictor->published((string) $plans[$id]['row']->type, $plans[$id]['channels']);
            }
        }

        $gaveUp = $this->refuse($stream, $epoch, [...$refused, ...$unhydrated], $rows);
        $gaveUp += $this->giveUp($stream, $epoch, $unsendable, $rows, 'its envelope cannot be sent at all (it does not encode, or it is larger than any batch Reverb accepts)');

        $finished = count($nowhere) + count($sent) + $gaveUp;

        return new PublishOutcome(
            read: $rows->count(),
            published: count($nowhere) + count($sent),
            gaveUp: $gaveUp,
            waiting: $rows->count() - $finished,
            outage: $outage,
        );
    }

    /**
     * The public pulse every open screen judges "live" by: `{at, build}` and nothing else (§1.5).
     *
     * Not sent while the breaker is open, and not sent when Reverb does not take it: either way the
     * screens stop hearing it, and within 25 s say that live updates are delayed, which is the truth.
     */
    public function pulse(): bool
    {
        if ($this->breaker->isOpen()) {
            return false;
        }

        return $this->sendOne([[
            'name' => self::PULSE_EVENT,
            'channel' => ChannelName::PULSE,
            'data' => self::json(['at' => self::instant(Carbon::now('UTC')), 'build' => config('live.build')]),
        ]], 0.5, 2.0);
    }

    /**
     * An ephemeral signal (LiveBus::signal, turnout only): never logged, no seq, one message per
     * channel. Skipped while the breaker is open; a missed turnout hint is re-read on catch-up.
     */
    public function signal(array $channels, string $type, array $data): void
    {
        if ($this->breaker->isOpen()) {
            return;
        }

        $items = array_map(static fn (string $channel): array => [
            'name' => self::EVENT,
            'channel' => ChannelName::WIRE_PREFIX.$channel,
            'data' => self::json(['v' => 1, 'q' => null, 't' => $type, 'd' => $data]),
        ], $channels);

        $this->sendLoose($items, 0.5, 1.0);
    }

    /**
     * The streams with a refused row resting, each with the clock time the first of them may be
     * tried again: the relay drains those streams again then, rather than at the next sweep.
     *
     * @return array<string, float>
     */
    public function retriesDue(): array
    {
        $due = [];

        foreach ($this->resting as $key => $rows) {
            if ($rows !== []) {
                $due[$key] = min($rows);
            }
        }

        return $due;
    }

    /**
     * The size one item adds to a batch body, measured the way pusher-php-server encodes it (its
     * default flags, so every quote in the data string is escaped), plus its comma.
     *
     * @param  array{name: string, channel: string, data: string}  $item
     */
    public static function itemBytes(array $item): int
    {
        return strlen((string) json_encode($item, JSON_THROW_ON_ERROR)) + 1;
    }

    /**
     * The envelope of one delivery (§2.2).
     *
     * @return array<string, mixed>
     */
    public static function envelope(Stream $stream, string $epoch, stdClass $row, int $seq, ?int $prev, bool $inline, mixed $data): array
    {
        $summary = $row->summary === null ? null : json_decode((string) $row->summary, true);

        $envelope = [
            'v' => 1,
            's' => $stream->code(),
            'ep' => substr($epoch, 0, 8),
            'q' => $seq,
            'p' => $prev,
            't' => (string) $row->type,
            'e' => (string) $row->entity,
            'i' => (string) $row->entity_id,
            'm' => $inline ? 'inline' : 'ref',
        ];

        if ($inline) {
            $envelope['d'] = $data;
        }

        return $envelope + [
            'sum' => is_array($summary) && $summary !== [] ? $summary : new stdClass,
            'o' => $row->origin === null ? null : (string) $row->origin,
            'at' => self::instant(Carbon::parse((string) $row->created_at, 'UTC')),
            'sim' => (bool) $row->simulated,
        ];
    }

    /**
     * Every inline row's data, one hydrator call per key. A key whose hydrator fails is left out,
     * and its rows are handled as refused: never published with data made up or missing.
     *
     * @param  Collection<int, stdClass>  $rows
     * @param  Collection<string, Collection<int, stdClass>>  $deliveries
     * @return array<string, array<array-key, mixed>>
     */
    private function hydrate(Collection $rows, Collection $deliveries): array
    {
        // Only rows that go somewhere: a row delivered nowhere is never hydrated for nobody.
        return Hydrators::hydrate($rows->filter(static fn (stdClass $row): bool => $deliveries->has((string) $row->seq)));
    }

    /**
     * @param  Collection<int, stdClass>  $deliveries
     * @return array{row: stdClass, items: list<array{name: string, channel: string, data: string}>, bytes: int, channels: list<string>}|null
     */
    private function plan(Stream $stream, string $epoch, stdClass $row, Collection $deliveries, bool $inline, mixed $data): ?array
    {
        $items = [];
        $channels = [];
        $bytes = 0;
        $oversized = false;

        foreach ($deliveries as $delivery) {
            try {
                $json = self::json(self::envelope($stream, $epoch, $row, (int) $delivery->seq, $delivery->prev_seq === null ? null : (int) $delivery->prev_seq, $inline, $data));
                $item = ['name' => self::EVENT, 'channel' => ChannelName::WIRE_PREFIX.$delivery->channel, 'data' => $json];
                $size = self::itemBytes($item);
            } catch (JsonException) {
                return null;
            }

            if (self::WRAPPER_BYTES + $size > self::BATCH_BYTES) {
                return null;
            }

            $oversized = $oversized || strlen($json) > self::ENVELOPE_BYTES;
            $items[] = $item;
            $channels[] = (string) $delivery->channel;
            $bytes += $size;
        }

        if ($oversized) {
            report(new RuntimeException("live_events row {$row->id} ({$row->type}) has an envelope over ".self::ENVELOPE_BYTES.' bytes (DESIGN.md §4.1). It is sent, because it fits a batch; its hydrator should return less.'));
        }

        return ['row' => $row, 'items' => $items, 'bytes' => $bytes, 'channels' => $channels];
    }

    /**
     * Send the planned events, packed into batches.
     *
     * Events are packed whole, several to a batch, so a refused batch of several can be re-sent one
     * event at a time; an event too big for one batch is sent alone, in as many as it needs.
     *
     * @param  array<int, array{row: stdClass, items: list<array{name: string, channel: string, data: string}>, bytes: int, channels: list<string>}>  $plans
     * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: bool} sent, refused, tried, outage
     */
    private function send(array $plans, ?PanicPublishBudget $budget): array
    {
        $ok = [];
        $need = [];
        $refused = [];
        $tried = [];

        foreach (self::pack($plans) as $batch) {
            $ids = array_values(array_filter($batch['ids'], static fn (int $id): bool => ! isset($refused[$id])));

            if ($ids === []) {
                continue;
            }

            foreach ($batch['ids'] as $id) {
                $need[$id] = ($need[$id] ?? 0) + 1;
            }

            if ($budget !== null && ! $budget->allowsPublish()) {
                break;
            }

            array_push($tried, ...$ids);

            try {
                $this->publish($batch['items'], $budget);

                foreach ($ids as $id) {
                    $ok[$id] = ($ok[$id] ?? 0) + 1;
                }
            } catch (PublishFailed $e) {
                if ($e->outage()) {
                    $this->down($e, $budget);

                    return [self::done($ok, $need, $refused), array_map(intval(...), array_keys($refused)), array_values(array_unique($tried)), true];
                }

                if (count($ids) === 1) {
                    $refused[$ids[0]] = true;

                    continue;
                }

                // Several events, one refusal: find the one Reverb will not take, and send the rest.
                foreach ($ids as $id) {
                    try {
                        $this->publish($plans[$id]['items'], $budget);
                        $ok[$id] = ($ok[$id] ?? 0) + 1;
                    } catch (PublishFailed $alone) {
                        if ($alone->outage()) {
                            $this->down($alone, $budget);

                            return [self::done($ok, $need, $refused), array_map(intval(...), array_keys($refused)), array_values(array_unique($tried)), true];
                        }

                        $refused[$id] = true;
                    }
                }
            }
        }

        return [self::done($ok, $need, $refused), array_map(intval(...), array_keys($refused)), array_values(array_unique($tried)), false];
    }

    /**
     * @param  array<int, int>  $ok
     * @param  array<int, int>  $need
     * @param  array<int, true>  $refused
     * @return list<int>
     */
    private static function done(array $ok, array $need, array $refused): array
    {
        $done = [];

        foreach ($need as $id => $batches) {
            if (! isset($refused[$id]) && ($ok[$id] ?? 0) >= $batches) {
                $done[] = (int) $id;
            }
        }

        return $done;
    }

    /**
     * @param  array<int, array{row: stdClass, items: list<array{name: string, channel: string, data: string}>, bytes: int, channels: list<string>}>  $plans
     * @return list<array{items: list<array{name: string, channel: string, data: string}>, ids: list<int>}>
     */
    private static function pack(array $plans): array
    {
        $batches = [];
        $open = self::emptyBatch();

        foreach ($plans as $id => $plan) {
            if (self::WRAPPER_BYTES + $plan['bytes'] <= self::BATCH_BYTES) {
                if ($open['bytes'] + $plan['bytes'] > self::BATCH_BYTES) {
                    $batches = self::close($batches, $open);
                    $open = self::emptyBatch();
                }

                $open['items'] = [...$open['items'], ...$plan['items']];
                $open['ids'][] = $id;
                $open['bytes'] += $plan['bytes'];

                continue;
            }

            // Too big for one batch: alone, over as many as it needs, with nothing else in them.
            $batches = self::close($batches, $open);
            $open = self::emptyBatch();

            foreach ($plan['items'] as $item) {
                $size = self::itemBytes($item);

                if ($open['items'] !== [] && $open['bytes'] + $size > self::BATCH_BYTES) {
                    $batches = self::close($batches, $open);
                    $open = self::emptyBatch();
                }

                $open['items'][] = $item;
                $open['ids'] = [$id];
                $open['bytes'] += $size;
            }

            $batches = self::close($batches, $open);
            $open = self::emptyBatch();
        }

        return self::close($batches, $open);
    }

    /** @return array{items: list<array{name: string, channel: string, data: string}>, ids: list<int>, bytes: int} */
    private static function emptyBatch(): array
    {
        return ['items' => [], 'ids' => [], 'bytes' => self::WRAPPER_BYTES];
    }

    /**
     * @param  list<array{items: list<array{name: string, channel: string, data: string}>, ids: list<int>}>  $batches
     * @param  array{items: list<array{name: string, channel: string, data: string}>, ids: list<int>, bytes: int}  $open
     * @return list<array{items: list<array{name: string, channel: string, data: string}>, ids: list<int>}>
     */
    private static function close(array $batches, array $open): array
    {
        if ($open['items'] !== []) {
            $batches[] = ['items' => $open['items'], 'ids' => $open['ids']];
        }

        return $batches;
    }

    /**
     * @param  list<array{name: string, channel: string, data: string}>  $items
     *
     * @throws PublishFailed
     */
    private function publish(array $items, ?PanicPublishBudget $budget): void
    {
        if ($budget === null) {
            $this->transport->publish($items, 0.5, 2.0);

            return;
        }

        $this->transport->publish($items, $budget->connectTimeout(), $budget->left());
    }

    /**
     * Messages with no row behind them (a nudge, a signal): sent in batches, and a failure only
     * reported. The five-minute safety catch-up is the bound when one is lost (§5.6).
     *
     * @param  list<array{name: string, channel: string, data: string}>  $items
     */
    private function sendLoose(array $items, float $connect, float $total): void
    {
        $batch = [];
        $bytes = self::WRAPPER_BYTES;

        foreach ($items as $item) {
            $size = self::itemBytes($item);

            if ($batch !== [] && $bytes + $size > self::BATCH_BYTES) {
                if (! $this->sendOne($batch, $connect, $total)) {
                    return;
                }

                $batch = [];
                $bytes = self::WRAPPER_BYTES;
            }

            $batch[] = $item;
            $bytes += $size;
        }

        if ($batch !== []) {
            $this->sendOne($batch, $connect, $total);
        }
    }

    /** @param  list<array{name: string, channel: string, data: string}>  $batch */
    private function sendOne(array $batch, float $connect, float $total): bool
    {
        try {
            $this->transport->publish($batch, $connect, $total);

            return true;
        } catch (PublishFailed $e) {
            $e->outage() ? $this->down($e, null) : report($e);

            return false;
        }
    }

    /**
     * Refused rows: counted, rested, and given up after the last try.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, stdClass>  $rows
     */
    private function refuse(Stream $stream, string $epoch, array $ids, Collection $rows): int
    {
        if ($ids === []) {
            return 0;
        }

        $key = $stream->key();
        $limit = max(1, (int) config('live.give_up_after', 5));
        $delays = array_values(array_map(floatval(...), (array) config('live.retry_seconds', [0.5, 1, 2, 5])));
        $delays = $delays === [] ? [5.0] : $delays;
        $last = [];

        foreach ($ids as $id) {
            $count = ($this->refusals[$key][$id] ?? 0) + 1;

            if ($count >= $limit) {
                unset($this->refusals[$key][$id], $this->resting[$key][$id]);
                $last[] = $id;

                continue;
            }

            $this->refusals[$key][$id] = $count;
            $this->resting[$key][$id] = $this->clock->now() + $delays[min($count - 1, count($delays) - 1)];
        }

        return $this->giveUp($stream, $epoch, $last, $rows, "Reverb refused it {$limit} times");
    }

    /**
     * Give rows up: `publish_failed_at`, a report, and a nudge to each of their channels.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, stdClass>  $rows
     */
    private function giveUp(Stream $stream, string $epoch, array $ids, Collection $rows, string $why): int
    {
        if ($ids === []) {
            return 0;
        }

        $db = DB::connection($stream->connection());

        $db->table('live_events')
            ->whereIn('id', $ids)
            ->whereNull('published_at')
            ->update(['publish_failed_at' => $db->raw('UTC_TIMESTAMP(6)')]);

        $where = $stream->isCentral() ? 'the central stream' : "estate {$stream->tenantId}'s stream";

        foreach ($rows->whereIn('id', $ids) as $row) {
            // The type and the row, never a channel: a channel name carries a record id.
            report(new RuntimeException("live_events row {$row->id} ({$row->type}) on {$where} was given up: {$why}. Its channels are nudged, so every client there recovers it by catch-up (DESIGN.md §1.5)."));
        }

        $channels = $db->table('live_deliveries')
            ->whereIn('event_id', $ids)
            ->distinct()
            ->pluck('channel')
            ->map(static fn ($channel): string => (string) $channel)
            ->all();

        $nudge = self::json(['v' => 1, 's' => $stream->code(), 'ep' => substr($epoch, 0, 8), 'q' => null, 't' => self::NUDGE]);

        $this->sendLoose(array_map(static fn (string $channel): array => [
            'name' => self::EVENT,
            'channel' => ChannelName::WIRE_PREFIX.$channel,
            'data' => $nudge,
        ], $channels), 0.5, 2.0);

        return count($ids);
    }

    /** @param  list<int>  $ids */
    private function markPublished(Stream $stream, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $db = DB::connection($stream->connection());

        $db->table('live_events')
            ->whereIn('id', $ids)
            ->whereNull('published_at')
            ->update([
                'published_at' => $db->raw('UTC_TIMESTAMP(6)'),
                'publish_attempts' => $db->raw('LEAST(publish_attempts + 1, '.self::MAX_ATTEMPTS.')'),
            ]);
    }

    /** @param  list<int>  $ids */
    private function countAttempts(Stream $stream, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $db = DB::connection($stream->connection());

        $db->table('live_events')
            ->whereIn('id', $ids)
            ->whereNull('published_at')
            ->update(['publish_attempts' => $db->raw('LEAST(publish_attempts + 1, '.self::MAX_ATTEMPTS.')')]);
    }

    /** Reverb is down: rest publishing, stop the budget, and say so at most once a minute. */
    private function down(PublishFailed $e, ?PanicPublishBudget $budget): void
    {
        $this->breaker->open();
        $budget?->markFailed();

        try {
            $first = Cache::store()->add('live:reverb_down_reported', true, 60);
        } catch (Throwable) {
            $first = true;
        }

        if ($first) {
            report($e);
        }
    }

    /** @return list<int> the ids still resting on this stream; the ones whose rest is over are forgotten */
    private function restingIds(string $key): array
    {
        $now = $this->clock->now();
        $ids = [];

        foreach ($this->resting[$key] ?? [] as $id => $until) {
            if ($until > $now) {
                $ids[] = (int) $id;
            } else {
                unset($this->resting[$key][$id]);
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws JsonException
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * The platform's one time format, `2026-10-02T14:03:11+00:00` (SiteTime::iso): the same
     * envelope reaches the phones through the API's catch-up, whose every instant is held to
     * that rule, and a socket message must not say a time differently from the answer that
     * repeats it.
     */
    private static function instant(Carbon $at): string
    {
        return (string) SiteTime::iso($at);
    }
}
