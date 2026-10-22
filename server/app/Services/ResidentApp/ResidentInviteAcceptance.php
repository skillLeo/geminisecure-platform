<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\ResidentAccount;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\ResidentInvites;
use App\Services\Estate\Residents;
use App\Services\Reviewer\ReviewerMode;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AN INVITE IS RECOGNISED WHEN ITS INVITEE SIGNS IN (household invites, stage 2, W-ACCEPT; DESIGN §5).
 *
 * The invitee proves the invited address with the one-time code. That proof, matched to EXACTLY ONE live
 * invite in this estate, is their claim: the household, the unit and the name are the register's, and there
 * is no form to fill in. The estate office confirms it with one tap (Claims, "Invited"). When the estate
 * trusts its primary residents' invites, a primary's invite is approved here, at once.
 *
 * WHY THIS IS NOT THE STALE-CONTACT LINK `ResidentAccounts` REFUSES (D-ACCEPT-1). An email on the register
 * links nothing: registers go stale, and a former tenant's address would open a gate. An INVITE is a
 * deliberate, dated act by somebody entitled to make it, to one address, for one register entry. It lapses
 * in a fortnight and is refused at its address when that address belongs elsewhere. Every one of these is
 * re-checked here against the register as it stands NOW:
 * - the invite is live;
 * - it is the only live one for the address;
 * - its person is still here and still pending;
 * - its person is still in the household at the invite's unit;
 * - its person still carries that address.
 * Otherwise the ordinary flow runs, exactly as before.
 *
 * Run INSIDE `$estate->run(...)`: an invite in estate A is never read by a sign-in to estate B.
 */
