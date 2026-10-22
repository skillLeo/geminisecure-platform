<?php

declare(strict_types=1);

namespace App\Live\Auth;

use LogicException;
use Pusher\Pusher;

/**
 * SIGNS A GRANT, AND NOTHING ELSE CAN (M11 Part C, DESIGN §3.6).
 *
 * `Pusher::authorizeChannel()` with the reverb application's own key and secret: an
 * HMAC-SHA256 over `socket_id:channel:channel_data`, which Reverb recomputes when the
 * socket subscribes. `channel_data` is `{"user_id":"u-12"}` — the principal's key — so a
 * client can neither drop it nor forge it, Reverb keeps it on the subscription, and
 * `terminate_connections` for that key closes every socket the principal holds.
 *
 * NEVER THROUGH THE BROADCAST MANAGER. The suite runs on the `null` broadcaster, which
 * answers 200 to everything; a signer that went through it would make every channel
 * test pass whatever the rules said. Reading the connection's credentials directly
 * means a test proves the rules or proves nothing, and production signs with exactly
 * the key Reverb verifies against.
 */
final class Signer
{
    /** One SDK instance for a whole batch, kept only while the credentials it was made with hold. */
    private ?Pusher $pusher = null;

    private string $madeWith = '';

    /** Whether there is a key and a secret to sign with at all. */
    public static function configured(): bool
    {
        [$key, $secret, $appId] = self::credentials();

        return $key !== '' && $secret !== '' && $appId !== '';
    }

    /**
     * The grant for one wire channel, bound to one socket and one principal.
     *
     * @return array{auth: string, channel_data: string}
     */
    public function sign(string $wireChannel, string $socketId, Principal $principal): array
    {
        [$key, $secret, $appId] = self::credentials();

        if ($key === '' || $secret === '' || $appId === '') {
            throw new LogicException('Live grants need the reverb connection\'s key, secret and app id.');
        }

        $channelData = json_encode(['user_id' => $principal->key()], JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $key."\0".$secret."\0".$appId);

        if ($this->pusher === null || $this->madeWith !== $fingerprint) {
            $this->pusher = new Pusher($key, $secret, $appId);
            $this->madeWith = $fingerprint;
        }

        $answer = json_decode(
            $this->pusher->authorizeChannel($wireChannel, $socketId, $channelData),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($answer) || ! is_string($answer['auth'] ?? null) || ($answer['channel_data'] ?? null) !== $channelData) {
            throw new LogicException('The Pusher SDK answered a channel authorisation in a shape this signer does not know.');
        }

        return ['auth' => $answer['auth'], 'channel_data' => $channelData];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function credentials(): array
    {
        $connection = config('broadcasting.connections.reverb');
        $connection = is_array($connection) ? $connection : [];

        return [
            is_scalar($connection['key'] ?? null) ? (string) $connection['key'] : '',
            is_scalar($connection['secret'] ?? null) ? (string) $connection['secret'] : '',
            is_scalar($connection['app_id'] ?? null) ? (string) $connection['app_id'] : '',
        ];
    }
}
