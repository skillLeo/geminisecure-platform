<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * A SESSION IS GOOD ONLY ON THE DOOR THAT MADE IT (M59, Hassam, 9 October 2026).
 *
 * Every estate and the Gemini console keep their sessions in ONE store, and every account is central, so a session
 * cookie copied from Phoenix Park's host onto another estate's host was a valid sign-in there: 55 of 69 pages on the
 * other estates knew the Super Admin by name and role (the M58 browser run). Nothing leaked, only because each page
 * carries `EnsureEstateAccess`; one page written without it would have been a cross-tenant leak.
 *
 * So a session remembers the host it was made on, and every estate has a host of its own (the Gemini console has the
 * root), so holding a session to its host holds it to its estate. Anywhere else it is refused HERE, ranked straight
 * after the session starts and before anything reads who is signed in: the request carries on as a stranger, on a
 * new and empty session, so the page asked for answers exactly as it answers anybody signed out, which on every
 * console page is its sign-in page. Nothing of the other door's session is read further, written or ended. The
 * per-page checks stay, as the second lock. (The local path form serves every estate from one host, so there, and
 * only there, the estates share a door.)
 *
 * No real journey crosses doors with a session: the cookie is host-only, so a browser never sends one host's session
 * to another, and the front door carries an account to its estate with a single-use token the estate redeems to make
 * its own (`FrontDoorRedeemController`).
 *
 * A session made before this rule carries no door, and nobody can say which door made it: a signed-in one is refused
 * everywhere once, and its holder signs in again.
 */
final class BindSessionToItsDoor
{
    /** The host the session was made on. */
    public const DOOR = '_door';

    /** What somebody signed in is told on the sign-in page their refused session sends them to (Hassam, 9 October). */
    public const SIGN_IN_AGAIN = 'Please sign in again.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $door = $request->getHost();
        $wasSignedIn = $this->madeElsewhere($session, $door) && $this->refuse($request, $door);

        $this->bind($session, $door);

        /*
         * A FORM SENT ON A SIGNED-IN SESSION THIS DOOR REFUSED (somebody mid-form when the deploy that brought this rule
         * landed) goes to the sign-in page, never to the "session expired" error page the CSRF check would give now that
         * the session is a new one; signed in again, they are taken back to the page the form was on. A page asked for
         * on such a session (a GET) carries on as a stranger's, and `auth` sends it to the sign-in page the same way.
         */
        if ($wasSignedIn && ! $request->isMethodSafe()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => self::SIGN_IN_AGAIN], 401);
            }

            $back = (string) $request->headers->get('referer');

            if ($back !== '' && parse_url($back, PHP_URL_HOST) === $door) {
                $session->put('url.intended', $back);
            }

            return redirect()->to(route('login'), 303);
        }

        $response = $next($request);

        // A session ended and begun again during the request (a sign-out, a sign-in over another person) is this door's too.
        $this->bind($session, $door);

        return $response;
    }

    /**
     * FOR CODE THAT SIGNS AN ACCOUNT IN BY ITSELF and hands the kernel a request (the isolation and console gates):
     * the session as the host's own door makes it, so the request is judged exactly as a real one there would be.
     * Call it after `Auth::login()`, with the host the request goes to.
     */
    public static function madeAt(string $host): void
    {
        session()->put(self::DOOR, $host);
    }

    private function madeElsewhere(Session $session, string $door): bool
    {
        $madeAt = $session->get(self::DOOR);

        return is_string($madeAt) ? $madeAt !== $door : $this->signedInAs($session) !== null;
    }

    private function bind(Session $session, string $door): void
    {
        if (! $session->has(self::DOOR)) {
            $session->put(self::DOOR, $door);
        }
    }

    /**
     * A new, empty session for this door, and nobody signed in. The other door's record is left exactly as it was:
     * whoever holds that cookie learns nothing here, and its owner is not signed out by somebody else's request.
     * Returns whether the refused session was signed in; if it was, the sign-in page says to sign in again.
     */
    private function refuse(Request $request, string $door): bool
    {
        $session = $request->session();
        $account = $this->signedInAs($session);

        Log::warning('A session made on another door was refused at this one.', [
            'made_at' => $session->get(self::DOOR),
            'door' => $door,
            'account' => $account,
        ]);

        $session->flush();
        $session->migrate();
        $session->regenerateToken();

        if ($account === null) {
            return false;
        }

        // The account that session named is not this request's, even in a worker that outlives one request.
        $guard = Auth::guard('web');

        if ($guard instanceof SessionGuard) {
            $guard->forgetUser();
        }

        $session->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['email' => [self::SIGN_IN_AGAIN]])));

        return true;
    }

    /** The account a session is signed in as, read from the session alone (no query), or null. */
    private function signedInAs(Session $session): int|string|null
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            return null;
        }

        $id = $session->get($guard->getName());

        return is_int($id) || is_string($id) ? $id : null;
    }
}
