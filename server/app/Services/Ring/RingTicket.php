<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Api\ApiError;
use Illuminate\Support\Carbon;
use JsonException;
use LogicException;

/**
 * THE PHONE'S PROOF OF WHO IT IS, WHEN ITS OWN SESSION HAS TIMED OUT (the guard ring §6.5).
 *
 * A guard's API token dies thirty minutes after its last request (`SessionWindow`), and
 * the audience the escalation rings is exactly the phones that have been idle — the
 * off-duty officers. Their Guard App cannot open the alert or say "on my way" with its
 * bearer token. The window is NOT widened for them: its hook "only ever NARROWS". A ring
 * carries this ticket instead, good for two narrow acts on one alert — "I'm responding"
 * and "can't respond" — and for the phone's receipts, for thirty minutes, bound to one
 * push address and one guard.
 *
 *     v1.<base64url(json claims)>.<base64url(HMAC-SHA256(key, "v1." + claims))>
 *
 *     a  the alert (0 for a test ring)        g  the guard
 *     t  the estate                           p  the `device_push_tokens` row
 *     r  the round                            s  the test's own number (0 for a ring)
 *     i  when it was issued (unix)            k  `ring` or `test`
 *
 * THE KEY IS DERIVED FROM APP_KEY FOR THIS PURPOSE ALONE (HKDF, its own label), so a
 * ticket is not any other signature the application makes, and rotating APP_KEY ends
 * every ticket at once.
 *
 * IT IS BUILT INSIDE THE SEND JOB, ONE PER PHONE. A queued payload carries row ids and no
 * ticket — the rule `SendExpoPush` keeps for delivery addresses.
 *
 * VERIFYING THE SIGNATURE IS NOT THE WHOLE CHECK. `RingAnswers::caller()` then asks the
 * rows: the push address still live and still this guard's at this estate, the guard still
 * active with a handset bound, and — for a ring — the alert at that estate with this guard
 * on its list. A wrong alert id and another estate's alert answer the same 401.
 */
final class RingTicket
{
    public const VERSION = 'v1';

    public const RING = 'ring';

    public const TEST = 'test';

    public const INVALID = 'ring_ticket_invalid';

    public const EXPIRED = 'ring_ticket_expired';

    /** The longest ticket the endpoints accept — a real one is about 200 characters. */
    public const MAX_LENGTH = 1024;

    /**
     * @param  array{a: int, t: string, g: int, p: int, r: int, s: int, k: string}  $claims  everything but when it was issued
     */
    public static function issue(array $claims, ?Carbon $at = null): string
    {
        $body = [
            'a' => (int) $claims['a'],
            't' => (string) $claims['t'],
            'g' => (int) $claims['g'],
            'p' => (int) $claims['p'],
            'r' => (int) $claims['r'],
            's' => (int) $claims['s'],
            'i' => ($at ?? Carbon::now())->getTimestamp(),
            'k' => $claims['k'] === self::TEST ? self::TEST : self::RING,
        ];

        $encoded = self::b64(json_encode($body, JSON_THROW_ON_ERROR));

        return self::VERSION.'.'.$encoded.'.'.self::b64(self::mac(self::VERSION.'.'.$encoded));
    }

    /**
     * The claims of a ticket this server signed and that has not expired — or the refusal.
     *
     * @return array{a: int, t: string, g: int, p: int, r: int, s: int, i: int, k: string}
     *
     * @throws ApiError 401 ring_ticket_invalid, 410 ring_ticket_expired
     */
    public static function verify(mixed $ticket, ?Carbon $now = null): array
    {
        if (! is_string($ticket) || $ticket === '' || strlen($ticket) > self::MAX_LENGTH) {
            throw self::invalid();
        }

        $parts = explode('.', $ticket);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw self::invalid();
        }

        [$version, $encoded, $signature] = $parts;

        $given = self::unb64($signature);

        // Compared in constant time, before a byte of the claims is trusted.
        if ($given === null || ! hash_equals(self::mac($version.'.'.$encoded), $given)) {
            throw self::invalid();
        }

        $json = self::unb64($encoded);

        try {
            $claims = $json === null ? null : json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $claims = null;
        }

        if (! is_array($claims)) {
            throw self::invalid();
        }

        foreach (['a', 'g', 'p', 'r', 's', 'i'] as $int) {
            if (! is_int($claims[$int] ?? null) || $claims[$int] < 0) {
                throw self::invalid();
            }
        }

        if (! is_string($claims['t'] ?? null) || $claims['t'] === '' || ! in_array($claims['k'] ?? null, [self::RING, self::TEST], true)) {
            throw self::invalid();
        }

        $now ??= Carbon::now();

        if ($now->getTimestamp() >= $claims['i'] + self::minutes() * 60) {
            throw new ApiError(410, self::EXPIRED, 'This ring can no longer be answered here: it is more than '.self::minutes().' minutes old. Open the Guard App or radio dispatch.');
        }

        /** @var array{a: int, t: string, g: int, p: int, r: int, s: int, i: int, k: string} $claims */
        return $claims;
    }

    public static function invalid(string $message = 'This ring can no longer be answered here. Open the Guard App or radio dispatch.'): ApiError
    {
        return new ApiError(401, self::INVALID, $message);
    }

    private static function minutes(): int
    {
        $minutes = (int) config('ring.ticket_minutes', 30);

        return $minutes > 0 ? $minutes : 30;
    }

    private static function mac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key(), true);
    }

    /** HKDF from APP_KEY, labelled for this use and no other. */
    private static function key(): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        if ($appKey === '') {
            throw new LogicException('APP_KEY is not set, so a ring ticket cannot be signed.');
        }

        return hash_hkdf('sha256', $appKey, 32, 'gs-guard-ring-ticket-v1');
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
