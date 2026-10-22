<?php

declare(strict_types=1);

namespace App\Support;

/**
 * TURNING WHAT THE OFFICE TYPED INTO SOMETHING A CARRIER WILL ACCEPT — and never
 * quietly changing what they typed.
 *
 * THE FOUR NUMBERS THE CLIENT GAVE ARE NOT WRITTEN THE SAME WAY (Q-M09):
 *
 *     President            1-876-508-8082      country code written
 *     Property Office      1-876-224-4444      country code written
 *     PPV1 Head of Security 1-876-845-9422     country code written
 *     Gemini Dispatch        876-354-2119      country code NOT written
 *
 * All four are the same kind of number. Jamaica is inside the North American
 * Numbering Plan: country code 1, area code 876 (and 658, its overlay). "876 354
 * 2119" and "1 876 354 2119" are one number written two ways, exactly as a Jamaican
 * would write either on a form — so the fourth is not a mistake to correct, and the
 * three others are not a format to strip.
 *
 * SO NOTHING IS NORMALISED IN PLACE. `site_emergency_numbers.number` keeps the
 * characters the office typed, for ever; this class works out the diallable form and
 * it is stored BESIDE it as `e164`. A person reading the row sees both, and can see
 * that the platform read their number the way they meant it. A silent rewrite would
 * leave nobody able to tell the difference between "we fixed your formatting" and "we
 * are texting the wrong phone".
 *
 * IT GUESSES ONLY ONE THING, AND ONLY WHEN THE GUESS IS FORCED.
 *
 *   11 digits starting 1   already complete           → +1XXXXXXXXXX
 *   10 digits              add the NANP country code  → +1XXXXXXXXXX
 *   anything else          NULL — no guess is made
 *
 * The ten-digit case is the only inference, and it is the one every Jamaican phone
 * already makes when you dial a local number. Everything else — a short code, a
 * seven-digit local number with no area code, a number from another country written
 * without its `+` — returns null, and the caller records the recipient as
 * `unroutable` rather than sending a life-safety message to a number the platform
 * invented. A wrong number in an emergency is worse than a missing one, because a
 * missing one is visible.
 *
 * AN EXPLICIT `+` IS BELIEVED AS WRITTEN. Somebody who types +44… means +44…, and
 * this class is not entitled to decide otherwise.
 */
final class JamaicanNumber
{
    /** The North American Numbering Plan country code, which Jamaica is inside. */
    public const COUNTRY_CODE = '1';

    /** How many digits a NANP number has after the country code. */
    private const NATIONAL_DIGITS = 10;

    /**
     * The diallable E.164 form of what somebody typed, or null when it cannot be
     * worked out without guessing.
     */
    public static function e164(?string $typed): ?string
    {
        $typed = trim((string) $typed);

        if ($typed === '') {
            return null;
        }

        // An explicit international prefix is an instruction, not a formatting choice.
        $explicit = str_starts_with($typed, '+') || str_starts_with($typed, '00');
        $digits = (string) preg_replace('/\D+/', '', $typed);

        if ($digits === '') {
            return null;
        }

        if ($explicit) {
            // "00" is the same instruction written the other way round.
            if (! str_starts_with($typed, '+') && str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            }

            // E.164 allows at most fifteen digits; fewer than eight is not a phone number.
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
        }

        if (strlen($digits) === self::NATIONAL_DIGITS) {
            return '+'.self::COUNTRY_CODE.$digits;
        }

        if (strlen($digits) === self::NATIONAL_DIGITS + 1 && str_starts_with($digits, self::COUNTRY_CODE)) {
            return '+'.$digits;
        }

        return null;
    }

    /**
     * A PERSON'S NUMBER, STORED ONE WAY (M18C §6) — "+1 876 555 0123" — or null when it does not look like a phone
     * number. For the numbers a person types about themselves or their people (a member, a contact, a visitor, a
     * guard, a console user), where the client asked for one normalised form; `site_emergency_numbers` keeps the
     * office's own typing and its `e164` beside it, above.
     *
     * Accepted the ways people really type them: spaces, dashes, dots, brackets, a leading 1 or +1. A North American
     * number (Jamaica's 876 and 658, and a relative's US or Canadian number) must have ten digits after the 1, with an
     * area code and exchange that do not start with 0 or 1. Anything written with an explicit + is another country's
     * and kept as "+" and its digits. A seven-digit local number is refused, never guessed at: which area code it
     * belongs to is not this class's to decide.
     */
    public static function normalise(?string $typed): ?string
    {
        $e164 = self::e164($typed);

        if ($e164 === null) {
            return null;
        }

        $digits = substr($e164, 1);

        if (strlen($digits) === self::NATIONAL_DIGITS + 1 && str_starts_with($digits, self::COUNTRY_CODE)) {
            $national = substr($digits, 1);

            // NANP: NXX-NXX-XXXX, N being 2–9. "123 456 7890" is not somebody's phone.
            if (preg_match('/^[2-9]\d\d[2-9]\d{6}$/', $national) !== 1) {
                return null;
            }

            return '+1 '.substr($national, 0, 3).' '.substr($national, 3, 3).' '.substr($national, 6);
        }

        // Another country's number, written with its +. NANP numbers never reach here: they have eleven digits.
        return str_starts_with($digits, self::COUNTRY_CODE) ? null : $e164;
    }

    /**
     * "+1876•••8082" — for a log line or a screen that has no business holding the
     * whole number. The ledger stores the number in full; a log file does not.
     */
    public static function mask(?string $number): string
    {
        $number = trim((string) $number);

        if ($number === '') {
            return '(none)';
        }

        return strlen($number) <= 8
            ? str_repeat('•', strlen($number))
            : substr($number, 0, 5).'•••'.substr($number, -4);
    }
}
