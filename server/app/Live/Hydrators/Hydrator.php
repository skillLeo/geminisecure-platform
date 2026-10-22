<?php

declare(strict_types=1);

namespace App\Live\Hydrators;

/**
 * What an INLINE live event carries, read from the record as it is when published (DESIGN.md §4.1).
 *
 * Inline data is never stored: the log keeps only the safe summary, and the relay asks the event's
 * hydrator for the rest at the moment it publishes (and catch-up does the same when it answers).
 * So the data is at least as new as the event, and a record that has gone reads as null, which a
 * screen takes to mean "remove it".
 *
 * `many()` is called ONCE per hydrator key for everything one publish pass holds, with every id,
 * so it reads them in one query. It runs in the event's own scope: inside the estate for an estate
 * stream, centrally for the central one. It reuses the page's own presenter, so a live row and a
 * page load can never disagree; and it returns only fields every principal admitted to the
 * channel already sees on the matching screen.
 *
 * Declared by the event class that uses it (`static hydrators()`), found by App\Live\Registry.
 */
interface Hydrator
{
    /**
     * @param  list<string>  $entityIds
     * @return array<string, array<string, mixed>|null> by entity id; a missing or null id is a record that has gone
     */
    public function many(array $entityIds): array;
}
