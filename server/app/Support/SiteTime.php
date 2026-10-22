<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ONE TIME RULE (M4 §3.1).
 *
 *   storage      true UTC, always. The server's clock is UTC and stays UTC.
 *   the office   types and reads wall-clock times IN THE SITE'S ZONE — `tenants.timezone`,
 *                an IANA name the estate is given when it is provisioned (M19A §9).
 *   the API      sends every time as a UTC instant, and the zone beside it as `timezone`.
 *
 * So a 07:00 shift typed in Kingston is stored 12:00:00 UTC, printed 7:00 AM on the
 * console, and sent to the phone as `…T12:00:00+00:00` with `timezone: America/Jamaica`.
 *
 * NEVER AN OFFSET, ALWAYS THE NAME. Jamaica keeps no daylight saving; the next territory
 * may, and `-05:00` would be wrong for half of its year. Everything here goes through the
 * IANA zone, and nothing in the codebase may hard-code a number of hours.
 *
 * WHICH SITE. With no argument, the estate that is open on this request (`tenant()`).
 * The Gemini console is central and spans every client, so its callers pass the
 * estate's id — a shift is read in the zone of the estate it is rostered at.
 */
final class SiteTime
{
    /**
     * The platform's own clock (`config/app.php` `platform_timezone`, M19A §9): for what belongs to no estate, and for a
     * stored zone PHP cannot read. Nothing in the code names a zone; an estate's is its own, and this is the operator's.
     * A setting PHP cannot read falls back to UTC — the instant as stored, never a guess.
     */
    public static function platformZone(): string
    {
        $zone = config('app.platform_timezone');

        if (is_string($zone) && $zone !== '') {
            try {
                new DateTimeZone($zone);

                return $zone;
            } catch (Throwable) {
                // Fall through to UTC.
            }
        }

        return 'UTC';
    }

    /** @var array<string, string> tenant id => zone, for the life of the process (flushed when an estate is saved) */
    private static array $zones = [];

    /** Whether every estate's zone has been read into `$zones` since the last flush. */
    private static bool $everyZoneRead = false;

    /** The IANA zone of an estate — the open one when none is named. */
    public static function zone(?string $tenantId = null): string
    {
        if ($tenantId === null || $tenantId === '') {
            $open = function_exists('tenant') ? tenant() : null;

            if ($open === null) {
                return self::platformZone();
            }

            return self::valid($open->getAttribute('timezone'));
        }

        // The estate that is open on this request already holds its zone: asking by its own id needs no query.
        $open = function_exists('tenant') ? tenant() : null;
        if ($open !== null && (string) $open->getTenantKey() === $tenantId) {
            return self::valid($open->getAttribute('timezone'));
        }

        if (! array_key_exists($tenantId, self::$zones)) {
            try {
                /*
                 * EVERY ESTATE'S ZONE IN ONE READ, the first time any is asked for (M11 A2).
                 *
                 * The Gemini console prints rows from every client on each one's own clock — a
                 * shift, a licence, a request, an alert — and a PHP-FPM worker starts every
                 * request remembering nothing, so reading a zone the first time each estate
                 * came up was a query per client on every cross-estate page: twelve on the
                 * dashboard, the coverage board and the roster at twelve clients. One read of
                 * every estate's id and zone is one small query however many clients there
                 * are. An estate created after it (a long-running worker outlives a request)
                 * is still read on its own, below, exactly as every estate used to be.
                 */
                if (! self::$everyZoneRead) {
                    foreach (Tenant::query()->pluck('timezone', 'id') as $id => $zone) {
                        self::$zones[(string) $id] ??= self::valid($zone);
                    }

                    self::$everyZoneRead = true;
                }

                if (! array_key_exists($tenantId, self::$zones)) {
                    self::$zones[$tenantId] = self::valid(Tenant::query()->whereKey($tenantId)->value('timezone'));
                }
            } catch (Throwable) {
                // Before the column exists (a fresh install mid-migration) every site is the default.
                return self::platformZone();
            }
        }

        return self::$zones[$tenantId];
    }

    /** The zone of an estate already in hand — read off its row, no query — and remembered for `zone()`. */
    public static function zoneOf(Tenant $estate): string
    {
        return self::$zones[(string) $estate->getTenantKey()] ??= self::valid($estate->getAttribute('timezone'));
    }

    /** Forget what was read — an estate's zone was changed. */
    public static function flush(): void
    {
        self::$zones = [];
        self::$everyZoneRead = false;
    }

