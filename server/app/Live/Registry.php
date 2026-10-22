<?php

declare(strict_types=1);

namespace App\Live;

use LogicException;
use ReflectionClass;

/**
 * What every live event class declares, collected by discovery (DESIGN.md §2.1, §2.15, §12.2).
 *
 * Each class in app/Live/Events declares the tables it makes live (`tracked()`), the hydrators its
 * inline audiences use (`hydrators()`) and the writes it deliberately leaves silent (`exempt()`).
 * The Registry scans that directory and merges them, so a wiring unit adds a class and edits no
 * shared list; EmitterCoverage and the static grep read `tracked()`, and the relay reads
 * `hydrators()`.
 *
 * A clash is refused rather than resolved: two classes naming one hydrator key for two different
 * hydrators, or one class tracking a table another declares silent, is a mistake whichever of the
 * two was meant, and a silent winner would hide it.
 *
 * The scan runs once per process. `useDirectory()` points it at a fixture directory for a test.
 */
final class Registry
{
    private const EVENTS_NAMESPACE = 'App\\Live\\Events';

    private const TABLE_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)?$/';

    private const HYDRATOR_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    private static ?string $directory = null;

    private static ?string $namespace = null;

    /** @var array{events: list<class-string<LiveEvent>>, tracked: list<string>, hydrators: array<string, class-string>, exempt: array<string, string>}|null */
    private static ?array $snapshot = null;

    /** @return list<class-string<LiveEvent>> */
    public static function events(): array
    {
        return self::snapshot()['events'];
    }

    /**
     * Every tracked `table` or `table.column`, sorted.
     *
     * @return list<string>
     */
    public static function tracked(): array
    {
        return self::snapshot()['tracked'];
    }

    /** @return array<string, class-string> */
    public static function hydrators(): array
    {
        return self::snapshot()['hydrators'];
    }

    /** @return array<string, string> */
    public static function exempt(): array
    {
        return self::snapshot()['exempt'];
    }

    /** Read event classes from another directory (a test's fixtures) until reset(). */
    public static function useDirectory(string $directory, string $namespace): void
    {
        self::$directory = rtrim($directory, '/\\');
        self::$namespace = trim($namespace, '\\');
        self::$snapshot = null;
    }

    /** Back to app/Live/Events, rescanned on next use. */
    public static function reset(): void
    {
        self::$directory = null;
        self::$namespace = null;
        self::$snapshot = null;
    }

    /**
     * Merge what these classes declare.
     *
     * @param  list<class-string<LiveEvent>>  $classes
     * @return array{tracked: list<string>, hydrators: array<string, class-string>, exempt: array<string, string>}
     *
     * @throws LogicException on a clash or a malformed declaration
     */
    public static function collect(array $classes): array
    {
        $tracked = [];
        $hydrators = [];
        $exempt = [];
        $owners = [];

        foreach ($classes as $class) {
            foreach ($class::tracked() as $entry) {
                if (preg_match(self::TABLE_PATTERN, $entry) !== 1) {
                    throw new LogicException("{$class}::tracked() names '{$entry}', which is neither a table nor table.column.");
                }

                $tracked[$entry] ??= $class;
            }

            foreach ($class::hydrators() as $key => $hydrator) {
                if (preg_match(self::HYDRATOR_PATTERN, $key) !== 1 || strlen($key) > 40) {
                    throw new LogicException("{$class}::hydrators() names the key '{$key}', which is not a hydrator key.");
                }

                if (! class_exists($hydrator)) {
                    throw new LogicException("{$class}::hydrators() maps '{$key}' to {$hydrator}, which does not exist.");
                }

                if (isset($hydrators[$key]) && $hydrators[$key] !== $hydrator) {
                    throw new LogicException("The hydrator key '{$key}' is claimed by {$owners[$key]} for {$hydrators[$key]} and by {$class} for {$hydrator}.");
                }

                $hydrators[$key] = $hydrator;
                $owners[$key] ??= $class;
            }

            foreach ($class::exempt() as $entry => $reason) {
                if (preg_match(self::TABLE_PATTERN, $entry) !== 1 || trim($reason) === '') {
                    throw new LogicException("{$class}::exempt() must map a table or table.column to the reason it is silent; '{$entry}' does not.");
                }

                $exempt[$entry] ??= [$reason, $class];
            }
        }

        foreach ($exempt as $entry => [, $class]) {
            if (str_contains($entry, '.')) {
                continue;
            }

            foreach ($tracked as $trackedEntry => $tracker) {
                if ($trackedEntry === $entry || str_starts_with($trackedEntry, $entry.'.')) {
                    throw new LogicException("The table '{$entry}' is tracked by {$tracker} and declared silent by {$class}. A table is live or it is not.");
                }
            }
        }

        $names = array_map(strval(...), array_keys($tracked));
        sort($names);
        ksort($hydrators);

        $reasons = array_map(static fn (array $pair): string => $pair[0], $exempt);
        ksort($reasons);

        return ['tracked' => $names, 'hydrators' => $hydrators, 'exempt' => $reasons];
    }

    /**
     * @return array{events: list<class-string<LiveEvent>>, tracked: list<string>, hydrators: array<string, class-string>, exempt: array<string, string>}
     */
    private static function snapshot(): array
    {
        if (self::$snapshot !== null) {
            return self::$snapshot;
        }

        $directory = self::$directory ?? app_path('Live/Events');
        $namespace = self::$namespace ?? self::EVENTS_NAMESPACE;
        $events = [];

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $class = $namespace.'\\'.basename($file, '.php');

            if (! class_exists($class)) {
                if (interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                    continue;
                }

                throw new LogicException(basename($file)." in {$directory} does not declare {$class}.");
            }

            if (! is_subclass_of($class, LiveEvent::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $events[] = $class;
        }

        sort($events);

        return self::$snapshot = ['events' => $events] + self::collect($events);
    }
}
