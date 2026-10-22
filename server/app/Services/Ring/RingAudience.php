<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Models\DuressAlert;
use App\Models\DuressAlertRingTarget;
use App\Models\Guard;
use App\Services\Push\PanicFanOut;
use App\Services\Push\PushNotifier;
use Illuminate\Support\Carbon;

/**
 * WHO EACH ROUND RINGS (the guard ring §3.1, §3.2).
 *
 *   R0  the raise   the officers on duty and rostered today — exactly who today's push
 *                   went to (`PanicFanOut`), as written onto the alert's list at the raise
 *   R1  escalation  EVERY ACTIVE OFFICER OF THE ESTATE, off duty too, and everybody above
 *   R2+ re-ring     everybody rung so far, whoever is on duty or rostered now, and every
 *                   active officer of the estate still — the same people as R1 unless one
 *                   became active since, and all of them when R1 itself was held up and
 *                   went out inside this round
 *
 * AND NEVER, WHATEVER THE ROUND:
 *
 *   - THE RAISER. A guard's own duress never rings that guard — least of all a silent one.
 *   - A GUARD UNDER THEIR OWN OPEN DURESS, silent or audible (R14, the server's half). A
 *     phone ringing loudly in the pocket of an officer who pressed a silent duress would
 *     betray them. The phone suppresses it too (§7.8); this is the half that does not
 *     depend on the phone.
 *   - A GUARD WHO SAID "CAN'T RESPOND" FOR THE WHOLE ALERT. A lock-screen decline is for
 *     the round in front of them only (ruling R10b) and does not remove them.
 *   - ANOTHER ESTATE'S OFFICERS. Every query below names the alert's estate, the push
 *     addresses are read by estate, and entitlement requires the guard to be posted there.
 */
final class RingAudience
{
    public function __construct(
        private readonly PushNotifier $push,
        private readonly PanicFanOut $fanOut,
    ) {}

    /**
     * The guards to ring this round, and which of them are new to the alert's list.
     *
     * @return array{guards: list<int>, new: array<int, string>}
     */
    public function for(DuressAlert $alert, int $round): array
    {
        $tenantId = (string) $alert->tenant_id;
        $raiser = $alert->guard_id === null ? null : (int) $alert->guard_id;

        $targets = DuressAlertRingTarget::query()
            ->where('duress_alert_id', (int) $alert->getKey())
            ->where('tenant_id', $tenantId)
            ->get(['guard_id', 'first_round', 'answer']);

        $declined = $targets->where('answer', DuressAlertRingTarget::CANNOT)->pluck('guard_id')->map(static fn ($id): int => (int) $id)->all();
        $rung = $targets->pluck('guard_id')->map(static fn ($id): int => (int) $id)->all();

        $onShift = [];
        $estate = [];

        if ($round === 0) {
            $wanted = $targets->where('first_round', 0)->pluck('guard_id')->map(static fn ($id): int => (int) $id)->all();
        } else {
            $onShift = array_values(array_unique([
                ...$this->push->onDutyGuardIds($tenantId, $raiser),
                ...$this->fanOut->rosteredToday($tenantId, $raiser),
            ]));

            /*
             * FROM THE ESCALATION ON, EVERY ACTIVE OFFICER OF THE ESTATE — on round 1, and on
             * every re-ring after it. After round 1 they are "everybody rung so far" anyway;
             * asked again, an officer who became active since is rung too, and a re-ring that
             * went out IN PLACE OF a held-up escalation (overdue rounds collapse) still reaches
             * everybody the escalation would have.
             */
            $estate = self::estateGuards($tenantId);

            $wanted = [...$rung, ...$onShift, ...$estate];
        }

        $wanted = array_values(array_unique(array_map('intval', $wanted)));
        $excluded = [...$declined, ...$this->underTheirOwnDuress($tenantId, $wanted)];

        if ($raiser !== null) {
            $excluded[] = $raiser;
        }

        $guards = array_values(array_diff($wanted, $excluded));

        $new = [];

        foreach ($guards as $id) {
            if (! in_array($id, $rung, true)) {
                $new[$id] = in_array($id, $onShift, true) ? DuressAlertRingTarget::ON_SHIFT : DuressAlertRingTarget::ESTATE;
            }
        }

        return ['guards' => $guards, 'new' => $new];
    }

    /**
     * Every active guard posted to this estate — the escalation's audience.
     *
     * @return list<int>
     */
    public static function estateGuards(string $tenantId): array
    {
        return Guard::query()->where('tenant_id', $tenantId)->where('status', 'active')
            ->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * Which of these guards have an open duress of their own at this estate.
     *
     * @param  list<int>  $guardIds
     * @return list<int>
     */
    private function underTheirOwnDuress(string $tenantId, array $guardIds): array
    {
        if ($guardIds === []) {
            return [];
        }

        return DuressAlert::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'duress')
            ->where('is_simulated', false)
            ->whereIn('guard_id', $guardIds)
            ->whereNull('cancelled_at')
            ->whereNotIn('status', ['resolved', 'false_alarm'])
            ->where('server_time', '>=', Carbon::now()->subMinutes((int) config('ring.own_duress_minutes', 60)))
            ->pluck('guard_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