    /**
     * What the office typed, as the moment it means — in UTC, ready to store or compare.
     *
     * A string with no offset ("2026-09-20 07:00", "2026-09-20T07:00") is a wall-clock time
     * at the site. A string that carries its offset or `Z` (what a phone sends) and any
     * date-time object is already a moment, and is only moved to UTC.
     */
    public static function parse(DateTimeInterface|string $officeTyped, ?string $tenantId = null): Carbon
    {
        if ($officeTyped instanceof DateTimeInterface) {
            return Carbon::instance($officeTyped)->utc();
        }

        $text = trim($officeTyped);

        if (self::carriesOffset($text)) {
            return Carbon::parse($text)->utc();
        }

        return Carbon::parse($text, self::zone($tenantId))->utc();
    }

    /** A moment, on the site's clock — for printing, and for rules that are local (a day, 22:00–06:00). */
    public static function local(DateTimeInterface $instant, ?string $tenantId = null): Carbon
    {
        return Carbon::instance($instant)->setTimezone(self::zone($tenantId));
    }

    /**
     * A moment on an ESTATE's clock, for a Gemini screen that lists many estates (M11 §4.1) — named with its
     * zone's abbreviation whenever that clock is not Gemini's own. A dispatcher in Kingston reading "8:15 PM"
     * beside a New York alert is an hour out with nothing saying so; beside a Kingston alert the time needs no
     * label, as nothing on the platform's own clock does. The estate's own console, all on one clock, keeps
     * `local()`.
     */
    public static function stamp(DateTimeInterface $instant, ?string $tenantId, string $format): string
    {
        return self::stampIn($instant, self::zone($tenantId), $format);
    }

    /** `stamp()`, for a caller that already holds the estate's zone by name (a row read with its `timezone`). */
    public static function stampIn(DateTimeInterface $instant, ?string $zone, string $format): string
    {
        /*
         * "MY TIME" (M19A §7): a Gemini viewer who switched the console to their own clock reads every row on it,
         * tagged with THEIR zone's tag, so the two are never confused. Site time — every row on its own estate's clock,
         * tagged — is the default.
         */
        $local = Carbon::instance($instant)->setTimezone(self::$viewerZone ?? self::valid($zone));

        return $local->format($format).self::label($local);
    }

    /** The viewer's own zone while a Gemini console user reads it in "my time" (M19A §7); null is site time. */
    private static ?string $viewerZone = null;

    /** Read every stamp on this request in the viewer's own zone (`HandleInertiaRequests`), or back in site time (null). */
    public static function viewAs(?string $zone): void
    {
        self::$viewerZone = $zone === null ? null : self::valid($zone);
    }

    /** Whether this request reads its stamps in the viewer's own zone. */
    public static function viewerZone(): ?string
    {
        return self::$viewerZone;
    }

    /**
     * "America/Jamaica" → "Jamaica time"; "America/New_York" → "New York time" (M19A §4.2): the estate's clock by its
     * zone's own place name, where there is room — an email, a pass, a confirmation. No zone is written into the product.
     */
    public static function clockName(?string $zone): string
    {
        $place = trim(str_replace('_', ' ', (string) last(explode('/', self::valid($zone)))));

        return $place !== '' ? $place.' time' : 'Estate time';
    }

