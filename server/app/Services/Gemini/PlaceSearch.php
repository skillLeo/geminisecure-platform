<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * FINDING A PLACE ON THE MAP BY ITS NAME (M30 §7.3): a city, an area or an address, typed above the Places map.
 *
 * Asked of OpenStreetMap's Nominatim by our server, never the browser, so that:
 *   - every request carries our own User-Agent and a contact address, as Nominatim's usage policy asks;
 *   - there is at most ONE REQUEST A SECOND ACROSS THE WHOLE PLATFORM, however many people are typing;
 *   - an answer is kept for 30 DAYS by its exact search text, so the same search asks nobody twice.
 *
 * The search finds the place; it never places a pin. And it never stands in the way: when Nominatim is slow, refuses or
 * is down, the answer is an empty list and one line saying so, and the map still places by a click or by pasted
 * numbers. A failure is not kept, so the next search asks again.
 */
final class PlaceSearch
{
    /** How long one answer is kept, by its search text. */
    public const CACHE_DAYS = 30;

    /** At most this many places in the list under the box. */
    public const LIMIT = 5;

    /** One key for the whole platform: the limit is Nominatim's, not a viewer's. */
    private const LIMITER = 'places:nominatim';

    /** How long Nominatim is waited for before the box says it is not answering. */
    private const TIMEOUT_SECONDS = 4;

    /**
     * Up to five places for this text, each with its name, its point and the box to show it in; or none, with why.
     *
     * @return array{results: list<array{name: string, lat: float, lng: float, bbox: list<float>|null}>, message: string|null}
     */
    public function search(string $text): array
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($q) < 3) {
            return ['results' => [], 'message' => 'Type at least three letters of a place or an address.'];
        }

        $q = mb_substr($q, 0, 200);
        $key = 'places:search:'.hash('sha256', mb_strtolower($q));
        $kept = Cache::get($key);

        if (is_array($kept)) {
            return self::answer($kept);
        }

        $found = null;
        $asked = RateLimiter::attempt(self::LIMITER, 1, function () use ($q, &$found): void {
            $found = $this->ask($q);
        }, 1);

        if ($asked === false) {
            return ['results' => [], 'message' => 'Place search is busy for a moment. Try again, or click the map where it is.'];
        }

        if ($found === null) {
            return ['results' => [], 'message' => 'Place search is not answering right now. Click the map where it is, or paste its numbers.'];
        }

        Cache::put($key, $found, now()->addDays(self::CACHE_DAYS));

        return self::answer($found);
    }

    /**
     * Nominatim's answer, as places; null when it did not answer as it should.
     *
     * @return list<array{name: string, lat: float, lng: float, bbox: list<float>|null}>|null
     */
    private function ask(string $q): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::agent(), 'Accept-Language' => 'en'])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get(rtrim((string) config('services.nominatim.url'), '/').'/search', ['q' => $q, 'format' => 'jsonv2', 'limit' => self::LIMIT]);
        } catch (Throwable) {
            return null;
        }

        $rows = $response->successful() ? $response->json() : null;

        if (! is_array($rows)) {
            return null;
        }

        $places = [];

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            if (! is_array($row) || ! is_numeric($row['lat'] ?? null) || ! is_numeric($row['lon'] ?? null)) {
                continue;
            }

            // Nominatim's box is south, north, west, east.
            $box = is_array($row['boundingbox'] ?? null) && count($row['boundingbox']) === 4 && count(array_filter($row['boundingbox'], 'is_numeric')) === 4
                ? array_map('floatval', array_values($row['boundingbox']))
                : null;

            $places[] = [
                'name' => (string) ($row['display_name'] ?? $row['name'] ?? ''),
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lon'],
                'bbox' => $box,
            ];
        }

        return $places;
    }

    /**
     * @param  list<array{name: string, lat: float, lng: float, bbox: list<float>|null}>  $places
     * @return array{results: list<array{name: string, lat: float, lng: float, bbox: list<float>|null}>, message: string|null}
     */
    private static function answer(array $places): array
    {
        return ['results' => $places, 'message' => $places === [] ? 'Nothing found by that name. Try a nearby town or road, or click the map where it is.' : null];
    }

    /** Who is asking, as Nominatim's policy wants it said: the application, and an address to write to. */
    public static function agent(): string
    {
        $contact = trim((string) config('services.nominatim.contact'));

        return 'GeminiSecure/1.0 (security operations console'.($contact !== '' ? '; '.$contact : '').')';
    }
}
