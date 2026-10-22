<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Models\DevicePushToken;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Services\Audit\AuditLogger;
use App\Services\Passes\VisitorPasses;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE ESTATE OFFICE ENDING — AND GIVING BACK — A RESIDENT APP ACCOUNT (Q-M44).
 *
 * Board 38's "End app access". This is the OFFICE acting, not the resident and not the
 * household, which is why it lives on the console behind `estate.residents.update` and
 * not on any Resident App endpoint. It is an explicit act with a reason recorded; it is
 * never a side effect of correcting a register row.
 *
 * WHAT GOES, AT ONCE
 *   · every API token on every phone — the sign-ins are destroyed, not expired
 *   · every push address. DELETED, not revoked: an Expo token is the ADDRESS of a
 *     handset, and keeping one against an account the estate has cut off is keeping the
 *     one thing that can still reach the person. (Ordinary sign-out keeps the row.)
 *   · every sign-in code in flight for that address, so a code already sent is dead
 *   · their own e-pass — the signed credential a guard admits a RESIDENT on. Cancelled,
 *     which also puts it on `/sync/pull`'s revoked list, so a handset that has been out
 *     of signal since before the revocation still refuses it at the barrier.
 *
 * WHAT STAYS, BECAUSE IT IS THE COMMUNITY'S AND NOT THE APP'S
 *   · the ESTATE REGISTER entry. "Moved out" and "locked out" are different facts and
 *     one of them is often wrong for a week (Q-M44's own ruling): the office edits the
 *     register separately, where that decision has a name and a date on it.
 *   · the gate history, the household's charges and statement, the receipts, the ballot,
 *     and the audit trail. A revocation is not a deletion — `DeleteResidentAccount` is
 *     the model here, and even IT de-identifies rather than dropping rows.
 *   · visitor passes the person issued for somebody else while they were a resident.
 *     Those belong to the HOUSEHOLD and to the visitor holding them; the office or the
 *     household cancels one deliberately, and a guest turned away at a barrier because
 *     of a change in a household they know nothing about is not the gate this builds.
 *   · `resident_id` and `unit_id` on the account. They are how the gate knows WHICH unit
 *     this person is no longer a resident of, and they are what an undo restores from.
 *
 * AND IT IS REVERSIBLE. People come back, the wrong row gets picked, a sale falls
 * through — and an office that cannot undo a mistake will avoid using the feature at all,
 * which leaves the gap exactly where it was.
 */
class RevokeResidentAccess
{
    /** What a guard is told, at the gate, about somebody the office has revoked. */
    public const GATE_HEADLINE = 'No longer a resident';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * End an account's access to this estate.
     *
     * @return array{tokens: int, push_addresses: int, codes: int, passes: int}
     */
    public function revoke(ResidentAccount $account, string $reason, string $by): array
    {
        if ($account->deleted_at !== null) {
            throw new DomainException('This account was deleted by the person themselves, so there is no access left to end.');
        }

        if ($account->isRevoked()) {
            throw new DomainException('This account\'s app access has already been ended.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why the access is being ended. It goes on the record beside your name.');
        }

        $estate = (string) $account->tenant_id;
        $now = Carbon::now();
        $before = ['status' => $account->status, 'resident_id' => $account->resident_id, 'unit_id' => $account->unit_id];

        // The e-pass first, in the ESTATE's database, while its context is open.
        $passes = $this->cancelResidentPasses($account, $now);

        // And any invite still open for them (stage 2): a revoked person is not invited back by an old link.
        $resident = $account->resident_id === null ? null : Resident::query()->find($account->resident_id);

        if ($resident !== null) {
            app(\App\Services\Estate\ResidentInvites::class)->cancel($resident, $by, null, 'access_revoked');
        }

        $counts = DB::connection('mysql')->transaction(function () use ($account, $estate, $now, $reason, $by): array {
            $tokens = $account->tokens()->count();
            $account->tokens()->delete();

            $addresses = 0;

            if (DB::connection('mysql')->getSchemaBuilder()->hasTable('device_push_tokens')) {
                $addresses = DB::connection('mysql')->table('device_push_tokens')
                    ->where('tenant_id', $estate)
                    ->where('principal_type', DevicePushToken::RESIDENT)
                    ->where('principal_id', $account->id)
                    ->delete();
            }

            $codes = 0;

            if (DB::connection('mysql')->getSchemaBuilder()->hasTable('resident_otps')) {
                $codes = DB::connection('mysql')->table('resident_otps')
                    ->where('tenant_id', $estate)
                    ->where('channel', (string) $account->channel)
                    ->where('destination_hash', hash_hmac('sha256', (string) $account->destination, (string) config('app.key')))
                    ->delete();
            }

            $account->forceFill([
                'status' => ResidentAccount::REVOKED,
                'revoked_at' => $now,
                'revoked_reason' => mb_substr($reason, 0, 190),
                'revoked_by' => mb_substr($by, 0, 160),
            ])->save();

            return ['tokens' => $tokens, 'push_addresses' => (int) $addresses, 'codes' => (int) $codes];
        });

        $this->audit->record(
            action: 'resident_account.access_revoked',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: $before,
            after: [
                'status' => ResidentAccount::REVOKED,
                'reason' => $reason,
                'by' => $by,
                'tokens_destroyed' => $counts['tokens'],
                'push_addresses_deleted' => $counts['push_addresses'],
                'sign_in_codes_destroyed' => $counts['codes'],
                'resident_passes_cancelled' => $passes,
                'revoked_at' => $now->toIso8601String(),
            ],
            tenantId: $estate,
        );

        return [...$counts, 'passes' => $passes];
    }

    /**
     * Give it back. The person signs in again on their own phone and is where they were.
     *
     * The cancelled e-pass is NOT un-cancelled: a cancelled pass stays cancelled, because
     * it is already on the revoked list every handset in the estate has pulled. The app
     * asks for a fresh one the first time the e-pass screen is opened, which is one
     * request and leaves no handset holding a credential it was told to refuse.
     */
    public function restore(ResidentAccount $account, string $by): string
    {
        if (! $account->isRevoked()) {
            throw new DomainException('This account\'s app access has not been ended, so there is nothing to give back.');
        }

        $estate = (string) $account->tenant_id;
        $reason = (string) $account->revoked_reason;

        // Where they were: linked to a unit and a register entry, or still waiting on a claim.
        $status = $account->resident_id !== null && $account->unit_id !== null
            ? ResidentAccount::ACTIVE
            : ResidentAccount::PENDING;

        $account->forceFill([
            'status' => $status,
            'revoked_at' => null,
            'revoked_reason' => null,
            'revoked_by' => null,
        ])->save();

        $this->audit->record(
            action: 'resident_account.access_restored',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: ['status' => ResidentAccount::REVOKED, 'reason' => $reason],
            after: ['status' => $status, 'by' => $by],
            tenantId: $estate,
        );

        return $status;
    }

    /* ------------------------------------------------------------------ */
    /* what the gate asks */
    /* ------------------------------------------------------------------ */

    /**
     * Is this register entry somebody whose app access the estate office ended?
     *
     * Asked at the barrier, on the pass scan. `ResidentAccount` is a CENTRAL model, so
     * this is correct with an estate's own database open — which is where the gate calls
     * it from. A register entry with no app account at all answers false, which is right:
     * only an account that was revoked is revoked.
     */
    public static function isRevokedResident(string $tenantId, ?int $residentId): bool
    {
        if ($residentId === null) {
            return false;
        }

        return ResidentAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('resident_id', $residentId)
            ->where('status', ResidentAccount::REVOKED)
            ->exists();
    }

    /**
     * The people at these units whose app access the office ended, unit by unit.
     *
     * Used by the guard's unit search so the officer reading the card is told plainly,
     * before anybody reaches the barrier, that the name they are looking at is not a
     * resident of that lot any more.
     *
     * @param  list<int>  $unitIds
     * @return array<int, list<string>>  unit id => the people's names
     */
    public static function revokedNamesByUnit(string $tenantId, array $unitIds): array
    {
        // No unit is id 0: that is a household with no unit, cast to an int by the caller.
        $unitIds = array_values(array_unique(array_filter($unitIds, static fn (int $id): bool => $id > 0)));

        if ($unitIds === []) {
            return [];
        }

        $accounts = ResidentAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ResidentAccount::REVOKED)
            ->whereIn('unit_id', $unitIds)
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        // The register's own spelling of the name, where there still is a register entry.
        $register = Resident::query()
            ->whereIn('id', $accounts->pluck('resident_id')->filter()->all())
            ->pluck('full_name', 'id');

        $byUnit = [];

        foreach ($accounts as $account) {
            $name = trim((string) ($register[$account->resident_id] ?? $account->full_name ?? ''));

            if ($name === '') {
                continue;
            }

            $unit = (int) $account->unit_id;
            $byUnit[$unit] ??= [];

            if (! in_array($name, $byUnit[$unit], true)) {
                $byUnit[$unit][] = $name;
            }
        }

        return $byUnit;
    }

    /**
     * The people at these units whose app access the office ended, and WHEN (stage 2, W-ACCEPT).
     *
     * The same rows as `revokedNamesByUnit()`, read with stage 2's rule: "revoked" is `status = revoked`
     * OR `revoked_at` set. An account a household removal overwrote to `suspended` before that removal
     * was fixed still counts, whether or not the central repair has run. `revokedNamesByUnit()`, which
     * the gate calls, is left exactly as it is.
     *
     * @param  list<int>  $unitIds
     * @return array<int, list<array{name: string, revoked_at: Carbon|null}>>  unit id => the people
     */
    public static function revokedPeopleByUnit(string $tenantId, array $unitIds): array
    {
        $unitIds = array_values(array_unique(array_filter($unitIds, static fn (int $id): bool => $id > 0)));

        if ($unitIds === []) {
            return [];
        }

        $accounts = ResidentAccount::query()
            ->where('tenant_id', $tenantId)
            ->where(static fn ($revoked) => $revoked->where('status', ResidentAccount::REVOKED)->orWhereNotNull('revoked_at'))
            ->whereNull('deleted_at')
            ->whereIn('unit_id', $unitIds)
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $register = Resident::query()
            ->whereIn('id', $accounts->pluck('resident_id')->filter()->all())
            ->pluck('full_name', 'id');

        $byUnit = [];

        foreach ($accounts as $account) {
            $name = trim((string) ($register[$account->resident_id] ?? $account->full_name ?? ''));

            if ($name === '') {
                continue;
            }

            $byUnit[(int) $account->unit_id][] = ['name' => $name, 'revoked_at' => $account->revoked_at];
        }

        return $byUnit;
    }

    /**
     * THE SENTENCE THE GUARD READS ON A UNIT CARD, and the client's own ruling in it:
     * a revoked person is no longer a resident, and at the gate they are a VISITOR.
     *
     * @param  list<string>  $names
     */
    public static function gateNotice(array $names, string $unitReference): ?string
    {
        if ($names === []) {
            return null;
        }

        $who = count($names) === 1
            ? $names[0]
            : implode(', ', array_slice($names, 0, -1)).' and '.$names[count($names) - 1];

        return count($names) === 1
            ? $who.' is no longer a resident of '.$unitReference.'. Do not admit as a resident — direct them to the Visitor Entrance.'
            : $who.' are no longer residents of '.$unitReference.'. Do not admit them as residents — direct them to the Visitor Entrance.';
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Cancel the person's own e-passes — the credential a guard admits a RESIDENT on.
     *
     * Cancelled rather than deleted: `VisitorPass` rows are what the gate log points at,
     * and `cancelled_at` is what `/sync/pull` reads to tell a handset that has been out
     * of signal to stop honouring it.
     */
    private function cancelResidentPasses(ResidentAccount $account, Carbon $at): int
    {
        // One query for both acts that end a person's gate credential: this one and a household's removal.
        return app(ResidentPassCancellation::class)->cancelFor($account->resident_id === null ? null : (int) $account->resident_id, $at);
    }
}
