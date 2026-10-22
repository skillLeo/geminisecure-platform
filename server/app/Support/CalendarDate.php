<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A day a URL names — `?date=2026-09-24` — or nothing.
 *
 * A SHAPE IS NOT A DAY (bug hunt B014). The coverage board and the roster both
 * checked `?date=` against four digits, two and two, and handed whatever passed
 * to `Carbon::parse()`. `2026-13-45` passes that and throws, so a stale bookmark
 * or a mangled link answered 500 on a screen whose own comment promised "a
 * malformed ?date= lands on today"; and `2026-02-30` passes it too and quietly
 * becomes 2 March, which is a different day from the one asked for rather than
 * a fall-back to today. A date is kept only when it is one the calendar has —
 * the rule `AuditLogController::date()` already keeps with `checkdate()`.
 */
final class CalendarDate
{
    /** `YYYY-MM-DD` when the value is exactly a real calendar day; null for anything else. */
    public static function from(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $text : null;
    }
}
