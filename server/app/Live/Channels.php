<?php

declare(strict_types=1);

namespace App\Live;

use App\Services\Platform\OperatorEstates;
use ArrayObject;
use InvalidArgumentException;

/**
 * The only place a live channel name is made (DESIGN.md §2.1, §3.1).
 *
 * THE CLIENT NEVER BUILDS A CHANNEL NAME. Pages and shells are handed their channel list by the
 * server, and every event names its audiences through these methods, so a name is either one of
 * the families below, in its one canonical spelling, or it does not exist. Names go on the wire as
 * `private-live.<name>`; that prefix is the signer's, never part of a name here.
 *
 * WHEN A NAME CARRIES A TENANT, THE TENANT IS THE SECOND SEGMENT. LiveBus relies on that to refuse,
 * at the moment an event is written, any channel of an estate the event is not about (§1.3):
 * `tenantOf()` reads it back, and refuses anything these builders could not have produced.
 *
 * EACH NAME IS CARRIED BY THE STREAMS §3.1 SAYS, AND NO OTHER. `streamsOf()` is that column: a
 * client keeps a cursor per (channel, stream) and catch-up reads a channel only from its own
 * streams, so an event named on any other stream would be sent live once and never recovered
 * after a drop (C6). LiveBus refuses it when the event is written, and the Sequencer refuses a row
 * that got past it.
 *
 * THE DEMONSTRATION ESTATE NEVER REACHES A GEMINI FEED. The Gemini console's live feeds refuse the
 * app-store reviewers' estate for every subscriber (routes/channels.php, "THE DEMONSTRATION ESTATE
 * IS NOT IN A DISPATCHER'S SCOPE"). `gemini()` is how an event about an estate names its Gemini
 * audience, and for that estate it names nothing. The single-name builders (`geminiFanIn()`,
 * `geminiFeed()`, `geminiRecord()`) only format; the rule is not theirs to keep, because the
 * fan-in carries every estate and no subscriber could filter it. LiveBus keeps it, when the event
 * is written: an event about the demonstration estate that names any gemini channel, however it
 * was built, is refused (DESIGN.md §2.1, §2.3 GD).
 *
 *   user.{u}                                 a console User
 *   gemini.{feed}                            Gemini fan-in (every estate but the demonstration one)
 *   gemini.{t}.{feed}                        one estate's copy of a Gemini feed
 *   gemini.{t}.{feed}.{type}.{id}            one record on a Gemini detail screen
 *   estate.{t}.{feed}                        an estate console feed
 *   estate.{t}.{feed}.{type}.{id}            one record on an estate detail screen
 *   estate.{t}.guards / estate.{t}.passes    every active guard of t / the gate's revoked passes
 *   guard.{t}.{g}  approval.{t}.{id}  household.{t}.{h}  resident.{t}.{a}  claim.{t}.{c}
 *   community.{t}  phase.{t}.{k}             k = first 10 hex of sha1(lower(trim(block)))
 *   live-pulse                               public: {at, build} only, and never an event's
 */
final class Channels
{
    /** @var list<string> */
    public const ESTATE_FEEDS = [
        'security', 'facilities', 'dues', 'ledger', 'payments', 'accounting', 'governance',
        'residents', 'settings', 'billing', 'console', 'payroll', 'structure',
    ];

    /** @var list<string> */
    public const GEMINI_FEEDS = ['dispatch', 'workforce', 'clients', 'billing', 'audit', 'settings', 'payroll', 'dashboard'];

    /**
     * Gemini feeds that exist only platform-wide: no `gemini.{t}.{feed}` copy (§3.1).
     *
     * @var list<string>
     */
    public const GEMINI_PLATFORM_ONLY = ['settings', 'dashboard'];

    /**
     * The record channels each estate feed has, by the detail screens that exist (§3.1).
     *
     * @var array<string, list<string>>
     */
    public const ESTATE_RECORDS = [
        'facilities' => ['ticket'],
        'ledger' => ['unit'],
        'residents' => ['unit'],
        'governance' => ['meeting', 'ballot', 'nomination'],
    ];

