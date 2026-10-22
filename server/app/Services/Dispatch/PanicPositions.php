<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Api\DeviceContext;
use App\Live\Events\AlertLive;
use App\Live\LiveBus;
use App\Models\DuressAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WHERE SOMEBODY ASKING FOR HELP IS, WHILE THEY ARE STILL ASKING (Hassam, 12 Oct 2026: "their position when they pressed
 * it, and updated while the alert stays open"; GUARD_TRACKING.md 5.3).
 *
 * The phone that raised a panic or a duress sends where it is to `POST duress/{alert}/positions` while the alert is
 * open; each reading is kept with the alert, for ever, like the alert (`duress_alert_positions`, append-only), and the
 * maps draw the newest. Answered reading by reading, as a guard's batch is: `accepted`, `duplicate` (stored once) or
 * `rejected` (`alert_closed`: a closed alert is no longer followed).
 *
 * NEVER REFUSED FOR BEING FAR AWAY. A guard's reading kilometres from the estate is refused as impossible; a person in
 * danger moving away from it is exactly what the control room must see, so theirs is kept, and a map that cannot place
 * it on the estate names it with how far out it is.
 */
final class PanicPositions
{
    /** At most this many readings in one send. */
    public const MAX_BATCH = 50;

    /**
     * @param  list<array<string, mixed>>  $positions  validated by the controller
     * @return array{alert_id: int, results: list<array<string, string>>}
     */
    public function record(DuressAlert $alert, array $positions, DeviceContext $context, bool $simulated = false): array
    {
        $key = static fn (array $p): string => Str::limit((string) $p['idempotency_key'], 64, '');

        if (in_array($alert->status, ['resolved', 'false_alarm'], true)) {
            return ['alert_id' => (int) $alert->id, 'results' => array_map(static fn (array $p): array => [
                'idempotency_key' => $key($p),
                'result' => 'rejected',
                'reason' => 'alert_closed',
                'message' => 'This alert is closed, so where you are is no longer followed. If you still need help, raise a new alert.',
            ], $positions)];
        }

        $known = array_flip(DB::connection('mysql')->table('duress_alert_positions')->whereIn('client_id', array_map($key, $positions))->pluck('client_id')->all());
        $results = [];
        $rows = [];

        foreach ($positions as $position) {
            $id = $key($position);

            if (isset($known[$id])) {
                $results[] = ['idempotency_key' => $id, 'result' => 'duplicate'];

                continue;
            }

            $known[$id] = true;
            $results[] = ['idempotency_key' => $id, 'result' => 'accepted'];
            $rows[] = [
                'duress_alert_id' => (int) $alert->id,
                'client_id' => $id,
                'latitude' => round((float) $position['lat'], 7),
                'longitude' => round((float) $position['lng'], 7),
                'accuracy_m' => isset($position['accuracy_metres']) ? min(65535, (int) $position['accuracy_metres']) : null,
                'captured_at' => Carbon::parse((string) $position['captured_at'])->utc(),
                'received_at' => $context->serverTime,
                'is_simulated' => $simulated || (bool) $alert->is_simulated,
            ];
        }

        if ($rows !== []) {
            DB::connection('mysql')->transaction(function () use ($rows, $alert): void {
                if (DB::connection('mysql')->table('duress_alert_positions')->insertOrIgnore($rows) > 0) {
                    // Every screen showing the alert reads where the phone is now (a reference: never the position).
                    LiveBus::recordAll(AlertLive::for($alert, 'moved'));
                }
            });
        }

        return ['alert_id' => (int) $alert->id, 'results' => $results];
    }
}
