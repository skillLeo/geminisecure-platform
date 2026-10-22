<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SpeaksAsAHandset;
use App\Models\Guard;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Mobile event simulator: alerts.
 *
 *   php artisan simulate:alerts --count=5
 *
 * Fires real events through the REAL /api/v1 endpoint the Guard and Resident
 * apps will call. Nothing here writes to a table directly, so the endpoint,
 * its validation, its idempotency and its clock-skew handling are all
 * exercised rather than bypassed - and nothing built against the simulator has
 * to be rewritten when the apps arrive.
 *
 * Every row it creates is flagged is_simulated, so the source badge on the web
 * screens can say plainly that the data is not real.
 */
class SimulateAlerts extends Command
{
    use SpeaksAsAHandset;

    protected $signature = 'simulate:alerts
        {--count=5 : how many alerts to raise}
        {--offline : mark them as captured offline, with a skewed device clock}';

    protected $description = 'Raise simulated mobile alerts through the real /api/v1 endpoint';

    private const KINDS = ['panic', 'duress', 'medical', 'intrusion', 'fire'];

    private const RESIDENTS = ['Andrea Fletcher', 'Devon Grant', 'Kayla Morrison', 'Rohan Peart'];

    public function handle(): int
    {
        $estates = Tenant::estates();

        if ($estates->isEmpty()) {
            $this->error('No estates provisioned. Run estate:provision first.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('count'));
        $offline = (bool) $this->option('offline');
        $created = 0;

        /*
         * A borrowed handset, and it is why this command had never worked.
         *
         * Every /api/v1 endpoint sits behind `auth:sanctum` and an ability. This
         * simulator predates that and was never updated, so it had been
         * answering 401 on every request and reporting "Raised 0 of 5" as though
         * the simulation had merely gone badly. It enrols a real handset now,
         * speaks over the wire with its bearer token, and hands it back.
         */
        $borrowed = $this->borrowHandset((string) $estates->first()->getTenantKey());

        if (! $borrowed) {
            $this->error('No active guard to borrow a handset from. An alert needs a device to raise it.');

            return self::FAILURE;
        }

        try {
            $created = $this->raise($estates, $count, $offline);
        } finally {
            // Always. A run that fails part way must not leave a working
            // credential behind on the machine that ran it.
            $this->returnHandset();
        }

        $this->info("Raised {$created} of {$count} through POST /api/v1/alerts.");

        return $created === $count ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Collection<int, Tenant>  $estates
     */
    private function raise($estates, int $count, bool $offline): int
    {
        $created = 0;

        for ($i = 0; $i < $count; $i++) {
            $estate = $estates[$i % $estates->count()];
            $kind = self::KINDS[$i % count(self::KINDS)];
            $isGuardAlert = in_array($kind, ['duress', 'intrusion'], true);

            $guard = $isGuardAlert
                ? Guard::where('tenant_id', $estate->getTenantKey())->inRandomOrder()->first()
                : null;

            $payload = [
                'tenant_id' => $estate->getTenantKey(),
                'kind' => $kind,
                'guard_id' => $guard?->id,
                'raised_by_name' => $isGuardAlert ? null : self::RESIDENTS[$i % count(self::RESIDENTS)],
                'unit_reference' => $isGuardAlert ? null : 'PP-'.(1 + $i % 4).'A',
                'captured_offline' => $offline,

                /*
                 * A deliberately skewed device clock when simulating offline
                 * capture, so the console's clock-skew flag has something real
                 * to display rather than being permanently dark.
                 */
                'device_time' => $offline
                    ? now()->subMinutes(9)->toIso8601String()
                    : now()->toIso8601String(),

                /*
                 * Stable, so re-running is idempotent rather than filling the
                 * queue with duplicates - but varied by MODE, because an
                 * offline capture is a different event from an online one and
                 * sharing a key would make --offline silently return the
                 * online row it had already created.
                 */
                'idempotency_key' => sprintf(
                    'sim-%s-%s-%s-%d',
                    $estate->getTenantKey(),
                    $kind,
                    $offline ? 'offline' : 'online',
                    $i,
                ),
                'simulated' => true,
            ];

            $response = $this->callApi('/api/v1/alerts', $payload);

            if ($response['status'] === 201) {
                $created++;
                $this->line("  <fg=green>201</> {$kind} at {$estate->name}");
            } else {
                $this->line("  <fg=red>{$response['status']}</> {$kind}: ".
                    str($response['body'])->limit(160));
            }
        }

        return $created;
    }
}
