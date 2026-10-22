<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Estate\ReminderDelivery;
use App\Services\Platform\OperatorEstates;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan reminders:deliver                     # every live estate
 *   php artisan reminders:deliver --estate=phoenixparkvillage1
 *
 * Takes the arrears reminders the morning ladder queued and hands them to their channels (M84 §3.5), or, while
 * `reminders.delivery_enabled` is false (it ships false), says what it would send and sends nothing. What it sends,
 * what it expires and why is `ReminderDelivery`; this only walks the estates, as `dunning:run` does.
 *
 * NOT ON THE SCHEDULE. Delivery is turned on by Hassam once EST-B-01 is fixed (config/reminders.php says how), and
 * the line that runs this each morning after `dunning:run` goes in with that decision, not before it.
 */
class RemindersDeliver extends Command
{
    protected $signature = 'reminders:deliver {--estate= : one estate only}';

    protected $description = 'Send the arrears reminders each live estate has queued, or, while delivery is off, show what would be sent';

    public function handle(ReminderDelivery $delivery): int
    {
        $estates = OperatorEstates::estates(function ($query): void {
            $query->where('status', 'active')->orderBy('id');

            if ($this->option('estate') !== null) {
                $query->whereKey((string) $this->option('estate'));
            }
        });

        if ($estates->isEmpty()) {
            $this->warn('No live estate to deliver for.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($estates as $estate) {
            try {
                if (! ReminderDelivery::enabled()) {
                    $preview = $estate->run(fn (): array => $delivery->preview());
                    $this->info(sprintf(
                        '%s: delivery is off, nothing was sent. %d %s would go out; %d queued earlier would be marked expired, never sent.',
                        $estate->name,
                        $preview['total'],
                        $preview['total'] === 1 ? 'reminder' : 'reminders',
                        $preview['would_expire'],
                    ));

                    continue;
                }

                $report = $estate->run(fn (): array => $delivery->run());
            } catch (Throwable $failure) {
                $failed++;
                $this->error("{$estate->name}: the delivery run failed: {$failure->getMessage()}");

                continue;
            }

            if ($report['refused'] !== null) {
                $failed++;
                $this->error("{$estate->name}: {$report['refused']}");

                continue;
            }

            $this->info(sprintf('%s: %d delivered, %d failed, %d expired, never sent.', $estate->name, $report['delivered'], $report['failed'], $report['expired']));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