    /** @var array<string, list<string>> */
    public const GEMINI_RECORDS = [
        'dispatch' => ['alert'],
        'workforce' => ['incident', 'orders', 'guard'],
    ];

    /**
     * §3.1's Streams column, one entry per row of the grammar: which log may carry a channel of
     * each family (`c` the central one, `e` its estate's own). It is what LiveBus and the
     * Sequencer enforce, and what catch-up must read a channel from (ChannelRegistry::streamsFor),
     * so the side that writes and the side that reads cannot disagree about where an event is.
     *
     * @var array<string, list<string>>
     */
    public const STREAMS = [
        'user.{u}' => ['c'],
        'gemini.{feed}' => ['c'],
        'gemini.{t}.{feed}' => ['c'],
        'gemini.{t}.{feed}.{type}.{id}' => ['c'],
        'estate.{t}.{feed}' => ['c', 'e'],
        'estate.{t}.{feed}.{type}.{id}' => ['c', 'e'],
        'guard.{t}.{g}' => ['c', 'e'],
        'estate.{t}.guards' => ['c', 'e'],
        'estate.{t}.passes' => ['e'],
        'approval.{t}.{id}' => ['e'],
        'household.{t}.{h}' => ['c', 'e'],
        'resident.{t}.{a}' => ['c', 'e'],
        'community.{t}' => ['e'],
        'phase.{t}.{k}' => ['e'],
        'claim.{t}.{c}' => ['e'],
    ];

    public const PULSE = 'live-pulse';

    /** The families whose name is `{family}.{t}.{record id}`. */
    private const PERSONAL_FAMILIES = [
        'guard' => 'guard.{t}.{g}',
        'approval' => 'approval.{t}.{id}',
        'household' => 'household.{t}.{h}',
        'resident' => 'resident.{t}.{a}',
        'claim' => 'claim.{t}.{c}',
    ];

    /** The container key of the demonstration-estate answers kept for one request or queued job. */
    private const DEMONSTRATION_MEMO = 'live.channels.demonstration';

    private const ID_PATTERN = '/^[1-9][0-9]{0,19}$/';

    private const PHASE_PATTERN = '/^[0-9a-f]{10}$/';

    public static function user(int|string $userId): string
    {
        return 'user.'.self::id($userId);
    }

    /**
     * A Gemini feed's audience for an event about one estate (or, NULL, about the platform): the
     * fan-in channel, and the estate's own copy unless the feed is platform-only. Nothing at all for
     * the demonstration estate. THIS IS HOW AN EVENT ABOUT AN ESTATE NAMES ITS GEMINI AUDIENCE.
     *
     * @return list<string>
     */
    public static function gemini(string $feed, ?string $tenantId): array
    {
        self::geminiFeedName($feed);

        if ($tenantId !== null) {
            self::tenant($tenantId);

            if (self::isDemonstrationEstate($tenantId)) {
                return [];
            }
        }

        $channels = [self::geminiFanIn($feed)];

        if ($tenantId !== null && ! in_array($feed, self::GEMINI_PLATFORM_ONLY, true)) {
            $channels[] = self::geminiFeed($tenantId, $feed);
        }

        return $channels;
    }

    /**
     * The fan-in channel alone: for a PLATFORM event (Stream::central() with no estate). An event
     * about an estate names it through gemini(), which leaves the demonstration estate out;
     * LiveBus refuses a demonstration estate's event that names it directly.
     */
    public static function geminiFanIn(string $feed): string
    {
        return 'gemini.'.self::geminiFeedName($feed);
    }

    /**
     * Whether an estate is the app-store reviewers' demonstration estate, which no Gemini feed
     * carries. Asked of the database once per estate per request or queued job (a scoped
     * instance, which the queue worker forgets between jobs), so a panic that names its Gemini
     * audience through gemini() and is then checked by LiveBus pays for one query, not two.
     */
    public static function isDemonstrationEstate(string $tenantId): bool
    {
        $app = app();

        if (! $app->bound(self::DEMONSTRATION_MEMO)) {
            $app->scoped(self::DEMONSTRATION_MEMO, static fn (): ArrayObject => new ArrayObject);
        }

        /** @var ArrayObject<string, bool> $memo */
        $memo = $app->make(self::DEMONSTRATION_MEMO);

        if (! $memo->offsetExists($tenantId)) {
            $memo->offsetSet($tenantId, OperatorEstates::isDemonstration($tenantId));
        }

        return (bool) $memo->offsetGet($tenantId);
    }

