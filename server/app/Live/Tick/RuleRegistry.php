<?php

declare(strict_types=1);

namespace App\Live\Tick;

use LogicException;
use ReflectionClass;

/**
 * The `live:tick` rules, found by scanning `app/Live/Tick/*Rule.php` (DESIGN.md §2.13, §12.2).
 *
 * A file whose name ends in `Rule.php` must declare a concrete class of that name implementing
 * Rule; one that does not is a mistake, and refused rather than skipped, since a rule that
 * silently never runs is a screen that silently never moves. The interface itself (`Rule.php`) is
 * the one exception. `useDirectory()` points the scan at a test's fixtures.
 */
final class RuleRegistry
{
    private const NAMESPACE = 'App\\Live\\Tick';

    private static ?string $directory = null;

    private static ?string $namespace = null;

    /** @var list<Rule>|null */
    private static ?array $rules = null;

    /** @return list<Rule> every rule, sorted by class name */
    public static function all(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $directory = self::$directory ?? app_path('Live/Tick');
        $namespace = self::$namespace ?? self::NAMESPACE;
        $classes = [];

        foreach (glob($directory.'/*Rule.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if ($name === 'Rule') {
                continue;
            }

            $class = $namespace.'\\'.$name;

            if (! class_exists($class)) {
                throw new LogicException(basename($file)." in {$directory} does not declare {$class}.");
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(Rule::class)) {
                throw new LogicException("{$class} is named as a live:tick rule but is not a concrete ".Rule::class.'.');
            }

            $classes[] = $class;
        }

        sort($classes);

        $rules = [];

        foreach ($classes as $class) {
            $rule = app($class);

            if (! in_array($rule->stream(), ['central', 'estate'], true)) {
                throw new LogicException("{$class}::stream() must say central or estate.");
            }

            $rules[] = $rule;
        }

        return self::$rules = $rules;
    }

    /** @return list<Rule> */
    public static function for(string $stream): array
    {
        return array_values(array_filter(self::all(), static fn (Rule $rule): bool => $rule->stream() === $stream));
    }

    /** Scan another directory (a test's fixtures) until reset(). */
    public static function useDirectory(string $directory, string $namespace): void
    {
        self::$directory = rtrim($directory, '/\\');
        self::$namespace = trim($namespace, '\\');
        self::$rules = null;
    }

    public static function reset(): void
    {
        self::$directory = null;
        self::$namespace = null;
        self::$rules = null;
    }
}
