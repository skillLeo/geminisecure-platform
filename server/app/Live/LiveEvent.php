<?php

declare(strict_types=1);

namespace App\Live;

use DateTimeInterface;

/**
 * One thing that happened, said the way every open screen can hear it (DESIGN.md §2.1).
 *
 * A wiring unit writes one class per entity group in app/Live/Events, with a named constructor per
 * verb, so each emit site is one line inside its own transaction:
 *
 *     LiveBus::record(AlertLive::resolved($alert));
 *
 * `type` is the client contract (`alert.resolved`), `entity` and `entityId` name the record, and
 * `stream()` says which database's log it goes to. `audiences()` builds its channel names ONLY
 * through App\Live\Channels; it is called by LiveBus inside the recording, so a builder that
 * refuses a name is handled there rather than thrown out of the caller's write.
 *
 * The three statics are how a class declares, for App\Live\Registry to discover, what it makes
 * live, so that no wiring unit edits a shared list (§2.15, §12.2):
 *   tracked()    tables whose every write must record an event (`table`, or `table.column` for
 *                one column's updates), enforced by EmitterCoverage in the test suite;
 *   hydrators()  inline hydrator key => class, used by the relay when it publishes;
 *   exempt()     `table.column` (or `table`) => the reason a write there is deliberately silent.
 */
abstract class LiveEvent
{
    /** `{entity}.{verb}`, e.g. `alert.resolved`. */
    abstract public function type(): string;

    /** e.g. `alert`. */
    abstract public function entity(): string;

    abstract public function entityId(): string;

    /** Stream::central(?string $tenantId) or Stream::estate(string $tenantId). */
    abstract public function stream(): Stream;

    /** @return list<Audience> */
    abstract public function audiences(): array;

    /** Flushed inline before the panic fan-out (§1.6). Only `alert.raised`. */
    public function urgent(): bool
    {
        return false;
    }

    /** Written HELD (available_at NULL) until LiveBus::release, after a permission-cache flush (§1.7). */
    public function held(): bool
    {
        return false;
    }

    public function simulated(): bool
    {
        return false;
    }

    /** NULL is now; a later moment is a scheduled "re-read now" hint, not sequenced before it (§1.3). */
    public function availableAt(): ?DateTimeInterface
    {
        return null;
    }

    /** @return list<string> */
    public static function tracked(): array
    {
        return [];
    }

    /** @return array<string, class-string> */
    public static function hydrators(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public static function exempt(): array
    {
        return [];
    }
}
