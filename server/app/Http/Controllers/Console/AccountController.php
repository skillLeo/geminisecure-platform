<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\Account\AccountSessions;
use App\Services\Account\PasswordChange;
use App\Services\Account\SignInEmailChange;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactor;
use App\Services\Console\ConsolePhotos;
use App\Support\JamaicanNumber;
use App\Support\SiteTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * MY ACCOUNT (M32 F9): one page, the same in both consoles, where a console user manages their own account — their
 * name, photo and phone; their password; two-step verification; and where they are signed in. Reached from their name
 * at the foot of the left navigation. Every path is the caller's own: there is no {user} in any of them.
 *
 *   The EMAIL is the address the account signs in with. It changes only through its own two steps (M34 §1,
 *   `SignInEmailChange`): the current password, then a code emailed to the NEW address; never with the name and phone,
 *   whatever a request carries. It was not changeable at all until M34, and the page told a Director to "ask a
 *   Director": the only one on production signed in with an address that takes no mail.
 *   The ROLE is shown, and set by the estate or by GeminiSecure.
 *   A PASSWORD changes with the old one and a code emailed to the account's own address (`PasswordChange`), and every
 *   other session ends.
 *   TWO-STEP VERIFICATION is turned on through the authenticator (a code typed back finishes it, and the recovery codes
 *   are shown once); off, replaced or given new recovery codes with the password and a current code; never off for a
 *   role that requires it (`TwoFactor::requiredBecause`), by the server as well as the page.
 *   SESSIONS are listed, and one or every other is signed out (`AccountSessions`).
 *
 * Every act is in the audit log, with the address and the browser it came from (`AuditLogger` records both).
 * Guards manage their own account in the Guard App, not here, and their biometric stays on their phone.
 */
final class AccountController extends Controller
{
    /** Where a new set of recovery codes waits for the one page view that shows it. */
    private const NEW_CODES = 'account.new_recovery_codes';

