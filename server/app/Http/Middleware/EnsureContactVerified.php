<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NO PROVEN ALERT ADDRESS, NO CONSOLE — M10 §1.4.
 *
 * "On first sign-in, every community console user … must supply a contact email
 * and verify it by OTP before they can continue. No verification, no access to the
 * console. The same for the Gemini console roles that receive alerts."
 *
 * WHERE IT SITS. On the authenticated route group of BOTH consoles — the Gemini
 * module group in routes/web.php, beside `RedirectToOwnConsole`, and both estate
 * groups in routes/tenant.php (the production subdomain and the local path form),
 * after `EnsureEstateAccess`. That placement is the whole design:
 *
 *   A GROUP, NOT A LIST OF PAGES. Every console route is inside one of those three
 *     groups, so a screen added next month is gated the day it is added and there
 *     is no page an unverified account can reach by typing its URL.
 *   AFTER THE ESTATE CHECK. An account asking for another estate's console is
 *     answered 404 by `EnsureEstateAccess` first, exactly as before — the gate is
 *     not a new way to learn that an estate exists.
 *   BEFORE EVERY `can:`. An unverified President asking for a screen they hold is
 *     sent to verify, not told "Not available to your role", which would read as
 *     the account being broken.
 *
 * WHAT IS OUTSIDE IT, and must stay outside: the verification page and its two
 * POSTs (registered in their own groups, on both doors, with `auth` but not this),
 * `logout` (registered on `auth` alone above every group), and the local-only
 * `dev/login/{role}` bypass, which is a guest route. They are also named in
 * `EXEMPT` below, so moving one of them inside a gated group by mistake cannot
 * turn the gate into a redirect loop.
 *
 * EVERY KIND OF REQUEST GETS THE SAME REDIRECT. A full page load, an Inertia visit,
 * an Inertia partial reload and a JSON request are all sent to the verification
 * page — never a 200 with the data the account has not yet earned, and never a 403
 * that explains nothing. Inertia follows a redirect and renders the page it lands
 * on, so a partial reload arrives on the verification screen rather than silently
 * refreshing half of a page the reader should not be on.
 *
 * WHO. `User::needsContactVerification()` decides, and nothing here re-derives it:
 * every estate-console account, and the Gemini roles in `Role::GEMINI_ALERT_RECIPIENTS`.
 */
final class EnsureContactVerified
{
    /** Route names that must never be gated, wherever they are registered. */
    private const EXEMPT = [
        'contact.verify',
        'contact.verify.send',
        'contact.verify.check',
        'contact.verify.phone',
        'estate.contact.verify',
        'estate.contact.verify.send',
        'estate.contact.verify.check',
        'estate.contact.verify.phone',
        'logout',
        'dev.login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->needsContactVerification()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::EXEMPT, true)) {
            return $next($request);
        }

        /*
         * BACK WHERE THEY WERE GOING, once verified. Only for a page the reader
         * asked for — a GET that is not a partial reload or a JSON call — because
         * the intended URL is somewhere to land, and a fragment of a page or an
         * API answer is not.
         */
        if ($request->isMethod('GET')
            && ! $request->headers->has('X-Inertia-Partial-Data')
            && ! ($request->expectsJson() && ! $request->headers->has('X-Inertia'))) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->to(self::pageUrl($request));
    }

    /**
     * Where the verification page is on the door this request came through.
     *
     * The same page on every door: `/verify-contact` on the central host and on an
     * estate's own hostname, and `/estate/{key}/verify-contact` in the local path
     * form — because a session cookie is host-only here, and sending an estate
     * account to the central host to verify would send them there as a stranger.
     */
    public static function pageUrl(Request $request): string
    {
        return url(self::doorRoot($request).'/verify-contact');
    }

    /**
     * '' on the central host and on an estate subdomain; '/estate/{key}' when the
     * estate was resolved from the path (local only). Read from the request that
     * was actually served, never from a parameter.
     */
    public static function doorRoot(Request $request): string
    {
        if (! tenancy()->initialized) {
            return '';
        }

        $key = (string) tenant()?->getTenantKey();

        return $request->segment(1) === 'estate' && $request->segment(2) === $key
            ? '/estate/'.$key
            : '';
    }
}
