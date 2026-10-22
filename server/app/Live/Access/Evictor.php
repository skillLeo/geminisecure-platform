<?php

declare(strict_types=1);

namespace App\Live\Access;

use App\Live\Relay\Clock;
use App\Live\Relay\PublishFailed;
use App\Live\Relay\Transport;

/**
 * A PERMISSION CHANGE TAKES EFFECT IN THE OPEN SESSION (DESIGN.md §7.1).
 *
 * When somebody's access changes, the business transaction records `access.changed` on their own
 * channel (`user.{u}`, `guard.{t}.{g}`, `resident.{t}.{a}`). The relay publishes it, so the open
 * console reloads its page and the phone re-reads what it may hold; and 250 ms later the relay
 * asks Reverb to close every socket that principal holds (`terminate_connections` for `u-12`,
 * `g-5`, `r-9`, the key signed into each of its grants, §3.6). Each client reconnects and
 * re-authorises every channel under the new rules, and a channel now refused stays refused. The
 * 250 ms is the message's head start: a socket closed first would never hear why.
 *
 * The event is in the log, so catch-up delivers it even to a socket that dropped before it came.
 *
 * Held access events (§1.7) never reach here early: they are not sequenced, so not published,
 * until RoleEditor has flushed the permission cache and released them.
 *
 * ONLY THE RELAY EVICTS. It is the one process that is still running 250 ms later; a request
 * would have answered and gone. Relay::flushNow therefore never publishes an access event.
 */
final class Evictor
{
    /** The message's head start over the socket being closed. */
    public const DELAY_SECONDS = 0.25;

    private const PRINCIPALS = [
        '/\Auser\.([1-9][0-9]{0,19})\z/' => 'u-',
        '/\Aguard\.[a-z][a-z0-9]{2,29}\.([1-9][0-9]{0,19})\z/' => 'g-',
        '/\Aresident\.[a-z][a-z0-9]{2,29}\.([1-9][0-9]{0,19})\z/' => 'r-',
    ];

    /** @var array<string, float> principal key => when to close its sockets */
    private array $due = [];

    public function __construct(
        private readonly Transport $transport,
        private readonly Clock $clock,
    ) {}

    /** The principal whose own channel this is, or null for any other channel. */
    public static function principalOf(string $channel): ?string
    {
        foreach (self::PRINCIPALS as $pattern => $prefix) {
            if (preg_match($pattern, $channel, $match) === 1) {
                return $prefix.$match[1];
            }
        }

        return null;
    }

    /**
     * The publisher has just published this event on these channels. An access event schedules its
     * principals' sockets to close; anything else is ignored.
     *
     * @param  list<string>  $channels
     */
    public function published(string $type, array $channels): void
    {
        if (! str_starts_with($type, 'access.')) {
            return;
        }

        $at = $this->clock->now() + self::DELAY_SECONDS;

        foreach ($channels as $channel) {
            $key = self::principalOf($channel);

            if ($key !== null) {
                $this->due[$key] ??= $at;
            }
        }
    }

    /**
     * Close the sockets whose time has come. A failure is reported and not retried: the event is
     * published and in the log, the reconcile backstop re-checks every grant within five minutes
     * (§7.4), and a Reverb that cannot be reached has no sockets to close.
     *
     * @return list<string> the principal keys terminated
     */
    public function runDue(): array
    {
        $now = $this->clock->now();
        $done = [];

        foreach ($this->due as $key => $at) {
            if ($at > $now) {
                continue;
            }

            unset($this->due[$key]);

            try {
                $this->transport->terminate($key, 0.5, 2.0);
                $done[] = $key;
            } catch (PublishFailed $e) {
                report($e);
            }
        }

        return $done;
    }

    /** Seconds until the next termination is due, or null when none is waiting. */
    public function nextDueIn(): ?float
    {
        if ($this->due === []) {
            return null;
        }

        return max(0.0, min($this->due) - $this->clock->now());
    }

    /** @return array<string, float> */
    public function pending(): array
    {
        return $this->due;
    }
}
