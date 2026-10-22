<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EndSessionOfInactiveAccount;
use App\Models\User;
use App\Services\Auth\FrontDoor;
use App\Services\Auth\TwoFactor;
use App\Support\ConsoleHome;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * THE FRONT DOOR (M11 §5.2, M24 §4): "Log in" on the client's site. Nobody has to remember their estate's address.
 *
 * TWO CHOICES FIRST (M24, D-223): "Log in to your community as staff" and "... as a resident", and nothing else on
 * the page. The resident path is ResidentDoorController's. This is the staff path:
 *
 *   1. the email                 said back on the next step whatever it is: nothing here tells an unknown
 *                                address from a known one
 *   2. the password              the same generic sentence for a wrong password and an unknown address, on
 *                                the same limiter as every other door (per address and per IP)
 *   3. where the account belongs a Gemini account is signed in HERE, where its console is; an estate account
 *                                is handed to its estate with a single-use token (App\Services\Auth\FrontDoor)
 *                                and is never signed in on this host; with more than one estate it chooses
 *
 * Nothing on any step says in advance which console an account will land on: the client was explicit that a
 * Gemini person does not need telling, and nobody else should be told.
 */
final class FrontDoorController extends Controller
{
    private const EMAIL_KEY = 'front_door.email';

    private const CHOOSING_KEY = 'front_door.choosing';

    /** A bcrypt hash of random bytes nobody kept, at the application's cost: what an unknown address is checked against. */
    private const NOBODY = '$2y$12$ARFQfc1cEqqrBAN41qXo/.cztNdaD5GHmXeTAI3p9gKjvJjpg2NyK';

    public function __construct(
        private readonly FrontDoor $door,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user() instanceof User && $request->user()->console === Console::Gemini) {
            return redirect()->to(ConsoleHome::for($request->user()));
        }

        $email = $request->session()->get(self::EMAIL_KEY);

        // The two choices, or the staff password once a staff address has been given.
        return view('public.front-door', [
            'title' => 'Log in',
            'step' => is_string($email) ? 'staff-password' : 'start',
            'email' => is_string($email) ? $email : null,
        ]);
    }

    /** "Log in to your community as staff": the email address, and nothing more. */
    public function staff(Request $request): View|RedirectResponse
    {
        if (is_string($request->session()->get(self::EMAIL_KEY))) {
            return redirect()->route('front-door.show');
        }

        return view('public.front-door', ['title' => 'Log in as staff', 'step' => 'staff-email', 'email' => null]);
    }

    /** A staff password forgotten: the same plain page, posting to the consoles' own reset (PasswordResetController). */
    public function forgot(Request $request): View
    {
        $email = $request->session()->get(self::EMAIL_KEY);

        return view('public.front-door', ['title' => 'Reset your password', 'step' => 'staff-forgot', 'email' => is_string($email) ? $email : null]);
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:190']], [
            'email.required' => 'Enter the email address you log in with.',
            'email.email' => 'That is not an email address. Check it and try again.',
        ]);

        $request->session()->put(self::EMAIL_KEY, trim((string) $data['email']));

        return redirect()->route('front-door.show');
    }

    /** Back to the staff email step, with another address. */
    public function restart(Request $request): RedirectResponse
    {
        $request->session()->forget([self::EMAIL_KEY, self::CHOOSING_KEY]);

        return redirect()->route('front-door.staff');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        $credentials = ['email' => trim((string) $data['email']), 'password' => (string) $data['password']];
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user instanceof User) {
            /*
             * A password is checked against something even when there is no account: without it an unknown
             * address answers faster than a known one, and the timing says what the sentence refuses to.
             */
            Hash::check($credentials['password'], self::NOBODY);

            throw ValidationException::withMessages(['password' => 'Those credentials do not match our records.']);
        }

        if (! Auth::validate($credentials)) {
            // The same sentence as the consoles' own doors, for a wrong password and an unknown address alike.
            throw ValidationException::withMessages(['password' => 'Those credentials do not match our records.']);
        }

        $refusal = EndSessionOfInactiveAccount::refusal($user);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['password' => $refusal]);
        }

        $request->session()->forget(self::EMAIL_KEY);

        if ($user->console === Console::Gemini) {
            // The Gemini console is on THIS host: its session is made here, as its own door makes it — after the
            // second factor, as at its own door (App\Services\Auth\TwoFactor).
            if ($this->twoFactor->required($user)) {
                return $this->twoFactor->begin($user, $request);
            }

            Auth::login($user);
            $request->session()->regenerate();
            $user->forceFill(['last_login_at' => now()])->save();

            return redirect()->intended(ConsoleHome::for($user));
        }

        return $this->door->handOff($user, $request);
    }

    /** An account in more than one estate chooses which one — it is never guessed at. */
    public function choose(Request $request): View|RedirectResponse
    {
        $user = $this->choosing($request);

        if ($user === null) {
            return redirect()->route('front-door.staff')->withErrors(['email' => 'That took too long. Please log in again.']);
        }

        return view('public.front-door', [
            'title' => 'Choose your community',
            'step' => 'choose',
            'email' => $user->email,
            'estates' => $this->door->estatesOf($user),
        ]);
    }

    public function chosen(Request $request): RedirectResponse
    {
        $user = $this->choosing($request);

        if ($user === null) {
            return redirect()->route('front-door.staff')->withErrors(['email' => 'That took too long. Please log in again.']);
        }

        $choice = (string) $request->input('estate', '');
        $held = array_column($this->door->estatesOf($user), 'id');

        if (! in_array($choice, $held, true)) {
            // Not one of theirs: the list they were shown is the only list there is.
            return redirect()->route('front-door.choose')->withErrors(['estate' => 'Choose one of the communities listed.']);
        }

        $request->session()->forget(self::CHOOSING_KEY);

        return $this->door->away($user, $choice, $request);
    }

    /** The account proven a moment ago and still choosing, or null once five minutes have passed. */
    private function choosing(Request $request): ?User
    {
        $ticket = $request->session()->get(self::CHOOSING_KEY);

        if (! is_array($ticket) || ! isset($ticket['user'], $ticket['until']) || (int) $ticket['until'] < Carbon::now()->getTimestamp()) {
            $request->session()->forget(self::CHOOSING_KEY);

            return null;
        }

        $user = User::query()->find((int) $ticket['user']);

        return $user instanceof User && EndSessionOfInactiveAccount::refusal($user) === null ? $user : null;
    }
}
