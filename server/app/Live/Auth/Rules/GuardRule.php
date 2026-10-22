<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Api\AppMatrix;
use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `guard.{t}.{g}` — one guard's own channel: their shifts, messages, requests, the
 * alerts addressed to them (DESIGN §3.1).
 *
 * Guard g and nobody else, active, posted at t, and t is the estate this handset's
 * request opened — so a guard reassigned to another estate is refused the old one's
 * channel on their next grant. A console user whose id happens to be g is a User, not
 * a Guard, and is refused by class.
 */
final class GuardRule implements Rule
{
    public function central(Principal $principal, ChannelName $channel): bool
    {
        $guard = $channel->tenant === null ? null : $principal->activeGuard($channel->tenant);

        return $guard !== null
            && $channel->id !== null
            && (string) $guard->id === $channel->id
            && $principal->tokenCan(AppMatrix::ability('alerts', AppMatrix::READ));
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
