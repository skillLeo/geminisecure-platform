<?php

declare(strict_types=1);

namespace App\Live\Tiles;

use App\Models\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use LogicException;
use ReflectionClass;
use Throwable;

/**
 * The live tiles, and their cache (DESIGN.md §4.4).
 *
 * FOUND, NOT LISTED: every `app/Live/Tiles/*Tile.php` that is a concrete Tile, so a wiring unit adds
 * a tile and edits no shared list. `useDirectory()` points the scan at a test's fixtures.
 *
 * CACHED PER AUDIENCE, ONE COMPUTE AT A TIME. A value is kept 60 s under
 * (tile, audienceKey, version); the version moves (`bump()`) when an event says the count moved, so
 * the next read computes afresh. Two viewers with different audience keys never share an entry,
 * and a burst of reads after a bump computes once: the first takes a lock, the rest wait for it
 * (for up to 3 s, then compute for themselves rather than show nothing).
 *
 * Kept in `Cache::store()`, untagged, so a tile read with an estate open and one read without are
 * the same entry; the audience key says which estate.
 */
final class TileRegistry
{
    public const TTL_SECONDS = 60;

    private const NAMESPACE = 'App\\Live\\Tiles';

    private static ?string $directory = null;

    private static ?string $namespace = null;

    /** @var array<string, Tile>|null */
    private static ?array $tiles = null;

    /** @return array<string, Tile> by key */
    public static function all(): array
    {
        if (self::$tiles !== null) {
            return self::$tiles;
        }

        $directory = self::$directory ?? app_path('Live/Tiles');
        $namespace = self::$namespace ?? self::NAMESPACE;
        $tiles = [];

        foreach (glob($directory.'/*Tile.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if ($name === 'Tile') {
                continue;
            }

            $class = $namespace.'\\'.$name;

            if (! class_exists($class) || (new ReflectionClass($class))->isAbstract() || ! is_subclass_of($class, Tile::class)) {
                throw new LogicException("{$name}.php in {$directory} is named as a live tile but does not declare a concrete ".Tile::class.'.');
            }

            /** @var Tile $tile */
            $tile = app($class);
            $key = $tile->key();

            if (isset($tiles[$key])) {
                throw new LogicException("Two live tiles claim the key '{$key}'.");
            }

            $tiles[$key] = $tile;
        }

        ksort($tiles);

        return self::$tiles = $tiles;
    }

    public static function find(string $key): ?Tile
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The tile's value for this viewer, or null when the viewer may not see it (or there is no such
     * tile): the same answer either way, so the endpoint says nothing about which tiles exist.
     */
    public static function read(string $key, User $user): mixed
    {
        $tile = self::find($key);

        if ($tile === null || ! $tile->gate($user)) {
            return null;
        }

        $cache = Cache::store();
        $entry = 'live:tile:'.$key.':'.hash('sha256', $tile->audienceKey($user)).':v'.self::version($key);

        $cached = $cache->get($entry);

        if (is_array($cached) && array_key_exists('value', $cached)) {
            return $cached['value'];
        }

        $compute = static function () use ($cache, $entry, $tile, $user): mixed {
            $again = $cache->get($entry);

            if (is_array($again) && array_key_exists('value', $again)) {
                return $again['value'];
            }

            $value = $tile->compute($user);
            $cache->put($entry, ['value' => $value], self::TTL_SECONDS);

            return $value;
        };

        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            return $compute();
        }

        try {
            return $store->lock($entry.':lock', 10)->block(3, $compute);
        } catch (LockTimeoutException) {
            // Somebody else's compute is taking too long: compute rather than show nothing.
            return $compute();
        }
    }

    /** The count moved: the next read of this tile, by anybody, computes afresh. */
    public static function bump(string $key): void
    {
        try {
            $cache = Cache::store();
            $cache->add('gs:live:tilever:'.$key, 0, now()->addDays(30));
            $cache->increment('gs:live:tilever:'.$key);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function version(string $key): int
    {
        try {
            return (int) Cache::store()->get('gs:live:tilever:'.$key, 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function useDirectory(string $directory, string $namespace): void
    {
        self::$directory = rtrim($directory, '/\\');
        self::$namespace = trim($namespace, '\\');
        self::$tiles = null;
    }

    public static function reset(): void
    {
        self::$directory = null;
        self::$namespace = null;
        self::$tiles = null;
    }
}
