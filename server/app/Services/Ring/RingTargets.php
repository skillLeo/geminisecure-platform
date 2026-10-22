<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Models\DuressAlert;
use App\Models\DuressAlertRingTarget;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * EVERY GUARD AN ALERT HAS RUNG (the guard ring §3.3) — and therefore who may answer it.
 *
 * `PanicFanOut::guardsFor()` is the authorisation set for a guard's handset: who may open
 * the alert, say "on my way", and be sent by a dispatcher. It was the officers on duty and
 * rostered today, recomputed live — so an off-duty officer rung at the escalation would
 * have been told about a panic and then refused `403 not_on_shift` when they tried to
 * answer it. It now unions this list in: the set is exactly who was rung.
 *
 * Central, through the model, and never throws: an empty list is the answer the panic
 * path can survive, and it is today's.
 */
final class RingTargets
{
    /** @return list<int> */
    public static function guardIdsFor(DuressAlert $alert): array
    {
        try {
            return DuressAlertRingTarget::query()
                ->where('duress_alert_id', (int) $alert->getKey())
                ->where('tenant_id', (string) $alert->tenant_id)
                ->pluck('guard_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return [];
        }
    }

    /**
     * Put these guards on the alert's list, once each. A guard already on it keeps the
     * round and audience they were first rung in.
     *
     * @param  list<int>  $guardIds
     */
    public static function add(DuressAlert $alert, array $guardIds, string $audience, int $round): void
    {
        if ($guardIds === []) {
            return;
        }

        $now = Carbon::now();

        DuressAlertRingTarget::query()->insertOrIgnore(array_map(static fn (int $id): array => [
            'duress_alert_id' => (int) $alert->getKey(),
            'tenant_id' => (string) $alert->tenant_id,
            'guard_id' => $id,
            'audience' => $audience,
            'first_round' => $round,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_unique($guardIds))));
    }
}