final class ResidentInviteAcceptance
{
    public function __construct(
        private readonly ResidentInvites $invites,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Match this sign-in to its invite and make the claim, or do nothing.
     *
     * @param  bool  $reopened  the account was reopened by `reopenSuspended()` in this same sign-in: never trusted
     */
    public function accept(ResidentAccount $account, bool $reopened = false): ?UnitClaim
    {
        // 1. Nothing to do, or not ours to do: a linked, revoked, texted or reviewer account; a pending claim of its
        //    own (a manual claim is the office's to decide, and an earlier invite claim is not made twice).
        if ($account->isActive() || $account->isRevoked() || $account->channel !== 'email' || ReviewerMode::isReviewer($account)) {
            return null;
        }

        if ($account->claim_id !== null && (UnitClaim::query()->find($account->claim_id)?->isPending() ?? false)) {
            return null;
        }

        $tenantId = (string) tenant()?->getTenantKey();
        $hint = ResidentSignIn::mask((string) $account->destination);

        // 2. Exactly one live invite for the proven address. Two is never two links (INV-2): neither is matched.
        $live = $this->invites->liveFor((string) $account->destination);

        if ($live->isEmpty()) {
            return null;
        }

        if ($live->count() > 1) {
            $this->audit->record(
                action: 'resident.invite_ambiguous',
                entityType: 'ResidentAccount',
                entityId: (string) $account->id,
                after: ['invite_ids' => $live->pluck('id')->all(), 'hint' => $hint],
                tenantId: $tenantId,
            );

            return null;
        }

        /** @var ResidentInvite $invite */
        $invite = $live->first();

        // 3. Fresh against the register NOW (the current scope leaves a removed person out).
        $resident = Resident::query()->whereKey($invite->resident_id)->first();
        $household = $resident === null ? null : Household::query()->find($resident->household_id);
        $unit = Unit::query()->find($invite->unit_id);

        if ($resident === null
            || $resident->status !== Resident::PENDING
            || $household === null
            || $unit === null
            || (int) $household->unit_id !== (int) $invite->unit_id
            || strtolower(trim((string) $resident->email)) !== strtolower(trim((string) $invite->email))) {
            return null;
        }

        // 4. The claim, and the invite claimed by it, together, with the invite row locked against a second sign-in.
        $claim = DB::connection('tenant')->transaction(function () use ($invite, $resident, $household, $unit, $hint): ?UnitClaim {
            $locked = ResidentInvite::query()->whereKey($invite->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isLive()) {
                return null;
            }

            $claim = UnitClaim::query()->create([
                'unit_id' => $unit->id,
                'household_id' => $household->id,
                'claim_type' => $resident->is_primary ? UnitClaim::TYPE_UNIT : UnitClaim::TYPE_MEMBER,
                'submitted_name' => $resident->full_name,
                'submitted_phase' => $unit->block,
                'submitted_lot' => $unit->reference,
                'submitted_phone' => null,
                'submitted_relationship' => $resident->relationship,
                'match_result' => 'exact',
                'review_flag' => sprintf(
                    'Invited by %s on %s; signed in with the invited address %s, proven by one-time code.',
                    $locked->invited_by_resident_id !== null
                        ? (string) $locked->invited_by_name
                        : 'the estate office'.(trim((string) $locked->invited_by_name) === '' ? '' : ' ('.trim((string) $locked->invited_by_name).')'),
                    SiteTime::local($locked->last_attempt_at ?? $locked->created_at ?? Carbon::now())->format('j M'),
                    $hint,
                ),
                'status' => UnitClaim::PENDING,
            ]);

            $claim->forceFill(['invite_id' => $locked->id])->save();
            $locked->forceFill(['claimed_at' => Carbon::now(), 'claim_id' => $claim->id])->save();

            return $claim;
        });

        if ($claim === null) {
            return null;
        }

        $account->forceFill(['claim_id' => $claim->id, 'full_name' => $resident->full_name])->save();

        $trusted = ! $reopened && $this->mayTrust($account, $invite, $resident, $household, $tenantId);

        $this->audit->record(
            action: 'resident.invite_accepted',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            after: ['invite_id' => $invite->id, 'claim_id' => $claim->id, 'hint' => $hint, 'trusted' => $trusted],
            tenantId: $tenantId,
        );

        // 5. Trust: approved here, as the office would, and named for what it was. Sign-in never fails on it.
        if ($trusted) {
            try {
                app(Residents::class)->approveClaim($claim, null);
                $claim->forceFill(['reviewed_by_name' => 'Trusted invite from '.((string) $invite->invited_by_name).' (estate setting)'])->save();
            } catch (DomainException) {
                // Left pending for the office: the claim is made either way.
            }
        }

        return $claim->fresh() ?? $claim;
    }

    /**
     * A SUSPENDED account — a member the household removed — invited again (DESIGN §5.3): back to pending,
     * unlinked, so this sign-in's `accept()` can match the new invite. Only with exactly one live invite for its
     * exact address, and NEVER a revoked one by stage 2's rule: only the office's restore undoes that.
     */
    public function reopenSuspended(ResidentAccount $account): bool
    {
        if ($account->status !== ResidentAccount::SUSPENDED
            || $account->deleted_at !== null
            || $account->revoked_at !== null
            || $account->channel !== 'email'
            || ReviewerMode::isReviewer($account)) {
            return false;
        }

        if ($this->invites->liveFor((string) $account->destination)->count() !== 1) {
            return false;
        }

        $before = ['status' => $account->status, 'resident_id' => $account->resident_id, 'unit_id' => $account->unit_id, 'claim_id' => $account->claim_id];

        $account->forceFill(['status' => ResidentAccount::PENDING, 'resident_id' => null, 'unit_id' => null, 'claim_id' => null])->save();

        $this->audit->record(
            action: 'resident_account.reopened_by_invite',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: $before,
            after: ['status' => ResidentAccount::PENDING, 'hint' => ResidentSignIn::mask((string) $account->destination)],
            tenantId: (string) tenant()?->getTenantKey(),
        );

        return true;
    }

    /**
     * TRUST (DESIGN §5.6, D-ACCEPT-2): only all of these at once.
     *
     *   - the estate chose "Trust the main resident's invites" (off by default)
     *   - a primary resident sent the invite — never the office's own invite, which the office confirms itself
     *   - that resident is STILL a current primary of this household: their own row, not `primaryResident()`,
     *     which picks the lowest id and would disqualify the second of two primaries (G11)
     *   - no revoked account shares the address (G1)
     *   - and no revoked account at this unit AT ALL, whatever its name (G3). A near-miss spelling of somebody
     *     the office cut off is never linked without review; revocations are rare, so the cost is one tap.
     */
    private function mayTrust(ResidentAccount $account, ResidentInvite $invite, Resident $resident, Household $household, string $tenantId): bool
    {
        if (! (bool) EstateSetting::current()->trust_primary_invites || $invite->invited_by_resident_id === null) {
            return false;
        }

        $inviterIsPrimary = Resident::query()
            ->whereKey($invite->invited_by_resident_id)
            ->where('household_id', $household->id)
            ->where('is_primary', true)
            ->exists();

        if (! $inviterIsPrimary) {
            return false;
        }

        $addressRevoked = ResidentInvites::revokedAccounts($tenantId)
            ->where('channel', 'email')
            ->where('destination', ResidentSignIn::normalise('email', (string) $account->destination))
            ->exists();

        if ($addressRevoked) {
            return false;
        }

        return RevokeResidentAccess::revokedPeopleByUnit($tenantId, [(int) $invite->unit_id]) === [];
    }
}
