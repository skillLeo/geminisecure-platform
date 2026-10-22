<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Services\Audit\AuditLogger;
use App\Services\Passes\VisitorPasses;
use App\Services\ResidentApp\ResidentPassCancellation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONE PERSON TAKEN OFF THEIR HOUSEHOLD: the primary resident's act in the Resident App (`MeController::removeMember`),
 * and since M35 §6 the estate office's too (`residents:remove-members`). One body of rules for both, so the two can
 * never disagree about what a removal does.
 *
 * Removed, not deleted: the row stays for the history that points at it, and `Resident`'s scope leaves it out of every
 * ordinary read from then on. Anyone signed in to the Resident App as that person loses access at once — the account
 * is suspended and its tokens are gone — because "no longer of this household" has to be true at the gate the moment
 * it is said.
 *
 * Run inside the estate's tenancy. Who may remove whom is the caller's: the app lets only the primary resident remove,
 * and never the primary; the command refuses a primary and a person a seeder invented.
 */
final class HouseholdMembers
{
    public function __construct(
        private readonly ResidentInvites $invites,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What removing this person would do, read and not done — for the command's dry run.
     *
     * @return array{accounts: int, accounts_active: int, claims: int, passes: int, invite_open: bool}
     */
    public function plan(Resident $target): array
    {
        $estate = (string) tenant()?->getTenantKey();
        $claims = $this->pendingInviteClaims($target);

        $own = ResidentAccount::query()->where('tenant_id', $estate)->where('resident_id', $target->id)
            ->where('status', '!=', ResidentAccount::REVOKED)
            ->pluck('status');

        $claimed = $claims->isEmpty() ? collect() : ResidentAccount::query()->where('tenant_id', $estate)->whereIn('claim_id', $claims->modelKeys())
            ->where('status', '!=', ResidentAccount::REVOKED)
            ->pluck('status');

        return [
            'accounts' => $own->count() + $claimed->count(),
            'accounts_active' => $own->merge($claimed)->filter(static fn (mixed $status): bool => $status === ResidentAccount::ACTIVE)->count(),
            'claims' => $claims->count(),
            'passes' => VisitorPass::query()->where('category', VisitorPasses::RESIDENT)->where('issued_by_resident_id', $target->id)->where('status', VisitorPass::ACTIVE)->count(),
            'invite_open' => $this->invites->openFor($target) !== null,
        ];
    }

    /**
     * Take them off. `$by` is the name the history gives the person who did it; `$byResident` is the primary resident
     * who did it in the app, or null for the estate office. `$audit` is added to the audit entry (how it was done, and
     * by which account or why); `$actorName` names the actor where no one is signed in, as on the server's command line.
     *
     * @param  array<string, mixed>  $audit
     * @return array{claims_rejected: int, resident_passes_cancelled: int}
     */
    public function remove(Resident $target, string $by, ?Resident $byResident, array $audit, ?string $actorName = null): array
    {
        DB::connection('tenant')->transaction(function () use ($target, $byResident): void {
            $target->forceFill(['removed_at' => Carbon::now(), 'removed_by_resident_id' => $byResident?->id])->save();
        });

        // An invite for somebody no longer of the household is not left standing.
        $this->invites->cancel($target, $by, null, 'member removed');

        $estate = (string) tenant()?->getTenantKey();

        /*
         * SUSPENDED — BUT A REVOKED ACCOUNT STAYS REVOKED (stage 2, G1). Overwriting it with `suspended` undid the
         * estate office's revocation: the guard's unit card stopped naming them, the office could no longer
         * restore, and sign-in stopped answering `account_revoked`. Removing a person from the household's list is
         * the household's; ending their app access is the office's.
         */
        ResidentAccount::query()->where('tenant_id', $estate)->where('resident_id', $target->id)
            ->where('status', '!=', ResidentAccount::REVOKED)
            ->get()
            ->each(static function (ResidentAccount $account): void {
                $account->tokens()->delete();
                $account->forceFill(['status' => ResidentAccount::SUSPENDED])->save();
            });

        /*
         * AND WHAT THE INVITE STARTED ENDS WITH THEM (stage 2). A claim their invitee's sign-in made is still
         * pending: it is rejected, and the account behind it — which has no `resident_id` yet, so the query above
         * missed it — is suspended too. Their own e-pass is cancelled, as a revocation cancels it: the gate
         * refuses only a revoked resident's pass, so a removed member walked in on theirs.
         */
        $claims = $this->pendingInviteClaims($target);

        foreach ($claims as $claim) {
            $claim->forceFill([
                'status' => UnitClaim::REJECTED,
                'decision_reason' => $byResident instanceof Resident
                    ? 'Removed from the household by the primary resident before approval.'
                    : 'Removed from the household by the estate office before approval.',
                'reviewed_by_name' => $by,
                'reviewed_at' => Carbon::now(),
            ])->save();

            ResidentAccount::query()->where('tenant_id', $estate)->where('claim_id', $claim->id)
                ->where('status', '!=', ResidentAccount::REVOKED)
                ->get()
                ->each(static function (ResidentAccount $account): void {
                    $account->tokens()->delete();
                    $account->forceFill(['status' => ResidentAccount::SUSPENDED])->save();
                });
        }

        $passes = app(ResidentPassCancellation::class)->cancelFor((int) $target->id);

        $this->audit->record(
            action: 'household.member_removed',
            entityType: 'Resident',
            entityId: (string) $target->id,
            before: ['household_id' => $target->household_id, 'relationship' => $target->relationship, 'status' => $target->status],
            after: [
                'removed_by_resident_id' => $byResident?->id,
                ...$audit,
                'claims_rejected' => $claims->count(),
                'resident_passes_cancelled' => $passes,
            ],
            tenantId: $estate,
            actorName: $actorName,
        );

        return ['claims_rejected' => $claims->count(), 'resident_passes_cancelled' => $passes];
    }

    /** @return Collection<int, UnitClaim> */
    private function pendingInviteClaims(Resident $target): Collection
    {
        return UnitClaim::query()
            ->whereIn('invite_id', ResidentInvite::query()->select('id')->where('resident_id', $target->id))
            ->where('status', UnitClaim::PENDING)
            ->get();
    }
}
