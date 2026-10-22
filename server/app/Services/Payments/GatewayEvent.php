<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One webhook from the card provider, in the platform's own words (M16 §2.2).
 *
 * Every driver turns its provider's payload into this, so `CardGateway` never reads a provider's format. The
 * event's id is the provider's own — the thing that makes a redelivered webhook recognisable.
 */
final readonly class GatewayEvent
{
    public const AUTHORISED = 'payment.authorised';

    public const CAPTURED = 'payment.captured';

    public const SETTLED = 'payment.settled';

    public const DECLINED = 'payment.declined';

    public const REFUNDED = 'payment.refunded';

    public const CHARGED_BACK = 'payment.charged_back';

    public const TYPES = [self::AUTHORISED, self::CAPTURED, self::SETTLED, self::DECLINED, self::REFUNDED, self::CHARGED_BACK];

    public function __construct(
        public string $eventId,
        public string $type,
        public string $intentReference,
        public ?string $providerRef,
        public ?int $amountMinor,
        public int $feeMinor,
        public ?Carbon $occurredAt,
        public ?string $declineCode = null,
    ) {
        if ($eventId === '' || strlen($eventId) > 128) {
            throw new InvalidArgumentException('A gateway event needs an id of at most 128 characters.');
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown gateway event type ['.$type.'].');
        }

        if ($intentReference === '' || strlen($intentReference) > 24) {
            throw new InvalidArgumentException('A gateway event must name the intent it is about.');
        }

        if ($amountMinor !== null && $amountMinor < 0) {
            throw new InvalidArgumentException('A gateway event amount cannot be negative.');
        }

        if ($feeMinor < 0) {
            throw new InvalidArgumentException('A gateway fee cannot be negative.');
        }
    }
}
