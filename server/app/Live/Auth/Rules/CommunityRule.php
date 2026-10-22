<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Api\AppMatrix;
use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `community.{t}` — the notices, meetings and elections every household of t is shown
 * (DESIGN §3.1).
 *
 * Any ACTIVE resident account of t with `notices:read`. A pending account has no
 * household yet and is refused, exactly as the notices read refuses it.
 *
 * AT A LEVEL THAT READS ALL THREE. One channel carries notices (Members and Dependents)
 * and meetings and elections (Members), so a level is admitted only if it reaches every
 * read in `CARRIES` — the same rule, and the same register read, as the household
 * channel (`HouseholdRule`). The estate half runs in the account's own estate.
 */
final class CommunityRule implements Rule
{
    /** @var list<string> */
    public const CARRIES = ['notices.index', 'meetings.index', 'elections.index'];

    public function central(Principal $principal, ChannelName $channel): bool
    {
        return $channel->tenant !== null
            && $principal->resident($channel->tenant, allowPending: false) !== null
            && $principal->tokenCan(AppMatrix::ability('notices', AppMatrix::READ));
    }

    public function needsEstate(): bool
    {
        return true;
    }

    public function estate(Principal $principal, ChannelName $channel): bool
    {
        $home = $channel->tenant === null ? null : HouseholdRule::home($principal, $channel->tenant);

        return $home !== null && HouseholdRule::levelReaches($home['level'], self::CARRIES);
    }
}
