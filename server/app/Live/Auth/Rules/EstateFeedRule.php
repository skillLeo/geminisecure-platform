<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Enums\Console;
use App\Live\Auth\EstateScope;
use App\Live\Auth\Feeds;
use App\Live\Auth\Principal;
use App\Live\ChannelName;

/**
 * `estate.{t}.{feed}` and `estate.{t}.{feed}.{type}.{id}` (DESIGN §3.1, §3.3, §3.5).
 *
 * An estate-console user, and exactly where the estate page decides the same thing:
 *
 *   CENTRALLY FIRST — the user is active and past the contact gate, the request did
 *     not come to another estate's host, and `canAccessEstate(t)` holds: an active
 *     assignment to THIS estate. A user of estate A asking for estate B's feed is
 *     refused here, and B's database is never opened for them.
 *   THEN INSIDE THE ESTATE — every `can:` of the feed's pinned route (and a record's
 *     detail route), plus the page's own extra predicate where it has one, asked with
 *     tenancy initialised, as `routes/tenant.php` asks it for the page.
 *
 * No existence check on a record id (§3.1): the channel carries a record's events, not
 * its existence, and a foreign id and a missing one are answered alike.
 */
final class EstateFeedRule implements Rule
{
    /** @var array<string, bool> */
    private array $access = [];

    /** @var array<string, bool> */
    private array $gates = [];

    public function central(Principal $principal, ChannelName $channel): bool
    {
        $user = $principal->activeUser();
        $tenant = $channel->tenant;

        if ($user === null || $user->console !== Console::Estate || $tenant === null || $channel->feed === null) {
            return false;
        }

        if (! $principal->hostAllows($tenant)) {
            return false;
        }

        return $this->access[$tenant] ??= $user->canAccessEstate($tenant);
    }

    public function needsEstate(): bool
    {
        return true;
    }

    public function estate(Principal $principal, ChannelName $channel): bool
    {
        $user = $principal->activeUser();
        $tenant = $channel->tenant;
        $feed = $channel->feed;

        if ($user === null || $tenant === null || $feed === null) {
            return false;
        }

        $routes = Feeds::estateRoutes($feed, $channel->type);
        $extra = Feeds::ESTATE_EXTRAS[$feed] ?? null;

        return $this->gates[$tenant.'|'.implode('|', $routes)] ??= EstateScope::run(
            $tenant,
            static fn (): bool => Feeds::allows($user, $routes, $extra),
        );
    }
}
