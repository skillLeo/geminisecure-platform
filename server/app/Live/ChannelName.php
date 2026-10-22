<?php

declare(strict_types=1);

namespace App\Live;

use InvalidArgumentException;

/**
 * THE ONE PARSER AND FORMATTER OF A LIVE CHANNEL NAME (M11 Part C, DESIGN §3.1).
 *
 * A live channel is sent on the wire as `private-live.<name>`, and `<name>` is written
 * in exactly one way. Everything that authorises, catches up or lists a channel reads
 * the name through `parse()`, and everything that writes one goes through a formatter
 * below, which refuses to produce a name `parse()` would not read back. A name that
 * could be spelled two ways is two names to authorise, and the second spelling is the
 * one nobody tested — so there is no second spelling:
 *
 *   - lower-case letters and digits only, in dot-separated segments, nothing else:
 *     no upper case, no whitespace, no empty segment, no hyphen (so no `presence-`
 *     and no `private-encrypted-` alias can reach a rule);
 *   - an id is a positive integer with no leading zero;
 *   - a tenant key is what `EstateProvisioner` accepts: `^[a-z][a-z0-9]{2,29}$`;
 *   - a phase key is exactly ten lower-case hex characters (`phaseKey()`);
 *   - a feed, a record type and a family come from the fixed lists here, and a
 *     platform-only Gemini feed (`settings`, `dashboard`) never carries a tenant.
 *
 * WHEN A NAME CARRIES A TENANT, THE TENANT IS ALWAYS THE SECOND SEGMENT. `LiveBus`
 * relies on that to refuse an event whose channels name another estate (§1.3).
 *
 * `live-pulse` is the one public channel. It parses, so that it can be listed and
 * checked like any other name, but no door signs it: a public channel needs no grant,
 * and `fromWire()` never returns it.
 */
final class ChannelName
{
    /** What every private live channel is prefixed with on the wire. */
    public const WIRE_PREFIX = 'private-live.';

    /** The public heartbeat channel: `{at, build}` only. */
    public const PULSE = 'live-pulse';

    public const USER = 'user';

    public const GEMINI_FEED = 'gemini.feed';

    public const GEMINI_TENANT_FEED = 'gemini.tenant_feed';

    public const GEMINI_RECORD = 'gemini.record';

    public const ESTATE_FEED = 'estate.feed';

    public const ESTATE_RECORD = 'estate.record';

    public const GUARD = 'guard';

    public const ESTATE_GUARDS = 'estate.guards';

    public const ESTATE_PASSES = 'estate.passes';

    public const APPROVAL = 'approval';

    public const HOUSEHOLD = 'household';

    public const RESIDENT = 'resident';

    public const COMMUNITY = 'community';

    public const PHASE = 'phase';

    public const CLAIM = 'claim';

    public const PULSE_FAMILY = 'pulse';

    /** Every family §3.1 defines. */
    public const FAMILIES = [
        self::USER,
        self::GEMINI_FEED,
        self::GEMINI_TENANT_FEED,
        self::GEMINI_RECORD,
        self::ESTATE_FEED,
        self::ESTATE_RECORD,
        self::GUARD,
        self::ESTATE_GUARDS,
        self::ESTATE_PASSES,
        self::APPROVAL,
        self::HOUSEHOLD,
        self::RESIDENT,
        self::COMMUNITY,
        self::PHASE,
        self::CLAIM,
        self::PULSE_FAMILY,
    ];

    /** The estate console's feeds, revision 2 included (`payroll`, `structure`). */
    public const ESTATE_FEEDS = [
        'security', 'facilities', 'dues', 'ledger', 'payments', 'accounting', 'governance',
        'residents', 'settings', 'billing', 'console', 'payroll', 'structure',
    ];

    /** The Gemini console's feeds, revision 2 included (`payroll`, `dashboard`). */
    public const GEMINI_FEEDS = ['dispatch', 'workforce', 'clients', 'billing', 'audit', 'settings', 'payroll', 'dashboard'];

    /** Gemini feeds that exist only as the platform channel, never per estate. */
    public const GEMINI_PLATFORM_ONLY = ['settings', 'dashboard'];

    /**
     * The detail screens with a record channel of their own, per feed.
     *
     * @var array<string, list<string>>
     */
    public const ESTATE_RECORD_TYPES = [
        'facilities' => ['ticket'],
        'ledger' => ['unit'],
        'residents' => ['unit'],
        'governance' => ['meeting', 'ballot', 'nomination'],
    ];

    /** @var array<string, list<string>> */
    public const GEMINI_RECORD_TYPES = [
        'dispatch' => ['alert'],
        'workforce' => ['incident', 'orders', 'guard'],
    ];

