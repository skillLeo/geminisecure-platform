<?php

declare(strict_types=1);

namespace App\Live\CatchUp;

use stdClass;

/**
 * One record, one event, in a catch-up answer (DESIGN.md §5.1, grafted from "fast").
 *
 * A screen that was away while one alert was raised, acknowledged and resolved needs to know
 * that it is resolved, not to replay the siren for a raise that is long over. So, per channel and
 * per stream, the events a catch-up returns are merged by (entity, entity_id): only the one with
 * the highest seq is kept, in seq order, and an inline one is hydrated once, now, which is the
 * record as it is (§4.1). The client's cursor still ends at the stream's head: nothing it skipped
 * is lost, because each kept event is the latest word on its record.
 *
 * An event whose meaning is not the record's latest state, but something that ADDS UP (for
 * example "these notifications were read"), must therefore name its own entity id per act, so
 * two of them are never merged into one.
 */
final class Merge
{
    /**
     * @param  list<stdClass>  $events  one stream's events on one channel, in seq order, each with `entity`, `entity_id` and `seq`
     * @return list<stdClass> the last event of each record, still in seq order
     */
    public static function latestPerRecord(array $events): array
    {
        $last = [];

        foreach ($events as $index => $event) {
            $last[$event->entity."\0".$event->entity_id] = $index;
        }

        $keep = array_flip($last);
        ksort($keep);

        return array_map(static fn (int $index): stdClass => $events[$index], array_keys($keep));
    }
}
