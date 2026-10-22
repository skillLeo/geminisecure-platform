<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Live\Auth\Feeds;
use App\Live\Auth\Principal;
use App\Live\ChannelName;
use App\Models\Tenant;
use App\Services\Platform\OperatorEstates;

/**
 * `gemini.{feed}`, `gemini.{t}.{feed}` and `gemini.{t}.{feed}.{type}.{id}` (DESIGN §3.1).
 *
 * Gemini staff only, evaluated centrally, as the Gemini pages are:
 *
 *   THE FAN-IN `gemini.{feed}` carries every estate's events of that feed, so it needs
 *     `widestScope() === All` — a Head of Security, scoped to assigned sites, is refused
 *     it whatever they hold. The one exception is `gemini.dashboard`: the dashboard's
 *     figures are platform-wide for everybody who may open the page, and the channel
 *     carries only tile hints and feed-row refs (§3.1).
 *   PER ESTATE `gemini.{t}.*` needs `canAccessEstate(t)` — the same answer every
 *     tenant-scoped Gemini read is given — for an estate that exists and is not the
 *     demonstration estate. The demonstration estate's live feed is refused to
 *     everybody, mirroring `routes/channels.php`: a reviewer's panic must never
 *     interrupt a real control room.
 *   THEN THE PAGE'S OWN GATE: every `can:` of the feed's pinned route, and for a record
 *     channel its detail route's too (`Feeds`). No existence check on the record id: an
 *     id that does not exist and one that is not yours are refused — or admitted — the
 *     same way, and the channel carries nothing about a record that is not there.
 *
 * On an estate's own host none of these is granted except that estate's per-estate
 * channels (host binding, §3.2); the platform-wide fan-in has no tenant, so it is not.
 */
final class GeminiFeedRule implements Rule
{
    /** @var array<string, bool> */
    private array $estates = [];

    /** @var array<string, bool> */
    private array $access = [];

    /** @var array<string, bool> */
    private array $gates = [];

    public function central(Principal $principal, ChannelName $channel): bool
    {
        $user = $principal->activeUser();

        if ($user === null || $user->console !== Console::Gemini || $channel->feed === null) {
            return false;
        }

        if (! $principal->hostAllows($channel->tenant)) {
            return false;
        }

        if ($channel->tenant === null) {
            if ($channel->feed !== 'dashboard' && $user->widestScope() !== AccessScope::All) {
                return false;
            }
        } else {
            $tenant = $channel->tenant;

            if (! $this->liveEstate($tenant)) {
                return false;
            }

            if (! ($this->access[$tenant] ??= $user->canAccessEstate($tenant))) {
                return false;
            }
        }

        $routes = Feeds::geminiRoutes($channel->feed, $channel->type);

        return $this->gates[implode('|', $routes)] ??= Feeds::allows($user, $routes);
    }

    public function needsEstate(): bool
    {
        return false;
    }

    public function estate(Principal $principal, ChannelName $channel): bool
    {
        return false;
    }

    /** An estate that exists and is not the demonstration estate. */
    private function liveEstate(string $tenantId): bool
    {
        return $this->estates[$tenantId] ??= Tenant::query()->whereKey($tenantId)->exists()
            && ! OperatorEstates::isDemonstration($tenantId);
    }
}
