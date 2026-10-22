<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PRINTS THE RANDOM SEED WHEN SOMETHING FAILS (M4 §4, defect 3).
 *
 * `phpunit.xml` runs the suite in random order so that an order dependence fails
 * on some seed instead of waiting to be found by luck. That only helps if the
 * person who sees the failure can reproduce it, and a seed nobody wrote down is
 * a failure nobody can chase.
 *
 * PHPUnit does print the seed in its own header — but this suite runs through
 * `php artisan test`, which hands Pest `--no-output` and renders the result
 * itself, so that header is not what a developer or an agent actually sees. The
 * line below is written to STDERR at the END of the run, and only when something
 * failed or errored, so a green run says nothing and a red one says exactly one
 * thing: the command that reproduces it.
 *
 * It is silent unless the run really was randomised, so `--order-by=default`
 * prints nothing misleading.
 */
final class RandomOrderSeed implements Extension
{
    private static bool $failed = false;

    private static ?int $seed = null;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if ($configuration->executionOrder() !== TestSuiteSorter::ORDER_RANDOMIZED) {
            return;
        }

        self::$seed = $configuration->randomOrderSeed();

        $facade->registerSubscribers(
            new class implements FailedSubscriber
            {
                public function notify(Failed $event): void
                {
                    RandomOrderSeed::remember();
                }
            },
            new class implements ErroredSubscriber
            {
                public function notify(Errored $event): void
                {
                    RandomOrderSeed::remember();
                }
            },
            new class implements ExecutionFinishedSubscriber
            {
                public function notify(ExecutionFinished $event): void
                {
                    RandomOrderSeed::report();
                }
            },
        );
    }

    /** Something went red; the seed is now worth printing. */
    public static function remember(): void
    {
        self::$failed = true;
    }

    /** One line, at the end, only if the run went red. */
    public static function report(): void
    {
        if (! self::$failed || self::$seed === null) {
            return;
        }

        fwrite(
            STDERR,
            PHP_EOL
            .'  RANDOM ORDER · seed '.self::$seed.'. This exact order is reproduced with:'.PHP_EOL
            .'    php artisan test --order-by=random --random-order-seed='.self::$seed.PHP_EOL
            .'  If it passes on its own and fails here, it is an ORDER DEPENDENCE: fix the test, not the order.'.PHP_EOL
            .PHP_EOL,
        );
    }
}
