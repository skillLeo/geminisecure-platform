<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\PublicRequest;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ResidentApp\AccountDeletion;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REMOVING A HOUSEHOLD: RECOVERABLE, NOT GONE (M21 §3.1, under M18H's model). Run inside the estate's tenancy.
 *
 * A family moves out; the office takes them off the lot. That is an account deletion for every one of them at once,
 * with the same window and the same rules — so it is BUILT FROM AccountDeletion rather than beside it:
 *
 *   remove()     every member with an app account: that account is deleted (AccountDeletion::delete, by the office) —
 *                sign-ins, e-pass, unused visitor passes, future bookings (a held deposit refunded) and push addresses
 *                all stop, and the office is told as for any deletion. Every member without one stops the same way
 *                in the estate's records (AccountDeletion::stopInEstate). The household itself leaves the default
 *                scope: its lot reads as vacant on the register, at the gate and in the app, and a new household can
 *                be added to it. What does NOT stop, and must not: the charges, the payments, the ledger (keyed on
 *                the unit — what the lot owes is unchanged), the gate log, incidents, ballots, the audit trail.
 *   restore()    within the estate's office window (M18H §1.2, `account_office_restore_days`, 30 by default): the
 *                household back on its lot, the register entries THIS removal took off back on it, and every account it
 *                deleted restored (each person emailed). Refused when another household is on the lot now — two
 *                households on one lot is a ledger and a gate nobody can read.
 *   anonymiseExpired()   once the window has closed: the household's name and its members' names, phones and emails
 *                are gone — "Removed household" and a reference — and no row is dropped. The accounts are anonymised
 *                by AccountDeletion's own nightly pass, on the same window.
 *
 * Audited — who, when, why, how much stopped — and never a name, because the audit log outlives the household.
 */
final class HouseholdRemoval
{
    public const REMOVED_NAME = 'Removed household';

    public function __construct(private readonly AuditLogger $audit, private readonly AccountDeletion $deletion) {}

    /**
     * @return array{reference: string, accounts: int, residents: int, passes: int, bookings: int, restore_until: string}
     */
    public function remove(Household $household, User $officer, string $reason): array
    {
        if ($household->isRemoved()) {
            throw new DomainException('This household has already been removed.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new DomainException('Say why the household is being removed, in a sentence; it is kept in the audit trail.');
        }

        $tenant = (string) tenant()->getTenantKey();
        $now = Carbon::now();
        $reference = PublicRequest::newReference();
        $byName = mb_substr((string) $officer->name, 0, 160);
        $members = Resident::query()->where('household_id', $household->id)->orderBy('id')->get();

        $accounts = [];
        $residents = [];
        $passes = 0;
        $bookings = 0;

        foreach ($members as $member) {
            $account = ResidentAccount::query()
                ->where('tenant_id', $tenant)
                ->where('resident_id', $member->id)
                ->whereNull('anonymised_at')
                ->where('status', '!=', ResidentAccount::DELETED)
                ->first();

            if ($account instanceof ResidentAccount) {
                // The whole of an account deletion, by the office: the account keeps everything and can come back.
                $this->deletion->delete($account, AccountDeletion::BY_OFFICE, $officer);
                $accounts[] = (int) $account->id;
                $residents[] = (int) $member->id;

                continue;
            }

            $stopped = $this->deletion->stopInEstate((int) $member->id, $now, $byName);
            $passes += $stopped['passes'];
            $bookings += $stopped['bookings'];
            if ($stopped['register']) {
                $residents[] = (int) $member->id;
            }
        }

        DB::connection('tenant')->transaction(function () use ($household, $now, $byName, $reference, $reason, $accounts, $residents): void {
            $household->forceFill([
                'removed_at' => $now,
                'removed_by_name' => $byName,
                'removal_reference' => $reference,
                'removal_reason' => mb_substr($reason, 0, 300),
                'removal_of' => ['residents' => $residents, 'accounts' => $accounts],
                'restored_at' => null,
                'restored_by_name' => null,
            ])->save();
        });

        $until = $this->restoreUntil($household->refresh(), $tenant);

        $this->audit->record(
            action: 'household.removed',
            entityType: 'Household',
            entityId: (string) $household->id,
            before: ['unit_id' => $household->unit_id, 'members' => $members->count()],
            after: [
                'reference' => $reference,
                'reason' => $reason,
                'accounts_deleted' => count($accounts),
                'register_entries_removed' => count($residents),
                'passes_cancelled' => $passes,
                'bookings_cancelled' => $bookings,
                'restore_until' => $until->copy()->setTimezone(SiteTime::zone($tenant))->toDateString(),
            ],
            tenantId: $tenant,
        );

        return [
            'reference' => $reference,
            'accounts' => count($accounts),
            'residents' => count($residents),
            'passes' => $passes,
            'bookings' => $bookings,
            'restore_until' => $until->copy()->setTimezone(SiteTime::zone($tenant))->toDateString(),
        ];
    }

    /** Whether it can still come back, and until when (the estate's office window, from its removal). */
    public function restoreUntil(Household $household, ?string $tenant = null): Carbon
    {
        $tenant ??= (string) tenant()->getTenantKey();
        $days = AccountDeletion::windowsFor($tenant)['office'];

        return Carbon::instance($household->removed_at ?? Carbon::now())->addDays($days);
    }

    public function canRestore(Household $household, ?Carbon $at = null): bool
    {
        return $household->isRemoved() && $household->anonymised_at === null && ($at ?? Carbon::now())->lessThan($this->restoreUntil($household));
    }

    /** Back on its lot, with the people this removal took off it. Returns how many accounts came back. */
    public function restore(Household $household, User $officer): int
    {
        if (! $household->isRemoved()) {
            throw new DomainException('This household is on its lot; there is nothing to restore.');
        }

        $tenant = (string) tenant()->getTenantKey();
        if (! $this->canRestore($household)) {
            throw new DomainException('This household can no longer be restored: its window closed on '.$this->restoreUntil($household, $tenant)->setTimezone(SiteTime::zone($tenant))->format('j M Y').'.');
        }

        $occupied = Household::query()->where('unit_id', $household->unit_id)->exists();
        if ($occupied) {
            throw new DomainException('Another household is on this lot now. Restoring this one would put two households on one lot: remove the new one first, or add these people to it.');
        }

        $of = $household->removal_of ?? [];
        $reference = (string) $household->removal_reference;
        $restoredAccounts = 0;

        DB::connection('tenant')->transaction(function () use ($household, $officer, $of): void {
            $household->forceFill([
                'removed_at' => null,
                'restored_at' => Carbon::now(),
                'restored_by_name' => mb_substr((string) $officer->name, 0, 160),
            ])->save();

            // The register entries THIS removal took off — a member the household itself removed earlier stays removed.
            Resident::withRemoved()->whereIn('id', $of['residents'] ?? [])->whereNotNull('removed_at')->get()
                ->each(static fn (Resident $r) => $r->forceFill(['removed_at' => null])->save());
        });

        foreach ($of['accounts'] ?? [] as $id) {
            $account = ResidentAccount::query()->where('tenant_id', $tenant)->find($id);
            if ($account instanceof ResidentAccount && $account->isDeleted() && $this->deletion->standing($account)['can_office_restore']) {
                $this->deletion->restore($account, AccountDeletion::BY_OFFICE, $officer);
                $restoredAccounts++;
            }
        }

        $this->audit->record(
            action: 'household.restored',
            entityType: 'Household',
            entityId: (string) $household->id,
            before: ['reference' => $reference],
            after: ['accounts_restored' => $restoredAccounts, 'register_entries_restored' => count($of['residents'] ?? [])],
            tenantId: $tenant,
        );

        return $restoredAccounts;
    }

    /**
     * The removed households still restorable, newest first, for Residents → Deleted accounts.
     *
     * @return list<array{id: int, lot: string, name: string, members: int, removed_on: string, removed_by: string|null, reference: string|null, reason: string|null, restore_until: string, days_left: int, lot_taken: bool}>
     */
    public function board(): array
    {
        $tenant = (string) tenant()->getTenantKey();
        $zone = SiteTime::zone($tenant);
        $now = Carbon::now();

        return Household::withRemoved()->with('unit')
            ->whereNotNull('removed_at')->whereNull('anonymised_at')
            ->orderByDesc('removed_at')
            ->get()
            ->filter(fn (Household $h): bool => $this->canRestore($h, $now))
            ->map(function (Household $h) use ($zone, $now): array {
                $until = $this->restoreUntil($h);

                return [
                    'id' => (int) $h->id,
                    'lot' => (string) ($h->unit->reference ?? ''),
                    'name' => (string) $h->name,
                    'members' => count($h->removal_of['residents'] ?? []),
                    // Law 5 as decided (M62 A5, DR-039): "11 Oct 2026", where the page printed "2026-10-11".
                    'removed_on' => Carbon::instance($h->removed_at ?? $now)->setTimezone($zone)->format('j M Y'),
                    'removed_by' => $h->removed_by_name,
                    'reference' => $h->removal_reference,
                    'reason' => $h->removal_reason,
                    'restore_until' => $until->copy()->setTimezone($zone)->format('j M Y'),
                    'days_left' => max(0, (int) ceil($now->diffInSeconds($until, false) / 86400)),
                    'lot_taken' => Household::query()->where('unit_id', $h->unit_id)->exists(),
                ];
            })
            ->values()
            ->all();
    }

    /** Every removed household whose window has closed: nothing of its people left, no row dropped. Returns how many. */
    public function anonymiseExpired(?Carbon $at = null): int
    {
        $at ??= Carbon::now();
        $done = 0;

        Household::withRemoved()->whereNotNull('removed_at')->whereNull('anonymised_at')->orderBy('id')->get()
            ->each(function (Household $household) use ($at, &$done): void {
                if ($at->lessThan($this->restoreUntil($household))) {
                    return;
                }

                $reference = (string) ($household->removal_reference ?: PublicRequest::newReference());
                DB::connection('tenant')->transaction(static function () use ($household, $reference, $at): void {
                    $household->forceFill(['name' => self::REMOVED_NAME.' · '.$reference, 'anonymised_at' => $at, 'removal_reason' => null])->save();

                    // Members with no account are this removal's to clear; those with one are AccountDeletion's.
                    $withAccount = ResidentAccount::query()->where('tenant_id', (string) tenant()->getTenantKey())
                        ->whereIn('resident_id', $household->removal_of['residents'] ?? [])->pluck('resident_id')->all();
                    Resident::withRemoved()->whereIn('id', array_diff($household->removal_of['residents'] ?? [], $withAccount))->get()
                        ->each(static fn (Resident $r) => $r->forceFill([
                            'full_name' => AccountDeletion::REMOVED_NAME.' · '.$reference, 'email' => null, 'phone' => null, 'is_primary' => false,
                        ])->save());
                });

                $this->audit->record(
                    action: 'household.anonymised',
                    entityType: 'Household',
                    entityId: (string) $household->id,
                    before: ['reference' => $reference],
                    after: ['why' => AccountDeletion::WINDOW_CLOSED, 'anonymised_at' => $at->toIso8601String()],
                    tenantId: (string) tenant()->getTenantKey(),
                );
                $done++;
            });

        return $done;
    }
}
