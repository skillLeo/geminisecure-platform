<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveEvent;
use App\Live\Stream;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Services\Push\PanicFanOut;
use InvalidArgumentException;
use Throwable;

/**
 * EVERY STEP OF AN ALERT, TO EVERY SCREEN SHOWING IT (M11 Part C, DESIGN.md §2.4 A).
 *
 * Raised, reach recorded, acknowledged, an officer dispatched, on the way, on scene, resolved or closed as a
 * false alarm, cancelled by the raiser. The client named the resolve step because it did not move: the
 * console's resolve wrote the row and broadcast nothing, so every other open screen — a second dispatcher,
 * the alert's own page, the estate's Security page and its bar, the resident's panic timeline, the guards'
 * alert screen — waited for its poll. Now each step is recorded to the live log in the step's own
 * transaction, and every one of those screens hears it.
 *
 * WHO HEARS IT (§2.3):
 *   GD    the Gemini dispatch feed: the fan-in and the estate's own copy (Channels::gemini, which leaves the
 *         demonstration estate out)
 *   GDr   the alert's own page on the Gemini console
 *   ES    the estate console's Security feed (its page, its panic bar)
 *   R     the resident who raised it, on their own channel
 *   Gd    each guard the alert concerns once it has been raised: every officer it reached or rang
 *         (PanicFanOut::guardsFor) and the officer responding. The guard who raised a duress only if they
 *         raised it AUDIBLY: a silent duress sends nothing to the phone in a watched guard's pocket (§8.7).
 *         Never for a simulated alert. NOT on the raise itself, which reaches the guards by the push and the
 *         ring before any socket could, and whose request must not wait for the guard list (§1.6)
 *
 * A REFERENCE, NOT THE ROW. The event says which alert and which step, nothing about who, where or why; each
 * screen asks for the alert again through its own read, which applies that viewer's scope. So nothing a
 * channel's holder may not see can ride on it, and the log keeps no personal data (§4.1).
 *
 * ONE EVENT PER ESTATE. A guard's own estate can differ from the alert's (an officer posted across clients),
 * and an event names only its own estate's channels (LiveBus), so `for()` returns the alert's event and one
 * more for each other estate whose guards it concerns, carrying only those guards' channels. Record them
 * with LiveBus::recordAll().
 */
final class AlertLive extends LiveEvent
{
    /**
     * `message` (M50 §4.2 #2): the resident or the control room wrote on the alert's thread, or the other side was
     * shown what they wrote (PanicMessages). It reaches the consoles and the resident; never the guards, whose
     * screens draw no thread. `audio` (§4.2 #1): the resident's live audio began (PanicAudio) — the same audience.
     * `moved` (12 Oct 2026): the phone that raised it sent where it is now (PanicPositions), to every screen showing the
     * alert, the officers it concerns included, so the one running to it reads where the person is.
     */
    public const VERBS = ['raised', 'reach', 'acknowledged', 'dispatched', 'en_route', 'on_scene', 'resolved', 'false_alarm', 'cancelled', 'message', 'audio', 'moved'];

    /** @return list<string> every type this class emits (App\Live\LiveTypes) */
    public static function types(): array
    {
        return array_map(static fn (string $verb): string => 'alert.'.$verb, self::VERBS);
    }

    /**
     * @param  list<string>  $channels
     */
    private function __construct(
        private readonly string $verb,
        private readonly int $alertId,
        private readonly string $tenantId,
        private readonly bool $isSimulated,
        private readonly array $channels,
    ) {}

    /**
     * The events for one step of one alert: the alert's estate first, then one per other estate whose guards
     * it concerns.
     *
     * @return list<self>
     */
    public static function for(DuressAlert $alert, string $verb): array
    {
        if (! in_array($verb, self::VERBS, true)) {
            throw new InvalidArgumentException("'{$verb}' is not a step of an alert.");
        }

        $tenantId = (string) $alert->tenant_id;
        $id = (int) $alert->getKey();
        $channels = Channels::gemini('dispatch', $tenantId);

        if (! Channels::isDemonstrationEstate($tenantId)) {
            $channels[] = Channels::geminiRecord($tenantId, 'dispatch', 'alert', $id);
        }

        $channels[] = Channels::estateFeed($tenantId, 'security');

        if ($alert->raised_by_account_id !== null) {
            $channels[] = Channels::resident($tenantId, (int) $alert->raised_by_account_id);
        }

        $elsewhere = [];

        foreach (self::guardsOf($alert, $verb) as $guardId => $guardTenant) {
            if ($guardTenant === $tenantId) {
                $channels[] = Channels::guard($tenantId, $guardId);
            } else {
                $elsewhere[$guardTenant][] = Channels::guard($guardTenant, $guardId);
            }
        }

        $simulated = (bool) $alert->is_simulated;
        $events = [new self($verb, $id, $tenantId, $simulated, $channels)];

        foreach ($elsewhere as $guardTenant => $guardChannels) {
            $events[] = new self($verb, $id, (string) $guardTenant, $simulated, $guardChannels);
        }

        return $events;
    }

    /**
     * The guards one step concerns, by id, each with their OWN estate.
     *
     * @return array<int, string>
     */
    private static function guardsOf(DuressAlert $alert, string $verb): array
    {
        // The raise reaches them by the push and the ring; "phones reached" is the control room's figure. A message on
        // the resident's thread is between the resident and the control room.
        // Nor a drill raised in drill mode (M53 §3A.1): no officer was told of it, so none hears how it goes.
        if ($verb === 'raised' || $verb === 'reach' || $verb === 'message' || $verb === 'audio' || (bool) $alert->is_simulated || $alert->wakesNobody()) {
            return [];
        }

        try {
            $ids = app(PanicFanOut::class)->guardsFor($alert);
        } catch (Throwable $e) {
            // The step is recorded for every console and the resident whatever happens here.
            report($e);
            $ids = [];
        }

        if ($alert->responding_guard_id !== null) {
            $ids[] = (int) $alert->responding_guard_id;
        }

        // The raiser: only an audible duress, and guardsFor already leaves them out.
        if ($alert->guard_id !== null && $alert->getAttribute('mode') === 'audible') {
            $ids[] = (int) $alert->guard_id;
        } elseif ($alert->guard_id !== null) {
            $ids = array_values(array_diff($ids, [(int) $alert->guard_id]));
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $tenants */
        $tenants = Guard::query()->whereIn('id', $ids)->whereNotNull('tenant_id')->pluck('tenant_id', 'id')
            ->map(static fn ($tenant): string => (string) $tenant)
            ->all();

        return $tenants;
    }

    public function type(): string
    {
        return 'alert.'.$this->verb;
    }

    public function entity(): string
    {
        return 'alert';
    }

    public function entityId(): string
    {
        return (string) $this->alertId;
    }

    public function stream(): Stream
    {
        return Stream::central($this->tenantId);
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
