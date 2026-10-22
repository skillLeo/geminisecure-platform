<?php

declare(strict_types=1);

namespace App\Jobs\Ring;

use App\Jobs\Push\CheckExpoPushReceipts;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\DuressAlertRing;
use App\Models\DuressAlertRingEvent as Event;
use App\Models\DuressAlertRingRound;
use App\Models\Tenant;
use App\Services\Push\ExpoPush;
use App\Services\Push\PushNotifier;
use App\Services\Ring\GuardRing;
use App\Services\Ring\RingPayload;
use App\Services\Ring\RingRule;
use App\Services\Ring\RingTargets;
use App\Services\Ring\RingTicket;
use App\Support\CentralQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ONE BATCH OF A RING, TO AT MOST 100 PHONES, HANDED TO EXPO (the guard ring §4.2).
 *
 * The ring's own sender, beside `SendExpoPush` and never through it. `SendExpoPush` is not
 * edited at all (G2): a promoted parameter added to it would unserialize as uninitialized
 * in a panic push queued before a deploy, and every retry of that push would throw.
 *
 *   ring          the call-style ring, data-only (`site_ring`)
 *   visible       the ordinary alert, tagged, for a phone that cannot yet ring on its own
 *   stop          the stop, data-only (`ring_stop`)
 *   visible_stop  the stop as an ordinary notice, replacing a visible re-ring by its tag
 *   test          a test ring the guard asked for (`ring_test`)
 *
 * EVERY SEND RE-CHECKS WHO MAY BE SENT IT (G7). A push address is one row that
 * `push.register` re-points to whoever registers the install next — another guard, another
 * estate. So each batch keeps only the addresses `PushNotifier::entitledTokens()` still
 * gives for THIS alert's estate and THIS alert's rung guards; one that moved gets nothing
 * and an event saying why. A ring or a visible re-ring also re-checks the ALERT and sends
 * nothing once it is answered — `SendExpoPush` never re-checks.
 *
 * IDEMPOTENT PER PHONE. A phone already sent this message for this alert and round (a stop:
 * at this round or later) is skipped, so a retry, the sweep and a second stop list never
 * send it twice. The ticket is built here, per phone, and is never in the payload.
 *
 * AND AFTER A RING BATCH, THE ALERT IS READ AGAIN (G6): answered while the batch was in
 * flight, the phones it just reached are sent their stop at once.
 *
 * ITS CONSTRUCTOR NEVER GROWS A PROMOTED PARAMETER (G2). A later optional field is an
 * ordinary property with a default, set after construction, so a batch serialized by the
 * previous release still runs.
 */
class SendRingPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const RING = 'ring';

    public const VISIBLE = 'visible';

    public const STOP = 'stop';

    public const VISIBLE_STOP = 'visible_stop';

    public const TEST = 'test';

    /** Under central's retry_after of 90, with Expo's own 12 s timeout inside it. */
    public int $timeout = 60;

    /**
     * @param  list<int>  $tokenIds  `device_push_tokens.id`, at most 100
     */
    public function __construct(
        public readonly array $tokenIds,
        public readonly int $alertId,
        public readonly int $round,
        public readonly string $kind,
        public readonly int $testSeq = 0,
    ) {}

    /** A ring older than its ttl is noise; a stop is worth longer. */
    public function tries(): int
    {
        return $this->isStop() ? 4 : 3;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return $this->isStop() ? [5, 15, 60] : [3, 10];
    }

    public function handle(ExpoPush $expo, PushNotifier $push): void
    {
        if (! config('push.enabled', true)) {
            return;
        }

        $ids = array_slice(array_values(array_unique(array_map('intval', $this->tokenIds))), 0, ExpoPush::MAX_BATCH);

        if ($ids === []) {
            return;
        }

        if ($this->kind === self::TEST) {
            $this->sendTest($expo, $push, $ids);

            return;
        }

        $alert = DuressAlert::query()->with(['estate', 'respondingGuard'])->find($this->alertId);

        if ($alert === null) {
            return;
        }

        if (! $this->isStop() && RingRule::stops($alert, $this->round)) {
            return;
        }

        $tenantId = (string) $alert->tenant_id;

        /** @var Collection<int, DevicePushToken> $entitled */
        $entitled = $push->entitledTokens($tenantId, DevicePushToken::GUARD, RingTargets::guardIdsFor($alert))->keyBy('id');

        foreach (array_diff($ids, $entitled->keys()->map(static fn ($id): int => (int) $id)->all()) as $gone) {
            Event::once(['duress_alert_id' => $this->alertId, 'tenant_id' => $tenantId, 'round' => $this->round, 'push_token_id' => (int) $gone, 'event' => Event::REFUSED, 'detail' => 'entitlement']);
        }

        $tokens = $entitled->only($ids)->values();
        $tokens = $tokens->reject(fn (DevicePushToken $token): bool => $this->alreadySent((int) $token->id));

        if ($tokens->isNotEmpty()) {
            $messages = $this->messages($alert, $tokens);
            $this->send($expo, $tokens, $messages, $tenantId, $this->alertId, 0);
        }

        /*
         * ANSWERED WHILE THIS BATCH WAS GOING (G6). The stop list built by the answer may
         * predate these sends, so the phones this batch just reached would keep ringing;
         * `stop()` is idempotent per phone and sends each only what it lacks.
         */
        if (! $this->isStop() && $tokens->isNotEmpty()) {
            $alert->refresh();

            if (RingRule::stops($alert, $this->round)) {
                rescue(static fn () => app(GuardRing::class)->stop($alert));
            }
        }
    }

    public function isStop(): bool
    {
        return in_array($this->kind, [self::STOP, self::VISIBLE_STOP], true);
    }

    /** The event a send of this kind writes. */
    public function sentEvent(): string
    {
        return match ($this->kind) {
            self::RING => Event::SENT_RING,
            self::VISIBLE => Event::SENT_VISIBLE,
            self::STOP => Event::SENT_STOP,
            self::VISIBLE_STOP => Event::SENT_VISIBLE_STOP,
            default => Event::SENT_TEST,
        };
    }

    /** @param list<int> $ids */
    private function sendTest(ExpoPush $expo, PushNotifier $push, array $ids): void
    {
        $token = DevicePushToken::query()->live()->whereKey($ids[0])->first();

        if ($token === null || $token->principal_type !== DevicePushToken::GUARD) {
            return;
        }

        $tenantId = (string) $token->tenant_id;

        // The one guard who asked, at the estate the address is registered to — and still entitled.
        $entitled = $push->entitledTokens($tenantId, DevicePushToken::GUARD, [(int) $token->principal_id])->keyBy('id');

        if (! $entitled->has($token->id)) {
            Event::once(['duress_alert_id' => 0, 'tenant_id' => $tenantId, 'round' => 0, 'test_seq' => $this->testSeq, 'push_token_id' => (int) $token->id, 'event' => Event::REFUSED, 'detail' => 'entitlement']);

            return;
        }

        if ($this->alreadySent((int) $token->id)) {
            return;
        }

        $estate = (string) (Tenant::query()->find($tenantId)?->getAttribute('name') ?? $tenantId);

        $ticket = RingTicket::issue(['a' => 0, 't' => $tenantId, 'g' => (int) $token->principal_id, 'p' => (int) $token->id, 'r' => 0, 's' => $this->testSeq, 'k' => RingTicket::TEST]);
        $message = RingPayload::ringTest(self::testId((int) $token->id, $this->testSeq), $estate, $ticket);

        $this->send($expo, collect([$token]), [['to' => $token->expo_token, ...$message]], $tenantId, 0, $this->testSeq);
    }

    /** The test ring's own id: a slug, the same for every retry of the same test. */
    public static function testId(int $tokenId, int $testSeq): string
    {
        return 'test-'.substr(hash('sha256', 'ring-test:'.$tokenId.':'.$testSeq), 0, 16);
    }

    /**
     * @param  Collection<int, DevicePushToken>  $tokens
     * @return list<array<string, mixed>>
     */
    private function messages(DuressAlert $alert, Collection $tokens): array
    {
        $now = Carbon::now();

        if ($this->isStop()) {
            $why = RingRule::answered($alert)
                ?? DuressAlertRing::query()->where('duress_alert_id', $this->alertId)->value('answered_why')
                ?? RingRule::ACKNOWLEDGED;
            $officer = $alert->responding_guard_id === null ? null : $alert->respondingGuard?->full_name;

            $message = $this->kind === self::STOP
                ? RingPayload::ringStop($alert, (string) $why, $officer)
                : RingPayload::visibleStop($alert, (string) $why, $officer);

            return $tokens->map(static fn (DevicePushToken $token): array => ['to' => $token->expo_token, ...$message])->values()->all();
        }

        if ($this->kind === self::VISIBLE) {
            $reason = (string) (DuressAlertRingRound::query()->where('duress_alert_id', $this->alertId)->where('round', $this->round)->value('reason') ?? DuressAlertRingRound::RE_RING);
            $message = RingPayload::visibleRing($alert, $reason, $now);

            return $tokens->map(static fn (DevicePushToken $token): array => ['to' => $token->expo_token, ...$message])->values()->all();
        }

        $lastRound = (int) (DuressAlertRing::query()->where('duress_alert_id', $this->alertId)->value('max_round') ?? config('ring.max_round', 5));

        return $tokens->map(fn (DevicePushToken $token): array => ['to' => $token->expo_token, ...RingPayload::siteRing(
            $alert,
            $this->round,
            $lastRound,
            RingTicket::issue(['a' => (int) $alert->id, 't' => (string) $alert->tenant_id, 'g' => (int) $token->principal_id, 'p' => (int) $token->id, 'r' => $this->round, 's' => 0, 'k' => RingTicket::RING]),
            $now,
        )])->values()->all();
    }

    /**
     * Hand the batch to Expo and write down, per phone, what Expo said.
     *
     * @param  Collection<int, DevicePushToken>  $tokens
     * @param  list<array<string, mixed>>  $messages  one per token, in order
     */
    private function send(ExpoPush $expo, Collection $tokens, array $messages, string $tenantId, int $alertId, int $testSeq): void
    {
        // Throws on a slow or broken Expo, so the queue retries. Nothing is recorded before it answers.
        $tickets = $expo->send($messages);
        $awaiting = [];
        $event = $this->sentEvent();

        foreach ($tokens->values() as $i => $token) {
            $ticket = $tickets[$i] ?? ['status' => 'error', 'id' => null, 'error' => 'Unknown'];
            $row = ['duress_alert_id' => $alertId, 'tenant_id' => $tenantId, 'round' => $this->round, 'test_seq' => $testSeq, 'guard_id' => (int) $token->principal_id, 'push_token_id' => (int) $token->id];

            if ($ticket['status'] === 'ok') {
                Event::once([...$row, 'event' => $event, 'detail' => $token->ring_state ?? ($token->platform === 'ios' ? 'ios' : 'no_module')]);

                if ($ticket['id'] !== null) {
                    $awaiting[$ticket['id']] = (int) $token->id;
                }

                continue;
            }

            if ($ticket['error'] === 'DeviceNotRegistered') {
                $token->forceFill(['revoked_at' => Carbon::now(), 'revoked_reason' => DevicePushToken::NOT_REGISTERED])->save();
            } else {
                // A code against the row id and the kind — never the token, never the words.
                Log::warning('ring.ticket_error', ['error' => $ticket['error'], 'kind' => $this->kind, 'push_token_id' => $token->id]);
            }

            Event::once([...$row, 'event' => Event::FAILED, 'detail' => $this->kind.':'.(string) $ticket['error']]);
        }

        $delay = (int) config('push.receipt_delay_seconds', 900);

        if ($awaiting !== [] && $delay > 0) {
            // Rescued: the messages have GONE. Failing to book the follow-up must not fail the send.
            rescue(function () use ($awaiting, $delay, $messages): void {
                $type = (string) ($messages[0]['data']['type'] ?? RingPayload::SITE_RING);
                dispatch(CentralQueue::pin((new CheckExpoPushReceipts($awaiting, $type))->delay($delay)));
            });
        }
    }

    /** Whether this phone already has this batch's message — for a stop, at this round or later. */
    private function alreadySent(int $tokenId): bool
    {
        $query = Event::query()
            ->where('duress_alert_id', $this->kind === self::TEST ? 0 : $this->alertId)
            ->where('push_token_id', $tokenId)
            ->where('event', $this->sentEvent());

        return match (true) {
            $this->kind === self::TEST => $query->where('test_seq', $this->testSeq)->exists(),
            $this->isStop() => $query->where('round', '>=', $this->round)->exists(),
            default => $query->where('round', $this->round)->exists(),
        };
    }
}
