<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Estate\PaymentIntent;

/**
 * WHAT A CARD PROVIDER'S DRIVER MUST DO — the one file still to write once the client chooses a Jamaican provider
 * (M16 §2.2). Everything else — idempotent intents, replay-proof webhooks, forward-only states, the ledger — is in
 * `App\Services\Estate\CardGateway` and does not change per provider.
 *
 * A driver is three things:
 *
 *   createIntent   ask the provider to start a payment for this intent, and say where the resident goes to enter
 *                  their card (the provider's hosted page — the card number never touches this platform, M16 §2.5)
 *   verify         whether a webhook really came from the provider: its signature, over the RAW body, with the
 *                  shared secret, and a timestamp no older than the tolerance (so a captured webhook cannot be
 *                  replayed later)
 *   parse          the provider's payload, in the platform's words (`GatewayEvent`)
 *
 * `SimulatedGateway` is the reference implementation, and the tests describe the contract.
 */
interface GatewayDriver
{
    /** The provider's name, as it appears in the webhook URL and on every intent. */
    public function name(): string;

    /** True for a driver that moves no real money: `GatewayDrivers` offers it only on a demonstration server. */
    public function demonstrationOnly(): bool;

    /**
     * @return array{provider_ref: string, next_action_url: string|null}
     */
    public function createIntent(PaymentIntent $intent, string $returnUrl): array;

    /** @param  array<string, string>  $headers  lower-cased header names */
    public function verify(string $rawBody, array $headers, string $secret, int $toleranceSeconds): bool;

    /** @throws \InvalidArgumentException when the payload is not an event this driver understands */
    public function parse(string $rawBody): GatewayEvent;
}
