<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Api\AccessLevels;
use App\Api\AppMatrix;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use RuntimeException;
use Throwable;

/**
 * WHO IN A HOUSEHOLD IS PUSHED WHAT — by access level (`AppMatrix::LEVELS`).
 *
 * A push opens a screen, and a Helper told "A message about your account" about the household's dues,
 * or a Dependent asked to let a stranger in at the gate, would be told what their level does not let
 * them read or do. So each resident push type is mapped to the ONE endpoint its screen opens, and an
 * account is pushed only when its level reaches that endpoint — the same rule, from the same table, as
 * the request the phone makes when the push is tapped.
 *
 * NEVER FILTERED: `panic_update` (the person's own alert — panic is never removed from anybody) and
 * `claim_approved` (the person's own account). Both go to one account and never through here.
 *
 * FAIL CLOSED FOR AN UNMAPPED TYPE. A resident type another branch adds to `PushTypes::TYPES` without a
 * line here goes to MEMBER accounts only, and is reported once per process: an unmapped screen is an
 * unlisted endpoint, and `levelAllows` would refuse it to everybody else too.
 *
 * FAIL OPEN FOR A FAILED LOOKUP. When the register cannot be read, the push goes as it did before
 * levels existed, and the failure is reported. These pushes carry no figure by design (`PushAnnouncer`),
 * and dropping a Member's reminder is the worse of the two mistakes.
 */
final class PushAudience
{
    /** Push type => the endpoint the screen it opens reads. */
    private const MAP = [
        'approval_request' => 'approvals.respond',
        'visitor_admitted' => 'visitor_passes.index',
        'notice' => 'notices.index',
        'ticket_update' => 'tickets.index',
        'payment_recorded' => 'payments.index',
        'dues_reminder' => 'invoices.index',
        'meeting_reminder' => 'meetings.index',
        'election_open' => 'elections.index',
        'election_results' => 'elections.index',
        'booking_decided' => 'bookings.index',
        // Gemini Security answered on Live chat (M50 §4.3): the person's own conversation, which every level reaches.
        'support_reply' => 'support.chat.show',
        // A Helper or a Dependent came in or went out: it opens the household on Profile.
        'household_entry' => 'me.household',
    ];

    /** @var list<string> */
    public const NEVER_FILTERED = ['panic_update', 'claim_approved'];

    /** @var array<string, true> */
    private static array $unmappedReported = [];

    /** The endpoint a resident push type's screen reads, or null when it has no mapping. */
    public static function endpointFor(string $type): ?string
    {
        return self::MAP[$type] ?? null;
    }

    /**
     * The accounts that may be sent this type, in the order given.
     *
     * @param  list<int>  $accountIds
     * @return list<int>
     */
    public function allowed(string $tenantId, string $type, array $accountIds): array
    {
        $accountIds = array_values(array_unique(array_map(intval(...), $accountIds)));

        if ($accountIds === [] || in_array($type, self::NEVER_FILTERED, true)) {
            return $accountIds;
        }

        // A Guard App type is not a household's to filter (and no resident account receives one).
        if (PushTypes::known($type) && PushTypes::app($type) !== AppMatrix::RESIDENT) {
            return $accountIds;
        }

        $endpoint = self::endpointFor($type);

        if ($endpoint === null) {
            self::reportUnmapped($type);
        }

        try {
            $levels = $this->levelsOf($tenantId, $accountIds);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return $accountIds;
        }

        return array_values(array_filter($accountIds, static function (int $id) use ($levels, $endpoint): bool {
            $level = $levels[$id] ?? AppMatrix::HELPER;

            return $endpoint === null ? $level === AppMatrix::MEMBER : AppMatrix::levelAllows($level, $endpoint);
        }));
    }

    /**
     * Whether anybody in this unit's household can answer a guard's walk-up request in the app: an
     * ACTIVE account whose level reaches `approvals.respond`. Read synchronously, before the guard is
     * answered, so the Guard App can say "call them" at once instead of waiting out the clock.
     */
    public function canAnswer(string $tenantId, int $unitId): bool
    {
        $accounts = ResidentAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ResidentAccount::ACTIVE)
            ->where('unit_id', $unitId)
            ->whereNotNull('resident_id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $this->allowed($tenantId, 'approval_request', $accounts) !== [];
    }

    /**
     * Account id => its level, read from the estate's register.
     *
     * @param  list<int>  $accountIds
     * @return array<int, string>
     */
    private function levelsOf(string $tenantId, array $accountIds): array
    {
        // The account must be THIS estate's: an id is only unique centrally.
        $residentOf = ResidentAccount::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->pluck('resident_id', 'id')
            ->all();

        $residentIds = array_values(array_unique(array_map(intval(...), array_filter($residentOf, static fn ($id): bool => $id !== null))));

        $residents = $residentIds === [] ? [] : $this->inEstate($tenantId, static fn (): array => Resident::query()
            ->whereIn('id', $residentIds)
            ->get(['id', 'is_primary', 'access_level'])
            ->keyBy('id')
            ->all());

        $levels = [];

        foreach ($residentOf as $accountId => $residentId) {
            $levels[(int) $accountId] = AccessLevels::of($residentId === null ? null : ($residents[(int) $residentId] ?? null));
        }

        return $levels;
    }

    /**
     * Read inside the estate, and leave tenancy EXACTLY as it was found — `HouseholdContactsSms`'s rule:
     * this runs inside estate requests (already open: no-op) and on queue workers (opened, then ended).
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function inEstate(string $tenantId, callable $read): mixed
    {
        $original = tenant();

        if ($original !== null && (string) $original->getTenantKey() === $tenantId) {
            return $read();
        }

        $estate = Tenant::query()->find($tenantId);

        if (! $estate instanceof Tenant) {
            throw new RuntimeException("No estate [{$tenantId}] to read the household access levels from.");
        }

        try {
            tenancy()->initialize($estate);

            return $read();
        } finally {
            if ($original !== null) {
                tenancy()->initialize($original);
            } else {
                tenancy()->end();
            }
        }
    }

    private static function reportUnmapped(string $type): void
    {
        if (isset(self::$unmappedReported[$type])) {
            return;
        }

        self::$unmappedReported[$type] = true;

        rescue(static fn () => report(new RuntimeException("Push type [{$type}] has no access-level mapping in PushAudience; it is sent to Members only.")), report: false);
    }
}
