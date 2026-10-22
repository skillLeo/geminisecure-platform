<?php

declare(strict_types=1);

namespace App\Jobs\Push;

use App\Models\DevicePushToken;
use App\Services\Push\ExpoPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * One notification, to at most 100 phones, handed to Expo (M4 §3.2).
 *
 * ALWAYS QUEUED. A panic's HTTP answer never waits on Expo; `PushNotifier` decides
 * who is told and what, writes this job, and returns. One job is one request to Expo,
 * so a bigger audience is several jobs and a failure costs only one of them. An
 * urgent push is queued on `push.urgent_queue`, which every worker reads first.
 *
 * A RETRY CAN SEND THE SAME BATCH TWICE — worth knowing, rather than wished away.
 * Expo has no idempotency key, and a connection that times out AFTER Expo accepted
 * the batch looks exactly like one that timed out before: the job throws, the queue
 * retries, and every phone in that batch is sent the same message again. The cost is
 * a second buzz for one alert — never a missed one, which is the right way round for
 * a panic, and never a duplicate RECORD, because this job only reads. Nothing
 * downstream removes it either: the phones' `dedupe` drops a LOCAL banner that
 * repeats a push, not a push that repeats a push, because a second push is taken to
 * mean the server decided it was news. If that ever has to stop, the answer is a
 * delivery ledger keyed on (token, type, record) — not fewer retries.
 *
 * IT CARRIES ROW IDS, NOT TOKENS. The Expo token is read from the table when the
 * job runs, so a phone de-registered between the decision and the send is skipped,
 * and a failed job's stored payload holds no delivery address.
 *
 * TICKETS. `DeviceNotRegistered` revokes that token for good. Any other error is
 * logged as a CODE against the row id and the notification type — never the token,
 * never the title or body, which can name a visitor or quote the control room.
 */
class SendExpoPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  list<int>  $tokenIds  `device_push_tokens.id`, at most 100
     * @param  array{type: string, id?: string, kind?: string}  $data
     */
    public function __construct(
        public readonly array $tokenIds,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data,
        public readonly string $channel,
        public readonly bool $urgent,
        public readonly ?int $ttl = null,
    ) {}

    public function handle(ExpoPush $expo): void
    {
        if (! config('push.enabled', true)) {
            return;
        }

        $tokens = DevicePushToken::query()->live()->whereKey(array_slice($this->tokenIds, 0, ExpoPush::MAX_BATCH))->get()->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $tickets = $expo->send($tokens->map(fn (DevicePushToken $token): array => $this->message($token->expo_token))->all());
        $awaiting = [];

        foreach ($tokens as $i => $token) {
            $ticket = $tickets[$i] ?? ['status' => 'error', 'id' => null, 'error' => 'Unknown'];

            if ($ticket['status'] === 'ok') {
                if ($ticket['id'] !== null) {
                    $awaiting[$ticket['id']] = $token->id;
                }

                continue;
            }

            if ($ticket['error'] === 'DeviceNotRegistered') {
                $token->forceFill(['revoked_at' => Carbon::now(), 'revoked_reason' => DevicePushToken::NOT_REGISTERED])->save();

                continue;
            }

            Log::warning('push.ticket_error', ['error' => $ticket['error'], 'type' => $this->data['type'], 'push_token_id' => $token->id]);
        }

        $delay = (int) config('push.receipt_delay_seconds', 900);

        if ($awaiting !== [] && $delay > 0) {
            // Rescued: the messages have GONE. Failing to book the follow-up must not fail — and so retry — the send.
            rescue(function () use ($awaiting, $delay): void {
                $check = (new CheckExpoPushReceipts($awaiting, $this->data['type']))->delay($delay);

                if ($this->connection !== null && config('queue.connections.'.$this->connection) !== null) {
                    $check->onConnection($this->connection);
                }

                dispatch($check);
            });
        }
    }

    /**
     * Expo's message. `channelId` is an Android channel THE PHONE CREATED
     * (`PushTypes::channel`); priority `high` wakes a dozing phone; on iOS a safety
     * push is time-sensitive, so a Focus mode does not hold it back.
     *
     * @return array<string, mixed>
     */
    private function message(string $to): array
    {
        return array_filter([
            'to' => $to,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'sound' => 'default',
            'channelId' => $this->channel,
            'priority' => $this->urgent ? 'high' : 'default',
            'interruptionLevel' => $this->urgent ? 'time-sensitive' : null,
            'ttl' => $this->ttl,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