    public static function geminiFeed(string $tenantId, string $feed): string
    {
        if (in_array(self::geminiFeedName($feed), self::GEMINI_PLATFORM_ONLY, true)) {
            throw new InvalidArgumentException("gemini.{$feed} is platform-wide only; there is no per-estate copy.");
        }

        return 'gemini.'.self::tenant($tenantId).'.'.$feed;
    }

    public static function geminiRecord(string $tenantId, string $feed, string $type, int|string $id): string
    {
        if (! in_array($type, self::GEMINI_RECORDS[$feed] ?? [], true)) {
            throw new InvalidArgumentException("gemini.{$feed} has no '{$type}' record channel.");
        }

        return self::geminiFeed($tenantId, $feed).'.'.$type.'.'.self::id($id);
    }

    public static function estateFeed(string $tenantId, string $feed): string
    {
        if (! in_array($feed, self::ESTATE_FEEDS, true)) {
            throw new InvalidArgumentException("'{$feed}' is not an estate live feed.");
        }

        return 'estate.'.self::tenant($tenantId).'.'.$feed;
    }

    public static function estateRecord(string $tenantId, string $feed, string $type, int|string $id): string
    {
        if (! in_array($type, self::ESTATE_RECORDS[$feed] ?? [], true)) {
            throw new InvalidArgumentException("estate.{t}.{$feed} has no '{$type}' record channel.");
        }

        return self::estateFeed($tenantId, $feed).'.'.$type.'.'.self::id($id);
    }

    public static function guard(string $tenantId, int|string $guardId): string
    {
        return 'guard.'.self::tenant($tenantId).'.'.self::id($guardId);
    }

    public static function estateGuards(string $tenantId): string
    {
        return 'estate.'.self::tenant($tenantId).'.guards';
    }

    public static function estatePasses(string $tenantId): string
    {
        return 'estate.'.self::tenant($tenantId).'.passes';
    }

    public static function approval(string $tenantId, int|string $approvalId): string
    {
        return 'approval.'.self::tenant($tenantId).'.'.self::id($approvalId);
    }

    public static function household(string $tenantId, int|string $householdId): string
    {
        return 'household.'.self::tenant($tenantId).'.'.self::id($householdId);
    }

    public static function resident(string $tenantId, int|string $accountId): string
    {
        return 'resident.'.self::tenant($tenantId).'.'.self::id($accountId);
    }

    public static function community(string $tenantId): string
    {
        return 'community.'.self::tenant($tenantId);
    }

    /** One phase (block) of an estate, keyed by a hash so the block's name is never on the wire. */
    public static function phase(string $tenantId, string $block): string
    {
        return 'phase.'.self::tenant($tenantId).'.'.self::phaseKey($block);
    }

    public static function phaseKey(string $block): string
    {
        return substr(sha1(strtolower(trim($block))), 0, 10);
    }

    public static function claim(string $tenantId, int|string $claimId): string
    {
        return 'claim.'.self::tenant($tenantId).'.'.self::id($claimId);
    }

    /** The public heartbeat channel. The relay's alone: no event is ever recorded to it. */
    public static function pulse(): string
    {
        return self::PULSE;
    }

    /**
     * The estate a channel belongs to, or NULL for a platform channel (`user.*`, `gemini.{feed}`).
     *
     * @throws InvalidArgumentException for any name these builders could not have produced,
     *                                  the public pulse included
     */
    public static function tenantOf(string $channel): ?string
    {
        return self::parse($channel)[1];
    }

    /**
     * The row of the §3.1 grammar a name belongs to: a key of STREAMS, e.g. `estate.{t}.passes`.
     *
     * @throws InvalidArgumentException for any name these builders could not have produced
     */
    public static function familyOf(string $channel): string
    {
        return self::parse($channel)[0];
    }

