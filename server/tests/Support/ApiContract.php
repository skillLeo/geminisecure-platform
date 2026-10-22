<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Api\AppMatrix;
use App\Api\Catalogue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Holds a response to its catalogue entry (13 D1, D2).
 *
 * THE ALLOWLIST. Every key path in the response body must be listed in the
 * entry's `response` — `items.3.name` is checked as `items.*.name`. A key the
 * catalogue does not name fails the test, which is how a field added to a guard
 * endpoint cannot carry a household's balance out unnoticed: somebody has to
 * write it into the catalogue, where it is reviewed and documented.
 *
 * AND INVARIANT 2, BY NAME. On any route a Guard App token reaches, no key path
 * may name money — `balance`, `arrears`, `owed`, `amount`, `_minor`, `ageing` —
 * and no value may be a currency figure. The one exception is `payslips.me`:
 * the guard's own pay, which is theirs to read (see `AppMatrix`, `payslips`).
 */
final class ApiContract
{
    /**
     * A key segment that names a household's (or anybody's) money — matched as a
     * word inside the segment, so `amount_due` and `balance` fail and `allowed`
     * (which contains "owed") does not.
     */
    private const MONEY_KEY = '/(^|_)(balances?|arrears?|owed|owing|amounts?|ageing|aging|outstanding|dues|minor|charges?|invoices?|payments?)(_|$)/';

    /** Whether a response key path names money. */
    public static function namesMoney(string $path): bool
    {
        foreach (explode('.', strtolower($path)) as $segment) {
            if (preg_match(self::MONEY_KEY, $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The guard's own wage is the one money a guard's handset may read. */
    private const GUARD_MONEY_ALLOWED = ['payslips.me'];

    public static function assertMatches(TestResponse $response, string $routeName): void
    {
        $entry = Catalogue::endpoints()[$routeName] ?? null;

        Assert::assertNotNull($entry, "No catalogue entry named [{$routeName}].");

        $body = $response->json();

        if (! is_array($body) || isset($body['error'])) {
            return;
        }

        $allowed = array_keys($entry['response']);
        $paths = self::paths($body);

        foreach ($paths as $path) {
            Assert::assertTrue(
                self::isAllowed($path, $allowed),
                "[{$routeName}] returned `{$path}`, which its catalogue entry does not list. Add it to the catalogue — and to MOBILE_HANDOFF.md — or stop returning it."
            );
        }

        self::assertOneTimeRule($body, $routeName);

        if (in_array(AppMatrix::GUARD, $entry['apps'], true) && ! in_array($routeName, self::GUARD_MONEY_ALLOWED, true)) {
            foreach ($paths as $path) {
                Assert::assertFalse(self::namesMoney($path), "Invariant 2: [{$routeName}] is reachable by a guard and returned `{$path}`.");
            }

            Assert::assertStringNotContainsString('J$', (string) $response->getContent(), "Invariant 2: [{$routeName}] returned a currency figure to a guard's handset.");
        }
    }

    /**
     * THE ONE TIME RULE (M4 §3.1), on every answer either app is given.
     *
     * Any value that is a date-time is a full ISO 8601 instant IN UTC, written `+00:00` —
     * never the site's offset, never the handset's, never a bare wall-clock string. The
     * apps show it in the site's zone, which they are handed by name as `timezone`. A
     * calendar date (`2026-09-20`) and a time of day (`08:00:00`) are not instants and
     * are left alone.
     *
     * @param  array<mixed>  $data
     */
    public static function assertOneTimeRule(array $data, string $routeName, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                self::assertOneTimeRule($value, $routeName, $path);

                continue;
            }

            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) === 1) {
                Assert::assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
                    $value,
                    "One time rule: [{$routeName}] returned `{$path}` = `{$value}`. Every time is a UTC instant (`…+00:00`); send it through SiteTime::iso()."
                );
            }
        }
    }

    /**
     * Every leaf and object key path, with list indices replaced by `*`.
     *
     * @param  array<mixed>  $data
     * @return list<string>
     */
    public static function paths(array $data, string $prefix = ''): array
    {
        $paths = [];

        foreach ($data as $key => $value) {
            $segment = is_int($key) ? '*' : (string) $key;
            $path = $prefix === '' ? $segment : $prefix.'.'.$segment;

            if (is_array($value) && $value !== []) {
                $children = self::paths($value, $path);

                // An object's own key is documented by its children; a list's by `*`.
                $paths = [...$paths, ...$children];

                continue;
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /** @param  list<string>  $allowed */
    private static function isAllowed(string $path, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            if ($pattern === $path) {
                return true;
            }

            // `results.*.body.**`: an opaque subtree, documented by the endpoint it came from.
            if (str_ends_with($pattern, '.**') && str_starts_with($path, substr($pattern, 0, -2))) {
                return true;
            }

            // A documented parent covers an empty object or list at that path.
            if (str_starts_with($pattern, $path.'.')) {
                return true;
            }
        }

        return false;
    }
}
