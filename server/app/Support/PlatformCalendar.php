<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * GEMINI'S CALENDAR — the day on which the platform's own money happens (D-143, B082).
 *
 * The server's clock is UTC and stays UTC (M4 §3.1). A BUSINESS DATE is not a
 * moment, though: it is the day the office would say it is. Kingston is five
 * hours behind UTC all year, so from 7 p.m. to midnight there the UTC date is
 * already tomorrow — and every "today" on Gemini's money paths used to be the UTC
 * date. An invoice raised or a credit note issued on the evening of 30 September
 * was journalled on 1 October under an October reference; the Director's price
 * change dated their own today was refused as retroactive, while one dated
 * tomorrow went straight onto the live price.
 *
 * ONE CALENDAR FOR THE WHOLE PLATFORM, AND NOT AN ESTATE'S. Gemini Security
 * Limited keeps one set of books and one price list for every client. Were each
 * posting dated in its client's own zone, two invoices raised in the same minute
 * could land on two different days of one ledger, and "never retroactive" would
 * mean a different day depending on whose price was being changed. So Gemini's
 * money is dated in Gemini's zone — the platform's own, `config('app.platform_timezone')`
 * (M19A §9) — and an estate's own records keep `SiteTime::today($tenantId)`, the estate's day.
 *
 * RETURNED AS A DATE: midnight of that calendar day in the APPLICATION's zone
 * (UTC), which is the shape every `date` column is read back in. That is what
 * lets it be compared with a due date or an effective date directly.
 * `Carbon::today($zone)` would not do: it is midnight IN THAT ZONE — 05:00 UTC
 * for Kingston — and five hours after the same day read from a column.
 */
final class PlatformCalendar
{
    /** Gemini's own zone: the operator's setting (SiteTime::platformZone()), never a name in the code. */
    public static function zone(): string
    {
        return SiteTime::platformZone();
    }

    /** Today, as Gemini's office would date a posting. */
    public static function today(): Carbon
    {
        return Carbon::parse(Carbon::now(self::zone())->toDateString())->startOfDay();
    }

    /** A stored moment on Gemini's clock, for printing. */
    public static function local(DateTimeInterface $instant): Carbon
    {
        return Carbon::instance($instant)->setTimezone(self::zone());
    }
}
