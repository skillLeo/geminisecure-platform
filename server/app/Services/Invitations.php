<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvitationSent;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\RoleEditor;
use App\Support\InviteMail;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issuing accounts. Ruled (12 §2, Wave 1): an invitation names inviter,
 * tenant, role, and expires in 14 days. Without it no committee can be
 * onboarded.
 *
 * THERE IS NO OTHER WAY IN. The platform has no registration route, and this
 * is why it does not need one: an account exists because somebody holding
 * Settings create on an estate — or, for Gemini staff, a platform role — asked
 * for it by name, and the person asked chose a password on a link only they
 * received. Every step is audited against the estate.
 *
 * ONE PERSON, ONE ESTATE. A committee member lives inside exactly one estate
 * (`User::canAccessEstate`), so an address that already holds an account
 * anywhere on the platform cannot be invited to a second estate: the refusal
 * says so rather than quietly making a user who could reach two communities.
 *
 * THE TOKEN IS SHOWN TO NOBODY BUT ITS RECIPIENT. It is the credential until
 * it is accepted; the users screen shows that an invitation is pending, who
 * sent it and when it lapses, and never the link.
 *
 * "SENT" MEANS THE MAIL SERVER TOOK IT (DESIGN §11 rows 1, 2 and 5). Every email
 * goes through `InviteMail`, the one delivery path resident invites use too, after
 * the row is committed; the row records every try (`sent_at`, `attempts`,
 * `last_attempt_at`, `last_error`), and a refusal is `InvitationNotSent`, which
 * the screens show in the invitation's own row. A resend changes the link only
 * when it goes.
 */
