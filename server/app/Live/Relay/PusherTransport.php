<?php

declare(strict_types=1);

namespace App\Live\Relay;

use GuzzleHttp\Client;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Pusher\PusherException;
use Throwable;

/**
 * Reverb's HTTP API, straight to this machine (DESIGN.md §1.5): the `reverb_relay` connection.
 *
 * A new client per call, because each call carries its own bounds. The daemon's are 0.5 s to
 * connect and 2 s in all; Relay::flushNow's are whatever its panic budget has left. Both are set
 * on the Guzzle client AND on the SDK, because pusher-php-server sends its own `timeout` with
 * every request and a request option beats the client's (the same reason config/broadcasting.php
 * sets the `reverb` bound twice). Making a client opens nothing; the socket is opened by the call.
 */
final class PusherTransport implements Transport
{
    public function publish(array $batch, float $connect, float $total): void
    {
        $this->call(fn (Pusher $pusher) => $pusher->triggerBatch($batch, true), $connect, $total, 'publish');
    }

    public function terminate(string $principalKey, float $connect, float $total): void
    {
        $this->call(fn (Pusher $pusher) => $pusher->terminateUserConnections($principalKey), $connect, $total, 'terminate');
    }

    public function occupiedChannels(float $connect, float $total): int
    {
        /*
         * `get('/channels')` and not `getChannels()`: with no channel occupied Reverb answers
         * `{"channels":[]}`, a list, and the SDK's getChannels() calls get_object_vars() on it and
         * dies with a TypeError, which read as "Reverb does not answer" on a Reverb that did.
         */
        $result = $this->call(fn (Pusher $pusher) => $pusher->get('/channels', [], true), $connect, $total, 'list channels');

        return is_array($result) && is_array($result['channels'] ?? null) ? count($result['channels']) : 0;
    }

    /**
     * @param  callable(Pusher): mixed  $call
     *
     * @throws PublishFailed
     */
    private function call(callable $call, float $connect, float $total, string $what): mixed
    {
        $config = (array) config('broadcasting.connections.reverb_relay');
        $options = (array) ($config['options'] ?? []);

        $connect = max(0.001, $connect);
        $total = max($connect, $total);

        try {
            $pusher = new Pusher(
                (string) ($config['key'] ?? ''),
                (string) ($config['secret'] ?? ''),
                (string) ($config['app_id'] ?? ''),
                [
                    'host' => (string) ($options['host'] ?? '127.0.0.1'),
                    'port' => (int) ($options['port'] ?? 8080),
                    'scheme' => 'http',
                    'useTLS' => false,
                    'timeout' => $total,
                ],
                new Client(['connect_timeout' => $connect, 'timeout' => $total, 'http_errors' => false]),
            );

            return $call($pusher);
        } catch (ApiErrorException $e) {
            // 0 when nothing answered (refused, reset, timed out); otherwise Reverb's own status.
            throw new PublishFailed("Reverb did not accept the relay's {$what} (".($e->getCode() === 0 ? 'no answer' : 'HTTP '.$e->getCode()).').', (int) $e->getCode(), $e);
        } catch (PusherException $e) {
            // The SDK refused the message before sending it (a channel name it will not send, data
            // it cannot encode): that message's fault, not Reverb's, so a refusal and not an outage.
            throw new PublishFailed("The relay's {$what} was refused before it was sent (".class_basename($e).').', 400, $e);
        } catch (Throwable $e) {
            throw new PublishFailed("The relay's {$what} did not reach Reverb (".class_basename($e).').', 0, $e);
        }
    }
}
