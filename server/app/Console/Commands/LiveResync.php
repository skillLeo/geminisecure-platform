<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\Auth\EstateScope;
use App\Live\Events\ResyncLive;
use App\Live\LiveBus;
use App\Live\Stream;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 *   php artisan live:resync {estate}
 *
 * Every open screen of one estate re-reads what it shows (DESIGN.md §5.5, §6.5): its estate
 * console, its guards' and residents' phones through the estate's feeds and community, and the
 * Gemini screens that carry it. For a repair made outside the application (a row put right by
 * hand, a restore) that no live event describes.
 *
 * It records `live.resync` on the estate's own stream and on the central one (ResyncLive), each
 * in its own transaction, so it is logged, sequenced and published like any change, and a screen
 * that is offline now resyncs when it catches up. It changes no data.
 */
class LiveResync extends Command
{
    protected $signature = 'live:resync
        {tenant : the estate whose open screens should re-read everything}';

    protected $description = 'Make every open screen of one estate re-read its data (a recorded live.resync)';

    public function handle(): int
    {
        $tenant = (string) $this->argument('tenant');

        try {
            Stream::estate($tenant);
        } catch (InvalidArgumentException) {
            $this->line("<fg=red>'{$tenant}' is not an estate key.</>");

            return self::FAILURE;
        }

        if (! Tenant::query()->whereKey($tenant)->exists()) {
            $this->line("<fg=red>There is no estate '{$tenant}'.</>");

            return self::FAILURE;
        }

        EstateScope::run($tenant, static function () use ($tenant): void {
            DB::connection('tenant')->transaction(static fn () => LiveBus::record(ResyncLive::estate($tenant)));
        });

        DB::connection(Stream::central()->connection())->transaction(static fn () => LiveBus::record(ResyncLive::gemini($tenant)));

        $this->line("Recorded live.resync for estate {$tenant}. Every open screen of it re-reads its data within a few seconds; one that is offline does when it reconnects.");

        return self::SUCCESS;
    }
}
