<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NotAnObviousPassword;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Response;

/**
 * Forgot password — both doors. Ruled (12 §2, Wave 1): single-use token,
 * 30-minute expiry, verified address, identical confirmation whether or not
 * the account exists.
 *
 * ONE SENTENCE WHATEVER HAPPENED. The request page answers "if that address
 * holds an account, a link has been sent" to a known address, an unknown one,
 * a suspended account and a throttled retry alike — because the sign-in form
 * already refuses to say which addresses hold accounts, and a reset form that
 * said "we don't know that address" would hand out the same list one guess at
 * a time. Laravel's broker returns a different status for each case; every
 * one of them lands on the same words.
 *
 * THE TOKEN IS THE FRAMEWORK'S. Hashed at rest, bound to the address, expired
 * by `auth.passwords.users.expire` (30) and deleted the moment it is used. A
 * second visit with the same link is refused as invalid, which is what
 * "single-use" means.
 *
 * THE VERIFIED ADDRESS is the one on the account: the link carries the email
 * the token was issued for, and the broker refuses a token presented with any
 * other. Nothing on the reset page can move a token to a different account.
 *
 * DRAWN ON THE SAME CARD AS SIGN-IN, on whichever door it was reached from:
 * `door()` reads the tenant the same way `LoginController` does.
 */
class PasswordResetController extends Controller
{
    /** The one sentence the request page says, whatever happened. */
    public const SENT = 'If that address holds an account, a link to choose a new password has been sent to it. It works once, for thirty minutes.';

    /** One refusal for a link that expired, was used, or was issued for another address (see update()). */
    public const REFUSED = 'That reset link is not valid any more. It may have expired, been used already, or been issued for a different address. Ask for a new one from the sign-in page.';

    public function __construct(private readonly AuditLogger $audit) {}

    public function request(): Response
    {
        return inertia('Auth/ForgotPassword', [
            'door' => LoginController::doorFor(tenant()),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        /*
         * The status is deliberately not read. A known address gets a link; an
         * unknown one, a suspended account or a throttled retry gets nothing —
         * and the page says the same thing in every case. Suspended accounts
         * are excluded from the broker below so no link ever reaches one.
         */
        Password::broker()->sendResetLink(
            ['email' => $data['email'], 'status' => 'active'],
        );

        return back()->with('status', self::SENT);
    }

    public function reset(Request $request, string $token): Response
    {
        return inertia('Auth/ResetPassword', [
            'door' => LoginController::doorFor(tenant()),
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            // Ten characters, and not one of the passwords anybody tries first (M32 F9).
            'password' => ['required', 'confirmed', PasswordRule::min(10), new NotAnObviousPassword],
        ]);

        $status = Password::broker()->reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $data['password'],
                'token' => $data['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    // M32 F9: dated, and every session made with the old password ends at its next request.
                    'password_changed_at' => now(),
                    'sign_in_generation' => (int) $user->sign_in_generation + 1,
                ])->save();

                /*
                 * A changed credential is an audit event whoever changed it.
                 * Recorded against the user rather than as the user: nobody
                 * is signed in on this page.
                 */
                $this->audit->record(
                    action: 'user.password_reset',
                    entityType: 'User',
                    entityId: (string) $user->id,
                    after: ['via' => 'reset link'],
                    tenantId: $user->accessibleEstateIds()[0] ?? null,
                );
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            /*
             * Expired, already used, or issued for another address — one
             * refusal for all three. Telling a visitor WHICH would tell a
             * visitor holding somebody else's link something about the
             * account it was issued for.
             */
            return back()->withErrors(['email' => self::REFUSED]);
        }

        return redirect()
            ->to($this->loginPath())
            ->with('status', 'Your password has been changed. Sign in with it.');
    }

    /** The sign-in page on the door this reset was made from. */
    private function loginPath(): string
    {
        $tenant = tenant();

        if ($tenant === null) {
            return route('login');
        }

        return app()->isLocal()
            ? '/estate/'.$tenant->getTenantKey().'/login'
            : '/login';
    }
}
