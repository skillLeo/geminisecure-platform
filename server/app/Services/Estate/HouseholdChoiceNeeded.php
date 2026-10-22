<?php

declare(strict_types=1);

namespace App\Services\Estate;

use DomainException;

/**
 * Add resident at a lot that already has a household, with no answer to what the person is (M32 F1): a member of it,
 * its new primary resident, or a new, separate household. Nothing was saved. Its own class so the form can put the
 * sentence beside the question rather than under the lot.
 */
final class HouseholdChoiceNeeded extends DomainException {}
