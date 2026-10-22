<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EndSessionOfInactiveAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Account\AccountSessions;
use App\Services\Auth\FrontDoor;
use App\Services\Auth\TwoFactor;
use App\Support\ConsoleHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Sign-in for both consoles.
 *
 * There is deliberately no `store`-adjacent registration action. Accounts are
 * issued by invitation and no public registration endpoint exists anywhere in
 * this application, and tests/Feature/AuthenticationTest.php asserts that
 * absence so adding one breaks a test rather than passing review unnoticed.
 */
class LoginController extends Controller
{
    /**
     * Which console's door this is, and everything that differs between them.
     *
     * ONE COMPONENT, TWO SETS OF WORDS. Boards super-admin-01 and
     * community-admin-01 draw the same card with four different strings and one
     * extra row, and the temptation is two components. It is the wrong trade:
     * what is genuinely identical between them is the part that must never
     * drift — one generic error for a wrong password and an unknown address, no
     * "remember me", and no registration route anywhere. Two copies of a login
     * form are two places for one of those to be quietly relaxed, and the copy
     * that gets relaxed is the one nobody is looking at.
     *
     * THE ESTATE DOOR CARRIES NO TWO-FACTOR NOTICE, and that is not an omission
     * on the board. The Gemini console holds data for every client estate on the
     * platform, which is what its notice is about; an estate console holds one
     * community's own records, and the platform has not ruled that a committee
     * member must carry a second factor. Printing the Gemini copy there would
     * promise a control that does not exist.
     *
     * FORGOT PASSWORD IS ON BOTH DOORS, as ruled (12 §2, Wave 1). Board
     * super-admin-01 draws no such link and community-admin-01 does; the
     * ruling names both, and a Gemini dispatcher locked out at 2 a.m. has the
     * same need as a committee member. `forgot_href` is where each door's
     * link goes, in that door's own URL shape.
     *
     * @return array<string, mixed>
     */
    public static function doorFor(?object $tenant): array
    {
        if ($tenant === null) {
            /*
             * THE WORDS FOLLOW WHAT THE SERVER DOES (M11 §0.3). The board draws "Continue with 2FA" and "required
             * for every sign-in", and for a long time nothing asked for a second factor: the card promised a
             * control that did not exist. It is enforced now (App\Services\Auth\TwoFactor) — and if it is ever
             * turned off (`auth.gemini_two_factor`), the card stops saying it, rather than going on promising it.
             */
            $twoFactor = (bool) config('auth.gemini_two_factor', true);

            /*
             * M24 (D-223): anybody can open this door by typing its address, so it says what any public page says
             * and no more: the company, staff only. Which console it opens is not said in advance.
             */
            return [
                'key' => 'gemini',
                'mark' => 'STAFF LOG IN',
                'colourway' => 'login',
                'email_label' => 'Work email',
                'submit_label' => $twoFactor ? 'Continue with 2FA' : 'Log in',
                'submitting_label' => 'Signing in…',
                'foot' => 'Gemini Security Limited · Internal use only',
                'mfa_note' => $twoFactor
                    ? 'Two-factor authentication is required for every sign-in, no exceptions.'
                    : null,
                'forgot_password' => true,
                'forgot_href' => '/forgot-password',
                'login_href' => '/login',

                /*
                 * IN THE FOOTER, not in a row of its own. Board super-admin-01
                 * draws no forgot link and no row for one; a row between the
                 * password field and the button would move the button and the
                 * footer down and put the whole card off its board. The footer
                 * line already exists, and a fourth clause on it is the least
                 * the board can change to carry what the ruling requires.
                 */
                'forgot_placement' => 'foot',
            ];
        }

        $root = app()->isLocal() ? '/estate/'.$tenant->getTenantKey() : '';

        return [
            'key' => 'estate',
            'mark' => 'STAFF LOG IN',
            'colourway' => 'estate',
            'email_label' => 'Email address',
            'submit_label' => 'Log in',
            'submitting_label' => 'Logging in…',
            'foot' => $tenant->name.' · Powered by Gemini Security Limited',
            'mfa_note' => null,
            'forgot_password' => true,
            'forgot_href' => $root.'/forgot-password',
            'login_href' => $root.'/login',

            // Where board community-admin-01 draws it: its own row above the button.
            'forgot_placement' => 'row',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function door(): array
    {
        return self::doorFor(tenant());
    }

    public function create(Request $request): Response
    {
        return inertia('Auth/Login', [
            'door' => $this->door(),

            /*
             * Quick-login buttons, local only.
             *
             * A closure, so the roles are not even queried outside local, and
             * an empty array reaches the page rather than a null the component
             * has to guard against.
             */
            'quickLoginRoles' => fn () => app()->isLocal()
                ? Role::query()
                    ->orderBy('console')
                    ->orderBy('sort')
                    ->get()
                    ->map(fn (Role $role) => [
                        'name' => $role->name,
                        'label' => $role->label,
                        'console' => $role->console->value,
                        'scope' => $role->scope_default->label(),
                    ])
                    ->all()
                : [],

            /*
             * The quick-login bypass bounces back here with a reason when it
             * cannot send a role anywhere - an estate role with no estate to
             * be assigned to. Without this the redirect lands on a sign-in
             * page that says nothing, which reads as the button being broken.
             */
            'quickLoginNotice' => fn (): ?string => $this->quickLoginNotice($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /*
         * No "remember me".
         *
         * The approved board draws no such control, and this console holds
         * data for every client estate on the platform: a long-lived cookie on
         * an unattended machine is exactly the risk the two-factor copy on the
         * card is about. Sessions here end with the session.
         */
        if (! Auth::attempt($credentials)) {
            /*
             * One message for both a wrong password and an unknown address.
             * Distinguishing them turns the login form into an oracle for
             * which addresses hold accounts on the platform.
             */
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $user = Auth::user();

        /*
         * The same sentence `EndSessionOfInactiveAccount` gives when it ends a session
         * whose account stopped being active after sign-in (B068): one rule, told the
         * same way at both doors.
         */
        $refusal = $user instanceof User ? EndSessionOfInactiveAccount::refusal($user) : null;

        if ($refusal !== null) {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages(['email' => $refusal]);
        }

        /*
         * AN ESTATE ACCOUNT AT A DOOR ON ANOTHER HOST IS HANDED OVER, NOT SIGNED IN HERE (M11 §5.2).
         *
         * This form posts to `/login` on whichever host served it. At the estate's own door the session belongs
         * on that host, and is made there as before. At the CENTRAL door it does not: a session made here is a
         * cookie the estate's host never receives — the 23 September journey. So the central session just made
         * is ended, and the account is carried to its estate with the front door's single-use token, which the
         * estate redeems to make its own.
         */
        if ($user instanceof User && $user->console === Console::Estate && ! $this->servesAnEstateOf($request, $user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return app(FrontDoor::class)->handOff($user, $request);
        }

        /*
         * THE SECOND FACTOR (M11 §0.3). `attempt()` has signed the account in; a Gemini account is not, until its
         * authenticator's code is given. The session made a moment ago is ended and the account waits for the
         * code (App\Services\Auth\TwoFactor), so no request reaches the console on the password alone.
         */
        $twoFactor = app(TwoFactor::class);

        if ($user instanceof User && $twoFactor->required($user)) {
            Auth::logout();
            $request->session()->regenerate();

            return $twoFactor->begin($user, $request);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        /*
         * The user's OWN console, not the Gemini one.
         *
         * This used to send every successful sign-in to gemini.dashboard, so
         * an estate committee member signing in through this form landed on
         * the Gemini dashboard and was refused by the permission gate — a 403
         * that reads as a broken login. The same fault was fixed in the local
         * quick-login bypass and left here, where it mattered far more.
         *
         * intended() is still honoured first: someone who was deep-linked into
         * a page and bounced to sign in should return to that page.
         */
        return redirect()->intended(ConsoleHome::for($user));
    }

    /**
     * Whether this request is on the host one of this account's estates is served from — its own door, where
     * a session made now is the session its console reads. On the one local host every estate is.
     */
    private function servesAnEstateOf(Request $request, User $user): bool
    {
        foreach ($user->accessibleEstateIds() as $tenantId) {
            if (parse_url(ConsoleHome::estateUrl((string) $tenantId), PHP_URL_HOST) === $request->getHost()) {
                return true;
            }
        }

        return false;
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Off My account's list of where this account is signed in (M32 F9).
        app(AccountSessions::class)->forget($request);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Why the local-only quick sign-in sent the visitor back here, if it did.
     *
     * Matched against a fixed set rather than echoed: the value arrives in the
     * query string, and a query string is the visitor's to write.
     */
    private function quickLoginNotice(Request $request): ?string
    {
        if (! app()->isLocal()) {
            return null;
        }

        return match ($request->query('quicklogin') ?? $request->query('reason')) {
            'no-estate' => 'That role belongs to the Estate Console, and there is no estate to sign it in to.',
            default => null,
        };
    }
}
