<?php

declare(strict_types=1);

namespace App\Support\Seeded;

/**
 * THE ADDRESSES AND NUMBERS NOBODY CAN HAVE (M35 §5.2).
 *
 * The demonstration seed invented residents with addresses on `email.com`, a live public mail service, and the platform
 * emailed one of them twice about a panic. A seeder may only write what the internet guarantees can reach nobody:
 *
 *   EMAIL   a domain reserved for examples (RFC 2606 §3: example.com, example.net, example.org, and anything under them)
 *           or a top-level name that never resolves (RFC 2606 §2, RFC 6761 §6: .test, .example, .invalid, .localhost).
 *   PHONE   the North American Numbering Plan's fictional block, 555-0100 to 555-0199, in any area code (Jamaica is
 *           inside the plan: 876 and its overlay 658). No other range is promised to stay unassigned.
 *
 * WHAT A SEEDED ROW IS REWRITTEN TO (`estate:rewrite-seeded-contacts`): the same local part on example.com, so
 * `andrea.fletcher@email.com` reads `andrea.fletcher@example.com`; and the same number with its exchange made 555 and its
 * line 01 and its own last two digits, every other character as it was typed, so `876-555-0047` reads `876-555-0147`.
 *
 * The rules are held to every seeder by `SeedersWriteOnlyUndeliverableContactsTest`, which fails the build.
 */
final class ReservedContacts
{
    /** Second-level names kept for examples (RFC 2606 §3); their subdomains are kept with them. */
    public const DOMAINS = ['example.com', 'example.net', 'example.org'];

    /** Top-level names that never resolve (RFC 2606 §2, RFC 6761 §6). */
    public const TLDS = ['test', 'example', 'invalid', 'localhost'];

    /** Where a seeded address is moved to. */
    public const REWRITE_DOMAIN = 'example.com';

    /** The area code a seeded number keeps when the one it was written with cannot be read. */
    public const AREA_CODE = '876';

    /** Is this address on a domain no mail can reach? An empty or unreadable one is not an address at all. */
    public static function emailIsReserved(?string $email): bool
    {
        $domain = self::domain($email);

        if ($domain === null) {
            return false;
        }

        foreach (self::DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return true;
            }
        }

        $labels = explode('.', $domain);

        return in_array((string) end($labels), self::TLDS, true);
    }

    /** Is this number inside the fictional block (NXX 555, line 0100 to 0199), whatever way it is written? */
    public static function phoneIsFictional(?string $phone): bool
    {
        $national = self::national($phone);

        if ($national === null) {
            return false;
        }

        $line = (int) substr($national, 6);

        return substr($national, 3, 3) === '555' && $line >= 100 && $line <= 199;
    }

    /**
     * A seeder's number for its nth person: "876-555-01" and n's last two digits, so lot 47 reads 876-555-0147 — the
     * number `fictionalPhone()` makes of the "876-555-0047" the seed used to write, on a fresh seed and a rewritten one
     * alike. A hundred people share a hundred numbers; nothing on the platform needs a seeded number to be unique.
     */
    public static function seededPhone(int $n, string $area = self::AREA_CODE): string
    {
        return $area.'-555-01'.str_pad((string) (abs($n) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "Andrea.Fletcher@Email.com" → "Andrea.Fletcher@example.com": the person's name kept, the mailbox nobody's. */
    public static function reservedEmail(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        $local = $at === false ? $email : substr($email, 0, $at);
        $local = trim($local) === '' ? 'seeded' : $local;

        return $local.'@'.self::REWRITE_DOMAIN;
    }

    /**
     * "876-555-0047" → "876-555-0147", "(876) 555 0223" → "(876) 555 0123", "+1 876 555 0212" → "+1 876 555 0112".
     *
     * The digits change and nothing else does, so the number still reads the way it was typed. A number that is not a
     * North American one (seven digits, another country's) has no area code to keep and becomes "876-555-01" and its
     * last two digits.
     */
    public static function fictionalPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        $national = self::national($phone);
        $last2 = str_pad(substr($digits, -2), 2, '0', STR_PAD_LEFT);

        if ($national === null) {
            return self::AREA_CODE.'-555-01'.$last2;
        }

        $area = substr($national, 0, 3);
        $area = preg_match('/^[2-9]\d\d$/', $area) === 1 ? $area : self::AREA_CODE;
        $wanted = (strlen($digits) === 11 ? '1' : '').$area.'555'.'01'.$last2;

        // The same characters, digit for digit: every bracket, space, dash and plus stays where it was.
        $i = 0;

        return (string) preg_replace_callback('/\d/', static function () use ($wanted, &$i): string {
            return $wanted[$i++] ?? '';
        }, trim($phone));
    }

    /** The lowercased domain of an address, or null when there is no `local@domain` to read. */
    public static function domain(?string $email): ?string
    {
        $email = trim((string) $email);
        $at = strrpos($email, '@');

        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return null;
        }

        return mb_strtolower(rtrim(substr($email, $at + 1), '.'));
    }

    /**
     * The ten national digits of a North American number — 10 digits, or 11 starting with the country code — or null
     * for anything else. Written with a `+` and another country code, it is that country's, and null.
     */
    private static function national(?string $phone): ?string
    {
        $typed = trim((string) $phone);
        $digits = (string) preg_replace('/\D+/', '', $typed);

        if (strlen($digits) === 10 && ! str_starts_with($typed, '+')) {
            return $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        return null;
    }
}
