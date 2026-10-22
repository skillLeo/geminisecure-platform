<?php

declare(strict_types=1);

namespace App\Services\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Expo's push service, and nothing else (M4 §3.2).
 *
 * THE ONLY CLASS THAT KNOWS EXPO'S WIRE FORMAT. `send()` posts up to 100 messages
 * and answers one TICKET per message, in order; `receipts()` asks, some minutes
 * later, what the carriers (FCM, APNs) made of them. Both go through Laravel's
 * Http client, so a test fakes them and never reaches the network.
 *
 * IT LOGS NOTHING AND DECIDES NOTHING. What a `DeviceNotRegistered` means for a
 * token row is the job's business; this class hands back Expo's answer reduced to
 * `status` + an error CODE — never Expo's free-text message, which can echo the
 * token it is complaining about.
 *
 * A SLOW OR BROKEN EXPO IS AN EXCEPTION, so the queued job retries with a backoff.
 * A request Expo REFUSED (4xx) is an answer: retrying the same thing cannot help.
 */
class ExpoPush
{
    /** `ExponentPushToken[…]` (what the SDK returns) or `ExpoPushToken[…]`. */
    public const TOKEN = '/^Expo(nent)?PushToken\[[^\[\]\s]{1,160}\]$/';

    public const MAX_BATCH = 100;

    public static function validToken(mixed $token): bool
    {
        return is_string($token) && strlen($token) <= 190 && preg_match(self::TOKEN, $token) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $messages  at most 100, each with `to`
     * @return list<array{status: string, id: string|null, error: string|null}>  one per message, in order
     */
    public function send(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        if (count($messages) > self::MAX_BATCH) {
            throw new RuntimeException('Expo accepts at most '.self::MAX_BATCH.' messages in one request.');
        }

        // A list, so it is sent as a JSON array; the tickets come back in the same order.
        $body = $this->post((string) config('push.expo_url'), $messages);

        if ($body === null) {
            return array_fill(0, count($messages), ['status' => 'error', 'id' => null, 'error' => 'RequestRefused']);
        }

        $tickets = array_values((array) ($body['data'] ?? []));

        return array_map(static function (int $i) use ($tickets): array {
            $ticket = is_array($tickets[$i] ?? null) ? $tickets[$i] : [];
            $ok = ($ticket['status'] ?? null) === 'ok';

            return [
                'status' => $ok ? 'ok' : 'error',
                'id' => $ok && is_string($ticket['id'] ?? null) ? $ticket['id'] : null,
                'error' => $ok ? null : self::code($ticket),
            ];
        }, array_keys($messages));
    }

    /**
     * @param  list<string>  $ticketIds
     * @return array<string, array{status: string, error: string|null}>  by ticket id; a receipt Expo does not have yet is absent
     */
    public function receipts(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        $body = $this->post((string) config('push.expo_receipts_url'), ['ids' => $ticketIds]);
        $receipts = [];

        foreach ((array) ($body['data'] ?? []) as $id => $receipt) {
            if (is_array($receipt)) {
                $ok = ($receipt['status'] ?? null) === 'ok';
                $receipts[(string) $id] = ['status' => $ok ? 'ok' : 'error', 'error' => $ok ? null : self::code($receipt)];
            }
        }

        return $receipts;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string, mixed>|null  null when Expo refused the request outright (4xx)
     */
    private function post(string $url, array $payload): ?array
    {
        try {
            $response = $this->client()->post($url, $payload);
        } catch (ConnectionException) {
            // Re-thrown WITHOUT the original: its message carries the URL and nothing useful, and a
            // failed job's stored exception should never be a place a payload could end up.
            throw new RuntimeException('Expo\'s push service could not be reached.');
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new RuntimeException('Expo\'s push service answered '.$response->status().'.');
        }

        if ($response->clientError()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function client(): PendingRequest
    {
        $client = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(12);
        $accessToken = config('services.expo.access_token');

        return is_string($accessToken) && $accessToken !== '' ? $client->withToken($accessToken) : $client;
    }

    /** @param  array<mixed>  $ticket */
    private static function code(array $ticket): string
    {
        $code = $ticket['details']['error'] ?? null;

        return is_string($code) && preg_match('/^[A-Za-z]{1,40}$/', $code) === 1 ? $code : 'Unknown';
    }
}
