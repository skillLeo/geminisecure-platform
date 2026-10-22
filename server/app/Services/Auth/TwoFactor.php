<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Console;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ConsoleHome;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * THE GEMINI CONSOLE'S SECOND FACTOR (M11 §0.3): a code from an authenticator app (TOTP, RFC 6238), on every
 * sign-in to the console that holds data for every client estate — and, since M32 F9, on every sign-in of an estate
 * account that has turned it on from My account, where it is also turned off, replaced and given new recovery codes.
 *
 * A PROVEN PASSWORD SIGNS NOBODY IN. It makes the account PENDING, in this session, for ten minutes; only a
 * correct code — or one of the account's one-time recovery codes — makes the session. So every route behind
 * `auth` is behind the second factor too, with no middleware for a module to forget.
 *
 * At its first sign-in an account enrols: a new key (shown as a QR code and as text), kept in the session until
 * the account proves it with a first code, and only then saved, encrypted, with eight recovery codes shown once
 * and stored as hashes. A code is accepted within one 30-second step either way, and never twice
 * (`mfa_last_step`). An account whose authenticator is lost is reset by `accounts:reset-two-factor`, audited.
 */
final class TwoFactor
{
    private const PENDING = 'two_factor.pending';

    private const ENROLLING = 'two_factor.enrolling';

    private const PENDING_MINUTES = 10;

    /** Steps either side of now a code may come from: one, i.e. ±30 seconds of clock drift. */
    private const WINDOW = 1;

    public const RECOVERY_CODES = 8;

    public function __construct(
        private readonly Google2FA $totp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Whether this account's sign-in needs the second factor: every Gemini console account (M11 §0.3), and an estate
     * account that has turned it on from My account (M32 F9).
     */
    public function required(User $user): bool
    {
        if ($user->console === Console::Gemini) {
            return (bool) config('auth.gemini_two_factor', true);
        }

        return $this->enrolled($user);
    }

    /**
     * WHETHER THIS ACCOUNT MAY TURN IT OFF (M32 F9): not when its role requires it. Every Gemini console account does
     * (M11 §0.3: the console that holds every client estate's data asks for the second factor at every sign-in, the
     * Director and the Accountant with every other role); an estate's Community Super Admin and Treasurer do. Null when
     * it may, or the sentence the page and the refusal say.
     */
    public function requiredBecause(User $user): ?string
    {
        if ($user->console === Console::Gemini && (bool) config('auth.gemini_two_factor', true)) {
            return 'Every Gemini console account uses two-step verification at every sign-in, so it cannot be turned off.';
        }

        $tenant = function_exists('tenant') ? tenant() : null;

        if ($tenant !== null) {
            foreach ([Role::COMMUNITY_SUPER_ADMIN => 'Community Super Admin', Role::TREASURER => 'Treasurer'] as $role => $label) {
                $ids = Role::idsStandingFor($role, (string) $tenant->getTenantKey());

                if ($ids !== [] && $user->roles()->whereIn('roles.id', $ids)->exists()) {
                    return 'Your role, '.$label.', requires two-step verification, so it cannot be turned off.';
                }
            }
        }

        return null;
    }

    /** Turned off from My account (M32 F9), by the person, with their password and a current code. */
    public function disable(User $user): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled' => false, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();
        $this->audit->record('user.two_factor_disabled', 'User', (string) $user->id, null, null, null, $user);
    }

    /**
     * A new set of recovery codes, the old set no longer working (M32 F9). Returned once, to be shown once.
     *
     * @return list<string>
     */
    public function newRecoveryCodesFor(User $user): array
    {
        $codes = $this->newRecoveryCodes();

        $user->forceFill(['mfa_recovery_codes' => array_map(fn (string $c): string => $this->recoveryHash($c), $codes)])->save();
        $this->audit->record('user.two_factor_recovery_codes_made', 'User', (string) $user->id, null, ['codes' => count($codes)], null, $user);

        return $codes;
    }

    /** How many of the account's recovery codes are left unused. */
    public function recoveryCodesLeft(User $user): int
    {
        return count(array_filter((array) $user->mfa_recovery_codes, 'is_string'));
    }

    /** Forget a key being set up in this session (My account's "Cancel"), so the next setup starts afresh. */
    public function forgetEnrolling(Request $request): void
    {
        $request->session()->forget(self::ENROLLING);
    }

    public function enrolled(User $user): bool
    {
        return $user->mfa_enabled && $user->mfa_confirmed_at !== null && is_string($user->mfa_secret) && $user->mfa_secret !== '';
    }

