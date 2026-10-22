<?php

declare(strict_types=1);

namespace App\Live;

/**
 * EVERY LIVE EVENT TYPE THE SERVER CAN EMIT (DESIGN.md §8.4), for the catalogue the apps are generated from.
 *
 * The phones' handlers are held to this list (the handler-type contract): a handler registered for a type the
 * server never sends can never run, and a test says so. Each event class names its types — a class with one type
 * as its `TYPE` constant, a class with a verb per step as a static `types()` — and this collects them from every
 * class App\Live\Registry discovers, so a wiring unit that adds a class adds its types with it.
 */
final class LiveTypes
{
    /** @return list<string> sorted, each once */
    public static function all(): array
    {
        $types = [];

        foreach (Registry::events() as $class) {
            if (method_exists($class, 'types')) {
                foreach ($class::types() as $type) {
                    $types[] = (string) $type;
                }

                continue;
            }

            if (defined($class.'::TYPE')) {
                $types[] = (string) constant($class.'::TYPE');
            }
        }

        $types = array_values(array_unique($types));
        sort($types);

        return $types;
    }
}
