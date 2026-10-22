<?php

declare(strict_types=1);

namespace App\Live;

use Illuminate\Support\Str;
use JsonException;
use LogicException;

/**
 * What a stored live summary may never hold (DESIGN.md §4.1 rule 3, §4.2).
 *
 * THE LOG IS NOT A SECOND STORE OF ANYBODY'S DATA. It is kept for a week and read back by every
 * catch-up, so it holds only a safe summary: enums, booleans, counts, record ids and timestamps.
 * Names, free text, contact details, locations, credentials, supervisor and geofence data, money,
 * standing and ballot choices are hydrated when an event is published, for audiences already
 * entitled to them, and are never written here.
 *
 * Checked twice: by LiveBus when an event is recorded (`assert`), and over every database's stored
 * summaries by `gate:isolation` step 14 (`violations`, f-web-probe). Both apply every rule below.
 *
 * A KEY IS REFUSED FOR WHAT IT NAMES, NOT ONLY FOR ITS EXACT SPELLING. §4.2 lists some keys by name
 * and some by category ("phone, e164, email; latitude, longitude, accuracy"; "any *_minor, amount,
 * balance, total, arrears, bucket"), and §4.3 adds `visitor_phone` and lat/long. So, with the key
 * lowercased and snake-cased (`raisedByName` is `raised_by_name`, `e-mail` is `e_mail`), it is
 * refused when:
 *   - it, or its singular, is a FORBIDDEN key, with or without its separators (`Who`, `e-mail`,
 *     `household_ids`);
 *   - it contains a FORBIDDEN_WORDS word anywhere, however it is joined (`visitor_phone`,
 *     `contactEmail`, `gps_latitude`, `pass_token`, `amount_due`, `subtotal`);
 *   - one of its words is a FORBIDDEN_ENDINGS word, or it ends with one (`lat`, `gps_lng`,
 *     `access_code`, `pin`, `resident_name`, `username`, `deposit_minor`). These are short, so
 *     they are not looked for inside a word: `latest_at` and `is_pinned` are allowed.
 *
 * A VALUE IS REFUSED UNLESS IT IS ONE OF THE THINGS A SUMMARY HOLDS. A value is null, a boolean,
 * an integer, a short token (an enum, a record id, a key such as `alert:55:open`: at most 64
 * letters, digits and `_ . : -`) or a date-time, or a list of those. So free text, a phone number
 * written `+92…`, an email address or a coordinate cannot pass under a harmless key such as
 * `status`: no space, no `@`, no `+` outside a time zone offset, and no float. A value must also
 * be flat, so nothing can be tucked one level down. A bare number cannot be told from a record id,
 * which is why the keys are checked as well.
 */
final class SummaryPolicy
{
    /** A summary's JSON is at most this many bytes (§1.3, §4.1 rule 5). */
    public const MAX_BYTES = 1024;

    /**
     * §4.2, word for word.
     *
     * @var list<string>
     */
    public const FORBIDDEN = [
        // Names
        'who', 'raised_by_name', 'guard_name', 'visitor_name', 'reported_by_name', 'technician_name',
        'responded_by_name', 'logged_by_name', 'officer',
        // Free text and labels
        'subject', 'unit_reference', 'lot', 'detail', 'description', 'body', 'resolution',
        'resolution_note', 'reason', 'note', 'handover_note', 'virtual_link',
        // Contact details and location
        'phone', 'e164', 'email', 'latitude', 'longitude', 'accuracy',
        // Credentials and identifiers
        'token', 'code', 'nonce', 'public_key', 'employee_number', 'psra_number', 'id_type', 'id_number',
        'device_id', 'idempotency_key',
        // Supervisor and geofence data
        'mock_location_flag', 'geofence_distance_m', 'overridden_by', 'override_reason',
        // Money (and every key ending in a FORBIDDEN_ENDINGS word, `_minor` among them)
        'amount', 'balance', 'total', 'arrears', 'bucket',
        // Standing, and a ballot's option or choice
        'access_restricted', 'option_id', 'option_ids', 'choice', 'choices', 'choice_id', 'choice_ids',
        // Account and household identifiers, which would name a person on a shared channel
        'account_id', 'resident_account_id', 'household_id',
    ];

    /**
     * Refused anywhere in a key, however it is joined. Each is long or particular enough never to
     * sit inside an innocent word. The §4.2 categories: contact details and location, credentials,
     * geofence data, money, and a ballot's choice (a vote is never a live fact, §2.11 K12).
     *
     * @var list<string>
     */
    public const FORBIDDEN_WORDS = [
        'phone', 'email', 'msisdn', 'latitude', 'longitude', 'accuracy',
        'token', 'nonce', 'password', 'apikey', 'privatekey',
        'geofence',
        'amount', 'balance', 'total', 'arrears', 'bucket', 'price', 'salary', 'wage',
        'choice', 'vote',
    ];

    /**
     * Refused as one of a key's words, or at its end (and as their plurals). Too short, or too
     * common inside other words, to be looked for anywhere: `lat` is refused in `gps_lat` and
     * `gpslat` but not in `latest_at`, and `secret` in `mfa_secret` but not in `secretary_id`.
     *
     * @var list<string>
     */
    public const FORBIDDEN_ENDINGS = ['name', 'e164', 'lat', 'lng', 'lon', 'long', 'gps', 'code', 'pin', 'otp', 'secret', 'minor'];

    /** An enum, a record id, a count written as text, or a key such as `alert:55:open`. */
    private const TOKEN_VALUE = '/^[A-Za-z0-9_.:\-]{0,64}$/D';