class Invitations
{
    /** A resend waits this long after the last try — the resident sign-in code's minute (`ResidentSignIn::RESEND_SECONDS`). */
    public const RESEND_SECONDS = 60;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoleEditor $roles,
    ) {}

    /**
     * Invite somebody to hold a role on an estate.
     *
     * ANY ROLE THE INVITING CONSOLE CAN SEE, AND NO OTHER (M10 §4). An estate
     * invites into the roles every community shares or into one of its own; the
     * platform into its own. A role another estate made is refused here — the
     * service, not only the screen's list — because the controller resolves the
     * role by name and a name can be typed. And a role that can change roles is
     * handed out only by somebody who holds that power themselves.
     *
     * @param  string|null  $tenantId  the estate, or null for Gemini staff
     */
    public function invite(string $email, Role $role, ?string $tenantId, User $by): Invitation
    {
        $email = strtolower(trim($email));

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            throw new DomainException($this->alreadyHeld($existing, $tenantId));
        }

        if ($tenantId !== null && $role->console !== Console::Estate) {
            throw new DomainException('Only an estate role can be held on an estate. '.$role->label.' is a Gemini role.');
        }

        if ($tenantId === null && $role->console !== Console::Gemini) {
            throw new DomainException('An estate role has to be invited to an estate.');
        }

        if ($role->tenant_id !== null && $role->tenant_id !== $tenantId) {
            throw new DomainException($tenantId === null ? RoleEditor::NOT_HERE_GEMINI : RoleEditor::NOT_HERE_ESTATE);
        }

        $this->roles->assertMayHandOut($by, $role);

        $pending = Invitation::query()
            ->where('email', $email)
            ->where('tenant_id', $tenantId)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($pending !== null) {
            throw new DomainException(sprintf(
                'An invitation to %s is already open until %s. Resend it rather than sending a second one.',
                $email,
                $pending->expires_at->format('M j'),
            ));
        }

        return $this->firstSend($this->issue($email, $role, $tenantId, $by));
    }

    /**
     * The row and its `user.invited` entry, together or not at all — and nothing sent yet.
     *
     * THE EMAIL GOES AFTER THIS COMMITS (`firstSend`). It used to go inside the transaction, so a relay that
     * refused it rolled the invitation back and answered with a server error page: nothing was listed, nothing
     * could be resent, and the log did not say anybody had tried.
     *
     * THE FIRST TRY IS CLAIMED AS THE ROW IS WRITTEN (`attempts` 1, `last_attempt_at`), the way a resend claims its
     * minute before it sends. So a Resend pressed in another tab while this email is still going waits its minute
     * like any other instead of sending a second; a row whose send was cut off reads "tried, not recorded" rather
     * than "sent before delivery was recorded"; and `attempts` 0 means exactly one thing — a row written before
     * delivery was recorded.
     *
     * ONE OPEN INVITATION PER ONBOARDING CONTACT, EVEN FOR TWO PRESSES AT ONCE. For the onboarding path (`$contact`)
     * the contact's own account row is locked first and the open-invitation check is asked again under that lock:
     * two "Send invitation" presses — two staff, two tabs — used to both find none and each email its own link.
     * The second now waits for the first to commit, finds it, and is refused in words.
     *
     * @param  array<string, scalar|null>  $via  what else the audit entry should say about where this came from
     *
     * @throws DomainException when, under the contact's lock, an invitation to them is already open
     */
    private function issue(string $email, Role $role, ?string $tenantId, User $by, array $via = [], ?User $contact = null): Invitation
    {
        return DB::connection('mysql')->transaction(function () use ($email, $role, $tenantId, $by, $via, $contact): Invitation {
            if ($contact !== null && $tenantId !== null) {
                User::query()->whereKey($contact->id)->lockForUpdate()->first();

                if ($this->openFor($email, $tenantId) !== null) {
                    throw new DomainException(self::alreadyOpenForContact($email));
                }
            }

            $now = Carbon::now();

            $invitation = new Invitation;
            $invitation->forceFill([
                'email' => $email,
                'token' => Str::random(64),
                'invited_by' => $by->id,
                'role_id' => $role->id,
                'tenant_id' => $tenantId,
                'expires_at' => $now->copy()->addDays(Invitation::DAYS),
                'attempts' => 1,
                'last_attempt_at' => $now,
            ])->save();

            $this->audit->record(
                action: 'user.invited',
                entityType: 'Invitation',
                entityId: (string) $invitation->id,
                after: ['email' => $email, 'role' => $role->name, 'expires_at' => $invitation->expires_at->toIso8601String(), ...$via],
                tenantId: $tenantId,
            );

            return $invitation;
        });
    }

    /**
     * Email a new invitation, and record what happened on its row.
     *
     * `sent_at` only when the mail server accepted it. A refusal leaves the invitation standing — listed, with
     * Resend — and is thrown as `InvitationNotSent`, so the screen says it did not go instead of "sent".
     *
     * @throws InvitationNotSent
     */
    private function firstSend(Invitation $invitation): Invitation
    {
        $error = $this->deliver($invitation);

        // The try was claimed as the row was written (`issue`); `sent_at` is that try's instant, as a resend's is.
        $invitation->forceFill([
            'sent_at' => $error === null ? ($invitation->last_attempt_at ?? Carbon::now()) : null,
            'last_error' => $error,
        ])->save();

        $this->audit->record(
            action: $error === null ? 'user.invitation_sent' : 'user.invitation_send_failed',
            entityType: 'Invitation',
            entityId: (string) $invitation->id,
            after: ['email' => $invitation->email, 'attempt' => $invitation->attempts, 'reason' => $error],
            tenantId: $invitation->tenant_id,
        );

        if ($error !== null) {
            throw new InvitationNotSent($invitation, $error, resend: false);
        }

        return $invitation;
    }

    /** The one delivery path every invitation takes (`InviteMail`). The refusal in words, or null when it went. */
    private function deliver(Invitation $invitation): ?string
    {
        return InviteMail::deliver($invitation->email, new InvitationSent($invitation), ['invitation' => $invitation->id]);
    }

    /**
     * Why an address that already holds an account cannot be invited, and where
     * the act the inviter actually wants lives.
     *
     * THE SENTENCE MUST POINT AT A SCREEN THE PERSON IS ON. It used to send every
     * inviter to "change their role from Users & roles" — including one inviting
     * back a Treasurer they had removed, who is not among the committee there,
     * and a Gemini director inviting an address that has nothing to do with any
     * estate's Users & roles. So an estate is told what is true of ITS OWN
     * committee — this person is on it, or was taken off it and can be
     * reinstated — and anything else, about another estate or Gemini's staff,
     * in one sentence that names neither.
     */
    private function alreadyHeld(User $existing, ?string $tenantId): string
    {
        if ($tenantId !== null && $existing->console === Console::Estate) {
            $here = EstateAssignment::query()
                ->where('user_id', $existing->id)
                ->where('tenant_id', $tenantId)
                ->whereHas('role', static fn ($role) => $role->where('console', Console::Estate->value))
                ->first();

            if ($here !== null && $here->is_active) {
                return 'That address is already on this estate\'s committee. Change their role from Users & roles '.
                    'rather than inviting them again.';
            }

            $elsewhere = EstateAssignment::query()
                ->where('user_id', $existing->id)
                ->where('is_active', true)
                ->exists();

            if ($here !== null && ! $elsewhere) {
                return 'That address belongs to somebody taken off this estate\'s committee. Reinstate them from '.
                    'Users & roles, under Taken off the committee, rather than inviting them again.';
            }
        }

        return 'That address already holds an account on this platform, and a person belongs to one console and '.
            'one estate, so it cannot be invited again.';
    }

    /**
     * Send it again, with a fresh token and a fresh fortnight — ONLY IF IT GOES.
     *
     * One open credential per invitation, never two and never none. The new token and expiry are put on the
     * invitation IN MEMORY, so the email carries them, and saved only once the mail server has accepted it: the
     * old link stops working at that moment and not before. This used to save the new token, record "resent",
     * and then send — so a relay that refused it killed the link the person already held, left a log saying it
     * had gone, and answered with a server error page. Now a refusal puts the old token and expiry back, records
     * the try and why it failed, and says so (`InvitationNotSent`): the link they already have still works.
     *
     * Not twice in a minute. The minute is claimed in one conditional UPDATE, so two presses at once cannot both
     * send, and the refusal names the time it can go again — in words that say what the last try did
     * (`lastTry`): "sent moments ago" only when it went, never under a row that reads "Could not send". The claim
     * also clears the last refusal, so while this try is still going nobody is told it failed.
     *
     * @throws DomainException when it was accepted, or was tried moments ago
     * @throws InvitationNotSent when it was tried and did not go
     */
    public function resend(Invitation $invitation, User $by): Invitation
    {
        if ($invitation->accepted_at !== null) {
            throw new DomainException('This invitation has been accepted. The account exists; there is nothing to resend.');
        }

        $now = Carbon::now();

        /*
         * WHAT THE PERSON HOLDS BEFORE THIS TRY, for the words if it fails — read BEFORE the claim below, which
         * clears the last refusal. `sent_at` is set only when the mail server took an email carrying the stored
         * link. With none set: `attempts` 0 is a row written before delivery was recorded; a recorded refusal on
         * the last try means no email has ever gone and they hold no link; anything else is a try that was cut
         * off before its outcome was written, which nobody can answer for.
         */
        $before = Invitation::query()->whereKey($invitation->id)->first(['id', 'sent_at', 'attempts', 'last_error']);
        $earlier = match (true) {
            $before?->sent_at !== null => InvitationNotSent::EARLIER_EMAILED,
            (int) ($before->attempts ?? 0) === 0 => InvitationNotSent::EARLIER_UNRECORDED,
            $before->last_error !== null => InvitationNotSent::EARLIER_NONE,
            default => InvitationNotSent::EARLIER_UNRECORDED,
        };

        $claimed = Invitation::query()
            ->whereKey($invitation->id)
            ->whereNull('accepted_at')
            ->where(static fn ($query) => $query->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', $now->copy()->subSeconds(self::RESEND_SECONDS)))
            ->increment('attempts', 1, ['last_attempt_at' => $now, 'last_error' => null, 'updated_at' => $now]);

        $invitation->refresh();

        if ($claimed === 0) {
            if ($invitation->accepted_at !== null) {
                throw new DomainException('This invitation has been accepted. The account exists; there is nothing to resend.');
            }

            throw new DomainException(sprintf(
                match (self::lastTry($invitation)) {
                    'sent' => 'The invitation to %s was sent moments ago. You can send it again at %s.',
                    'not_sent' => 'The invitation to %s was tried moments ago and did not go. You can try again at %s.',
                    default => 'The invitation to %s was tried moments ago. You can try again at %s.',
                },
                $invitation->email,
                SiteTime::fmt(SiteTime::local(self::resendAt($invitation) ?? $now, $invitation->tenant_id), 'g:i A'),
            ));
        }

        $keep = ['token' => $invitation->token, 'expires_at' => $invitation->expires_at];

        $invitation->forceFill(['token' => Str::random(64), 'expires_at' => $now->copy()->addDays(Invitation::DAYS)]);

        $error = $this->deliver($invitation);

        if ($error === null) {
            $invitation->forceFill(['sent_at' => $now, 'last_error' => null])->save();

            $this->audit->record(
                action: 'user.invitation_resent',
                entityType: 'Invitation',
                entityId: (string) $invitation->id,
                after: ['email' => $invitation->email, 'expires_at' => $invitation->expires_at->toIso8601String(), 'attempt' => $invitation->attempts],
                tenantId: $invitation->tenant_id,
            );

            return $invitation;
        }

        // Nothing went: the link they already have is the one that stands, exactly as it stood.
        $invitation->forceFill([...$keep, 'last_error' => $error])->save();

        $this->audit->record(
            action: 'user.invitation_resend_failed',
            entityType: 'Invitation',
            entityId: (string) $invitation->id,
            after: ['email' => $invitation->email, 'attempt' => $invitation->attempts, 'reason' => $error],
            tenantId: $invitation->tenant_id,
        );

        throw new InvitationNotSent($invitation, $error, resend: true, earlier: $earlier);
    }

    /**
     * What the LAST try did, from the row: `sent` when the mail server took it, `not_sent` when it was refused,
     * `unrecorded` when nothing is recorded yet (it is still going, it was cut off, or the row predates the
     * record). The resend refusal and the screens' Resend tooltip both say it, so a row reading "Could not send"
     * is never told "it was just sent".
     */
    public static function lastTry(Invitation $invitation): string
    {
        return match (true) {
            $invitation->last_attempt_at === null => 'unrecorded',
            $invitation->sent_at !== null && $invitation->sent_at->greaterThanOrEqualTo($invitation->last_attempt_at) => 'sent',
            $invitation->last_error !== null => 'not_sent',
            default => 'unrecorded',
        };
    }

    /** When a resend may go again, or null when it may go now. */
    public static function resendAt(Invitation $invitation): ?Carbon
    {
        if ($invitation->last_attempt_at === null) {
            return null;
        }

        $at = $invitation->last_attempt_at->copy()->addSeconds(self::RESEND_SECONDS);

        return $at->isFuture() ? $at : null;
    }

    /**
     * What the invitation lists say about its email, in the row: sent and when, or not and why.
     *
     *   sent         "Emailed Sep 24, 3:42 PM"
     *   not_set_up   "Not sent: Mail is not set up on this platform yet, …"
     *   not_sent     "Could not send: The relay refused …", or "Could not send it again: … Last emailed Sep 24, …"
     *   unrecorded   "Sent Sep 20, before delivery was recorded. Resend it if it never arrived."
     *   unknown      tried, and what happened was not recorded (the send was cut off)
     *
     * Times on the estate's clock for an estate's invitation, on the platform's for Gemini staff. `last_try` is
     * what the last try did (`lastTry`), for the words beside a Resend that is waiting out its minute.
     *
     * @return array{delivery: string, delivery_state: string, last_try: string, sent_at: string|null, resend_after: string|null}
     */
    public static function deliveryShape(Invitation $invitation): array
    {
        $at = static fn (Carbon $instant): string => SiteTime::fmt(SiteTime::local($instant, $invitation->tenant_id), 'M j, g:i A');

        [$state, $sentence] = match (true) {
            $invitation->last_error !== null && $invitation->last_error === InviteMail::NOT_SET_UP => ['not_set_up', 'Not sent: '.$invitation->last_error],
            $invitation->last_error !== null && $invitation->sent_at !== null => ['not_sent', 'Could not send it again: '.rtrim($invitation->last_error, '.').'. Last emailed '.$at($invitation->sent_at).'.'],
            $invitation->last_error !== null => ['not_sent', 'Could not send: '.$invitation->last_error],
            $invitation->sent_at !== null => ['sent', 'Emailed '.$at($invitation->sent_at)],
            // A full stop where an em dash was (M53 law 3; the Settings pass, 12 Oct): both consoles' lists read these.
            (int) $invitation->attempts === 0 => ['unrecorded', 'Sent '.($invitation->created_at === null ? '' : SiteTime::local($invitation->created_at, $invitation->tenant_id)->format('M j').', ').'before delivery was recorded. Resend it if it never arrived.'],
            default => ['unknown', 'Tried '.($invitation->last_attempt_at === null ? '' : $at($invitation->last_attempt_at).' ').'and whether it went was not recorded. Resend it if it never arrived.'],
        };

        return [
            'delivery' => $sentence,
            'delivery_state' => $state,
            'last_try' => self::lastTry($invitation),
            'sent_at' => SiteTime::iso($invitation->sent_at),
            'resend_after' => SiteTime::iso(self::resendAt($invitation)),
        ];
    }

    /**
     * Withdraw it. The row goes: an unaccepted invitation is not a record of
     * anything, and the audit entry is what remembers it was sent.
     */
    public function revoke(Invitation $invitation, User $by): void
    {
        if ($invitation->accepted_at !== null) {
            throw new DomainException('This invitation has been accepted. Suspend the account from Users & roles instead.');
        }

        $this->audit->record(
            action: 'user.invitation_revoked',
            entityType: 'Invitation',
            entityId: (string) $invitation->id,
            before: ['email' => $invitation->email, 'role' => $invitation->role->name],
            tenantId: $invitation->tenant_id,
        );

        $invitation->delete();
    }

    /** The invitation this token opens, or null when it opens nothing. */
    public function find(string $token): ?Invitation
    {
        return Invitation::query()->with(['inviter', 'role'])->where('token', $token)->first();
    }

    /**
     * Accept: the account comes into existence here and nowhere else.
     *
     * Active from the first moment, because the person chose the password
     * themselves on the link only they received; there is no second
     * confirmation to wait for. The assignment binds them to the estate and
     * the role decides what they may do there.
     */
    public function accept(Invitation $invitation, string $name, string $password): User
    {
        if (! $invitation->isPending()) {
            throw new DomainException(
                $invitation->accepted_at !== null
                    ? 'This invitation has already been accepted. Sign in with the password you chose.'
                    : 'This invitation has expired. Ask whoever invited you to send it again.'
            );
        }

        $existing = User::query()->where('email', $invitation->email)->first();

        if ($existing !== null) {
            /*
             * THE ONE ACCOUNT AN INVITATION MAY FINISH RATHER THAN CREATE (§11 row 5). Onboarding a client makes
             * its contact an Estate Console account in `invited` standing, assigned to the new estate, with a
             * random password nobody knows — and then sent nothing, so the contact could never get in: sign-in
             * refuses an account that is not active, and this refused an address that has an account. Now the
             * onboarding sends this invitation, and accepting it activates exactly that account. Anybody else
             * with an account — active, suspended, another estate's, Gemini's — is refused as before.
             */
            if (self::isOnboardingContact($existing, $invitation)) {
                return $this->activateOnboardingContact($invitation, $existing, $name, $password);
            }

            throw new DomainException('An account already exists for this address. Sign in, or ask for a password reset.');
        }

        return DB::connection('mysql')->transaction(function () use ($invitation, $name, $password): User {
            $role = $invitation->role;

            $user = $this->createAccount($invitation->email, $name, $password, $role);

            if ($invitation->tenant_id !== null) {
                EstateAssignment::create([
                    'user_id' => $user->id,
                    'tenant_id' => $invitation->tenant_id,
                    'role_id' => $role->id,
                    'is_active' => true,
                ]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit->record(
                action: 'user.invitation_accepted',
                entityType: 'User',
                entityId: (string) $user->id,
                after: ['email' => $user->email, 'role' => $role->name],
                tenantId: $invitation->tenant_id,
            );

            return $user;
        });
    }

    /**
     * The client's contact, invited — right after onboarding makes them (§11 row 5, DESIGN §11).
     *
     * `ClientOnboarding::start` makes the contact an Estate Console account in `invited` standing with an active
     * assignment to the new estate, and nothing ever reached them: the checklist said "Estate admin invited" the
     * moment a contact existed. This is the invitation that account was waiting for — the role of its
     * assignment, the new estate, fourteen days — delivered like every other (`firstSend`), and accepted by the
     * branch in `accept()` that activates that one account. Only for a user who IS that: `invited`, on the
     * Estate Console, assigned to this estate. `ClientOnboarding` itself is untouched (B001).
     *
     * @throws DomainException when the user is not this estate's invited contact, or an invitation is already open
     * @throws InvitationNotSent when it was saved and the email did not go
     */
    public function inviteOnboardingContact(Tenant $estate, User $contact, User $by): Invitation
    {
        $assignment = self::onboardingAssignment($contact, (string) $estate->getTenantKey());

        if ($assignment === null) {
            throw new DomainException(sprintf('%s is not %s\'s invited contact, so there is no onboarding invitation to send them.', $contact->email, $estate->name));
        }

        $email = strtolower(trim((string) $contact->email));

        // Asked here without a lock, for the plain case, and again under the contact's lock in `issue`, for two at once.
        if ($this->openFor($email, (string) $estate->getTenantKey()) !== null) {
            throw new DomainException(self::alreadyOpenForContact($email));
        }

        return $this->firstSend($this->issue($email, $assignment->role, (string) $estate->getTenantKey(), $by, ['via' => 'client_onboarding'], $contact));
    }

    private static function alreadyOpenForContact(string $email): string
    {
        return sprintf('An invitation to %s is already open. Resend it rather than sending a second one.', $email);
    }

    /**
     * The client page's "Send invitation" / "Resend invitation" for its contact: the first one when none was ever
     * sent — every client onboarded before this release — and otherwise a resend of the one standing, under the
     * resend's own rules (a fresh link only if it goes, not twice in a minute).
     *
     * @throws DomainException when the estate has no invited contact, or the resend is refused
     * @throws InvitationNotSent when it was tried and did not go
     */
    public function sendToOnboardingContact(Tenant $estate, User $by): Invitation
    {
        $contact = self::onboardingContact((string) $estate->getTenantKey());

        if ($contact === null) {
            throw new DomainException(sprintf('%s has no contact still waiting for an invitation: whoever it was has already signed in, or was never added.', $estate->name));
        }

        $open = $this->openFor((string) $contact->email, (string) $estate->getTenantKey());

        return $open === null ? $this->inviteOnboardingContact($estate, $contact, $by) : $this->resend($open, $by);
    }

    /**
     * What the client page says about its contact's invitation, or null when there is no contact waiting for one.
     *
     * @return array{name: string, email: string, invitation_id: int|null, delivery: string, delivery_state: string, last_try: string|null, sent_at: string|null, resend_after: string|null, expires_on: string|null, action: string}|null
     */
    public static function onboardingContactShape(string $tenantId): ?array
    {
        $contact = self::onboardingContact($tenantId);

        if ($contact === null) {
            return null;
        }

        $invitation = Invitation::query()->where('email', $contact->email)->where('tenant_id', $tenantId)->whereNull('accepted_at')->latest('id')->first();

        if ($invitation === null) {
            return [
                'name' => (string) $contact->name, 'email' => (string) $contact->email, 'invitation_id' => null,
                'delivery' => 'No invitation has been sent to them yet, so they cannot sign in.', 'delivery_state' => 'none', 'last_try' => null,
                'sent_at' => null, 'resend_after' => null, 'expires_on' => null, 'action' => 'Send invitation',
            ];
        }

        return [
            'name' => (string) $contact->name,
            'email' => (string) $contact->email,
            'invitation_id' => $invitation->id,
            ...self::deliveryShape($invitation),
            'expires_on' => $invitation->expires_at->format('M j, Y'),
            'action' => 'Resend invitation',
        ];
    }

    /** The estate's contact still waiting to be let in: `invited`, on the Estate Console, actively assigned there. */
    private static function onboardingContact(string $tenantId): ?User
    {
        return User::query()
            ->where('status', 'invited')
            ->where('console', Console::Estate->value)
            ->whereHas('assignments', static fn ($query) => $query->where('tenant_id', $tenantId)->where('is_active', true))
            ->orderBy('id')
            ->first();
    }

    private static function onboardingAssignment(User $user, string $tenantId): ?EstateAssignment
    {
        if ($user->status !== 'invited' || $user->console !== Console::Estate) {
            return null;
        }

        return EstateAssignment::query()
            ->with('role')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereHas('role', static fn ($query) => $query->where('console', Console::Estate->value))
            ->orderBy('id')
            ->first();
    }

    private static function isOnboardingContact(User $user, Invitation $invitation): bool
    {
        return $invitation->tenant_id !== null && self::onboardingAssignment($user, $invitation->tenant_id) !== null;
    }

    /** An open invitation to this address for this estate — not accepted, whether or not it has lapsed. */
    private function openFor(string $email, string $tenantId): ?Invitation
    {
        return Invitation::query()->where('email', strtolower(trim($email)))->where('tenant_id', $tenantId)->whereNull('accepted_at')->latest('id')->first();
    }

    /**
     * The contact chose their name and password on the link only they received: their account is active from
     * this moment, verified, and holds the invitation's role on the estate it is already assigned to.
     */
    private function activateOnboardingContact(Invitation $invitation, User $user, string $name, string $password): User
    {
        return DB::connection('mysql')->transaction(function () use ($invitation, $user, $name, $password): User {
            $role = $invitation->role;

            $user->forceFill([
                'name' => trim($name),
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
            ])->save();

            $user->syncRoles([$role->name]);

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit->record(
                action: 'user.invitation_accepted',
                entityType: 'User',
                entityId: (string) $user->id,
                after: ['email' => $user->email, 'role' => $role->name, 'via' => 'client_onboarding'],
                tenantId: $invitation->tenant_id,
            );

            return $user;
        });
    }

    /**
     * The platform's FIRST Director, on a platform that has no Gemini staff at all (M7).
     *
     * THE ONE ACCOUNT NOBODY CAN INVITE. Every account is an accepted invitation, and an
     * invitation names the person who sent it — so a freshly deployed production server, with
     * no staff yet, has nobody who could send the first one. This makes exactly that one
     * account, through the same steps `accept()` takes (active, verified, one role), and then
     * refuses for ever: the moment any Gemini account exists, the next Director is invited from
     * Platform admins like everybody else.
     *
     * The password is the operator's to choose and is never shown here; `platform:first-director`
     * reads it from standard input so it reaches neither a command line nor a log.
     */
    public function bootstrapFirstDirector(string $email, string $name, string $password): User
    {
        $email = strtolower(trim($email));

        if (User::query()->where('console', Console::Gemini->value)->exists()) {
            throw new DomainException(
                'This platform already has Gemini staff. The next Director is invited from Platform admins, '.
                'by somebody who holds the role.'
            );
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new DomainException('That address already holds an account on this platform.');
        }

        if (mb_strlen($password) < 16) {
            throw new DomainException('The first Director\'s password must be at least 16 characters.');
        }

        return DB::connection('mysql')->transaction(function () use ($email, $name, $password): User {
            $role = Role::named(Role::DIRECTOR);
            $user = $this->createAccount($email, $name, $password, $role);

            $this->audit->record(
                action: 'user.bootstrapped',
                entityType: 'User',
                entityId: (string) $user->id,
                after: ['email' => $user->email, 'role' => $role->name, 'via' => 'platform:first-director'],
            );

            return $user;
        });
    }

    /** Active from the first moment, verified, holding exactly one role. */
    private function createAccount(string $email, string $name, string $password, Role $role): User
    {
        $user = User::create([
            'name' => trim($name),
            'email' => $email,
            'password' => Hash::make($password),
            'console' => $role->console->value,
            'status' => 'active',
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles([$role->name]);

        return $user;
    }
}
