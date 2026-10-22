<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Estate\AdoptionReport;
use Illuminate\Console\Command;

/**
 * Each estate counts its own take-up and pushes it to the platform.
 *
 * THE COMPANION TO `adoption:rollup`, AND THE DIRECTION IS THE POINT. That
 * command counts what Gemini operates — guards, gates, passes — from data
 * already in `gs_platform`. This one runs INSIDE each estate's tenancy, counts
 * the modules the estate itself operates, and sends two integers and a sentence
 * outward.
 *
 * A single command that opened every estate database to build a cross-client
 * report would be the same code with the arrows reversed, and it would be a
 * tenant-isolation breach. The difference is not cosmetic: here, each estate is
 * the only thing reading itself, and what crosses the boundary carries no name,
 * no household, no unit and no money.
 *
 * A COMMAND RATHER THAN A QUERY ON PAGE LOAD, for `adoption:rollup`'s own
 * reason: client health is read far more often than it changes, and putting a
 * scan of six modules across every estate behind a screen an account manager
 * keeps in a tab is how a report becomes the slowest page on the platform.
 */
class ReportEstateAdoption extends Command
{
    protected $signature = 'adoption:report
        {estate? : One estate key. Omit to report for every estate.}';

    protected $description = 'Each estate counts its own module take-up and reports it centrally';

    public function handle(AdoptionReport $report): int
    {
        $only = $this->argument('estate');

        /*
         * Narrowed out of `estates()` rather than queried separately, so a key
         * typed on the command line can only ever name a real estate. A bare
         * `whereKey()` would happily report on a central tenant row that has no
         * estate database behind it, and the failure would be a connection
         * error rather than a sentence saying the estate does not exist.
         */
        $estates = Tenant::estates()
            ->filter(fn (Tenant $estate): bool => $only === null || (string) $estate->getTenantKey() === $only)
            ->values();

        if ($estates->isEmpty()) {
            $this->error($only === null
                ? 'No estates to report on.'
                : "No estate found for [{$only}].");

            return self::FAILURE;
        }

        $reported = 0;

        foreach ($estates as $estate) {
            $key = (string) $estate->getTenantKey();

            /*
             * Inside the estate, every time. `run()` points the `tenant`
             * connection at exactly one database for the duration of the
             * closure, which is what makes each count a fact about that estate
             * and nothing else — and the central write inside it goes to
             * `gs_platform` because ClientAdoption is pinned there.
             */
            $written = $estate->run(fn (): int => $report->push($key));

            $reported += $written;

            $this->line(sprintf('  %-16s %d capabilit%s reported', $key, $written, $written === 1 ? 'y' : 'ies'));
        }

        $this->info(sprintf('%d adoption row(s) reported by %d estate(s).', $reported, $estates->count()));

        return self::SUCCESS;
    }
}
