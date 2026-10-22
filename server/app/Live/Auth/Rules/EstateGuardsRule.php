<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Api\AppMatrix;
use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `estate.{t}.guards` — what every guard at an estate sees of the gate and of each
 * other: the gate log and colleagues on duty (DESIGN §3.1).
 *
 * Any active guard of t whose token can read both — `gate:read`, as `gate.activity`
 * asks, and `shifts:read`, as `shifts.open` asks — so the channel is never wider than
 * the two reads it mirrors.
 */
final class EstateGuardsRule implements Rule
{
    public function central(Principal $principal, ChannelName $channel): bool
    {
        return $channel->tenant !== null
            && $principal->activeGuard($channel->tenant) !== null
            && $principal->tokenCan(AppMatrix::ability('gate', AppMatrix::READ))
            && $principal->tokenCan(AppMatrix::ability('shifts', AppMatrix::READ));
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
