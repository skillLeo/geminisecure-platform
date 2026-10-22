<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\ResidentAccount;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;

/**
 * A Resident App account's place in its estate: its claim, and once approved, its
 * unit, household and register entry (13 D3).
 *
 * THE ESTATE DECIDES, THE PLATFORM FOLLOWS. A sign-in proves an address; a unit
 * claim asks the estate; the estate's approval (`Residents::approveClaim`, board
 * 31) is what links the account. `reconcile()` reads that decision from the
 * estate's own record, so the account cannot be active on a claim the estate
 * has not approved, and cannot stay pending on one it has.
 *
 * NEVER LINKED BY MATCHING A CONTACT. An email on the register that matches the
 * one a code went to is shown to the reviewer as an exact match — it does not
 * approve anything. Registers go stale, and a former tenant's email still on file
 * would otherwise open a household's gate to them.
 */
class ResidentAccounts
{
    public function reconcile(ResidentAccount $account): void
    {
        /*
         * A REVOKED ACCOUNT IS NEVER RECONCILED BACK INTO ITS ESTATE (Q-M44). The claim
         * that linked it is still approved and always will be — approval is a historical
         * fact — so without this line the estate office's revocation would be undone by
         * the account's very next request. Only `RevokeResidentAccess::restore()` gives
         * access back, and only the office can call it.
         */
        if ($account->isRevoked() || $account->isActive() || $account->claim_id === null) {
            return;
        }

        $claim = UnitClaim::query()->find($account->claim_id);

        if ($claim === null || $claim->status !== UnitClaim::APPROVED || $claim->resolved_resident_id === null || $claim->unit_id === null) {
            return;
        }

        $account->forceFill([
            'status' => ResidentAccount::ACTIVE,
            'resident_id' => $claim->resolved_resident_id,
            'unit_id' => $claim->unit_id,
        ])->save();
    }

    /**
     * Lodge a unit claim for a signed-in account — the estate reviews it on board 31.
     *
     * @param  array{full_name: string, lot: string, phase?: string|null, phone?: string|null, relationship?: string|null}  $fields
     * @return array{claim: UnitClaim, created: bool}
     */
    public function claim(ResidentAccount $account, array $fields, bool $simulated = false): array
    {
        if ($account->isActive()) {
            throw ApiError::conflict('already_linked', 'This account is already linked to a unit. Contact the estate office to move it.');
        }

        $existing = $account->claim_id === null ? null : UnitClaim::query()->find($account->claim_id);

        if ($existing !== null && $existing->isPending()) {
            return ['claim' => $existing, 'created' => false];
        }

        $lot = trim($fields['lot']);
        $phase = isset($fields['phase']) ? trim((string) $fields['phase']) : null;
        $name = trim($fields['full_name']);

        $unit = Unit::query()->with('household.residents')
            ->where(fn ($q) => $q->where('reference', $lot)->orWhere('reference', 'Lot '.$lot))
            ->first();

        $household = $unit?->household;
        $phaseAgrees = $phase === null || $phase === '' || $unit === null || strcasecmp((string) $unit->block, $phase) === 0;
        $knownByName = $household !== null && $household->residents->contains(fn (Resident $r): bool => strcasecmp($r->full_name, $name) === 0);

        $claim = UnitClaim::query()->create([
            'unit_id' => $unit?->id,
            'household_id' => $household !== null && ! $knownByName && $household->residents->isNotEmpty() ? $household->id : null,
            'claim_type' => match (true) {
                $unit === null => UnitClaim::TYPE_UNVERIFIED,
                $household !== null && $household->residents->isNotEmpty() && ! $knownByName => UnitClaim::TYPE_MEMBER,
                default => UnitClaim::TYPE_UNIT,
            },
            'submitted_name' => $name,
            'submitted_phase' => $phase === '' ? null : $phase,
            'submitted_lot' => $lot,
            'submitted_phone' => $fields['phone'] ?? ($account->channel === 'sms' ? $account->destination : null),
            'submitted_relationship' => $fields['relationship'] ?? null,
            'match_result' => match (true) {
                $unit === null => 'none',
                $phaseAgrees && ($knownByName || $household === null || $household->residents->isEmpty()) => 'exact',
                default => 'partial',
            },
            'review_flag' => 'Claimed from the Resident App by '.ResidentSignIn::mask($account->destination).', verified by one-time code.',
            'status' => UnitClaim::PENDING,
        ]);

        $claim->forceFill(['is_simulated' => $simulated])->save();

        $account->forceFill(['claim_id' => $claim->id, 'full_name' => $name])->save();

        return ['claim' => $claim, 'created' => true];
    }

    /**
     * The active account's unit, household and register entry, or a refusal.
     *
     * @return array{unit: Unit, household: Household, resident: Resident|null, account: ResidentAccount}
     */
    public function home(DeviceContext $context): array
    {
        $account = $context->residentOrFail();

        /*
         * A REVOKED ACCOUNT HAS NO HOUSEHOLD (Q-M44). Its token is already refused by
         * `ResolveDevice`, so nothing should reach here — this is the second lock on the
         * same door, because `home()` is what every balance, statement, roster, pass and
         * ballot read in the Resident App goes through, and one missed middleware string
         * would otherwise open all of them at once.
         */
        if ($account->isRevoked()) {
            throw ApiError::forbidden('account_revoked', 'The estate office has ended this account\'s access, so it is no longer linked to a household.');
        }

        $unit = $account->unit_id === null ? null : Unit::query()->with('household')->find($account->unit_id);

        if ($unit === null || $unit->household === null) {
            throw ApiError::forbidden('account_pending', 'This account is not linked to a household yet.');
        }

        return [
            'unit' => $unit,
            'household' => $unit->household,
            'resident' => $account->resident_id === null ? null : Resident::query()->find($account->resident_id),
            'account' => $account,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function claimShape(?UnitClaim $claim): ?array
    {
        if ($claim === null) {
            return null;
        }

        $shape = [
            'id' => $claim->id,
            'status' => $claim->status,
            'submitted_unit' => $claim->submittedUnitLabel(),
            'submitted_name' => $claim->submitted_name,
            'decision_reason' => $claim->decision_reason,
            'document_requested' => $claim->document_requested_kind,
            'submitted_at' => ($claim->created_at ?? Carbon::now())->toIso8601String(),
        ];

        /*
         * A CLAIM MADE FROM AN INVITE SAYS SO (stage 2): who invited them — the primary resident by name, or
         * "the estate office" — and when. Only then: an ordinary claim carries neither key.
         */
        if ($claim->invite_id !== null) {
            $invite = ResidentInvite::query()->find($claim->invite_id);

            $shape['invited_by'] = $invite === null
                ? null
                : ($invite->invited_by_resident_id !== null ? (string) $invite->invited_by_name : 'the estate office');
            $shape['invited_at'] = SiteTime::iso($invite->last_attempt_at ?? $invite?->created_at);
        }

        return $shape;
    }
}
