<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Account\AccountSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * A SESSION SIGNED OUT FROM MY ACCOUNT ENDS AT ITS NEXT REQUEST (M32 F9), and the list of where an account is signed in
 * is kept (`AccountSessions`). Beside `EndSessionOfInactiveAccount`, and ranked before `auth` with it, for the same
 * reason: a route's own `auth` then sees the session already ended and answers as it does for any guest.
 *
 * It costs an ordinary page nothing: the generation is on the user row the guard has read already, and the list is
 * written once after a sign-in and touched at most every fifteen minutes.
 */
final class KeepAccountSession
{
    public function __construct(private readonly AccountSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User && $request->hasSession() && ! $this->sessions->keep($request, $user)) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['email' => ['You were signed out of this browser from My account. Log in again.']])));
        }

        return $next($request);
    }
}