    /** Which log a family's cursor follows: `c` central, `e` the estate's own (§3.1). */
    private const STREAMS = [
        self::USER => ['c'],
        self::GEMINI_FEED => ['c'],
        self::GEMINI_TENANT_FEED => ['c'],
        self::GEMINI_RECORD => ['c'],
        self::ESTATE_FEED => ['c', 'e'],
        self::ESTATE_RECORD => ['c', 'e'],
        self::GUARD => ['c', 'e'],
        self::ESTATE_GUARDS => ['c', 'e'],
        self::ESTATE_PASSES => ['e'],
        self::APPROVAL => ['e'],
        self::HOUSEHOLD => ['c', 'e'],
        self::RESIDENT => ['c', 'e'],
        self::COMMUNITY => ['e'],
        self::PHASE => ['e'],
        self::CLAIM => ['e'],
        self::PULSE_FAMILY => [],
    ];

    /** `live_deliveries.channel` and `live_grants.channel` are VARCHAR(160). */
    public const MAX_LENGTH = 160;

    private const SHAPE = '/\A[a-z0-9]+(?:\.[a-z0-9]+)*\z/';

    private const TENANT = '/\A[a-z][a-z0-9]{2,29}\z/';

    private const ID = '/\A[1-9][0-9]{0,18}\z/';

    private const PHASE_KEY = '/\A[0-9a-f]{10}\z/';

    private function __construct(
        public readonly string $family,
        public readonly string $name,
        public readonly ?string $tenant = null,
        public readonly ?string $feed = null,
        public readonly ?string $type = null,
        public readonly ?string $id = null,
    ) {}

    /** The canonical bare name, parsed; null for anything written any other way. */
    public static function parse(string $name): ?self
    {
        if ($name === self::PULSE) {
            return new self(self::PULSE_FAMILY, $name);
        }

        if (strlen($name) > self::MAX_LENGTH || preg_match(self::SHAPE, $name) !== 1) {
            return null;
        }

        $s = explode('.', $name);
        $n = count($s);

        return match ($s[0]) {
            'user' => $n === 2 && self::isId($s[1]) ? new self(self::USER, $name, id: $s[1]) : null,
            'gemini' => self::parseGemini($name, $s),
            'estate' => self::parseEstate($name, $s),
            'guard' => self::tenantAndId(self::GUARD, $name, $s),
            'approval' => self::tenantAndId(self::APPROVAL, $name, $s),
            'household' => self::tenantAndId(self::HOUSEHOLD, $name, $s),
            'resident' => self::tenantAndId(self::RESIDENT, $name, $s),
            'claim' => self::tenantAndId(self::CLAIM, $name, $s),
            'community' => $n === 2 && self::isTenant($s[1]) ? new self(self::COMMUNITY, $name, $s[1]) : null,
            'phase' => $n === 3 && self::isTenant($s[1]) && preg_match(self::PHASE_KEY, $s[2]) === 1
                ? new self(self::PHASE, $name, $s[1], id: $s[2])
                : null,
            default => null,
        };
    }

    /**
     * A wire name — exactly `private-live.` and a canonical name after it — or null.
     *
     * The door's half of the principal gate's last step (§3.2 item 6): only the
     * `private-live.` prefix is accepted, so a `presence-live.` or
     * `private-encrypted-live.` alias of an admitted name is refused before any rule
     * reads it. The public pulse is never returned: it is not granted, it is joined.
     */
    public static function fromWire(string $wire): ?self
    {
        if (! str_starts_with($wire, self::WIRE_PREFIX)) {
            return null;
        }

        $channel = self::parse(substr($wire, strlen(self::WIRE_PREFIX)));

        return $channel === null || $channel->family === self::PULSE_FAMILY ? null : $channel;
    }

    /** The name as a socket subscribes to it. */
    public function wire(): string
    {
        return $this->family === self::PULSE_FAMILY ? self::PULSE : self::WIRE_PREFIX.$this->name;
    }

    /**
     * The streams whose cursor this channel keeps.
     *
     * @return list<string>
     */
    public function streams(): array
    {
        return self::STREAMS[$this->family];
    }

    public static function user(int|string $userId): string
    {
        return self::formatted(self::USER, 'user.'.self::idText($userId));
    }

    public static function geminiFeed(string $feed): string
    {
        return self::formatted(self::GEMINI_FEED, 'gemini.'.$feed);
    }

    public static function geminiTenantFeed(string $tenantId, string $feed): string
    {
        return self::formatted(self::GEMINI_TENANT_FEED, 'gemini.'.$tenantId.'.'.$feed);
    }

    public static function geminiRecord(string $tenantId, string $feed, string $type, int|string $id): string
    {
        return self::formatted(self::GEMINI_RECORD, 'gemini.'.$tenantId.'.'.$feed.'.'.$type.'.'.self::idText($id));
    }

    public static function estateFeed(string $tenantId, string $feed): string
    {
        return self::formatted(self::ESTATE_FEED, 'estate.'.$tenantId.'.'.$feed);
    }

    public static function estateRecord(string $tenantId, string $feed, string $type, int|string $id): string
    {
        return self::formatted(self::ESTATE_RECORD, 'estate.'.$tenantId.'.'.$feed.'.'.$type.'.'.self::idText($id));
    }

