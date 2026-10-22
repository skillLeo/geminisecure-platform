<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Models\DevicePushToken;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Resident;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\VisitorPass;
use App\Models\PublicRequest;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccountDeletionNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\Amenities;
use App\Services\Estate\ResidentInvites;
use App\Services\Passes\VisitorPasses;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * DELETING AN ACCOUNT: RECOVERABLE, NOT GONE (M18H).
 *
 * Deleting an account and deleting the data are two different things, and this class does both, in that order:
 *
 *   delete()      the account moves to `deleted` and KEEPS EVERYTHING. What stops, at once (§1.3): signing in on every
 *                 phone (every token destroyed), the e-pass, every visitor pass the person issued that has not been used
 *                 (cancelled, so every gate handset refuses it — the visitor is told at the gate in a cancelled pass's own
 *                 words), their future amenity bookings (cancelled, a held deposit refunded — never kept), every push
 *                 address, and their name on the estate's active register (the register entry is removed, not erased).
 *                 What does not stop, and must not (§1.4): the dues, the ledger, the gate log, incidents and panics,
 *                 ballots, the audit trail — they belong to the household and the unit.
 *   restore()     within the estate's windows (§1.2: the person themselves for 7 days, by signing in; the office for 30):
 *                 everything back — unit, household, balance, history — and the other side told.
 *   anonymise()   once the window has closed, or sooner when the office deletes it permanently (§5): nothing of the
 *                 person is left on the account or the register entry — "Removed resident" and a reference — and every
 *                 row that points at them keeps its amounts, its times and its references. The ledger still balances;
 *                 the gate log is still complete. It drops no row.
 *
 * Every act is audited: who, when, why — and never the person's name, because the audit log outlives the account.
 */
final class AccountDeletion
{
    public const BY_RESIDENT = 'resident';

    public const BY_OFFICE = 'office';

    public const DEFAULT_SELF_DAYS = 7;

    public const DEFAULT_OFFICE_DAYS = 30;

    /** What a register entry is called once nothing of the person is left. */
    public const REMOVED_NAME = 'Removed resident';

    /** Who the audit log says acted when the person did it themselves — never their name, which the log would outlive. */
    public const RESIDENT_ACTOR = 'The resident (Resident App)';

    /** @var array<string, array{self: int, office: int}> the windows already read, by estate */
    private array $windows = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /* ------------------------------------------------------------------ */
    /* the windows */
    /* ------------------------------------------------------------------ */

    /**
     * The estate's two windows, in days (§1.2) — read in its own database.
     *
     * @return array{self: int, office: int}
     */
    public static function windowsFor(string $tenantId): array
    {
        $read = static function (): array {
            try {
                $s = EstateSetting::current();
                $self = (int) ($s->getAttribute('account_self_restore_days') ?? self::DEFAULT_SELF_DAYS);
                $office = (int) ($s->getAttribute('account_office_restore_days') ?? self::DEFAULT_OFFICE_DAYS);
            } catch (Throwable) {
                [$self, $office] = [self::DEFAULT_SELF_DAYS, self::DEFAULT_OFFICE_DAYS];
            }

            // The office's window is never shorter than the person's own: an estate cannot set a window nobody is in.
            $self = max(0, $self);

            return ['self' => $self, 'office' => max($self, $office)];
        };

        $open = function_exists('tenant') ? tenant() : null;
        if ($open !== null && (string) $open->getTenantKey() === $tenantId) {
            return $read();
        }

        $estate = Tenant::query()->find($tenantId);

        return $estate === null ? ['self' => self::DEFAULT_SELF_DAYS, 'office' => self::DEFAULT_OFFICE_DAYS] : $estate->run($read);
    }

