<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Api\Handoff;
use Illuminate\Console\Command;

/**
 *   php artisan api:handoff            # write MOBILE_HANDOFF.md from the catalogue
 *   php artisan api:handoff --check    # exit 1 if the file on disk is stale
 *
 * See `App\Api\Handoff`.
 */
class ApiHandoff extends Command
{
    protected $signature = 'api:handoff {--check : fail if MOBILE_HANDOFF.md differs from the catalogue}';

    protected $description = 'Write MOBILE_HANDOFF.md from the API catalogue';

    public function handle(): int
    {
        $path = base_path('MOBILE_HANDOFF.md');
        $rendered = Handoff::render();

        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $rendered) {
                $this->error('MOBILE_HANDOFF.md is stale. Run `php artisan api:handoff`.');

                return self::FAILURE;
            }

            $this->info('MOBILE_HANDOFF.md matches the catalogue.');

            return self::SUCCESS;
        }

        file_put_contents($path, $rendered);

        $this->info('Wrote MOBILE_HANDOFF.md — '.substr_count($rendered, "\n### `").' endpoints.');

        return self::SUCCESS;
    }
}