    public static function guard(string $tenantId, int|string $guardId): string
    {
        return self::formatted(self::GUARD, 'guard.'.$tenantId.'.'.self::idText($guardId));
    }

    public static function estateGuards(string $tenantId): string
    {
        return self::formatted(self::ESTATE_GUARDS, 'estate.'.$tenantId.'.guards');
    }

    public static function estatePasses(string $tenantId): string
    {
        return self::formatted(self::ESTATE_PASSES, 'estate.'.$tenantId.'.passes');
    }

    public static function approval(string $tenantId, int|string $approvalId): string
    {
        return self::formatted(self::APPROVAL, 'approval.'.$tenantId.'.'.self::idText($approvalId));
    }

    public static function household(string $tenantId, int|string $householdId): string
    {
        return self::formatted(self::HOUSEHOLD, 'household.'.$tenantId.'.'.self::idText($householdId));
    }

    public static function resident(string $tenantId, int|string $accountId): string
    {
        return self::formatted(self::RESIDENT, 'resident.'.$tenantId.'.'.self::idText($accountId));
    }

    public static function community(string $tenantId): string
    {
        return self::formatted(self::COMMUNITY, 'community.'.$tenantId);
    }

    /** @param  string  $key  a `phaseKey()`, never the block's own text */
    public static function phase(string $tenantId, string $key): string
    {
        return self::formatted(self::PHASE, 'phase.'.$tenantId.'.'.$key);
    }

    public static function claim(string $tenantId, int|string $claimId): string
    {
        return self::formatted(self::CLAIM, 'claim.'.$tenantId.'.'.self::idText($claimId));
    }

    public static function pulse(): string
    {
        return self::PULSE;
    }

    /**
     * The key a phase channel carries: the first ten hex characters of
     * sha1(lower(trim(block))). A block's own text never reaches a channel name.
     */
    public static function phaseKey(string $block): string
    {
        $normalised = mb_strtolower(trim($block));

        if ($normalised === '') {
            throw new InvalidArgumentException('A blank block has no phase channel.');
        }

        return substr(sha1($normalised), 0, 10);
    }

    /** @param  list<string>  $s */
    private static function parseGemini(string $name, array $s): ?self
    {
        $n = count($s);

        if ($n < 2) {
            return null;
        }

        if ($n === 2) {
            return in_array($s[1], self::GEMINI_FEEDS, true) ? new self(self::GEMINI_FEED, $name, feed: $s[1]) : null;
        }

        if (! self::isTenant($s[1])) {
            return null;
        }

        if ($n === 3) {
            return in_array($s[2], self::GEMINI_FEEDS, true) && ! in_array($s[2], self::GEMINI_PLATFORM_ONLY, true)
                ? new self(self::GEMINI_TENANT_FEED, $name, $s[1], $s[2])
                : null;
        }

        if ($n === 5 && in_array($s[3], self::GEMINI_RECORD_TYPES[$s[2]] ?? [], true) && self::isId($s[4])) {
            return new self(self::GEMINI_RECORD, $name, $s[1], $s[2], $s[3], $s[4]);
        }

        return null;
    }

    /** @param  list<string>  $s */
    private static function parseEstate(string $name, array $s): ?self
    {
        $n = count($s);

        if ($n < 3 || ! self::isTenant($s[1])) {
            return null;
        }

        if ($n === 3) {
            return match (true) {
                $s[2] === 'guards' => new self(self::ESTATE_GUARDS, $name, $s[1]),
                $s[2] === 'passes' => new self(self::ESTATE_PASSES, $name, $s[1]),
                in_array($s[2], self::ESTATE_FEEDS, true) => new self(self::ESTATE_FEED, $name, $s[1], $s[2]),
                default => null,
            };
        }

        if ($n === 5 && in_array($s[3], self::ESTATE_RECORD_TYPES[$s[2]] ?? [], true) && self::isId($s[4])) {
            return new self(self::ESTATE_RECORD, $name, $s[1], $s[2], $s[3], $s[4]);
        }

        return null;
    }

    /** @param  list<string>  $s */
    private static function tenantAndId(string $family, string $name, array $s): ?self
    {
        return count($s) === 3 && self::isTenant($s[1]) && self::isId($s[2])
            ? new self($family, $name, $s[1], id: $s[2])
            : null;
    }

    private static function isTenant(string $segment): bool
    {
        return preg_match(self::TENANT, $segment) === 1;
    }

    private static function isId(string $segment): bool
    {
        return preg_match(self::ID, $segment) === 1;
    }

    private static function idText(int|string $id): string
    {
        $text = (string) $id;

        if (! self::isId($text)) {
            throw new InvalidArgumentException("[{$text}] is not a record id a live channel can carry.");
        }

        return $text;
    }

    /** The name, once it is certain to read back as the family it was written for. */
    private static function formatted(string $family, string $name): string
    {
        if (self::parse($name)?->family !== $family) {
            throw new InvalidArgumentException("[{$name}] is not a canonical {$family} channel name.");
        }

        return $name;
    }
}
