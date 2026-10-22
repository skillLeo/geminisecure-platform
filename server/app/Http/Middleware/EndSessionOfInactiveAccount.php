<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\DemoMode;
use App\Support\DemonstrationAccounts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * A session belongs to an ACTIVE account, on every request, or it ends (B068).
 *
 * WHAT WAS WRONG. `users.status` was read in two places: at sign-in, and in
 * `User::canAccessEstate()`. Nothing on the Gemini Console's own routes asked it —
 * every `can:gemini.*` gate is answered from the role, and suspending a person
 * (`PlatformAdmins::manage(..., 'suspended')`) deliberately keeps their role. So a
 * Director suspended while signed in kept Platform settings until the session idled
 * out: they could repoint the mail relay that carries reset links, change the SMS
 * credentials, and invite a new Director to a mailbox of their own, who would then
 * keep access for good. The estate side refused such a person every page, but left
 * the session alive behind a 404.
 *
 * WHAT IT DOES. Before `auth` runs, the account behind the session is looked at. One
 * that is not `active` is signed out — the guard, the session and its CSRF token —
 * and the request carries on as a guest's: a route behind `auth` then answers
 * exactly as it does for anybody signed out (the sign-in page, or 401 to an XHR),
 * and a public page is simply shown. The sign-in page says why, in the same words
 * the sign-in form uses for the same account.
 *
 * WHY HERE AND NOT WHERE THE SUSPENSION HAPPENS. Production keeps sessions in Redis,
 * where nothing can find "every session of user 42" to delete; and a suspension is a
 * column that several screens, a console command and a database edit can all write.
 * Asking on each request covers every one of them, and costs nothing: the guard
 * reads the user row on every authenticated request already, and this reads the same
 * object.
 *
 * WHY IN THE WEB GROUP, AND RANKED BEFORE `auth`. Every browser route on both consoles
 * runs the web group — the Gemini modules, every estate group in `routes/tenant.php`,
 * the contact gate, `/broadcasting/auth` — so no route file can be the one that forgot.
 * It sits before `HandleInertiaRequests`, which reads the user to share with the page,
 * and `bootstrap/app.php` ranks it before `auth` so that a route's own `auth` sees the
 * account already signed out. What remains open is a websocket subscribed before the
 * suspension: the channel callbacks refuse a suspended account the next time it
 * subscribes (`routes/channels.php`), and it cannot subscribe from a page it can no
 * longer load.
 */
final class EndSessionOfInactiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User) {
            $refusal = self::refusal($user);

            if ($refusal !== null) {
                Auth::guard('web')->logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                /*
                 * For the sign-in page this request is about to be sent to, in the
                 * shape a refused sign-in leaves there — so it is drawn under the
                 * email field, where the form draws its own refusals.
                 */
                $request->session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['email' => [$refusal]])));
            }
        }

        return $next($request);
    }

    /**
     * Why this account may not be signed in, or null when it may.
     *
     * The one sentence for each standing, used by the sign-in form when it refuses a
     * password and by this middleware when it ends a session — so a person is told
     * the same thing whichever door they met it at.
     */
    public static function refusal(User $user): ?string
    {
        /*
         * NO SEEDED ACCOUNT SIGNS IN ON A LIVE SYSTEM (M16 §1.2). A demonstration
         * console account — one on a `.demo.geminisl.com` or `.test` address a seeder
         * invented — is refused whenever the demonstration switch is off, at the door
         * and on every later request alike (this method answers for both). On a
         * demonstration server it is `active` like any other. Read before status, so a
         * suspended demo account still reads as unavailable rather than merely suspended.
         */
        if (! DemoMode::enabled() && DemonstrationAccounts::isDemonstrationAddress((string) $user->email)) {
            return 'Demonstration accounts are not available on this system.';
        }

        return match ($user->status) {
            'active' => null,
            'invited' => 'This invitation has not been accepted yet.',
            default => 'This account is suspended.',
        };
    }
}
