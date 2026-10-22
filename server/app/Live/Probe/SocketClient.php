<?php

declare(strict_types=1);

namespace App\Live\Probe;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Uri;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Throwable;

/**
 * A WebSocket client for the server to talk to its own Reverb with (DESIGN.md section 10.3).
 *
 * Production has no Node, so live:smoke cannot use pusher-js. This speaks the Pusher protocol over
 * ratchet/rfc6455 and react/socket, both already in the lock file because Reverb is built on them:
 * the TLS connection and the HTTP upgrade as a browser makes them (with an Origin, which Reverb
 * checks), then text frames of JSON, masked as a client's must be, answering pings.
 *
 * One conversation per `run()`: `$script` is called with every Pusher message as it arrives, and a
 * way to send; it returns true when it has what it came for. The loop runs until then, until the
 * connection closes, or until `$timeout`, whichever is first, and the answer says which.
 *
 * Pure ASCII.
 */
final class SocketClient
{
    /**
     * @param  Closure(array<string, mixed>, Closure(array<string, mixed>): void): bool  $script  message, send => done
     * @return array{done: bool, reason: string, seconds: float}
     */
    public static function run(string $url, string $origin, float $timeout, Closure $script): array
    {
        $loop = Loop::get();
        $started = hrtime(true);
        $outcome = ['done' => false, 'reason' => 'the time ran out'];
        $settled = false;
        /** @var ConnectionInterface|null $connection */
        $connection = null;

        // The first outcome is the answer; a close that follows it is only its echo.
        $finish = static function (bool $done, string $reason) use (&$outcome, &$settled, &$connection, $loop): void {
            if ($settled) {
                return;
            }

            $settled = true;
            $outcome = ['done' => $done, 'reason' => $reason];
            $connection?->close();
            $loop->stop();
        };

        $uri = new Uri($url);
        $secure = $uri->getScheme() === 'wss';
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($secure ? 443 : 80);

        $timer = $loop->addTimer($timeout, static function () use ($finish): void {
            $finish(false, 'the time ran out');
        });

        $negotiator = new ClientNegotiator(new HttpFactory);
        $request = $negotiator->generateRequest($uri)->withHeader('Origin', $origin);

        (new Connector(['timeout' => $timeout, 'tls' => ['peer_name' => $host]]))
            ->connect(($secure ? 'tls://' : 'tcp://').$host.':'.$port)
            ->then(static function (ConnectionInterface $socket) use (&$connection, $request, $negotiator, $script, $finish): void {
                $connection = $socket;
                $head = '';
                $upgraded = false;

                // The buffer and the send function need each other, so they meet through this.
                $holder = new class
                {
                    public ?MessageBuffer $buffer = null;
                };

                $send = static function (array $message) use ($holder): void {
                    $holder->buffer?->sendMessage((string) json_encode($message));
                };

                $buffer = $holder->buffer = new MessageBuffer(
                    new CloseFrameChecker,
                    static function (MessageInterface $message) use ($script, $send, $finish): void {
                        $decoded = json_decode($message->getPayload(), true);

                        if (! is_array($decoded)) {
                            return;
                        }

                        try {
                            if ($script($decoded, $send)) {
                                $finish(true, 'done');
                            }
                        } catch (Throwable $e) {
                            $finish(false, class_basename($e).': '.$e->getMessage());
                        }
                    },
                    static function (FrameInterface $frame) use ($holder, $finish): void {
                        match ($frame->getOpcode()) {
                            Frame::OP_PING => $holder->buffer?->sendFrame($holder->buffer->newFrame($frame->getPayload(), true, Frame::OP_PONG)),
                            Frame::OP_CLOSE => $finish(false, 'the server closed the socket'),
                            default => null,
                        };
                    },
                    false,
                    null,
                    null,
                    null,
                    static function (string $bytes) use ($socket): void {
                        $socket->write($bytes);
                    },
                );

                $socket->on('data', static function (string $data) use (&$head, &$upgraded, $buffer, $request, $negotiator, $finish): void {
                    if ($upgraded) {
                        $buffer->onData($data);

                        return;
                    }

                    $head .= $data;
                    $end = strpos($head, "\r\n\r\n");

                    if ($end === false) {
                        return;
                    }

                    $response = Message::parseResponse(substr($head, 0, $end + 4));

                    if ($response->getStatusCode() !== 101 || ! $negotiator->validateResponse($request, $response)) {
                        $finish(false, 'the upgrade was refused: HTTP '.$response->getStatusCode());

                        return;
                    }

                    $upgraded = true;
                    $rest = substr($head, $end + 4);

                    if ($rest !== '') {
                        $buffer->onData($rest);
                    }
                });

                $socket->on('close', static fn () => $finish(false, 'the connection closed'));

                $socket->write(Message::toString($request));
            }, static function (Throwable $e) use ($finish): void {
                $finish(false, 'could not connect: '.$e->getMessage());
            });

        $loop->run();
        $loop->cancelTimer($timer);

        return $outcome + ['seconds' => (hrtime(true) - $started) / 1e9];
    }
}
