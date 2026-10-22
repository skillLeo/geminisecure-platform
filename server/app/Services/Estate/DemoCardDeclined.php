<?php

declare(strict_types=1);

namespace App\Services\Estate;

use DomainException;

/**
 * A test card Stripe declines (M12 §2.6). Nothing was posted; the message is Stripe's own sentence and what to do next,
 * never a gateway code. `outcome` is Stripe's word for it: declined, insufficient_funds, expired_card, incorrect_cvc.
 */
final class DemoCardDeclined extends DomainException
{
    public function __construct(public readonly string $outcome, string $message)
    {
        parent::__construct($message);
    }
}
