<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Api\AccessLevels;
use App\Api\AppMatrix;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Notifications\ResidentInviteSent;
use App\Services\Audit\AuditLogger;
use App\Services\ResidentApp\ResidentSignIn;
use App\Services\ResidentApp\RevokeResidentAccess;
use App\Support\InviteMail;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * INVITES TO THE RESIDENT APP, ACTUALLY SENT — the office's Add resident and a household's own "add a member".
 *
 * Neither sent anything. The office's wrote a `resident_invites` row that said, in its own docblock, "there is
 * no mail driver on this path yet"; the Resident App's wrote nothing at all. An invited family member never
 * received a thing, while the app's result screen said "Sent to the estate office — Confirmed". The user sent
 * one, checked the inbox, and found nothing (24 September 2026).
 *
 * Now there is one way an invite goes out, and it says what happened:
 *
 *   sent        by email, through the platform's own mail path — the relay the Director saved under
 *               Platform settings → Mail, the same one the test message and the committee invites use —
 *               and SYNCHRONOUSLY, so `sent_at` is stamped only when the mail server accepted it
 *   not sent    the refusal kept in words (`MailSettings::explain`), never a credential; or "mail is not set
 *               up", naming who can set it up
 *   resent      with a fresh token and a fresh fortnight, never two live links, and never faster than the
 *               timer allows: 1 minute after the first send, 2 after the second, 5 after every one after
 *   cancelled   by whoever may send it, and whenever the member is removed
 *
 * Every send and cancel is audited against the estate.
 *
 * NOT AN ACCOUNT LINK. The email carries no link that signs anybody in. The invitee signs in with the app's
 * one-time code to the INVITED ADDRESS, and that proof, matched to exactly one live invite
 * (`ResidentInviteAcceptance`), is their claim: there is no form to fill in (stage 2, G13).
 * - By default the estate office confirms them with one tap.
 * - When the estate trusts its primary residents' invites, a primary's invite links them at once.
 * An invite is never matched by a contact on the register: a register goes stale; a proven address does not.
 */
final class ResidentInvites
{
    /** Resend waits 1, then 2, then 5 minutes (the user, 25 September): long enough that nobody floods a mailbox, short enough that nobody waits at a phone for long. */
    public const COOLDOWN_SECONDS = [60, 120, 300];

    public const NOT_SET_UP = InviteMail::NOT_SET_UP;

    /** A primary resident's address refusals in a day before the app stops trying for them (G5, DESIGN INV-14). */
    public const REFUSALS_PER_DAY = 5;

    /** Sends to one address in a day, across every row and household (DESIGN INV-4). */
    public const SENDS_PER_ADDRESS_PER_DAY = 5;

    /** An add older than this, sent from a phone that was offline, carries no invite (G14, D-ACCEPT-4). */
    public const STALE_ADD_SECONDS = 900;

