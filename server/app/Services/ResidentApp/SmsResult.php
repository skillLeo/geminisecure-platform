<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

/**
 * WHAT HAPPENED TO ONE TEXT MESSAGE — and the difference between "sent" and
 * "written down", which this platform is not allowed to blur.
 *
 * `SmsGateway::send()` used to return nothing, because its only caller was a sign-in
 * code: if the code never arrived the resident asked for another one, and nobody had
 * to be told anything. A PANIC is the opposite case (Q-M09). Four people are texted
 * that somebody pressed an emergency button, and the platform has to be able to say,
 * afterwards and to each number, whether the message went — so the gateway now
 * answers.
 *
 * FOUR OUTCOMES, AND ONLY ONE OF THEM IS A DELIVERY:
 *
 *   SENT        a provider accepted the message. The furthest this platform can ever
 *               honestly claim: the carrier, the handset and the person are all
 *               downstream, exactly as Expo is downstream of a push.
 *   RECORDED    THERE IS NO PROVIDER. The message was written down — log, ledger —
 *               and delivered to NOBODY. This is what the platform does today, and it
 *               is reported as its own word rather than as a success, because a
 *               panic that says "texted" when no text left the building is the exact
 *               lie this product refuses to tell.
 *   FAILED      a provider was configured and refused, or threw.
 *   UNROUTABLE  the stored number has no diallable form, so nothing was attempted.
 *   SEEDED      the number belongs to a person a seeder invented, so nothing was attempted (M35 §5,
 *               `SeededRecipients`): a stranger may own a number the seed made up.
 *
 * `delivered()` is true for SENT and for nothing else. Every count the apps and the
 * console show is built on that one method, so there is one place to be wrong.
 */
final class SmsResult
{
    public const SENT = 'sent';

    public const RECORDED = 'recorded';

    public const FAILED = 'failed';

    public const UNROUTABLE = 'unroutable';

    public const SEEDED = 'seeded';

    private function __construct(
        public readonly string $status,
        public readonly string $driver,
        public readonly ?string $reference = null,
        public readonly ?string $error = null,
    ) {}

    /** A provider accepted it. */
    public static function sent(string $driver, ?string $reference = null): self
    {
        return new self(self::SENT, $driver, $reference);
    }

    /** Written down and delivered to nobody — no provider is configured. */
    public static function recorded(string $driver): self
    {
        return new self(self::RECORDED, $driver);
    }

    /** A provider was there and it did not go. `$error` is a CODE, never prose. */
    public static function failed(string $driver, string $error): self
    {
        return new self(self::FAILED, $driver, null, substr($error, 0, 64));
    }

    /** Nothing was attempted: there is no number to attempt. */
    public static function unroutable(string $driver): self
    {
        return new self(self::UNROUTABLE, $driver, null, 'no_diallable_form');
    }

    /** Nothing was attempted: the number is a seeded person's (M35 §5). */
    public static function seeded(string $driver): self
    {
        return new self(self::SEEDED, $driver, null, 'seeded_person');
    }

    /** True ONLY when a provider accepted the message. */
    public function delivered(): bool
    {
        return $this->status === self::SENT;
    }
}
