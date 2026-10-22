<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Platform\AdoptionRollup;
use Illuminate\Console\Command;

/**
 * Recomputes the adoption figures Gemini owns.
 *
 * A command rather than a query run on page load, because client health is read
 * far more often than it changes: a shift starting or a visitor arriving moves
 * these counts by one, and recomputing them for every client each time somebody
 * opens the report would put a full scan of the day's gate traffic behind a
 * screen an account manager keeps in a tab.
 *
 * Only the Gemini-owned rows. Dues, facilities, governance and the estate's own
 * payroll are counted inside each estate's database, and their rows arrive from
 * the estate console.
 */
class RefreshAdoptionRollup extends Command
{
    protected $signature = 'adoption:rollup';

    protected $description = 'Recompute the client adoption figures Gemini owns';

    public function handle(AdoptionRollup $rollup): int
    {
        $written = $rollup->refresh();

        $this->info(sprintf('%d adoption row(s) recomputed from platform data.', $written));

        return self::SUCCESS;
    }
}
