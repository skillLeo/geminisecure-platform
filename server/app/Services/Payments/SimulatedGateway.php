<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Estate\PaymentIntent;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A PROVIDER THAT MOVES NO MONEY — for the tests and the M17 dues-day burst harness only.
 *
 * It behaves like a real card provider where it matters to the platform: it hands back a provider reference and
 * signs its webhooks the way most providers do (an HMAC-SHA256 over `timestamp.body` with the shared secret, sent
 * as `Gateway-Signature: t=<unix>,v1=<hex>`), so the verification, the replay window, the duplicate handling and
 * the ordering are exercised for real. It never talks to a network.
 *
 * IT CANNOT RUN ON A LIVE SYSTEM. `GatewayDrivers` refuses it unless DEMO_MODE is on, so a misconfigured
 * production `.env` gets "no card gateway" — never a simulator taking pretend payments (M16 §2.4).
 */
final class SimulatedGateway implements GatewayDriver
{
    public const NAME = 'simulated';

    public const HEADER = 'gateway-signature';

    public function name(): string
    {
        return self::NAME;
    }

    public function demonstrationOnly(): bool
    {
        return true;
    }

    public function createIntent(PaymentIntent $intent, string $returnUrl): array
    {
        return [
            'provider_ref' => 'sim_'.bin2hex(random_bytes(10)),
            // A real provider returns its hosted card page here; the simulator has none.
            'next_action_url' => null,
        ];
    }

    public function verify(string $rawBody, array $headers, string $secret, int $toleranceSeconds): bool
    {
        $header = $headers[self::HEADER] ?? '';

        if ($header === '' || $secret === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        // A signature older (or newer) than the tolerance is a replay, however valid it once was.
        if (abs(Carbon::now()->getTimestamp() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(self::signature($timestamp, $rawBody, $secret), $signature);
    }

    public function parse(string $rawBody): GatewayEvent
    {
        $data = json_decode($rawBody, true);

        if (! is_array($data)) {
            throw new InvalidArgumentException('The webhook body is not JSON.');
        }

        return new GatewayEvent(
            eventId: (string) ($data['id'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            intentReference: (string) ($data['intent'] ?? ''),
            providerRef: isset($data['provider_ref']) ? (string) $data['provider_ref'] : null,
            amountMinor: isset($data['amount_minor']) ? (int) $data['amount_minor'] : null,
            feeMinor: (int) ($data['fee_minor'] ?? 0),
            occurredAt: isset($data['occurred_at']) ? Carbon::parse((string) $data['occurred_at']) : null,
            declineCode: isset($data['decline_code']) ? (string) $data['decline_code'] : null,
        );
    }

    /** The signature a webhook body carries — what the tests and the harness sign with. */
    public static function signature(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /** The whole header value for a body, signed now (or at `$at`). */
    public static function header(string $rawBody, string $secret, ?int $at = null): string
    {
        $t = (string) ($at ?? Carbon::now()->getTimestamp());

        return 't='.$t.',v1='.self::signature($t, $rawBody, $secret);
    }
}