    /**
     * "America/Jamaica" → "JA"; "America/New_York" → "NY"; "America/Port_of_Spain" → "POS" (M19A §4.2): the tag an estate
     * time carries in a dense place — a table, a list — "9:38 AM JA". The phone's `clockTagOf` makes the same.
     */
    public static function clockTag(?string $zone): string
    {
        $words = array_values(array_filter(preg_split('/[\s_-]+/', (string) last(explode('/', self::valid($zone)))) ?: []));
        if ($words === []) {
            return 'ET';
        }
        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_strtoupper(mb_substr(implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1), $words)), 0, 3));
    }

    /**
     * A time ALREADY ON THE ESTATE'S CLOCK (a `SiteDateTime` value, or `local()`'s answer), formatted and tagged with
     * that clock: `fmt($ticket->reported_at, 'M j, g:i A')` → "Sep 27, 9:38 AM JA" (M19A §4.2 — never a bare time).
     */
    public static function fmt(DateTimeInterface $local, string $format): string
    {
        return $local->format($format).self::label($local);
    }

    /** "9:38 AM JA" — a moment on the estate's clock, tagged (M19A §4.2). */
    public static function tagged(DateTimeInterface $instant, ?string $tenantId, string $format = 'g:i A'): string
    {
        $zone = self::zone($tenantId);

        return Carbon::instance($instant)->setTimezone($zone)->format($format).' '.self::clockTag($zone);
    }

    /** "9:38 AM Jamaica time" — a moment on the estate's clock, named (M19A §4.2). */
    public static function named(DateTimeInterface $instant, ?string $tenantId, string $format = 'g:i A'): string
    {
        $zone = self::zone($tenantId);

        return Carbon::instance($instant)->setTimezone($zone)->format($format).' '.self::clockName($zone);
    }

    /**
     * " JA" / " NY" beside EVERY time a Gemini screen prints — Gemini's own clock included (M19A §4.2, §7: never a bare
     * "9:38 AM"). It used to print the zone's abbreviation, and only for a clock other than Gemini's; but Jamaica and
     * New York in winter are both "EST", and a Kingston row with no label left a dispatcher in another zone guessing.
     * Daylight saving is in the digits, from the zone's own rules: "7:00 AM NY" is right on both sides of the change.
     */
    public static function label(DateTimeInterface $local): string
    {
        $tag = ' '.self::clockTag($local->getTimezone()->getName());

        /*
         * THE HOUR THAT HAPPENS TWICE. The night the clocks go back, 1:30 AM comes round twice in New York, an hour apart;
         * "1:30 AM NY" alone could be either. Only then, the zone's own abbreviation tells them apart: "1:30 AM NY (EDT)",
         * then "1:30 AM NY (EST)".
         */
        $at = Carbon::instance($local);
        $wall = $at->format('Y-m-d H:i');
        $repeats = $at->copy()->addHour()->format('Y-m-d H:i') === $wall || $at->copy()->subHour()->format('Y-m-d H:i') === $wall;

        return $repeats ? $tag.' ('.$at->format('T').')' : $tag;
    }

    /** Now, on the site's clock. */
    public static function now(?string $tenantId = null): Carbon
    {
        return Carbon::now(self::zone($tenantId));
    }

    /** The start of the site's today, on the site's clock. `->utc()` it before binding it to a query. */
    public static function today(?string $tenantId = null): Carbon
    {
        return Carbon::today(self::zone($tenantId));
    }

    /**
     * THE SITE'S CALENDAR DATE, AS A DATE COLUMN READS BACK (M11 §4.3): the day it is at the estate, as midnight
     * in the APPLICATION's zone (UTC). A `date` column — a due date, a move-in day, a meeting's notice date — is
     * a calendar day, not a moment, and is read back as that day's UTC midnight; this is the value to compare it
     * with, store in it, or add days to. `today()` would not do: it is midnight AT THE SITE, hours away from the
     * same date read from a column. It is `PlatformCalendar::today()`'s shape, on the estate's calendar instead
     * of Gemini's.
     */
    public static function date(?string $tenantId = null): Carbon
    {
        return Carbon::parse(Carbon::now(self::zone($tenantId))->toDateString())->startOfDay();
    }

    /**
     * The site's day as two UTC instants — [start, end] — for `whereBetween` on a stored time,
     * and for "is this before today".
     *
     * A DAY IS A CALENDAR DATE, NOT A MOMENT. `null` is the site's today. A string is read for
     * its `YYYY-MM-DD`. A date-time object is read for THE DATE IT CARRIES (its own digits, in
     * its own zone) — so `Carbon::parse('2026-09-20')` and a `SiteDateTime` value both name the
     * day a person would say. To ask for the site's day AT a moment, pass `SiteTime::local($moment)`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayBounds(DateTimeInterface|string|null $day = null, ?string $tenantId = null): array
    {
        $zone = self::zone($tenantId);

        $start = match (true) {
            $day === null => Carbon::today($zone),
            $day instanceof DateTimeInterface => Carbon::parse($day->format('Y-m-d'), $zone)->startOfDay(),
            default => Carbon::parse(substr(trim($day), 0, 10), $zone)->startOfDay(),
        };

        return [$start->copy()->utc(), $start->copy()->endOfDay()->utc()];
    }

    /**
     * THE API'S ONE TIME FORMAT: a full ISO-8601 instant in UTC, `2026-09-20T12:00:00+00:00`.
     * The phone shows it in the site's zone, which it is sent as `timezone`.
     */
    public static function iso(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : Carbon::instance($instant)->utc()->toIso8601String();
    }

    /** A zone name PHP knows, or the default. A bad value in the column must not take a console down. */
    public static function valid(mixed $zone): string
    {
        if (! is_string($zone) || $zone === '') {
            return self::platformZone();
        }

        try {
            new DateTimeZone($zone);
        } catch (Throwable) {
            return self::platformZone();
        }

        return $zone;
    }

    /** Whether a typed string names its own offset (`Z`, `+00:00`, `-0500`) rather than leaving it to the site. */
    public static function carriesOffset(string $text): bool
    {
        return preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $text) === 1 && preg_match('/\d{1,2}:\d{2}/', $text) === 1;
    }
}
