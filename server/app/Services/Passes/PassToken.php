<?php

declare(strict_types=1);

namespace App\Services\Passes;

use InvalidArgumentException;

/**
 * The signed visitor pass, as it travels in a QR code (13 D1).
 *
 *   base64url(canonical payload JSON) . "." . base64url(Ed25519 signature)
 *
 * THE PAYLOAD IS EXACTLY EIGHT FIELDS, in this order, and nothing else:
 *
 *   pass_id      the pass's UUID
 *   tenant_id    the estate database it was issued from
 *   site_id      the physical site it admits to — today the estate itself
 *   valid_from   ISO 8601, UTC
 *   valid_to     ISO 8601, UTC
 *   single_use   whether one admission consumes it
 *   nonce        16 random bytes, hex — two passes with identical terms never
 *                share a signature
 *   key_version  which of the site's keys signed it
 *
 * No visitor name, no unit, no household. A QR code is shown to anyone standing
 * near the visitor, and a pass that carried the household would tell a stranger
 * where the visitor is going. The guard's screen gets names from the server when
 * it is online, and "valid pass" when it is not.
 *
 * CANONICAL means the bytes signed are the bytes a device re-derives: fixed key
 * order, no whitespace, unescaped slashes. The signature is over the encoded
 * payload segment exactly as it appears in the token, so a verifier never
 * re-serialises anything.
 */
final class PassToken
{
    public const FIELDS = ['pass_id', 'tenant_id', 'site_id', 'valid_from', 'valid_to', 'single_use', 'nonce', 'key_version'];

    /**
     * @param  array{pass_id: string, tenant_id: string, site_id: string, valid_from: string, valid_to: string, single_use: bool, nonce: string, key_version: int}  $payload
     */
    public static function encodePayload(array $payload): string
    {
        $ordered = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("A pass payload needs [{$field}].");
            }

            $ordered[$field] = $payload[$field];
        }

        if (array_diff(array_keys($payload), self::FIELDS) !== []) {
            throw new InvalidArgumentException('A pass payload carries the eight fields and nothing else.');
        }

        return self::base64url((string) json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Split a token into its signed segment, decoded payload and raw signature.
     *
     * @return array{segment: string, payload: array<string, mixed>, signature: string}|null null when the token is not in the format
     */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 2) {
            return null;
        }

        $json = self::unbase64url($parts[0]);
        $signature = self::unbase64url($parts[1]);

        if ($json === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload) || array_keys($payload) !== self::FIELDS) {
            return null;
        }

        return ['segment' => $parts[0], 'payload' => $payload, 'signature' => $signature];
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unbase64url(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
