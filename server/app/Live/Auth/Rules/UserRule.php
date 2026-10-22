<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `user.{u}` — a console user's own channel, and only theirs (DESIGN §3.1).
 *
 * It carries `access.changed` and the bell's read marks, so it is admitted on any
 * host, the estate console's included: a person's own channel is the one thing host
 * binding leaves them everywhere (§3.2). The principal gate still applies — a
 * suspended account, or one waiting on the contact gate, holds no channel at all.
 */
final class UserRule implements Rule
{
    public function central(Principal $principal, ChannelName $channel): bool
    {
        $user = $principal->activeUser();

        return $user !== null && $channel->id !== null && (string) $user->id === $channel->id;
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
