<?php

declare(strict_types=1);

namespace App\Live\Hydrators;

use App\Live\Registry as Events;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The hydrator an inline audience names (DESIGN.md §2.1, §4.1): `Registry::for('alert.console')`.
 *
 * The keys are the ones the event classes declare, collected by App\Live\Registry from
 * app/Live/Events, so no unit edits a list here. An unknown key is a mistake the relay reports and
 * never guesses around: the event is not published with invented data.
 */
final class Registry
{
    /**
     * @throws LogicException for a key no event class declares, or a class that is not a Hydrator
     */
    public static function for(string $key): Hydrator
    {
        $class = Events::hydrators()[$key] ?? null;

        if ($class === null) {
            throw new LogicException("No live event class declares the hydrator '{$key}'.");
        }

        $hydrator = app($class);

        if (! $hydrator instanceof Hydrator) {
            throw new LogicException("The hydrator '{$key}' is {$class}, which does not implement ".Hydrator::class.'.');
        }

        return $hydrator;
    }

    /**
     * The data of every inline row, ONE `many()` call per hydrator key for every id the rows hold
     * (DESIGN.md §4.1): what the relay puts in `d` when it publishes, and what catch-up puts in it
     * when it answers, so a screen is told the same thing either way.
     *
     * A key whose hydrator fails is left out of the answer and reported: the caller must then not
     * send those rows' data at all, never an empty or invented `d`.
     *
     * @param  iterable<object{mode: string, hydrate: string|null, entity_id: string|int}>  $rows
     * @return array<string, array<array-key, mixed>> hydrator key => entity id => data (null: the record is gone)
     */
    public static function hydrate(iterable $rows): array
    {
        $wanted = [];

        foreach ($rows as $row) {
            if ($row->mode === 'inline' && $row->hydrate !== null) {
                $wanted[(string) $row->hydrate][(string) $row->entity_id] = true;
            }
        }

        $hydrated = [];

        foreach ($wanted as $key => $ids) {
            try {
                $hydrated[$key] = self::for($key)->many(array_map(strval(...), array_keys($ids)));
            } catch (Throwable $e) {
                report(new RuntimeException("The live hydrator '{$key}' failed; its events are not sent with invented data.", 0, $e));
            }
        }

        return $hydrated;
    }
}
