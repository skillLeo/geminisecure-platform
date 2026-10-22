<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Support\DemoMode;

/**
 * WHICH CARD PROVIDER THIS PLATFORM TALKS TO, IF ANY (M16 §2.2).
 *
 * The one place `payments.gateway.driver` is read. Null — the default — is "no gateway connected": card payments
 * are off and the phones show How to pay. A name with no driver behind it is also off, never a guess. The
 * simulator is refused unless DEMO_MODE is on, so it can never stand in for a real provider on a live system.
 *
 * When the client chooses a provider, its driver is added to `DRIVERS` and `PAYMENTS_GATEWAY=<name>` switches it
 * on — with `PAYMENTS_WEBHOOK_SECRET` from the provider's dashboard — and a restart.
 */
final class GatewayDrivers
{
    /** @var array<string, class-string<GatewayDriver>> */
    private const DRIVERS = [
        SimulatedGateway::NAME => SimulatedGateway::class,
    ];

    /** The configured driver, or null when card payments through a gateway are off. */
    public static function current(): ?GatewayDriver
    {
        $name = config('payments.gateway.driver');

        if (! is_string($name) || $name === '' || ! array_key_exists($name, self::DRIVERS)) {
            return null;
        }

        $driver = app(self::DRIVERS[$name]);

        // A driver that takes no real card and settles nothing real exists only on a demonstration server.
        return $driver->demonstrationOnly() && ! DemoMode::enabled() ? null : $driver;
    }

    /** Whether a card can be taken through a gateway right now. */
    public static function enabled(): bool
    {
        return self::current() !== null;
    }
}
