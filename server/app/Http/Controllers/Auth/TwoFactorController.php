<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EndSessionOfInactiveAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use App\Support\ConsoleHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * The Gemini door's second step (M11 §0.3): the code from the authenticator app, or — the first time — setting
 * the authenticator up. Reached only with a password proven a moment ago in THIS session; nobody is signed in
 * until a code is accepted (App\Services\Auth\TwoFactor). Since M32 F9 it is an estate door's too, for an estate
 * account that turned two-step verification on from My account, on that estate's own host.
 *
 * Codes are limited per account as well as per address: five a minute, so the six digits cannot be guessed
 * through however many addresses somebody has.
 */
final class TwoFactorController extends Controller
{
    private const PER_MINUTE = 5;

    private const NEW_CODES = 'two_factor.new_codes';

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user() instanceof User) {
            return redirect()->to(ConsoleHome::for($request->user()));
        }

        $user = $this->twoFactor->pending($request);

        if ($user === null) {
            return $this->again();
        }

        if ($this->twoFactor->enrolled($user)) {
            return inertia('Auth/TwoFactor', ['door' => $this->doorOf($user, $request), 'mode' => 'challenge', 'email' => $user->email]);
        }

        $secret = $this->twoFactor->enrollingSecret($request);

        return inertia('Auth/TwoFactor', [
            'door' => $this->doorOf($user, $request),
            'mode' => 'enrol',
            'email' => $user->email,
            'qr' => $this->twoFactor->qrCode($user, $secret),
            // Grouped in fours for typing into an app by hand.
            'secret' => trim(chunk_split($secret, 4, ' ')),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->pendingOrFail($request);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];

        $this->limit($user, 'code');

        if (! $this->twoFactor->verify($user, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not right. The code changes every 30 seconds: enter the one your app shows now.']);
        }

        return $this->twoFactor->complete($user, $request);
    }

    public function recovery(Request $request): RedirectResponse
    {
        $user = $this->pendingOrFail($request);
        $code = (string) $request->validate(['recovery_code' => ['required', 'string', 'max:20']])['recovery_code'];

        $this->limit($user, 'recovery_code');

        if (! $this->twoFactor->useRecoveryCode($user, $code)) {
            throw ValidationException::withMessages(['recovery_code' => 'That recovery code is not one of this account\'s, or it has been used.']);
        }

        return $this->twoFactor->complete($user, $request);
    }

    /** Setting the authenticator up: its first code proves it, and the recovery codes are shown once. */
    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->pendingOrFail($request);

        if ($this->twoFactor->enrolled($user)) {
            return redirect()->route('two-factor.show');
        }

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];

        $this->limit($user, 'code');

        $codes = $this->twoFactor->confirm($user, $request, $code);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Scan the QR code again or check the key you typed, then enter the code your app shows now.']);
        }

        $this->twoFactor->complete($user, $request);
        $request->session()->put(self::NEW_CODES, $codes);

        return redirect()->route('two-factor.codes');
    }

    /** The recovery codes, once, straight after enrolling. */
    public function codes(Request $request): Response|RedirectResponse
    {
        $codes = $request->session()->pull(self::NEW_CODES);
        $user = $request->user();

        if (! is_array($codes) || ! $user instanceof User) {
            return redirect()->to($user instanceof User ? ConsoleHome::for($user) : route('login'));
        }

        return inertia('Auth/TwoFactor', [
            'door' => $this->doorOf($user, $request),
            'mode' => 'codes',
            'email' => $user->email,
            'codes' => array_values($codes),
            'continue' => ConsoleHome::for($user),
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->twoFactor->abandon($request);

        return redirect()->route('login');
    }

    private function pendingOrFail(Request $request): User
    {
        $user = $this->twoFactor->pending($request);

        if ($user === null) {
            throw ValidationException::withMessages(['code' => 'That took too long. Sign in again.'])->redirectTo(route('login'));
        }

        $refusal = EndSessionOfInactiveAccount::refusal($user);

        if ($refusal !== null) {
            $this->twoFactor->abandon($request);

            throw ValidationException::withMessages(['email' => $refusal])->redirectTo(route('login'));
        }

        return $user;
    }

    /** Five codes a minute for one account, whatever address they come from. */
    private function limit(User $user, string $field): void
    {
        $key = 'two-factor:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw ValidationException::withMessages([$field => 'Too many codes. Wait a minute, then enter the code your app shows.']);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * The door this step is drawn as: the Gemini console's, or — for an estate account that turned two-step
     * verification on (M32 F9) — its own estate's, on whose host this step is served, so the card it left is the card
     * it is asked on.
     *
     * @return array<string, mixed>
     */
    private function doorOf(User $user, Request $request): array
    {
        if ($user->console === Console::Estate) {
            foreach ($user->accessibleEstateIds() as $tenantId) {
                if (parse_url(ConsoleHome::estateUrl((string) $tenantId), PHP_URL_HOST) === $request->getHost()) {
                    $tenant = Tenant::query()->find((string) $tenantId);

                    if ($tenant instanceof Tenant) {
                        return LoginController::doorFor($tenant);
                    }
                }
            }
        }

        return LoginController::doorFor(null);
    }

    private function again(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => 'Sign in with your email and password first.']);
    }
}
