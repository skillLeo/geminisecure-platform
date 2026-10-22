<?php

declare(strict_types=1);

namespace App\Support\Seeded;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A COMMAND THAT SEEDS MARKS EVERY ROW IT WRITES (M33 §5.2, KI-25).
 *
 * On the command itself, not on the console's start and finish events: Laravel routes those through Symfony's
 * dispatcher only outside the unit tests, and a mark the tests cannot see is a mark nobody has proved. Whatever runs
 * the command — `php artisan`, `Artisan::call()`, a test — the mark is on for exactly as long as it runs, and off
 * again even when it throws. The outermost seeding command names the rows (`Seeding`).
 *
 * Used by `db:seed` (`MarkedSeedCommand`) and by `demo:seed`, `phoenix:demo-seed` and `company:demo-seed`;
 * `SeededMarksTest` fails a seeding command that does not use it.
 */
trait SeedsMarkedRows
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (int) Seeding::run((string) $this->getName(), fn (): int => (int) parent::execute($input, $output));
    }
}