    /** Set when two-step verification is being set up (or replaced) from this page, in this session. */
    private const SETTING_UP = 'account.two_factor_setting_up';

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly AccountSessions $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): Response
    {
        $user = $this->me($request);
        $estate = $user->console === Console::Estate;
        $setting = $request->session()->get(self::SETTING_UP);
        $secret = $setting !== null ? $this->twoFactor->enrollingSecret($request) : null;
        $emailLocked = SignInEmailChange::lockedBecause($user);

        return inertia('Account/Show', [
            'console' => $estate ? 'estate' : 'gemini',
            'estate' => $estate ? ['name' => (string) tenant()?->name] : null,
            'root' => $estate ? self::estateRoot() : '',

            'details' => [
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'email_note' => $emailLocked ?? 'The address you sign in with. Changing it needs your password and a code we email to the new address.',
                'role' => $this->roleLabel($user),
                'role_note' => $estate ? 'Set by your estate. Not changeable here.' : 'Set by GeminiSecure. Not changeable here.',
                'phone' => (string) ($user->contact_phone ?? ''),
                // The picture's version, or null (initials): the page builds `/account/photo?v=…` on its own door.
                'photo_version' => ConsolePhotos::version($user),
            ],

            // M34 §1: the sign-in address's own two steps. `pending` is the new address a code went to, from this session.
            'signInEmail' => [
                'locked' => $emailLocked,
                'pending' => $emailLocked === null ? $request->session()->get(SignInEmailChange::PENDING) : null,
                'minutes' => SignInEmailChange::TTL_MINUTES,
            ],

            'password' => [
                'changed' => $user->password_changed_at === null ? 'Not changed here yet' : 'Last changed '.SiteTime::stamp($user->password_changed_at, null, 'j F Y'),
                'code_to' => (string) $user->email,
            ],

            'twoFactor' => [
                'on' => $this->twoFactor->enrolled($user),
                'since' => $user->mfa_confirmed_at === null ? null : 'Set up '.SiteTime::stamp($user->mfa_confirmed_at, null, 'j F Y'),
                'codes_left' => $this->twoFactor->recoveryCodesLeft($user),
                'codes_total' => TwoFactor::RECOVERY_CODES,
                'required_because' => $this->twoFactor->requiredBecause($user),
                // Being set up or replaced from this page: the key, as a QR code and as text. Never once it is saved.
                'setup' => $secret === null ? null : [
                    'mode' => (string) $setting,
                    'qr' => $this->twoFactor->qrCode($user, $secret),
                    'secret' => trim(chunk_split($secret, 4, ' ')),
                ],
                // Shown once, on the one page view after they were made, and never again.
                'new_codes' => $request->session()->pull(self::NEW_CODES),
            ],

            'sessions' => $this->sessions->listFor($user, $request),
        ]);
    }

    /** Name and phone. The email is refused by name, should a request carry one: it has its own two steps. */
    public function details(Request $request): RedirectResponse
    {
        $user = $this->me($request);

        $data = $request->validate([
            'email' => ['prohibited'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40', new PhoneNumber],
        ], [
            'email.prohibited' => 'Your email is how you sign in, so it is not changed with your name and phone. Use "Change my sign-in email": it needs your password and a code sent to the new address.',
            'name.required' => 'Enter your name as it should appear to others.',
            'phone.required' => 'Enter your mobile number: the control room calls it in an emergency.',
        ]);

        $before = ['name' => (string) $user->name, 'phone' => $user->contact_phone];
        $user->forceFill(['name' => trim((string) $data['name']), 'contact_phone' => JamaicanNumber::normalise((string) $data['phone'])])->save();
        $after = ['name' => (string) $user->name, 'phone' => $user->contact_phone];

        if ($before !== $after) {
            $this->audit->record(action: 'account.details_changed', entityType: 'User', entityId: (string) $user->id, before: $before, after: $after, actor: $user);
        }

        return back()->with('success', $before === $after ? 'Nothing had changed.' : 'Your details are saved.');
    }

    /** The sign-in address, step one: the new address and the current password; a code goes to the new address. */
    public function signInEmailCode(Request $request, SignInEmailChange $change): RedirectResponse
    {
        $user = $this->me($request);
        $this->limit($user);
        $to = $change->sendCode($user, $request->only(['new_email', 'current_password']), $request);

        return back()->with('success', 'A code is on its way to '.$to.'. It works for '.SignInEmailChange::TTL_MINUTES.' minutes: enter it below to finish.');
    }

    /** Step two: the code. From now on the account signs in with the new address. */
    public function signInEmail(Request $request, SignInEmailChange $change): RedirectResponse
    {
        $user = $this->me($request);
        $this->limit($user);
        $old = $change->change($user, $request->only(['code']), $request);

        return back()->with('success', 'You now sign in with '.$user->email.'. '.$old.' no longer signs you in, and we have emailed it to say so.');
    }

    /** Not now: the address waiting for its code is forgotten, and an emailed code for it can no longer finish. */
    public function signInEmailCancel(Request $request): RedirectResponse
    {
        $this->me($request);
        $request->session()->forget(SignInEmailChange::PENDING);

        return back();
    }

    public function passwordCode(Request $request, PasswordChange $change): RedirectResponse
    {
        $user = $this->me($request);
        $change->sendCode($user);

        return back()->with('success', 'A code is on its way to '.$user->email.'. It works for '.PasswordChange::TTL_MINUTES.' minutes.');
    }

    public function password(Request $request, PasswordChange $change): RedirectResponse
    {
        $user = $this->me($request);
        $others = $change->change($user, $request->only(['current_password', 'code', 'password', 'password_confirmation']), $request);

        return back()->with('success', 'Your password is changed. '.($others === 0
            ? 'You were not signed in anywhere else; any other place you were is signed out too.'
            : 'You are signed out everywhere else: '.$others.' other '.($others === 1 ? 'place' : 'places').'.'));
    }

    /** Turning it on: the password first, then the authenticator, then a code typed back (confirm). */
    public function twoFactorStart(Request $request): RedirectResponse
    {
        $user = $this->me($request);
        $this->limit($user);

        if ($this->twoFactor->enrolled($user)) {
            return back()->with('error', 'Two-step verification is on already.');
        }

        $this->passwordOrFail($user, $request);
        $this->twoFactor->forgetEnrolling($request);
        $request->session()->put(self::SETTING_UP, 'on');

        return back();
    }

    /** Replacing the app: the password and a code from the app being replaced, then the new app's setup. */
    public function twoFactorReplace(Request $request): RedirectResponse
    {
        $user = $this->me($request);
        $this->limit($user);
        $this->passwordAndCodeOrFail($user, $request);

        $this->twoFactor->forgetEnrolling($request);
        $request->session()->put(self::SETTING_UP, 'replace');

        return back();
    }

    /** The code the new app shows: it is saved, and the recovery codes are made and shown once. */
    public function twoFactorConfirm(Request $request): RedirectResponse
    {
        $user = $this->me($request);
        $this->limit($user);
        $mode = $request->session()->get(self::SETTING_UP);

        if ($mode === null) {
            return back()->with('error', 'Start setting two-step verification up first.');
        }

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']], ['code.required' => 'Enter the code your app shows.'])['code'];
        $codes = $this->twoFactor->confirm($user, $request, $code);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Scan the QR code again or check the key you typed, then enter the code your app shows now.']);
        }

        $request->session()->forget(self::SETTING_UP);
        $request->session()->put(self::NEW_CODES, $codes);

        if ($mode === 'replace') {
            $this->audit->record(action: 'user.two_factor_replaced', entityType: 'User', entityId: (string) $user->id, actor: $user);
        }

        return back()->with('success', $mode === 'replace' ? 'The new app is set up. The old one no longer works.' : 'Two-step verification is on. Every sign-in now asks for your app\'s code.');
    }

    public function twoFactorCancel(Request $request): RedirectResponse
    {
        $this->me($request);
        $this->twoFactor->forgetEnrolling($request);
        $request->session()->forget(self::SETTING_UP);

        return back();
    }

    /** Off: the password and a current code, and never for a role that requires it. */
    public function twoFactorOff(Request $request): RedirectResponse
    {
        $user = $this->me($request);
        $this->limit($user);

        $because = $this->twoFactor->requiredBecause($user);

        if ($because !== null) {
            $this->audit->record(action: 'user.two_factor_disable_refused', entityType: 'User', entityId: (string) $user->id, after: ['why' => 'required_by_role'], actor: $user);

            throw ValidationException::withMessages(['code' => $because]);
        }

        $this->passwordAndCodeOrFail($user, $request);
        $this->twoFactor->disable($user);

        return back()->with('success', 'Two-step verification is off. Sign-in asks for your password only.');
    }

    /** New recovery codes: the password and a current code; the old ones stop working. */
    public function twoFactorCodes(Request $request): RedirectResponse
    {
        $user = $this->me($request);
        $this->limit($user);
        $this->passwordAndCodeOrFail($user, $request);

        $request->session()->put(self::NEW_CODES, $this->twoFactor->newRecoveryCodesFor($user));

        return back()->with('success', 'Your new recovery codes are below. The old ones no longer work.');
    }

    public function signOutSession(Request $request, int $session): RedirectResponse
    {
        $user = $this->me($request);

        if (! $this->sessions->signOut($user, $session, $request)) {
            return back()->with('error', 'That is not one of your other sessions.');
        }

        return back()->with('success', 'That session is signed out.');
    }

    public function signOutOthers(Request $request): RedirectResponse
    {
        $user = $this->me($request);
        $count = $this->sessions->signOutOthers($user, $request);

        return back()->with('success', 'You are signed out everywhere else'.($count > 0 ? ': '.$count.' other '.($count === 1 ? 'place' : 'places') : '').'. This browser stays signed in.');
    }

    private function me(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** The role as the sidebar names it (`HandleInertiaRequests::presentUser`). */
    private function roleLabel(User $user): string
    {
        return (string) ($user->roles->first()->label ?? 'No role assigned');
    }

    private function passwordOrFail(User $user, Request $request): void
    {
        $password = (string) $request->validate(['current_password' => ['required', 'string']], ['current_password.required' => 'Enter your password.'])['current_password'];

        if (! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your password.']);
        }
    }

    private function passwordAndCodeOrFail(User $user, Request $request): void
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:12'],
        ], [
            'current_password.required' => 'Enter your password.',
            'code.required' => 'Enter the code your app shows now.',
        ]);

        if (! Hash::check((string) $data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your password.']);
        }

        if (! $this->twoFactor->verify($user, (string) $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not right. The code changes every 30 seconds: enter the one your app shows now.']);
        }
    }

    /** Ten tries a minute at the password and code prompts here, for one account, from wherever. */
    private function limit(User $user): void
    {
        $key = 'account-security:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['code' => 'Too many tries. Wait a minute, then try again.']);
        }

        RateLimiter::hit($key, 60);
    }

    /** This estate's door as the path it was served on, as the estate's pages build theirs (LoginController::doorFor). */
    private static function estateRoot(): string
    {
        return app()->isLocal() ? '/estate/'.(string) tenant()?->getTenantKey() : '';
    }
}
