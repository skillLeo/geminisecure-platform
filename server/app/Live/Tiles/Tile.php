<?php

declare(strict_types=1);

namespace App\Live\Tiles;

use App\Models\User;

/**
 * One count or tile a screen re-reads when a `tile.changed` event names it (DESIGN.md §4.4).
 *
 * A tile says three things, and the endpoint asks them in this order:
 *   gate(user)         may this person see it at all (the page's own gate, never wider);
 *   audienceKey(user)  WHO ELSE sees exactly the same number: two people with the same key are
 *                      given one cached value, so the key must hold everything that changes the
 *                      answer (the estate, the position tier, the scope of sites...). Two viewers
 *                      whose answers differ must never share a key;
 *   compute(user)      the page's own service call, the number itself.
 *
 * Each tile is its own file, `app/Live/Tiles/<Name>Tile.php`, found by TileRegistry.
 */
interface Tile
{
    /** Lowercase, e.g. `duress_open`; the `id` of its `tile.changed` events (TileLive). */
    public function key(): string;

    public function gate(User $user): bool;

    public function audienceKey(User $user): string;

    public function compute(User $user): mixed;
}
