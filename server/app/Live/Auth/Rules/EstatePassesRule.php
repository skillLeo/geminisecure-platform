<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Api\AppMatrix;
use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `estate.{t}.passes` — pass revocations for the gate's offline list (DESIGN §3.1).
 *
 * Any active guard of t with `gate:read`: the same guards, and the same ability, that
 * `sync.pull` hands revoked passes to. Nobody else verifies a pass at t's gate.
 */
final class EstatePassesRule implements Rule
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
