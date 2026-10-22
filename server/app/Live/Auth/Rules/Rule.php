<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * One family's answer to "may this principal hold this channel?" (DESIGN §3.1, §3.2).
 *
 * IN TWO HALVES, AND THE ORDER IS THE POINT (§3.5). `central()` asks everything that
 * needs no estate database — the principal's class, status and contact gate, the
 * tenant, the host, the token's ability, the scope — and only a channel that passes it
 * ever has an estate opened for `estate()`. So an estate is never opened for somebody
 * who does not belong to it: a foreign tenant is refused before its database is
 * touched.
 *
 * A rule returns false for anything it does not recognise, and `ChannelRegistry`
 * refuses a channel whose rule throws. There is no "allow unless".
 */
interface Rule
{
    /** The checks that need no estate database. */
    public function central(Principal $principal, ChannelName $channel): bool;

    /** Whether a channel that passed `central()` still needs `estate()`. */
    public function needsEstate(): bool;

    /** The estate half, asked only after `central()` passed, with the channel's estate open. */
    public function estate(Principal $principal, ChannelName $channel): bool;
}
