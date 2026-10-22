<?php

declare(strict_types=1);

namespace App\Services\Estate;

use DomainException;

/**
 * AN INVITE REFUSED AT ITS ADDRESS (stage 2, W-ACCEPT), and two sentences for it.
 *
 * The APP is told only "This address can't be invited from the app. Ask the estate office." — the
 * message. It is the same for every reason. Otherwise a primary resident trying address after address
 * would learn which ones belong to a resident here, another household's invitee or somebody the office
 * cut off (DESIGN INV-14). The OFFICE, which already sees the register, is told the real reason
 * (`officeSentence`). `reason` is what is stored on the row (`resident_invites.refused_reason`) and
 * audited. (Not `code`: every exception already has an integer one.)
 */
final class InviteAddressRefused extends DomainException
{
    public const GENERIC = 'This address can\'t be invited from the app. Ask the estate office.';

    public function __construct(
        public readonly string $reason,
        public readonly string $officeSentence,
    ) {
        parent::__construct(self::GENERIC);
    }
}
