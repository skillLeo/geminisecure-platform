<?php

declare(strict_types=1);

namespace App\Services\Estate;

use DomainException;

/** What a booking costs changed between the phone showing it and the card being taken: nothing was charged (M47 §10). */
final class QuoteChanged extends DomainException {}
