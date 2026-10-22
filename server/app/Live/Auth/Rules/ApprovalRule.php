<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Api\AppMatrix;
use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `approval.{t}.{id}` — the household's answer to one walk-up request, for the guard
 * waiting at the gate (DESIGN §3.1).
 *
 * Any active guard of t with `gate:read`, as `gate.approvals.show` admits: that read
 * answers any guard of the estate about any request id of it, and this channel is no
 * wider. No existence check on the id.
 */
final class ApprovalRule implements Rule
{
    public function central(Principal $principal, ChannelName $channel): bool
    {
        return $channel->tenant !== null
            && $principal->activeGuard($channel->tenant) !== null
            && $principal->tokenCan(AppMatrix::ability('gate', AppMatrix::READ));
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
