<?php

declare(strict_types=1);

namespace App\Support\Seeded;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A COMMAND THAT KEEPS THE DATABASE, RATHER THAN SHOWS IT, SEES EVERY ROW (M33 §5.4, KI-25).
 *
 * The retirement itself and the purge read and write the rows a retirement hides, so the scope is off for as long as
 * they run — on the command, for the reason `SeedsMarkedRows` gives. The migrations, the grants and the gates are seen
 * blind through the console's events (`Retirements::BLIND_COMMANDS`), which is where the server runs them.
 */
trait SeesEveryRow
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (int) Retirements::seeingEverything(fn (): int => (int) parent::execute($input, $output));
    }
}
