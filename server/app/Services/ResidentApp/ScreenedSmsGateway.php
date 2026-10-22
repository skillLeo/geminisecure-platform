<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Support\Seeded\SeededRecipients;

/**
 * EVERY TEXT, SCREENED BEFORE THE PROVIDER SEES IT (M35 §5.2, layer 1).
 *
 * Wraps whichever driver `AppServiceProvider` chose, where it is chosen, so every text the platform sends passes
 * through here: a panic's, a sign-in code, the settings screen's test. A number that only a person a seeder invented
 * holds is not sent — the driver is never called, the drop is counted (`seeded_recipient_drops`) and the answer is
 * `SmsResult::SEEDED`, which no count of delivered texts includes. Every other number goes to the driver unchanged.
 */
final class ScreenedSmsGateway implements SmsGateway
{
    public function __construct(private readonly SmsGateway $driver, private readonly string $driverName) {}

    public function send(string $to, string $message): SmsResult
    {
        if (SeededRecipients::screenNumber($to) !== null) {
            return SmsResult::seeded($this->driverName);
        }

        return $this->driver->send($to, $message);
    }

    /** The driver underneath, for a caller that has to know which one is connected. */
    public function driver(): SmsGateway
    {
        return $this->driver;
    }
}
