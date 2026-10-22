<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Api\AppMatrix;
use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `phase.{t}.{k}` — the notices and meetings addressed to one phase (DESIGN §3.1).
 *
 * An ACTIVE resident account of t with `notices:read` whose own unit's block keys to k
 * (`ChannelName::phaseKey`). The key is a hash, so the block's own text never travels
 * in a channel name; a unit with no block has no phase channel.
 *
 * At a level that reads everything the channel carries, phase notices and meetings, as
 * the household channel asks it (`HouseholdRule::levelReaches`).
 */
final class PhaseRule implements Rule
{
    /** @var list<string> */
    public const CARRIES = ['notices.index', 'meetings.index'];

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

        return $home !== null
            && $home['block'] !== null
            && $channel->id !== null
            && ChannelName::phaseKey($home['block']) === $channel->id
            && HouseholdRule::levelReaches($home['level'], self::CARRIES);
    }
}
