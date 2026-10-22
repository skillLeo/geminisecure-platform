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
 * What the carriers made of a batch, asked some minutes after it went (M4 §3.2).
 *
 * A ticket says Expo ACCEPTED a message; the receipt says whether FCM or APNs
 * delivered it. An app that was uninstalled is reported here — `DeviceNotRegistered`
 * — and that token is revoked, so the platform stops pushing to a phone that will
 * never ring. Anything else is a code in the log, against the row id: no token, no
 * title, no body.
 *
 * A RECEIPT THAT IS NOT READY YET IS ASKED FOR AGAIN. Expo leaves a ticket it has no
 * verdict for OUT of the answer entirely, so asking once and giving up would leave the
 * dead addresses Expo was slow about being pushed to for ever — the one failure this
 * job exists to prevent. Those tickets are asked for again, after the same delay, up
 * to `ROUNDS` times in all (Expo keeps a receipt for a day; this gives up well inside
 * that). A ticket nobody ever answers for costs three small requests and stops.
 */
class CheckExpoPushReceipts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /** How many times in all a batch's receipts are asked for before the rest are let go. */
    public const ROUNDS = 3;

    /**
     * @param  array<string, int>  $tickets  Expo ticket id => `device_push_tokens.id`
     * @param  int  $round  which ask this is — 1 is the first
     */
    public function __construct(public readonly array $tickets, public readonly string $type = '', public readonly int $round = 1) {}

    public function handle(ExpoPush $expo): void
    {
        if (! config('push.enabled', true)) {
            return;
        }

        $answers = $expo->receipts(array_map('strval', array_keys($this->tickets)));
        $waiting = [];

        foreach ($this->tickets as $ticketId => $tokenId) {
            $receipt = $answers[(string) $ticketId] ?? null;

            if ($receipt === null) {
                // Expo has no verdict yet. Not an error, and not something to forget either.
                $waiting[(string) $ticketId] = $tokenId;

                continue;
            }

            if ($receipt['status'] === 'ok') {
                continue;
            }

            if ($receipt['error'] === 'DeviceNotRegistered') {
                DevicePushToken::query()->live()->whereKey($tokenId)
                    ->update(['revoked_at' => Carbon::now(), 'revoked_reason' => DevicePushToken::NOT_REGISTERED]);

                continue;
            }

            Log::warning('push.receipt_error', ['error' => $receipt['error'], 'type' => $this->type, 'push_token_id' => $tokenId]);
        }

        $delay = (int) config('push.receipt_delay_seconds', 900);

        if ($waiting !== [] && $this->round < self::ROUNDS && $delay > 0) {
            // Rescued: the verdicts already read have been acted on. Failing to book the next ask must not undo them.
            rescue(function () use ($waiting, $delay): void {
                $next = (new self($waiting, $this->type, $this->round + 1))->delay($delay);

                if ($this->connection !== null && config('queue.connections.'.$this->connection) !== null) {
                    $next->onConnection($this->connection);
                }

                dispatch($next);
            });
        }
    }
}
