<?php

declare(strict_types=1);

namespace App\Services\Estate;

use DomainException;
use Illuminate\Support\Carbon;

/**
 * The period asked for is already somebody else's (`Amenities::book`). A DomainException like every other refusal, so
 * the console reads the estate's own sentence; it also carries WHEN the diary is taken, so the Resident App can say
 * so in the household's terms — the hours, never whose booking it is or its reference (M21 §1.5).
 */
final class SlotTaken extends DomainException
{
    public function __construct(string $message, public readonly Carbon $from, public readonly Carbon $to)
    {
        parent::__construct($message);
    }
}
