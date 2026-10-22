<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Console;
use App\Services\Auth\FrontDoor;
use App\Support\ConsoleHome;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An estate account on the Gemini console is sent to its own console, not refused.
 *
 * WHAT THIS FIXES, AND IT WAS NOT A SMALL THING.
 *
 * The two consoles live on different hostnames, and session cookies here are host-only by
 * design — `SESSION_DOMAIN` is null so an estate's cookie is never sent to Gemini's console or
 * to another estate's. That is right, and it stays.
 *
 * What was wrong is what happened when a committee member signed in at the CENTRAL door. The
 * door accepted them — their account is real — set a session on the central hostname, and
 * redirected them to their estate on a different hostname, where that cookie does not travel.
 * They arrived as a stranger, were bounced to a sign-in page, and went back to the only address
 * they had been given: the central one. There they were still signed in, as an estate user on
 * Gemini's console, and every Gemini page refused them with 403 "Not available to your role".
 *
 * Every page. Every role. In front of the client, on 23 September 2026, with seven committee
 * accounts that were each individually correct: active, roled, permissioned, assigned to the
 * estate. Nothing in the permission matrix was broken. The JOURNEY was broken, and the 403 page
 * — which exists to explain a refusal — was telling the exact truth about the wrong thing.
 *
 * So the refusal is removed at the point it is meaningless. An estate account has no business
 * on a Gemini route, and answering "you may not" to a request nobody meant to make is worse
 * than useless: it reads as the account being broken. Carrying them to the console they do hold
 * is the only answer that is ever useful here.
 *
 * `away()` and not `to()`: the estate console is a different origin, and `redirect()->to()`
 * would resolve the path against this one.
 *
 * AN ESTATE ACCOUNT WITH NO ESTATE is signed out rather than redirected. `ConsoleHome::for()`
 * answers the sign-in page for that case, and redirecting an authenticated user to a `guest`
 * route is a loop, not a destination — which is the same class of fault as the one above.
 */
final class RedirectToOwnConsole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->console !== Console::Estate) {
            return $next($request);
        }

        $estate = $user->accessibleEstateIds()[0] ?? null;

        if ($estate === null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'This account belongs to an estate console, and it holds no estate. The estate office can restore its assignment.',
            ]);
        }

        /*
         * CARRIED SIGNED IN, NOT AS A STRANGER (M11 §5.2). A bare redirect to the estate's host arrived without a
         * session — the cookie is this host's — and the member met a sign-in page. Where the estate is on
         * another host, the session here is ended and the account is handed over with the front door's
         * single-use token, which the estate redeems to make its own. On the one local host the session already
         * reaches the estate's path, and the redirect is enough.
         */
        $home = ConsoleHome::estateUrl((string) $estate);

        if (parse_url($home, PHP_URL_HOST) === $request->getHost()) {
            return redirect()->away($home);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return app(FrontDoor::class)->handOff($user, $request);
    }
}
