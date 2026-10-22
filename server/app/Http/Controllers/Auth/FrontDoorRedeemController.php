<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\FrontDoor;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * THE ESTATE'S END OF THE FRONT DOOR (M11 §5.2), on the estate's OWN host: the single-use token the central
 * door issued is redeemed here, once, and THIS host makes its own session. No cookie came with it.
 *
 * A token that is unknown, spent, older than 60 seconds or for another estate is refused with one sentence —
 * which of those it was is written to the audit log, not told to whoever holds the link. Somebody already
 * signed in here as someone else is signed out first: the token names who is to be signed in, and a session
 * belonging to another person must not carry on underneath it.
 */
final class FrontDoorRedeemController extends Controller
{
    public function __invoke(Request $request, FrontDoor $door, string $token): RedirectResponse
    {
        $tenantId = (string) tenant()?->getTenantKey();
        $user = $door->redeem($token, $tenantId, $request);

        if ($user === null) {
            return redirect()->to($this->root($tenantId).'/login')
                ->withErrors(['email' => 'That sign-in link has expired or has already been used. Sign in again.'])
                ->header('Referrer-Policy', 'no-referrer');
        }

        if (Auth::check() && Auth::id() !== $user->id) {
            Auth::logout();
            $request->session()->invalidate();
        }

        /*
         * AN ESTATE ACCOUNT THAT TURNED TWO-STEP VERIFICATION ON (M32 F9) is not signed in by the token alone: it waits
         * for its authenticator's code here, on its own estate's host, where the session it makes will live.
         */
        $twoFactor = app(TwoFactor::class);

        if ($twoFactor->required($user)) {
            return $twoFactor->begin($user, $request)->header('Referrer-Policy', 'no-referrer');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->to($this->root($tenantId).'/', 303)->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * This estate's door, as the path it was served on — its own host's root in production, `/estate/{t}` on the
     * one local host — so the redirect stays on the host the session was just made on (the estate's pages build
     * their paths the same way; LoginController::doorFor).
     */
    private function root(string $tenantId): string
    {
        return app()->isLocal() ? '/estate/'.$tenantId : '';
    }
}