    /**
     * Where a deleted account stands, on the estate's calendar.
     *
     * @return array{deleted_at: string, deleted_on: string, self_until: string, office_until: string, can_self_restore: bool, can_office_restore: bool, days_left: int, by: ?string}
     */
    public function standing(ResidentAccount $account, ?Carbon $at = null): array
    {
        if ($account->deleted_at === null) {
            throw new DomainException('This account has not been deleted.');
        }

        $at ??= Carbon::now();
        $tenant = (string) $account->tenant_id;
        // Once per estate per instance: a list of forty deleted accounts reads the estate's settings once, not forty times.
        $windows = $this->windows[$tenant] ??= self::windowsFor($tenant);
        $deleted = Carbon::instance($account->deleted_at);
        $selfUntil = $deleted->copy()->addDays($windows['self']);
        $officeUntil = $deleted->copy()->addDays($windows['office']);
        $zone = SiteTime::zone($tenant);

        return [
            'deleted_at' => SiteTime::iso($deleted) ?? '',
            'deleted_on' => $deleted->copy()->setTimezone($zone)->toDateString(),
            'self_until' => $selfUntil->copy()->setTimezone($zone)->toDateString(),
            'office_until' => $officeUntil->copy()->setTimezone($zone)->toDateString(),
            'can_self_restore' => $account->isDeleted() && $at->lessThan($selfUntil),
            'can_office_restore' => $account->isDeleted() && $at->lessThan($officeUntil),
            // Whole days until nothing is left of it, counted up: a day with an hour left is a day left.
            'days_left' => $account->isDeleted() ? max(0, (int) ceil($at->diffInSeconds($officeUntil, false) / 86400)) : 0,
            'by' => $account->deleted_by,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* delete */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{reference: string, deleted_at: string, self_until: string, permanent_on: string}
     */
    public function delete(ResidentAccount $account, string $by, ?User $officer = null): array
    {
        if ($account->anonymised_at !== null) {
            throw new DomainException('Nothing of this account is left to delete.');
        }

        if ($account->isDeleted()) {
            $s = $this->standing($account);

            return ['reference' => (string) $account->deleted_reference, 'deleted_at' => $s['deleted_at'], 'self_until' => $s['self_until'], 'permanent_on' => $s['office_until']];
        }

        $reference = PublicRequest::newReference();
        $now = Carbon::now();
        $tenant = (string) $account->tenant_id;
        $estate = Tenant::query()->find($tenant);
        $before = ['status' => $account->status, 'resident_id' => $account->resident_id, 'unit_id' => $account->unit_id];

        // In the estate's database: the gate credentials, the bookings, the register entry, any open invite.
        $inEstate = ['passes' => 0, 'bookings' => 0, 'register' => false, 'was_primary' => false];
        if ($estate !== null && $account->resident_id !== null) {
            $inEstate = $estate->run(fn (): array => $this->stopInEstate((int) $account->resident_id, $now, $by === self::BY_OFFICE ? (string) ($officer->name ?? 'The estate office') : 'The resident'));
        }

        DB::connection('mysql')->transaction(function () use ($account, $reference, $now, $by, $officer, $inEstate, $tenant): void {
            // Every signed-in phone loses access on its next request.
            $account->tokens()->delete();

            // Nothing may reach the person's phones: an Expo token is a device's ADDRESS. The app registers again on a restore.
            if (DB::connection('mysql')->getSchemaBuilder()->hasTable('device_push_tokens')) {
                DB::connection('mysql')->table('device_push_tokens')
                    ->where('tenant_id', $tenant)
                    ->where('principal_type', DevicePushToken::RESIDENT)
                    ->where('principal_id', $account->id)
                    ->delete();
            }

            // Codes in flight for the address are dead.
            if (DB::connection('mysql')->getSchemaBuilder()->hasTable('resident_otps')) {
                DB::connection('mysql')->table('resident_otps')
                    ->where('tenant_id', $tenant)
                    ->where('channel', (string) $account->channel)
                    ->where('destination_hash', hash_hmac('sha256', (string) $account->destination, (string) config('app.key')))
                    ->delete();
            }

            // A deletion request the person sent from the public page has been carried out.
            PublicRequest::query()->where('kind', PublicRequest::ACCOUNT_DELETION)->where('contact', (string) $account->destination)->delete();

            $account->forceFill([
                'status_before_delete' => $account->status,
                'status' => ResidentAccount::DELETED,
                'deleted_at' => $now,
                'deleted_reference' => $reference,
                'deleted_by' => $by,
                'deleted_by_name' => $by === self::BY_OFFICE ? mb_substr((string) ($officer->name ?? 'The estate office'), 0, 160) : null,
                'removed_register_at' => $inEstate['register'] ? $now : null,
            ])->save();
        });

        $standing = $this->standing($account->refresh(), $now);

        $this->audit->record(
            action: 'resident_account.deleted',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: $before,
            after: [
                'reference' => $reference,
                'by' => $by,
                'officer' => $officer?->name,
                'passes_cancelled' => $inEstate['passes'],
                'bookings_cancelled' => $inEstate['bookings'],
                'register_entry_removed' => $inEstate['register'],
                'was_primary' => $inEstate['was_primary'],
                'self_restore_until' => $standing['self_until'],
                'permanent_on' => $standing['office_until'],
            ],
            tenantId: $tenant,
            actorName: $by === self::BY_RESIDENT ? self::RESIDENT_ACTOR : null,
        );

        // §2.2: the email, at once — the one message a deleted account still receives.
        $this->mail($account, AccountDeletionNotice::DELETED, $standing, $estate);

        // §4.1, §4.3: the estate is told — what was cancelled, and that a primary resident's household needs a new one.
        $this->mailOffice($account, AccountDeletionNotice::DELETED_OFFICE, $standing, $estate, $reference, [
            'passes' => $inEstate['passes'],
            'bookings' => $inEstate['bookings'],
            'was_primary' => $inEstate['was_primary'],
        ]);

        return ['reference' => $reference, 'deleted_at' => $standing['deleted_at'], 'self_until' => $standing['self_until'], 'permanent_on' => $standing['office_until']];
    }

    /**
     * What stops in the estate's own records. Run inside the estate's tenancy. Public for a household's removal
     * (HouseholdRemoval, M21 §3.1), whose members with no app account stop exactly as an account's person does.
     *
     * @return array{passes: int, bookings: int, register: bool, was_primary: bool}
     */
    public function stopInEstate(int $residentId, Carbon $now, string $byName): array
    {
        // Their own e-pass: the credential a guard admits a RESIDENT on.
        $passes = app(ResidentPassCancellation::class)->cancelFor($residentId, $now);

        // Every visitor pass they sent that has not been used and has not ended: refused at every gate from now on, in a
        // cancelled pass's own words (§4.4). The row stays: the gate log points at it.
        VisitorPass::query()
            ->where('issued_by_resident_id', $residentId)
            ->where('category', '!=', VisitorPasses::RESIDENT)
            ->where('status', VisitorPass::ACTIVE)
            ->where('valid_to', '>', $now)
            ->get()
            ->each(static function (VisitorPass $pass) use (&$passes): void {
                app(VisitorPasses::class)->cancel($pass);
                $passes++;
            });

        // Future bookings are cancelled and a held deposit goes back (§4.3) — never kept without a word.
        $bookings = 0;
        AmenityBooking::query()
            ->where('resident_id', $residentId)
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->where('starts_at', '>', $now)
            ->get()
            ->each(static function (AmenityBooking $booking) use (&$bookings): void {
                app(Amenities::class)->cancelForDeletedAccount($booking);
                $bookings++;
            });

        // Off the active register (§1.3) — REMOVED, not erased: the row is what the ledger and the gate log point at.
        $resident = Resident::withRemoved()->find($residentId);
        $register = false;
        if ($resident !== null && $resident->removed_at === null) {
            $resident->forceFill(['removed_at' => $now])->save();
            $register = true;
        }

        // An invite still open for them leads nowhere now.
        if ($resident !== null) {
            try {
                app(ResidentInvites::class)->cancel($resident, $byName, null, 'account_deleted');
            } catch (Throwable) {
                // No open invite, or one already spent: nothing to cancel.
            }
        }

        return ['passes' => $passes, 'bookings' => $bookings, 'register' => $register, 'was_primary' => (bool) ($resident->is_primary ?? false)];
    }

    /* ------------------------------------------------------------------ */
    /* restore */
    /* ------------------------------------------------------------------ */

    /** Bring it back (§2.3, §3). Returns the status it is restored to. */
    public function restore(ResidentAccount $account, string $by, ?User $officer = null): string
    {
        if (! $account->isDeleted()) {
            throw new DomainException('This account is not deleted, so there is nothing to restore.');
        }

        $standing = $this->standing($account);

        if ($by === self::BY_RESIDENT && ! $standing['can_self_restore']) {
            throw new DomainException('Only the estate office can restore this account now. Contact them — they can bring it back until '.self::dayWords($standing['office_until']).'.');
        }

        if (! $standing['can_office_restore']) {
            throw new DomainException('This account can no longer be restored: its window closed on '.self::dayWords($standing['office_until']).'.');
        }

        $tenant = (string) $account->tenant_id;
        $estate = Tenant::query()->find($tenant);
        $now = Carbon::now();

        // The register entry this deletion removed — and only that one: a removal the household made stands.
        if ($estate !== null && $account->resident_id !== null && $account->removed_register_at !== null) {
            $estate->run(static function () use ($account): void {
                $resident = Resident::withRemoved()->find((int) $account->resident_id);
                if ($resident !== null && $resident->removed_at !== null) {
                    $resident->forceFill(['removed_at' => null])->save();
                }
            });
        }

        $status = in_array($account->status_before_delete, [ResidentAccount::ACTIVE, ResidentAccount::PENDING], true)
            ? (string) $account->status_before_delete
            : ($account->resident_id !== null && $account->unit_id !== null ? ResidentAccount::ACTIVE : ResidentAccount::PENDING);
        $reference = (string) $account->deleted_reference;
        $deletedOn = $standing['deleted_on'];

        $account->forceFill([
            'status' => $status,
            'deleted_at' => null,
            'deleted_by' => null,
            'deleted_by_name' => null,
            'status_before_delete' => null,
            'removed_register_at' => null,
            'restored_at' => $now,
            'restored_by' => $by === self::BY_OFFICE ? mb_substr((string) ($officer->name ?? 'The estate office'), 0, 160) : 'The resident',
        ])->save();

        $this->audit->record(
            action: 'resident_account.restored',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: ['status' => ResidentAccount::DELETED, 'reference' => $reference, 'deleted_on' => $deletedOn],
            after: ['status' => $status, 'by' => $by, 'officer' => $officer?->name],
            tenantId: $tenant,
            actorName: $by === self::BY_RESIDENT ? self::RESIDENT_ACTOR : null,
        );

        // The other side is told: the person when the office brought it back; the office when the person did.
        $facts = [...$standing, 'deleted_on' => $deletedOn];
        $by === self::BY_OFFICE
            ? $this->mail($account, AccountDeletionNotice::RESTORED, $facts, $estate, $reference)
            : $this->mailOffice($account, AccountDeletionNotice::RESTORED_OFFICE, [...$facts, 'can_self_restore' => false], $estate, $reference);

        return $status;
    }

    /* ------------------------------------------------------------------ */
    /* anonymise */
    /* ------------------------------------------------------------------ */

    public const WINDOW_CLOSED = 'window_closed';

    public const PERMANENT = 'permanent';

    /**
     * NOTHING OF THE PERSON LEFT, NO ROW DROPPED (§5). The account and the register entry keep their ids, and every row
     * that points at them keeps its amounts, its times and its references — now attached to the unit rather than a
     * person. Irreversible.
     */
    public function anonymise(ResidentAccount $account, string $why, ?User $officer = null, ?string $reason = null): string
    {
        if ($account->anonymised_at !== null) {
            return (string) $account->deleted_reference;
        }

        $now = Carbon::now();
        $tenant = (string) $account->tenant_id;
        $estate = Tenant::query()->find($tenant);
        $reference = (string) ($account->deleted_reference ?: PublicRequest::newReference());
        $removedName = self::REMOVED_NAME.' · '.$reference;

        // The person's own photo identifies them.
        try {
            app(AccountPhotos::class)->remove($account);
        } catch (Throwable) {
            // No photo, or already gone.
        }

        if ($estate !== null && $account->resident_id !== null) {
            $estate->run(static function () use ($account, $removedName, $now): void {
                $resident = Resident::withRemoved()->find((int) $account->resident_id);
                if ($resident !== null) {
                    $resident->forceFill([
                        'full_name' => $removedName,
                        'email' => null,
                        'phone' => null,
                        'is_primary' => false,
                        'removed_at' => $resident->removed_at ?? $now,
                    ])->save();
                }

                // What they wrote on a claim to the unit names them too.
                if ($account->claim_id !== null) {
                    UnitClaim::query()->whereKey($account->claim_id)->get()->each(static function (UnitClaim $claim) use ($removedName): void {
                        $claim->forceFill(['submitted_name' => $removedName, 'submitted_phone' => null])->save();
                    });
                }
            });
        }

        $account->forceFill([
            // `destination` is unique per (estate, channel): a value that belongs to nobody and cannot be signed in to.
            'destination' => 'deleted.'.$account->id.'@account.invalid',
            'full_name' => null,
            'device_uid' => null,
            'device_platform' => null,
            // What the account was shown once (M47 §3) goes with everything else about the person.
            'shown' => null,
            'status' => ResidentAccount::SUSPENDED,
            'deleted_at' => $account->deleted_at ?? $now,
            'deleted_reference' => $reference,
            'anonymised_at' => $now,
            'anonymise_reason' => $reason === null ? null : mb_substr(trim($reason), 0, 300),
        ])->save();
        $account->tokens()->delete();

        // Never removed: who, when, why — and no name, because this entry outlives the account.
        $this->audit->record(
            action: $why === self::PERMANENT ? 'resident_account.deleted_permanently' : 'resident_account.anonymised',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: ['status' => ResidentAccount::DELETED, 'reference' => $reference],
            after: ['why' => $why, 'officer' => $officer?->name, 'reason' => $reason, 'anonymised_at' => $now->toIso8601String()],
            tenantId: $tenant,
        );

        return $reference;
    }

    /** Every deleted account whose estate's window has closed, anonymised (the daily schedule). Returns how many. */
    public function anonymiseExpired(?Carbon $at = null): int
    {
        $at ??= Carbon::now();
        $done = 0;

        ResidentAccount::query()
            ->where('status', ResidentAccount::DELETED)
            ->whereNotNull('deleted_at')
            ->whereNull('anonymised_at')
            ->orderBy('id')
            ->get()
            ->each(function (ResidentAccount $account) use ($at, &$done): void {
                if (! $this->standing($account, $at)['can_office_restore']) {
                    $this->anonymise($account, self::WINDOW_CLOSED);
                    $done++;
                }
            });

        return $done;
    }

    /* ------------------------------------------------------------------ */
    /* signing in to a deleted account */
    /* ------------------------------------------------------------------ */

    /** How long the sign-in's proof of the code stays good for the "Restore my account" button. */
    public const TICKET_MINUTES = 15;

    /**
     * WHAT SIGNING IN TO A DELETED ACCOUNT IS TOLD (§2.3). Only after the code is proven, so it tells nobody who does not
     * hold the address that an account was ever there. `ticket` is that proof, carried to `auth.restore` by this phone —
     * encrypted with the app key, bound to the account and the install, dead after fifteen minutes; null when only the
     * estate office can restore it now.
     *
     * @return array{deleted_on: string, restore_until: string, office_until: string, can_restore: bool, office_email: ?string, office_phone: ?string, ticket: ?string, ticket_expires_at: ?string}
     */
    public function signInDetails(ResidentAccount $account, string $deviceUid, ?Carbon $at = null): array
    {
        $at ??= Carbon::now();
        $standing = $this->standing($account, $at);
        $office = $this->officeContact($account);
        $expires = $at->copy()->addMinutes(self::TICKET_MINUTES);

        return [
            'deleted_on' => $standing['deleted_on'],
            'restore_until' => $standing['self_until'],
            'office_until' => $standing['office_until'],
            'can_restore' => $standing['can_self_restore'],
            'office_email' => $office['email'],
            'office_phone' => $office['phone'],
            'ticket' => $standing['can_self_restore'] ? Crypt::encryptString((string) json_encode([
                'a' => $account->id,
                't' => (string) $account->tenant_id,
                'd' => $deviceUid,
                'x' => $expires->getTimestamp(),
            ])) : null,
            'ticket_expires_at' => $standing['can_self_restore'] ? SiteTime::iso($expires) : null,
        ];
    }

    /** The sentence the sign-in refusal carries, in the order §2.3 gives it. */
    public function signInSentence(ResidentAccount $account, ?Carbon $at = null): string
    {
        $standing = $this->standing($account, $at);
        $deleted = self::dayWords($standing['deleted_on'], withWeekday: false);

        return $standing['can_self_restore']
            ? 'This account was deleted on '.$deleted.'. You can restore it until '.self::dayWords($standing['self_until'], withWeekday: false).' and nothing will be lost.'
            : 'This account was deleted on '.$deleted.'. Only the estate office can restore it now. Contact them and they can bring it back until '.self::dayWords($standing['office_until'], withWeekday: false).'.';
    }

    /**
     * The account a restore ticket was issued for — on this estate, to this install, inside its fifteen minutes — or null.
     */
    public function ticketAccount(string $ticket, string $tenantId, string $deviceUid, ?Carbon $at = null): ?ResidentAccount
    {
        try {
            $claim = json_decode(Crypt::decryptString($ticket), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($claim) || ($claim['t'] ?? null) !== $tenantId || ! hash_equals((string) ($claim['d'] ?? ''), $deviceUid)
            || (int) ($claim['x'] ?? 0) <= ($at ?? Carbon::now())->getTimestamp()) {
            return null;
        }

        $account = ResidentAccount::query()->where('tenant_id', $tenantId)->find((int) ($claim['a'] ?? 0));

        return $account instanceof ResidentAccount && $account->isDeleted() ? $account : null;
    }

    /**
     * The estate office's email and phone, from the estate's own settings (the "Contact the estate office" button).
     *
     * @return array{email: ?string, phone: ?string}
     */
    public function officeContact(ResidentAccount $account): array
    {
        $estate = Tenant::query()->find((string) $account->tenant_id);
        if ($estate === null) {
            return ['email' => null, 'phone' => null];
        }

        return $estate->run(static function (): array {
            try {
                $s = EstateSetting::current();

                return ['email' => $s->enquiries_email ?: null, 'phone' => $s->enquiries_phone ?: null];
            } catch (Throwable) {
                return ['email' => null, 'phone' => null];
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /** "2026-10-04" → "Sunday 4 October" (or "4 October") — the dates a person reads in the emails and on the screens. */
    public static function dayWords(string $date, bool $withWeekday = true): string
    {
        try {
            return Carbon::parse($date)->format($withWeekday ? 'l j F' : 'j F');
        } catch (Throwable) {
            return $date;
        }
    }

    /** @param  array<string, mixed>  $standing */
    private function mail(ResidentAccount $account, string $kind, array $standing, ?Tenant $estate, ?string $reference = null): void
    {
        $to = (string) $account->destination;
        if ($account->channel !== 'email' || ! str_contains($to, '@') || str_ends_with($to, '@account.invalid')) {
            return;
        }

        try {
            Notification::route('mail', $to)->notifyNow(new AccountDeletionNotice($kind, [
                'estate' => (string) ($estate->name ?? 'your estate'),
                'deleted_on' => self::dayWords((string) $standing['deleted_on']),
                'self_until' => $standing['can_self_restore'] ?? false ? self::dayWords((string) $standing['self_until']) : null,
                'permanent_on' => self::dayWords((string) $standing['office_until']),
                'reference' => $reference ?? (string) $account->deleted_reference,
            ]));
        } catch (Throwable $e) {
            // A notice that could not go is not a deletion that did not happen — and it is on the mail failures list.
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $standing
     * @param  array<string, mixed>  $extra  what else the office is told (`passes`, `bookings`, `was_primary`)
     */
    private function mailOffice(ResidentAccount $account, string $kind, array $standing, ?Tenant $estate, string $reference, array $extra = []): void
    {
        if ($estate === null) {
            return;
        }

        [$to, $who, $unit] = $estate->run(static function () use ($account): array {
            $resident = $account->resident_id === null ? null : Resident::withRemoved()->with('household.unit')->find((int) $account->resident_id);

            return [
                (string) (EstateSetting::current()->enquiries_email ?? ''),
                $resident->full_name ?? $account->full_name,
                $resident?->household?->unit?->reference,
            ];
        });

        if ($to === '' || ! str_contains($to, '@')) {
            return;
        }

        try {
            Notification::route('mail', $to)->notifyNow(new AccountDeletionNotice($kind, [
                'estate' => (string) $estate->name,
                'deleted_on' => self::dayWords((string) $standing['deleted_on']),
                'self_until' => ($standing['can_self_restore'] ?? false) ? self::dayWords((string) $standing['self_until']) : null,
                'permanent_on' => self::dayWords((string) $standing['office_until']),
                'reference' => $reference,
                'who' => $who,
                'unit' => $unit,
                ...$extra,
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
