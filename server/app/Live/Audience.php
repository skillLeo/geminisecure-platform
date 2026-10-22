<?php

declare(strict_types=1);

namespace App\Live;

/**
 * Who hears one event, and how much they are told (DESIGN.md §2.1, §4.1).
 *
 * One `live_events` row is written per audience. `ref` (the default) sends the id and the version
 * and the client refetches that one record through an endpoint that re-applies the page's gate.
 * `inline` sends the row itself, HYDRATED WHEN PUBLISHED by the named hydrator, from the current
 * row, and never stored, and is allowed only where every principal on these channels already sees
 * those fields on the matching screen. `summary` is stored, so it may hold only what
 * App\Live\SummaryPolicy allows: enums, booleans, counts, record ids and timestamps.
 *
 * Channel names come from App\Live\Channels and nowhere else. Nothing is checked here; LiveBus
 * checks everything when the event is recorded, where a refusal can be handled without throwing
 * out of somebody's business write in production.
 */
final class Audience
{
    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $mode = 'ref',
        public readonly array $summary = [],
        public readonly ?string $hydrate = null,
    ) {}
}
