<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\AlertRaised;
use App\Models\DuressAlert;
use Illuminate\Console\Command;

/**
 * Fires one alert broadcast so a browser can be watched receiving it.
 *
 * LOCAL ONLY. This exists because a WebSocket cannot be asserted from PHP: a
 * feature test can prove the event WOULD broadcast on the right channel, but
 * not that a browser connects, that authorization admits it, and that the
 * payload lands. Those are three separate failures that all present as the
 * same thing on screen — a queue that never updates.
 *
 * Paired with tests/Fidelity/echo-check.mjs, which does the watching.
 *
 * The alert is marked simulated and is NOT persisted: this is a transport
 * check, and a test broadcast that leaves a row behind would put a fake panic
 * in a real dispatch queue.
 */
class BroadcastTestAlert extends Command
{
    protected $signature = 'alerts:broadcast-test {tenant : The estate id to broadcast to}';

    protected $description = 'Broadcast one simulated alert so the socket can be observed (local only)';

    public function handle(): int
    {
        if (! app()->isLocal()) {
            $this->error('alerts:broadcast-test is a local diagnostic and must not run elsewhere.');

            return self::FAILURE;
        }

        $tenant = (string) $this->argument('tenant');

        /*
         * Unsaved, on purpose. AlertRaised reads only the attributes it
         * broadcasts, and SerializesModels leaves a model with no key alone
         * rather than trying to re-fetch it.
         */
        $alert = new DuressAlert([
            'tenant_id' => $tenant,
            'kind' => 'panic',
            'server_time' => now(),
            'is_simulated' => true,
        ]);

        $alert->id = 0;

        AlertRaised::dispatch($alert);

        $this->info("Broadcast alert.raised on private-estate.{$tenant}.alerts");

        return self::SUCCESS;
    }
}
