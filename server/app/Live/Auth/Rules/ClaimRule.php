<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Api\AppMatrix;
use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `claim.{t}.{c}` — the estate's decision on one unit claim, for the Pending screen
 * that is waiting on it (DESIGN §3.1, §7.3).
 *
 * The resident account whose own claim c is, at t, with `household:read` (as `me.show`
 * asks). PENDING IS ALLOWED — a pending account is the whole audience of this channel —
 * and an active one keeps it, since its claim is still its own.
 */
final class ClaimRule implements Rule
{
    public function central(Principal $principal, ChannelName $channel): bool
    {
        $account = $channel->tenant === null ? null : $principal->resident($channel->tenant, allowPending: true);

        return $account !== null
            && $account->claim_id !== null
            && $channel->id !== null
            && (string) $account->claim_id === $channel->id
            && $principal->tokenCan(AppMatrix::ability('household', AppMatrix::READ));
    }

    public function needsEstate(): bool
    {
        return false;
    }

    public function estate(Principal $principal, ChannelName $channel): bool
    {
        return false;
    }
}