    /**
     * The streams whose log may carry this channel (§3.1): `['c']`, `['e']` or `['c', 'e']`.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException for any name these builders could not have produced
     */
    public static function streamsOf(string $channel): array
    {
        return self::STREAMS[self::familyOf($channel)];
    }

    /** Whether a name is a feed channel (never a per-record or per-person one). */
    public static function isFeed(string $channel): bool
    {
        try {
            return in_array(self::familyOf($channel), ['gemini.{feed}', 'gemini.{t}.{feed}', 'estate.{t}.{feed}'], true);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * A name's family and its tenant (NULL for a platform channel), or a refusal.
     *
     * @return array{0: string, 1: string|null}
     *
     * @throws InvalidArgumentException
     */
    private static function parse(string $channel): array
    {
        $parts = explode('.', $channel);
        $family = $parts[0];
        $count = count($parts);

        $tenant = isset($parts[1]) && preg_match(Stream::TENANT_PATTERN, $parts[1]) === 1 ? $parts[1] : null;
        $isId = static fn (int $at): bool => isset($parts[$at]) && preg_match(self::ID_PATTERN, $parts[$at]) === 1;
        $inEstate = $tenant !== null;

        $name = match (true) {
            $family === 'user' && $count === 2 && $isId(1) => 'user.{u}',
            $family === 'gemini' && $count === 2 && in_array($parts[1], self::GEMINI_FEEDS, true) => 'gemini.{feed}',
            $family === 'gemini' && $count === 3 && $inEstate
                && in_array($parts[2], self::GEMINI_FEEDS, true)
                && ! in_array($parts[2], self::GEMINI_PLATFORM_ONLY, true) => 'gemini.{t}.{feed}',
            $family === 'gemini' && $count === 5 && $inEstate
                && in_array($parts[3], self::GEMINI_RECORDS[$parts[2]] ?? [], true)
                && $isId(4) => 'gemini.{t}.{feed}.{type}.{id}',
            $family === 'estate' && $count === 3 && $inEstate && in_array($parts[2], self::ESTATE_FEEDS, true) => 'estate.{t}.{feed}',
            $family === 'estate' && $count === 3 && $inEstate && $parts[2] === 'guards' => 'estate.{t}.guards',
            $family === 'estate' && $count === 3 && $inEstate && $parts[2] === 'passes' => 'estate.{t}.passes',
            $family === 'estate' && $count === 5 && $inEstate
                && in_array($parts[3], self::ESTATE_RECORDS[$parts[2]] ?? [], true)
                && $isId(4) => 'estate.{t}.{feed}.{type}.{id}',
            isset(self::PERSONAL_FAMILIES[$family]) && $count === 3 && $inEstate && $isId(2) => self::PERSONAL_FAMILIES[$family],
            $family === 'community' && $count === 2 && $inEstate => 'community.{t}',
            $family === 'phase' && $count === 3 && $inEstate && preg_match(self::PHASE_PATTERN, $parts[2]) === 1 => 'phase.{t}.{k}',
            default => null,
        };

        if ($name === null) {
            throw new InvalidArgumentException("'{$channel}' is not a live channel name App\\Live\\Channels can produce.");
        }

        return [$name, in_array($name, ['user.{u}', 'gemini.{feed}'], true) ? null : $tenant];
    }

    private static function geminiFeedName(string $feed): string
    {
        if (! in_array($feed, self::GEMINI_FEEDS, true)) {
            throw new InvalidArgumentException("'{$feed}' is not a Gemini live feed.");
        }

        return $feed;
    }

    private static function tenant(string $tenantId): string
    {
        if (preg_match(Stream::TENANT_PATTERN, $tenantId) !== 1) {
            throw new InvalidArgumentException("'{$tenantId}' is not an estate key.");
        }

        return $tenantId;
    }

    private static function id(int|string $id): string
    {
        $id = (string) $id;

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException("'{$id}' is not a record id: a positive whole number with no leading zero.");
        }

        return $id;
    }
}
