<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\LiveBus;
use App\Live\LiveEvent;
use App\Live\Stream;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * A RECORD CHANGED — every other screen that shows it reads it again (M11 §2, the rest of Part C; D-175).
 *
 * Alerts, passes, approvals, the gate, access and guard positions have their own classes: each has steps a screen
 * or a handset acts on differently (a siren, a gate refusal, a sign-out). Everything else a screen shows changes the
 * same way — it is created, it changes, it is removed — and every screen does the same thing about it: reads that
 * record, or the list it sits in, again through its own endpoint and its own gate. So one class carries them all:
 *
 *   `{entity}.changed`   created or changed      `{entity}.removed`   deleted (a screen drops it)
 *
 * A REFERENCE, NEVER THE ROW. Which record, and nothing about it: no name, no amount, no text rides a channel or
 * sits in the live log (§4.1). A screen that may not see the record learns only that something it cannot read moved.
 *
 * WHICH STREAM IS THE RECORD'S DATABASE: incidents, shifts, messages, standing orders, guard requests and payroll are
 * central; tickets, dues, notices, meetings and elections live in the estate. An estate record is told only with
 * that estate open (`tell` checks), as every request that changes one runs.
 *
 * THE CHANNELS ARE THE CALLER'S, built by App\Live\Channels for the screens that show the record (the design's
 * audience tables); LiveBus checks every one against the stream when it records.
 */
final class RecordLive extends LiveEvent
{
    /** entity => the stream its records live on. */
    public const ENTITIES = [
        'incident' => 'central',
        'shift' => 'central',
        'message' => 'central',
        'orders' => 'central',
        'request' => 'central',
        'payroll' => 'central',
        'ticket' => 'estate',
        'dues' => 'estate',
        'notice' => 'estate',
        'meeting' => 'estate',
        'election' => 'estate',
        // The register (M18H §6): a person, a household or a claim — named by the UNIT, as the register's pages are.
        'resident' => 'estate',
        // A Resident App sign-in: deleted, restored, revoked, linked — the register's "on the app" and Deleted accounts.
        'account' => 'central',
        // M18H §7.2 — the screens that still said "This screen does not update by itself":
        'booking' => 'estate',     // an amenity booking, or an amenity's terms: Facilities → Bookings
        'structure' => 'estate',   // a lot or a phase: Structure, and the register's phase chips
        'accounting' => 'estate',  // a vendor, a bill, a bill payment, an account, a reconciliation
        'staff' => 'estate',       // the estate's own payroll: an employee, a run, an exception, a filing
        'client' => 'central',     // an estate as a client of Gemini: its row, its subscription
        'invoice' => 'central',    // Gemini's invoice to an estate
        'officer' => 'central',    // a guard's record — hired, suspended, their licence (not their position: GuardPositionLive)
        'setting' => 'central',    // who may sign in to an estate's console: Settings → Users
        // M50 §4.3 — a resident's Live chat with Gemini Security: Dispatch → Live chat, the bell, and their own chat screen
        'chat' => 'central',
    ];

    public const VERBS = ['changed', 'removed'];

    /** @return list<string> every type this class emits (App\Live\LiveTypes) */
    public static function types(): array
    {
        $types = [];

        foreach (array_keys(self::ENTITIES) as $entity) {
            foreach (self::VERBS as $verb) {
                $types[] = $entity.'.'.$verb;
            }
        }

        return $types;
    }

    /**
     * @param  list<string>  $channels
     */
    private function __construct(
        private readonly string $entityName,
        private readonly string $id,
        private readonly string $verb,
        private readonly string $tenantId,
        private readonly array $channels,
        private readonly bool $isSimulated,
    ) {}

    /**
     * @param  list<string>  $channels  from App\Live\Channels
     */
    public static function for(string $entity, int|string $id, string $tenantId, array $channels, string $verb = 'changed', bool $simulated = false): self
    {
        if (! array_key_exists($entity, self::ENTITIES)) {
            throw new InvalidArgumentException("'{$entity}' is not an entity RecordLive carries.");
        }

        if (! in_array($verb, self::VERBS, true)) {
            throw new InvalidArgumentException("'{$verb}' is not a change RecordLive carries.");
        }

        return new self($entity, (string) $id, $verb, $tenantId, array_values(array_unique($channels)), $simulated);
    }

    /**
     * Record it — inside the change's own transaction when there is one, on its own commit when the write was a
     * single statement with none. An estate record with no estate open (a seeder, a fixture) is told to nobody:
     * there is no open stream and no screen watching. A failure to build the channels is reported, never thrown
     * into the write that caused it.
     *
     * @param  list<string>|callable(): list<string>  $channels
     */
    public static function tell(string $entity, int|string $id, string $tenantId, array|callable $channels, string $verb = 'changed', bool $simulated = false): void
    {
        if ((self::ENTITIES[$entity] ?? null) === 'estate' && tenant()?->getTenantKey() !== $tenantId) {
            return;
        }

        try {
            $event = self::for($entity, $id, $tenantId, is_callable($channels) ? $channels() : $channels, $verb, $simulated);
        } catch (Throwable $e) {
            if (app()->environment('local', 'testing')) {
                throw $e;
            }

            report($e);

            return;
        }

        if ($event->channels === []) {
            return;
        }

        $connection = DB::connection($event->stream()->connection());

        if ($connection->transactionLevel() > 0) {
            LiveBus::record($event);

            return;
        }

        $connection->transaction(static fn () => LiveBus::record($event));
    }

    public function type(): string
    {
        return $this->entityName.'.'.$this->verb;
    }

    public function entity(): string
    {
        return $this->entityName;
    }

    public function entityId(): string
    {
        return $this->id;
    }

    public function stream(): Stream
    {
        return self::ENTITIES[$this->entityName] === 'estate' ? Stream::estate($this->tenantId) : Stream::central($this->tenantId);
    }

    public function audiences(): array
    {
        return [new Audience($this->channels)];
    }

    public function simulated(): bool
    {
        return $this->isSimulated;
    }
}