    /** A proven password: the account waits for its code here. Nobody is signed in yet. */
    public function begin(User $user, Request $request): RedirectResponse
    {
        $request->session()->put(self::PENDING, ['user' => $user->id, 'until' => Carbon::now()->addMinutes(self::PENDING_MINUTES)->getTimestamp()]);
        $request->session()->forget(self::ENROLLING);

        return redirect()->route('two-factor.show');
    }

    /** The account waiting for its second factor in this session, or null (none, or it waited too long). */
    public function pending(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || ! isset($pending['user'], $pending['until']) || (int) $pending['until'] < Carbon::now()->getTimestamp()) {
            $this->abandon($request);

            return null;
        }

        $user = User::query()->find((int) $pending['user']);

        return $user instanceof User ? $user : null;
    }

    public function abandon(Request $request): void
    {
        $request->session()->forget([self::PENDING, self::ENROLLING]);
    }

    /** The key being enrolled in this session — made once, and kept only here until a code proves it. */
    public function enrollingSecret(Request $request): string
    {
        $secret = $request->session()->get(self::ENROLLING);

        if (! is_string($secret) || $secret === '') {
            $secret = $this->totp->generateSecretKey(32);
            $request->session()->put(self::ENROLLING, $secret);
        }

        return $secret;
    }

    /** The authenticator's QR code for this key, as an SVG data URI — made here, never by a third party. */
    public function qrCode(User $user, string $secret): string
    {
        $url = $this->totp->getQRCodeUrl((string) config('legal.product', 'GeminiSecure'), $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The first code proves the key: it is saved, and the recovery codes are made. Returns them, once; null when
     * the code was not right, and nothing is saved.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, Request $request, string $code): ?array
    {
        $secret = $this->enrollingSecret($request);
        // An old timestamp of 0, not null: with null the library answers true rather than the step it matched,
        // and the step is what stops this same code being used again at the next sign-in.
        $step = $this->totp->verifyKeyNewer($secret, $this->digits($code), 0, self::WINDOW);

        if ($step === false) {
            return null;
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
            'mfa_confirmed_at' => Carbon::now(),
            'mfa_recovery_codes' => array_map(fn (string $c): string => $this->recoveryHash($c), $codes),
            'mfa_last_step' => is_int($step) ? $step : null,
        ])->save();

        $request->session()->forget(self::ENROLLING);
        $this->audit->record('user.two_factor_enrolled', 'User', (string) $user->id, null, null, null, $user);

        return $codes;
    }

    /** A code from the account's authenticator, from a step not used before. */
    public function verify(User $user, string $code): bool
    {
        if (! $this->enrolled($user)) {
            return false;
        }

        $step = $this->totp->verifyKeyNewer((string) $user->mfa_secret, $this->digits($code), $user->mfa_last_step ?? 0, self::WINDOW);

        if ($step === false) {
            return false;
        }

        $user->forceFill(['mfa_last_step' => is_int($step) ? $step : $user->mfa_last_step])->save();

        return true;
    }

    /** One of the account's recovery codes, which is spent by being used. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $given = $this->recoveryHash($code);
        $left = array_values(array_filter((array) $user->mfa_recovery_codes, 'is_string'));
        $match = null;

        foreach ($left as $i => $stored) {
            if (hash_equals($stored, $given)) {
                $match = $i;
            }
        }

        if ($match === null) {
            return false;
        }

        unset($left[$match]);
        $user->forceFill(['mfa_recovery_codes' => array_values($left)])->save();
        $this->audit->record('user.two_factor_recovery_used', 'User', (string) $user->id, null, ['remaining' => count($left)], null, $user);

        return true;
    }

    /** The second factor proven: the session is made, as the door would have made it. */
    public function complete(User $user, Request $request): RedirectResponse
    {
        $this->abandon($request);

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(ConsoleHome::for($user));
    }

    /** Forget the account's authenticator and recovery codes: it enrols again at its next sign-in. */
    public function reset(User $user, ?User $actor = null): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled' => false, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();
        $this->audit->record('user.two_factor_reset', 'User', (string) $user->id, null, null, null, $actor);
    }

    /** @return list<string> e.g. "7KQM-3XRW": no 0/O or 1/I to misread off a printout. */
    private function newRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = '';

            for ($j = 0; $j < 8; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $codes[] = substr($raw, 0, 4).'-'.substr($raw, 4);
        }

        return $codes;
    }

    /** How a recovery code is kept and compared: without its dash or spaces, in capitals — "7kqm 3xrw" is "7KQM-3XRW". */
    private function recoveryHash(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
    }

    /** "123 456" and "123-456" are the code 123456; anything else is not a code at all. */
    private function digits(string $code): string
    {
        return Str::of($code)->replaceMatches('/\D/', '')->substr(0, 6)->value();
    }
}