    /** A date, or a date and time with an optional fraction and zone: `2026-10-02 14:03:11+05:00`. */
    private const TIMESTAMP_VALUE = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/D';

    /**
     * Refuse a summary that holds anything it may not, is not flat, or is over 1 KB.
     *
     * @param  array<array-key, mixed>  $summary
     *
     * @throws LogicException
     */
    public static function assert(string $type, array $summary): void
    {
        if ($summary === []) {
            return;
        }

        if (array_is_list($summary)) {
            throw new LogicException("The summary of {$type} is a list; a summary is a map of named keys.");
        }

        foreach ($summary as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException("The summary of {$type} has an unnamed entry; a summary is a map of named keys.");
            }

            if (! self::isFlat($value)) {
                throw new LogicException("The summary of {$type} holds a value under '{$key}' that is not flat. A summary value is a string, an integer, a boolean, null, or a list of those.");
            }
        }

        $found = self::inspect($summary);
        $keys = array_keys(array_filter($found, static fn (string $what): bool => $what === 'key'));
        $values = array_keys(array_filter($found, static fn (string $what): bool => $what === 'value'));

        if ($keys !== []) {
            throw new LogicException('The summary of '.$type.' holds '.implode(', ', $keys).', which SummaryPolicy forbids in the live log (DESIGN.md §4.2). Hydrate it at publish for the audiences entitled to it instead.');
        }

        if ($values !== []) {
            throw new LogicException('The summary of '.$type.' holds a value under '.implode(', ', $values).' that is not an enum, a record id, a count or a timestamp (at most 64 letters, digits and _ . : -, or a date-time). Free text, contact details and locations are hydrated at publish, never stored (DESIGN.md §4.1, §4.2).');
        }

        $bytes = strlen(self::encode($summary));

        if ($bytes > self::MAX_BYTES) {
            throw new LogicException("The summary of {$type} is {$bytes} bytes; a summary is at most ".self::MAX_BYTES.' bytes.');
        }
    }

    /**
     * Every forbidden key, and every value that is not a summary's kind of value, at any depth of a
     * summary, as a dotted path in the order they appear.
     *
     * @param  array<array-key, mixed>  $summary
     * @return list<string>
     */
    public static function violations(array $summary): array
    {
        return array_map(strval(...), array_keys(self::inspect($summary)));
    }

    /** Whether one key is forbidden, however it is spelt. */
    public static function isForbidden(string $key): bool
    {
        foreach (self::forms($key) as [$words, $joined]) {
            $underscored = implode('_', $words);

            foreach ([$underscored, $joined] as $form) {
                $singular = str_ends_with($form, 's') ? substr($form, 0, -1) : $form;

                foreach ([$form, $singular] as $candidate) {
                    if (in_array($candidate, self::FORBIDDEN, true) || in_array($candidate, self::stripped(), true)) {
                        return true;
                    }
                }
            }

            foreach (self::FORBIDDEN_WORDS as $word) {
                if (str_contains($joined, $word)) {
                    return true;
                }
            }

            foreach (self::FORBIDDEN_ENDINGS as $ending) {
                if (str_ends_with($joined, $ending) || str_ends_with($joined, $ending.'s')
                    || in_array($ending, $words, true) || in_array($ending.'s', $words, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The JSON a summary is stored as.
     *
     * @param  array<array-key, mixed>  $summary
     *
     * @throws LogicException
     */
    public static function encode(array $summary): string
    {
        try {
            return json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('A live summary could not be written as JSON: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Each path that breaks a rule, and whether its key or its value does (the key first).
     *
     * @param  array<array-key, mixed>  $summary
     * @param  array<string, 'key'|'value'>  $found
     * @return array<string, 'key'|'value'>
     */
    private static function inspect(array $summary, string $path = '', array $found = []): array
    {
        foreach ($summary as $key => $value) {
            $at = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && self::isForbidden($key)) {
                $found[$at] ??= 'key';
            }

            if (is_array($value)) {
                $found = self::inspect($value, $at, $found);

                continue;
            }

            if (! self::isSafeValue($value)) {
                $found[$at] ??= 'value';
            }
        }

        return $found;
    }

    private static function isSafeValue(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return true;
        }

        return is_string($value)
            && (preg_match(self::TOKEN_VALUE, $value) === 1 || preg_match(self::TIMESTAMP_VALUE, $value) === 1);
    }

    private static function isFlat(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return true;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! ($item === null || is_bool($item) || is_int($item) || is_string($item))) {
                return false;
            }
        }

        return true;
    }

    /**
     * One key read two ways, lowercased and snake-cased, each as its words and as those words
     * joined with nothing between them.
     *
     * @return list<array{0: list<string>, 1: string}>
     */
    private static function forms(string $key): array
    {
        $forms = [];

        foreach (array_unique([strtolower($key), strtolower(Str::snake($key))]) as $base) {
            $words = array_values(array_filter(
                explode('_', (string) preg_replace('/[^a-z0-9]+/', '_', $base)),
                static fn (string $word): bool => $word !== '',
            ));

            $forms[] = [$words, implode('', $words)];
        }

        return $forms;
    }

    /**
     * The list with its underscores removed, so `raisedbyname` is caught as `raised_by_name`.
     *
     * @return list<string>
     */
    private static function stripped(): array
    {
        static $stripped = null;

        return $stripped ??= array_map(static fn (string $key): string => str_replace('_', '', $key), self::FORBIDDEN);
    }
}
