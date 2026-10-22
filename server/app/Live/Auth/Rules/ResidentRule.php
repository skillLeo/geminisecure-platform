<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Api\AppMatrix;
use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `resident.{t}.{a}` — one resident account's own channel: its panic, its e-pass, its
 * notice read marks, and the access changes that concern it (DESIGN §3.1).
 *
 * Account a and nobody else, at t, never revoked, suspended or deleted. PENDING IS
 * ALLOWED, because an account waiting on its claim still has its own panic button and
 * must hear its own access change; everything that belongs to a household stays
 * refused until the estate approves the claim.
 */
final class ResidentRule implements Rule
{
    public function central(Principal $principal, ChannelName $channel): bool
    {
        $account = $channel->tenant === null ? null : $principal->resident($channel->tenant, allowPending: true);

        return $account !== null
            && $channel->id !== null
            && (string) $account->id === $channel->id
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
