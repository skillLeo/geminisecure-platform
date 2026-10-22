<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;

/**
 * A panic's legs, read back from `duress_alert_deliveries` and summarised across presses (M11 A1).
 *
 * The platform writes one row per leg of the fan-out with the moment it let go of that leg
 * (`elapsed_ms`, M10 §1.3). This reads those rows for a set of alerts and puts the same leg of
 * every press side by side, so a leg's figure is the median of what the platform itself recorded.
 * Nothing is timed here.
 *
 * ONE DESTINATION CAN HAVE MORE THAN ONE LEG ON ONE CHANNEL (the texts are filed under whom they
 * are for), so a leg is keyed by destination, channel and the first clause of its note.
 */
final class PanicLegs
{
    /**
     * @param  list<int>  $alertIds
     * @return list<array{alert: int, fanout_ms: int|null, legs: list<array<string, mixed>>}>
     */
    public static function walks(array $alertIds): array
    {
        $walks = [];

        foreach ($alertIds as $id) {
            $alert = DuressAlert::query()->findOrFail($id);

            $walks[] = [
                'alert' => $alert->id,
                'fanout_ms' => $alert->fanout_ms,
                'legs' => DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)->orderBy('id')
                    ->get(['destination', 'channel', 'recipients', 'outcome', 'elapsed_ms', 'note'])->toArray(),
            ];
        }

        return $walks;
    }

    /**
     * @param  list<array{legs: list<array<string, mixed>>}>  $walks
     * @return list<array<string, mixed>> one row per leg, fastest hand-off first
     */
    public static function summarise(array $walks): array
    {
        $legs = [];

        foreach ($walks as $walk) {
            foreach ($walk['legs'] as $leg) {
                $key = $leg['destination'].' · '.$leg['channel'].' · '.strtok((string) $leg['note'], '.');
                $legs[$key]['destination'] = $leg['destination'];
                $legs[$key]['channel'] = $leg['channel'];
                $legs[$key]['outcomes'][$leg['outcome']] = true;
                $legs[$key]['recipients'][] = $leg['recipients'];
                $legs[$key]['elapsed_ms'][] = $leg['elapsed_ms'];
                $legs[$key]['note'] = $leg['note'];
            }
        }

        $rows = [];

        foreach ($legs as $leg) {
            $elapsed = array_values(array_filter($leg['elapsed_ms'], static fn ($v): bool => $v !== null));
            $rows[] = [
                'destination' => $leg['destination'],
                'label' => DuressAlertDelivery::DESTINATIONS[$leg['destination']] ?? $leg['destination'],
                'channel' => $leg['channel'],
                'outcome' => implode('|', array_keys($leg['outcomes'])),
                'recipients' => ApiProbe::median($leg['recipients']),
                'n' => count($elapsed),
                'elapsed_ms_median' => ApiProbe::median($elapsed),
                'elapsed_ms_max' => $elapsed === [] ? null : max($elapsed),
                'note' => $leg['note'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($a['elapsed_ms_median'] ?? PHP_INT_MAX) <=> ($b['elapsed_ms_median'] ?? PHP_INT_MAX));

        return $rows;
    }

    /**
     * Did every press reach at least one leg of every one of the seven destinations?
     *
     * @param  list<array{legs: list<array<string, mixed>>}>  $walks
     */
    public static function everyDestinationOnEveryPress(array $walks): bool
    {
        $all = collect(array_keys(DuressAlertDelivery::DESTINATIONS))->sort()->values()->all();

        foreach ($walks as $walk) {
            if (collect($walk['legs'])->pluck('destination')->unique()->sort()->values()->all() !== $all) {
                return false;
            }
        }

        return true;
    }
}
