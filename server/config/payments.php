<?php

declare(strict_types=1);

return [
    /*
     * THE TEST-CARD CHECKOUT (M12 §2.6, the client's instruction overriding D-077; D-179). On: a resident can pay dues,
     * an amenity fee and a deposit with one of Stripe's published test cards, through to a real receipt and a lower
     * balance, marked everywhere as a demonstration payment and posted to 1090, never to the bank. No real card is
     * ever accepted or stored. Off: the phones show How to pay (bank transfer) only. Gated behind DEMO_MODE (M16 §2.4).
     */
    'demo_card' => (bool) env('PAYMENTS_DEMO_CARD', true),

    /*
     * THE CARD GATEWAY (M16 §2.2, M17 §1.2) — App\Services\Estate\CardGateway.
     *
     * Everything above the provider is built: a payment intent that a retry cannot charge twice, a signed and
     * replay-proof webhook that is the ONLY thing that moves an intent forward, forward-only states that take
     * webhooks in any order (authorised → captured → settled, and a refund or chargeback after), the receipt posted
     * to 1080 Card Payments in Transit at capture, and the settlement posted at payout — net to 1000 Bank, the
     * provider's fee to 5300 Bank & Card Charges — so the books reconcile to the bank statement.
     *
     * WHAT IS MISSING IS THE PROVIDER. `driver` names it; null — the default — means no gateway is connected, card
     * payments are OFF, and the phones show How to pay. The chosen Jamaican provider's driver (its API call and its
     * signature scheme) is the one file still to write: App\Services\Payments\GatewayDriver says what it must do.
     *
     * `simulated` is the provider used by the tests and the M17 burst harness. It refuses to run unless DEMO_MODE
     * is on (App\Services\Payments\GatewayDrivers), so it can never take a real payment — or pretend to — on a live
     * system.
     */
    'gateway' => [
        'driver' => env('PAYMENTS_GATEWAY') ?: null,

        // The shared secret the provider signs its webhooks with. No secret, no webhook is accepted.
        'webhook_secret' => env('PAYMENTS_WEBHOOK_SECRET') ?: null,

        // How old a signature's timestamp may be before the webhook is refused as a replay.
        'webhook_tolerance_seconds' => (int) env('PAYMENTS_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],
];
