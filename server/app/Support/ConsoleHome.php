<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Console;
use App\Models\User;

/**
 * Where a signed-in user's console actually lives.
 *
 * ONE ANSWER, in one place. The two consoles are not on the same URL: Gemini
 * is on the central domain, an estate is on its own subdomain in production
 * and under /estate/{tenant} locally. Sending an estate user to /dashboard
 * lands them on the Gemini dashboard, where the permission gate correctly
 * refuses them — a 403 that looks like a bug and is the gate working.
 *
 * That fault was fixed once, in the local quick-login bypass, and left in
 * place on the real sign-in form, where it mattered more: every estate user
 * signing in through the actual login page hit it. Two copies of this logic is
 * how that happened, so there is now one.
 */
final class ConsoleHome
{
    /**
     * @return string A URL, not a route name — the estate console's address
     *                depends on the environment, and route() cannot express
     *                both shapes.
     */
    public static function for(User $user): string
    {
        if ($user->console === Console::Gemini) {
            return route('gemini.dashboard');
        }

        $tenantId = $user->accessibleEstateIds()[0] ?? null;

        if ($tenantId === null) {
            // No estate to send them to. Better to say so on the sign-in page
            // than to bounce them into a console they hold no assignment for
            // and let the gate refuse them without explanation.
            return route('login').'?reason=no-estate';
        }

        return self::estateUrl((string) $tenantId);
    }

    /**
     * The estate console's address for one estate.
     *
     * Production gives each community its own hostname. Local uses the path
     * form on one host and port, because *.localhost does not resolve on
     * Windows and a second registrable domain means the session cookie does
     * not travel — which presents as a login loop rather than a login screen.
     */
    public static function estateUrl(string $tenantId): string
    {
        if (app()->isLocal()) {
            return url("/estate/{$tenantId}");
        }

        $scheme = request()->isSecure() ? 'https' : 'http';

        return "{$scheme}://{$tenantId}.".config('app.estate_domain');
    }
}