    /** What the office reads for each stored refusal (`officeReason`). The app never sees these. */
    public const OFFICE_REASONS = [
        'in_use' => 'This address already signs in to the Resident App for another home here, so it cannot be invited for this person. Use their own address.',
        'other_household' => 'Another household here has a live invite to this address. Cancel that one first, or use a different address.',
        'revoked_account' => 'The estate office ended app access for this address. Give it back on the person\'s row instead of inviting them again.',
        'revoked_name' => 'The estate office ended app access for a person of this name at this home. Give it back on their row or, if this is somebody else, add them with "Estate verifies" instead.',
        'refusal_limit' => 'The household\'s primary resident has had five invites refused today, so the app sent no more. Send this one from here if it is right.',
        'stale_offline_add' => 'Added on the phone while it was offline, so no invite went with it. Send it now.',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /** Seconds to wait after the Nth send before the next may go. */
    public static function cooldownAfter(int $attempts): int
    {
        $step = max(1, min($attempts, count(self::COOLDOWN_SECONDS))) - 1;

        return self::COOLDOWN_SECONDS[$step];
    }

    /** When it may be sent again, or null when it may be sent now. */
    public static function resendAt(ResidentInvite $invite): ?Carbon
    {
        if ($invite->last_attempt_at === null || $invite->attempts < 1) {
            return null;
        }

        $at = $invite->last_attempt_at->copy()->addSeconds(self::cooldownAfter($invite->attempts));

        return $at->isFuture() ? $at : null;
    }

    /** The invite still standing for this person — not claimed, not cancelled (a lapsed one is reopened by a resend). */
    public function openFor(Resident $resident): ?ResidentInvite
    {
        return ResidentInvite::query()
            ->where('resident_id', $resident->id)
            ->whereNull('claimed_at')
            ->whereNull('cancelled_at')
            ->latest('id')
            ->first();
    }

    /** The latest invite for this person, whatever became of it — what a row on screen describes. */
    public function latestFor(Resident $resident): ?ResidentInvite
    {
        return ResidentInvite::query()->where('resident_id', $resident->id)->latest('id')->first();
    }

    /**
     * `latestFor()` for everybody on a list, in ONE read (M11 A2) — a household's members, which
     * asked it once a member.
     *
     * Each person's latest invite is the one with their highest id, exactly as `latestFor()` picks
     * it. A person never invited is absent, which `latestFor()` answers as null.
     *
     * @param  iterable<Resident>  $residents
     * @return array<int, ResidentInvite> by resident id
     */
    public function latestForEach(iterable $residents): array
    {
        $ids = [];

        foreach ($residents as $resident) {
            $ids[(int) $resident->id] = true;
        }

        if ($ids === []) {
            return [];
        }

        $ids = array_keys($ids);

        return ResidentInvite::query()
            ->whereIn('id', ResidentInvite::query()->selectRaw('MAX(id)')->whereIn('resident_id', $ids)->groupBy('resident_id'))
            ->get()
            ->keyBy(static fn (ResidentInvite $invite): int => (int) $invite->resident_id)
            ->all();
    }

    /**
     * Send it — the first time, or again.
     *
     * IN THIS ORDER (stage 2, W-ACCEPT). Stage 1's checks keep their place and their words; the new ones are
     * marked. Who is asking decides two of them: `$byResidentId` is the APP (a primary resident), otherwise
     * the OFFICE (`$byUser`).
     *
     *   1. no address, not pending, no unit                           stage 1
     *   2. NEW, app only: the primary has had REFUSALS_PER_DAY address refusals in a day   (G5)
     *   3. the Resend timer                                            stage 1
     *   4. NEW: the address itself is refused — in use by another home here, another household's live
     *      invite, a revoked account's, or the name of somebody the office cut off at this unit  (G3)
     *   5. NEW: the address has had SENDS_PER_ADDRESS_PER_DAY sends in a day
     *   6. NEW: the write, with every other open invite of this household to the same address cancelled
     *      as superseded, in ONE transaction: never two live links for one address           (G4)
     *   7. deliver, after the commit                                   stage 1, through InviteMail (G10)
     *
     * A REFUSED SEND IS RECORDED ON THE ROW, NOT SWALLOWED (G7), by `recordNotSent()`: both add paths swallow
     * the throw, and without a row the phone says "no invite yet" and the office "recorded but never sent".
     *
     * @throws InviteAddressRefused the address is refused (step 2 or 4): the app is shown only its generic sentence
     * @throws DomainException with the sentence to show: no address, not pending, no unit, the timer, the daily cap
     */
    public function send(Resident $resident, string $invitedByName, ?int $byResidentId = null, ?User $byUser = null): ResidentInvite
    {
        $email = strtolower(trim((string) $resident->email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException(sprintf('There is no email address for %s, so there is nowhere to send an invite. Add one, then send it.', $resident->full_name));
        }

        if ($resident->status !== Resident::PENDING) {
            throw new DomainException(sprintf('%s is already confirmed on the register, so there is nothing to invite them to.', $resident->full_name));
        }

        // The household and unit a caller already holds are used as they are (`addMember` hands both on).
        $household = $resident->household;
        $unit = $household !== null && $household->relationLoaded('unit') && (int) $household->unit?->id === (int) $household->unit_id
            ? $household->unit
            : Unit::query()->find((int) ($household->unit_id ?? 0));

        if ($unit === null) {
            throw new DomainException(sprintf('%s is not on a unit, so an invite could not say which home to claim.', $resident->full_name));
        }

        $tenantId = (string) tenant()?->getTenantKey();
        $appPath = $byResidentId !== null;
        $refusals = 'invite-refusals:'.$tenantId.':'.$byResidentId;

        // 2. The per-primary refusal limit (G5), app path only. Not hit again, so the day's window frees.
        if ($appPath && RateLimiter::tooManyAttempts($refusals, self::REFUSALS_PER_DAY)) {
            $refused = new InviteAddressRefused(
                'refusal_limit',
                'This household\'s primary resident has had five invites refused at their address today, so the app is sending no more for now. The office can still send this one from the household\'s page.',
            );

            $this->refuse($resident, $unit, $email, $refused, $appPath, $invitedByName, $byUser, $tenantId, ['by_resident_id' => $byResidentId]);
        }

        /*
         * The open invites that matter here, read ONCE (M11's write budget): this person's own — `openFor()`,
         * whatever address it went to — and every one to this address in the estate. Step 3 reads the first,
         * step 4 asks whether another household holds a live one of the second, and step 6 cancels this
         * household's other ones. Only people still on the register: a removed person's invite links nobody,
         * so it holds no address.
         */
        $open = ResidentInvite::query()
            ->select('resident_invites.*', 'residents.household_id as household_of')
            ->join('residents', 'residents.id', '=', 'resident_invites.resident_id')
            ->whereNull('residents.removed_at')
            ->where(static fn (Builder $q) => $q->where('resident_invites.email', $email)->orWhere('resident_invites.resident_id', $resident->id))
            ->whereNull('resident_invites.claimed_at')
            ->whereNull('resident_invites.cancelled_at')
            ->orderBy('resident_invites.id')
            ->get();

        // 3. The Resend timer — stage 1, unchanged. This person's open invite is `openFor()`'s: their latest.
        $invite = $open->filter(static fn (ResidentInvite $mine): bool => (int) $mine->resident_id === (int) $resident->id)->last();

        // The estate's own time, with no zone after it (Law 5 as decided, M62 A5, DR-039): it read "7:18 PM JA".
        if ($invite !== null && ($at = self::resendAt($invite)) !== null) {
            throw new DomainException(sprintf('The invite was just sent. You can send it again at %s.', SiteTime::local($at)->format('g:i A')));
        }

        // Everybody else's, to this address.
        $open = $open->filter(static fn (ResidentInvite $other): bool => (int) $other->resident_id !== (int) $resident->id
            && strtolower((string) $other->email) === $email);

        // 4. The address itself (G3, DESIGN INV-14, INV-15).
        $refused = $this->addressRefusal($resident, $email, $unit, $tenantId, $open);

        if ($refused !== null) {
            if ($appPath) {
                RateLimiter::hit($refusals, 86400);
            }

            $this->refuse($resident, $unit, $email, $refused, $appPath, $invitedByName, $byUser, $tenantId);
        }

        // 5. At most SENDS_PER_ADDRESS_PER_DAY sends to one address a day, across every row and household.
        $capKey = 'resident-invite:'.$tenantId.':'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($capKey, self::SENDS_PER_ADDRESS_PER_DAY)) {
            $frees = Carbon::now()->addSeconds(RateLimiter::availableIn($capKey));
            $sentence = sprintf('This address has had five invites today. You can send it again at %s.', SiteTime::local($frees)->format('g:i A'));

            $this->recordNotSent($resident, $sentence, null, notLive: false);

            throw new DomainException($sentence);
        }

        // 6. The write, and one live link per address per household (G4), together.
        $invite = DB::connection('tenant')->transaction(function () use ($invite, $open, $resident, $unit, $email, $byResidentId, $byUser, $invitedByName, $tenantId): ResidentInvite {
            // The open invite step 3 read, as stage 1 wrote it: nothing between there and here writes one.
            $invite ??= new ResidentInvite(['resident_id' => $resident->id, 'unit_id' => $unit->id, 'channels' => 'email']);
            $invite->forceFill([
                // A fresh token every send: whatever an older email carried is not a second live link.
                'token' => (string) Str::uuid(),
                'email' => $email,
                'expires_at' => Carbon::now()->addDays(ResidentInvite::DAYS),
                'invited_by_resident_id' => $byResidentId,
                'invited_by_user_id' => $byUser?->getKey(),
                'invited_by_name' => $invitedByName,
                'attempts' => (int) $invite->attempts + 1,
                'last_attempt_at' => Carbon::now(),
                'refused_reason' => null,
            ])->save();

            // Another current member of THIS household, invited at the same address: superseded by this one.
            $others = $open->filter(static fn (ResidentInvite $other): bool => (int) $other->id !== (int) $invite->id
                && (int) $other->resident_id !== (int) $resident->id
                && (int) $other->getAttribute('household_of') === (int) $resident->household_id);

            foreach ($others as $other) {
                $other->forceFill(['cancelled_at' => Carbon::now()])->save();

                $this->audit->record(
                    action: 'resident.invite_cancelled',
                    entityType: 'Resident',
                    entityId: (string) $other->resident_id,
                    after: ['invite_id' => $other->id, 'by' => $invitedByName, 'why' => 'superseded', 'superseded_by' => $invite->id],
                    tenantId: $tenantId,
                    actor: $byUser,
                );
            }

            return $invite;
        });

        // 7. Deliver, after the commit. The address limiter counts every real attempt.
        RateLimiter::hit($capKey, 86400);
        $error = $this->deliver($invite, $resident, $unit, $invitedByName, $tenantId);

        // `sent_at` is the last send the mail server ACCEPTED; a failed resend leaves an earlier success standing.
        $invite->forceFill(['sent_at' => $error === null ? Carbon::now() : $invite->sent_at, 'last_error' => $error])->save();

        $this->audit->record(
            action: $error === null ? 'resident.invite_sent' : 'resident.invite_not_sent',
            entityType: 'Resident',
            entityId: (string) $resident->id,
            after: ['invite_id' => $invite->id, 'email' => $email, 'attempt' => $invite->attempts, 'by' => $invitedByName, 'reason' => $error],
            tenantId: $tenantId,
            actor: $byUser,
        );

        return $invite->refresh();
    }

    /** Cancel what is standing. Returns the invite cancelled, or null when there was none. */
    public function cancel(Resident $resident, string $byName, ?User $byUser = null, string $why = 'cancelled'): ?ResidentInvite
    {
        $invite = $this->openFor($resident);

        if ($invite === null) {
            return null;
        }

        $invite->forceFill(['cancelled_at' => Carbon::now()])->save();

        $this->audit->record(
            action: 'resident.invite_cancelled',
            entityType: 'Resident',
            entityId: (string) $resident->id,
            after: ['invite_id' => $invite->id, 'by' => $byName, 'why' => $why],
            tenantId: (string) tenant()?->getTenantKey(),
            actor: $byUser,
        );

        return $invite;
    }

    /**
     * What a screen says about an invite, in one shape for the app and the console alike.
     *
     *   none        there is no invite
     *   sent        the mail server accepted it (`sent_at`)
     *   not_sent    it was not sent — `reason` says why — or it was recorded and never sent
     *   expired     it lapsed; a resend starts it again
     *   cancelled · claimed
     *
     * `resend_after` is when Resend may be pressed again; null means now.
     *
     * @return array{status: string, email: string|null, sent_at: string|null, last_attempt_at: string|null, reason: string|null, expires_at: string|null, resend_after: string|null, attempts: int}
     */
    public static function outcome(?ResidentInvite $invite): array
    {
        if ($invite === null) {
            return ['status' => 'none', 'email' => null, 'sent_at' => null, 'last_attempt_at' => null, 'reason' => null, 'expires_at' => null, 'resend_after' => null, 'attempts' => 0];
        }

        $lapsed = $invite->expires_at !== null && $invite->expires_at->isPast();
        $lastFailed = $invite->last_error !== null;
        $neverTried = $invite->attempts < 1;

        // Cancelled before claimed: the one claimed invite ever cancelled is one whose claim the office rejected
        // (stage 2), and it must read as cancelled, so the primary may send a fresh one.
        $status = match (true) {
            $invite->cancelled_at !== null => 'cancelled',
            $invite->claimed_at !== null => 'claimed',
            $lapsed => 'expired',
            $lastFailed, $neverTried => 'not_sent',
            default => 'sent',
        };

        return [
            'status' => $status,
            'email' => $invite->email,
            'sent_at' => SiteTime::iso($invite->sent_at),
            'last_attempt_at' => SiteTime::iso($invite->last_attempt_at),
            'reason' => $status === 'not_sent'
                ? ($invite->last_error ?? ($invite->email === null ? 'There is no email address to send it to. Add one, then send it.' : 'This invite was recorded but never sent. Send it now.'))
                : null,
            'expires_at' => SiteTime::iso($invite->expires_at),
            'resend_after' => SiteTime::iso(self::resendAt($invite)),
            'attempts' => (int) $invite->attempts,
        ];
    }

    /**
     * The email, now, through the one delivery rule every invitation keeps (`InviteMail`, G10). The refusal in
     * words, or null when the mail server accepted it.
     *
     * The mail names the estate and the inviter, never the unit (DESIGN INV-14): whoever holds this address has
     * not yet proven it, and must not learn where somebody lives from it. With trust on and a primary's invite,
     * it says the app opens straight away — which is what will happen.
     */
    private function deliver(ResidentInvite $invite, Resident $resident, Unit $unit, string $invitedByName, string $tenantId): ?string
    {
        $trusted = $invite->invited_by_resident_id !== null && (bool) EstateSetting::current()->trust_primary_invites;
        $estateName = (string) (tenant()?->getAttribute('name') ?? 'your estate');
        $lapsesOn = SiteTime::local($invite->expires_at ?? Carbon::now(), $tenantId === '' ? null : $tenantId)->format('j F Y');

        return InviteMail::deliver((string) $invite->email, new ResidentInviteSent(
            estateName: $estateName,
            memberName: (string) $resident->full_name,
            invitedBy: $invitedByName,
            email: (string) $invite->email,
            lapsesOn: $lapsesOn,
            appUrl: ($url = config('services.resident_app.url')) ? (string) $url : null,
            trusted: $trusted,

            // M18G §5: signed until the invite lapses, carrying the estate and the address — it opens the app at sign-in
            // with the address filled in. It signs nobody in; the code is still proven at that address.
            openUrl: $tenantId === '' ? null : rescue(static fn (): string => URL::temporarySignedRoute('public.open-invite', $invite->expires_at ?? Carbon::now()->addDays(14), [
                'estate' => $tenantId,
                'email' => (string) $invite->email,
                'estate_name' => $estateName,
                'by' => $invitedByName,
                'lapses' => $lapsesOn,
            ]), null, report: true),
        ), ['invite' => $invite->id, 'unit' => $unit->id]);
    }

    /**
     * Every live invite in THIS estate for one address (stage 2, W-ACCEPT): what a sign-in is recognised by.
     * Live is `ResidentInvite::isLive()`, asked in SQL — never `isOpen()`.
     *
     * @return Collection<int, ResidentInvite>
     */
    public function liveFor(string $email): Collection
    {
        return ResidentInvite::query()
            ->where('email', strtolower(trim($email)))
            ->whereNull('claimed_at')
            ->whereNull('cancelled_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', Carbon::now())
            ->where('attempts', '>=', 1)
            ->orderBy('id')
            ->get();
    }

    /**
     * "REVOKED", EVERYWHERE IN STAGE 2 (G1): `status = revoked` OR `revoked_at` set, in this estate. One rule,
     * so refusal (c), the trust gate and reopening a suspended account read it alike, and an account a household
     * removal overwrote before that was fixed still counts, whether or not the central repair has run.
     *
     * @return Builder<ResidentAccount>
     */
    public static function revokedAccounts(string $tenantId): Builder
    {
        return ResidentAccount::query()
            ->where('tenant_id', $tenantId)
            ->where(static fn (Builder $revoked) => $revoked->where('status', ResidentAccount::REVOKED)->orWhereNotNull('revoked_at'));
    }

    /**
     * RECORDING A REFUSAL ON THE ROW (G7): the row a screen describes says what happened, where a swallowed throw
     * would have left "no invite yet" on the phone and "recorded but never sent" at the office.
     *
     * `$sentence` is what the APP may see — the generic one for an address refusal, the cap's own sentence for
     * the daily cap. `$code` is the office-only reason. `$notLive` (an address refusal, a stale offline add)
     * clears `expires_at`, so the row is never live (`isLive`) and reads `not_sent`, not `expired`. `attempts`
     * and `sent_at` are untouched: nothing was tried, and an earlier accepted send stays recorded.
     */
    public function recordNotSent(Resident $resident, string $sentence, ?string $code, bool $notLive = true): ResidentInvite
    {
        $unitId = (int) ($resident->household->unit_id ?? 0);
        $invite = $this->openFor($resident) ?? new ResidentInvite(['resident_id' => $resident->id, 'unit_id' => $unitId, 'channels' => 'email']);

        $fill = [
            'email' => ($email = strtolower(trim((string) $resident->email))) === '' ? null : $email,
            'last_error' => $sentence,
            'last_attempt_at' => Carbon::now(),
            'refused_reason' => $code,
        ];

        if (! $invite->exists) {
            $fill['token'] = (string) Str::uuid();
        }

        if ($notLive) {
            $fill['expires_at'] = null;
        }

        $invite->forceFill($fill)->save();

        return $invite;
    }

    /**
     * `outcome()` as the OFFICE reads it: the same keys, plus `office_reason` — the real reason an invite that did
     * not go was refused, or null. The app's `memberShape` never carries it (DESIGN INV-14).
     *
     * @return array<string, mixed>
     */
    public static function officeOutcome(?ResidentInvite $invite): array
    {
        $outcome = self::outcome($invite);

        return [...$outcome, 'office_reason' => $outcome['status'] === 'not_sent' ? self::officeReason($invite) : null];
    }

    /** What the OFFICE reads about an invite that did not go: the stored reason in words, else the last error. */
    public static function officeReason(?ResidentInvite $invite): ?string
    {
        if ($invite === null) {
            return null;
        }

        if ($invite->refused_reason !== null && isset(self::OFFICE_REASONS[$invite->refused_reason])) {
            return self::OFFICE_REASONS[$invite->refused_reason];
        }

        return $invite->last_error;
    }

    /**
     * An address refusal, recorded and thrown: the app is told the generic sentence, the office the real one
     * (in the flash, from the exception), and the audit holds the reason.
     *
     * @param  array<string, mixed>  $extra
     *
     * @throws InviteAddressRefused
     */
    private function refuse(Resident $resident, Unit $unit, string $email, InviteAddressRefused $refused, bool $appPath, string $by, ?User $byUser, string $tenantId, array $extra = []): never
    {
        $this->recordNotSent($resident, InviteAddressRefused::GENERIC, $refused->reason);

        $this->audit->record(
            action: 'resident.invite_refused',
            entityType: 'Resident',
            entityId: (string) $resident->id,
            after: ['reason' => $refused->reason, 'email' => $email, 'by' => $by, 'app' => $appPath, 'unit_id' => $unit->id, ...$extra],
            tenantId: $tenantId,
            actor: $byUser,
        );

        throw $refused;
    }

    /**
     * Whether this address may be invited for this person, or the refusal (G3, DESIGN INV-14, INV-15):
     *
     *   in_use            an ACTIVE account here signs in with it, linked to somebody else
     *   other_household   another household's live invite is to it
     *   revoked_account   a revoked account here has it, or this person is linked to one
     *   revoked_name      this person's name is the name of somebody the office cut off at this unit
     *
     * The accounts that could refuse it are read in ONE query — those at this address, this person's own, and
     * the revoked ones at this unit — and the register's names only when this unit has a revoked account.
     *
     * @param  Collection<int, ResidentInvite>  $open  this address's open invites here, each with `household_of`
     */
    private function addressRefusal(Resident $resident, string $email, Unit $unit, string $tenantId, Collection $open): ?InviteAddressRefused
    {
        $address = ResidentSignIn::normalise('email', $email);

        $accounts = ResidentAccount::query()
            ->where('tenant_id', $tenantId)
            ->where(static fn (Builder $q) => $q
                ->where(static fn (Builder $at) => $at->where('channel', 'email')->where('destination', $address))
                ->orWhere('resident_id', $resident->id)
                ->orWhere(static fn (Builder $here) => $here
                    ->where('unit_id', $unit->id)
                    ->where(static fn (Builder $revoked) => $revoked->where('status', ResidentAccount::REVOKED)->orWhereNotNull('revoked_at'))))
            ->get();

        // "Revoked" is stage 2's one rule (`revokedAccounts`): the status, or the date the office ended it.
        $isRevoked = static fn (ResidentAccount $a): bool => $a->status === ResidentAccount::REVOKED || $a->revoked_at !== null;
        $atAddress = static fn (ResidentAccount $a): bool => $a->channel === 'email' && strtolower((string) $a->destination) === strtolower($address);

        $inUse = $accounts->contains(static fn (ResidentAccount $a): bool => $atAddress($a)
            && $a->status === ResidentAccount::ACTIVE
            && $a->resident_id !== null
            && (int) $a->resident_id !== (int) $resident->id);

        if ($inUse) {
            return new InviteAddressRefused('in_use', sprintf('%s already signs in to the Resident App for another home here, so it cannot be invited for %s. Use their own address.', $email, $resident->full_name));
        }

        $elsewhere = $open->contains(static fn (ResidentInvite $live): bool => $live->isLive()
            && (int) $live->resident_id !== (int) $resident->id
            && (int) $live->getAttribute('household_of') !== (int) $resident->household_id);

        if ($elsewhere) {
            return new InviteAddressRefused('other_household', sprintf('Another household here has a live invite to %s. Cancel that one first, or use a different address for %s.', $email, $resident->full_name));
        }

        $revoked = $accounts->contains(static fn (ResidentAccount $a): bool => $isRevoked($a)
            && ($atAddress($a) || (int) $a->resident_id === (int) $resident->id));

        if ($revoked) {
            return new InviteAddressRefused('revoked_account', sprintf('The estate office ended app access for %s. Give it back on their row instead of inviting them again.', $resident->full_name));
        }

        $revokedHere = $accounts->contains(static fn (ResidentAccount $a): bool => $isRevoked($a)
            && $a->deleted_at === null
            && (int) $a->unit_id === (int) $unit->id);

        if (! $revokedHere) {
            return null;
        }

        $name = self::comparableName((string) $resident->full_name);

        foreach (RevokeResidentAccess::revokedPeopleByUnit($tenantId, [(int) $unit->id])[(int) $unit->id] ?? [] as $person) {
            if (self::comparableName($person['name']) === $name) {
                return new InviteAddressRefused('revoked_name', sprintf(
                    'App access for %s at %s was ended by the estate office%s. Give it back on their row or, if this is a different person, add them with "Estate verifies" instead.',
                    $person['name'],
                    $unit->reference,
                    $person['revoked_at'] === null ? '' : ' on '.SiteTime::local($person['revoked_at'], $tenantId)->format('j M Y'),
                ));
            }
        }

        return null;
    }

    /**
     * WHAT THE CLAIMS BOARD SAYS BESIDE EACH CLAIM (stage 2, DESIGN §7.3, G3, G8) — one batched read, keyed by
     * claim id, for the pending claims board 31 draws:
     *
     *   invited          a claim made from an invite: who invited, when, from where, the access the register entry
     *                    holds, the masked address proven, and the approve button's own words; null otherwise
     *   access           the level the approval will give — that of the register entry it will bind to (the invite's
     *                    person; else the household's member of exactly the submitted name, `approveClaim`'s own
     *                    rule), or Member for a new entry — and whether it is fixed because it is the primary's
     *   revoked_name_on  the date the office ended app access for a person of the submitted name at this unit, or
     *                    null (G3: a revoked person asking again under a new address is checked before approval)
     *
     * @param  iterable<UnitClaim>  $claims  loaded with `unit.household.residents` and `household.residents`
     * @return array<int, array{invited: array<string, mixed>|null, access: array{level: string, label: string, locked: bool, new_entry: bool, open: bool}, revoked_name_on: string|null}>
     */
    public static function claimNotes(iterable $claims): array
    {
        $claims = collect($claims);

        if ($claims->isEmpty()) {
            return [];
        }

        $tenantId = (string) tenant()?->getTenantKey();

        $invites = ResidentInvite::query()
            ->whereIn('id', $claims->pluck('invite_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $invited = Resident::query()->whereIn('id', $invites->pluck('resident_id')->filter()->all())->get()->keyBy('id');

        $revoked = RevokeResidentAccess::revokedPeopleByUnit(
            $tenantId,
            $claims->pluck('unit_id')->filter()->map(static fn ($id): int => (int) $id)->all(),
        );

        $notes = [];

        foreach ($claims as $claim) {
            /** @var UnitClaim $claim */
            $household = $claim->household ?? $claim->unit?->household;
            $invite = $claim->invite_id === null ? null : $invites->get($claim->invite_id);

            // The entry the approval binds to, by `approveClaim`'s own rule.
            $entry = null;

            if ($invite !== null) {
                $person = $invited->get($invite->resident_id);
                $entry = $person !== null && $household !== null && (int) $person->household_id === (int) $household->id ? $person : null;
            } elseif ($household !== null) {
                $entry = $household->residents->first(
                    static fn (Resident $r): bool => strcasecmp(trim($r->full_name), trim((string) $claim->submitted_name)) === 0,
                );
            }

            // An invite whose person has left the household binds to nobody: `approveClaim` refuses it in words.
            $gone = $invite !== null && $entry === null;
            $newEntry = $entry === null && ! $gone;
            $locked = match (true) {
                $gone => true,
                $newEntry => $household === null || $household->residents->doesntContain(static fn (Resident $r): bool => (bool) $r->is_primary),
                default => (bool) $entry?->is_primary,
            };
            $level = $entry === null ? AppMatrix::MEMBER : AccessLevels::of($entry);

            $notes[(int) $claim->id] = [
                'invited' => $invite === null ? null : self::claimBadge($invite, $entry),
                'access' => [
                    'level' => $level,
                    'label' => match (true) {
                        $gone => 'None: the invited person is no longer in this household',
                        $locked => 'Member (primary)',
                        $newEntry => 'Member · a new register entry',
                        default => AccessLevels::label($level),
                    },
                    'locked' => $locked,
                    'new_entry' => $newEntry,
                    // Whether Dependent and Helper may be given yet (`AccessLevels::open`).
                    'open' => AccessLevels::open(),
                ],
                'revoked_name_on' => self::revokedNameOn($revoked[(int) $claim->unit_id] ?? [], (string) $claim->submitted_name, $tenantId),
            ];
        }

        return $notes;
    }

    /**
     * The "Invited" block on a claim card, every sentence made here so the page derives nothing.
     *
     * @return array{by: string, via: string, on: string, by_line: string, level_label: string|null, hint: string|null, proof_line: string, approve_label: string}
     */
    private static function claimBadge(ResidentInvite $invite, ?Resident $entry): array
    {
        $viaPrimary = $invite->invited_by_resident_id !== null;
        $by = $viaPrimary ? (string) $invite->invited_by_name : 'the estate office';
        // Law 5 as decided (M62 A5, DR-039): "9 Oct", as the rest of the claims card writes a date.
        $on = SiteTime::local($invite->last_attempt_at ?? $invite->created_at ?? Carbon::now())->format('j M');
        $hint = $invite->email === null ? null : ResidentSignIn::mask((string) $invite->email);

        return [
            'by' => $by,
            'via' => $viaPrimary ? 'primary' : 'office',
            'on' => $on,
            'by_line' => 'Invited by '.$by.($viaPrimary ? ' (primary)' : '').' on '.$on,
            'level_label' => $entry === null ? null : AccessLevels::label(AccessLevels::of($entry)),
            'hint' => $hint,
            'proof_line' => 'Signed in with the invited address'.($hint === null ? '' : ', '.$hint).', proven by a one-time code',
            'approve_label' => 'Approve · invited by '.$by,
        ];
    }

    /**
     * When app access for a person of this name at this unit was ended, as the site's date, or null.
     *
     * @param  list<array{name: string, revoked_at: Carbon|null}>  $people
     */
    private static function revokedNameOn(array $people, string $submittedName, string $tenantId): ?string
    {
        $name = self::comparableName($submittedName);

        foreach ($people as $person) {
            if (self::comparableName($person['name']) === $name) {
                return $person['revoked_at'] === null ? 'an earlier date' : SiteTime::local($person['revoked_at'], $tenantId)->format('j M Y');
            }
        }

        return null;
    }

    /** A name as it is compared: trimmed, single-spaced and without case. */
    public static function comparableName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
