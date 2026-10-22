<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Rules\NotAnObviousPassword;
use App\Services\Invitations;
use App\Support\ConsoleHome;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Response;

/**
 * Accepting an invitation — the one way an account comes into existence.
 *
 * DRAWN ON THE DOOR THE LINK LANDED ON. An estate's invitation opens on the
 * estate's own card — "Phoenix Park Village · Powered by Gemini Security" —
 * and names who asked, for what role, and how long the link stands. A link
 * that opens nothing (unknown, expired, already accepted) draws the same card
 * with the reason, and never a form: there is no account to create.
 *
 * SIGNED IN ON ACCEPTANCE. The person chose the password on a link only they
 * received; making them type it again on the next screen would prove nothing.
 */
class InvitationController extends Controller
{
    public function show(string $token, Invitations $invitations): Response
    {
        $invitation = $invitations->find($token);

        $estate = $invitation?->tenant_id === null ? null : Tenant::query()->find($invitation->tenant_id);

        return inertia('Auth/AcceptInvitation', [
            'door' => LoginController::doorFor(tenant()),
            'token' => $token,
            'invitation' => $invitation === null ? null : [
                'email' => $invitation->email,
                'role' => (string) ($invitation->role->label ?? $invitation->role->name),
                'inviter' => $invitation->inviter->name,
                'where' => $estate === null ? 'the Gemini Console' : (string) $estate->name,
                'expires_on' => $invitation->expires_at->format('F j, Y'),
                'pending' => $invitation->isPending(),
            ],
            'refusal' => match (true) {
                $invitation === null => 'This link does not open an invitation. It may have been withdrawn, or copied incompletely — ask whoever invited you to send it again.',
                $invitation->accepted_at !== null => 'This invitation has already been accepted. Sign in with the password you chose, or ask for a reset.',
                $invitation->isExpired() => 'This invitation expired on '.$invitation->expires_at->format('F j').'. Ask whoever invited you to send it again.',
                default => null,
            },
        ]);
    }

    public function accept(Request $request, string $token, Invitations $invitations): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Ten characters, and not one of the passwords anybody tries first (M32 F9).
            'password' => ['required', 'confirmed', PasswordRule::min(10), new NotAnObviousPassword],
        ]);

        $invitation = $invitations->find($token);

        if ($invitation === null) {
            return back()->withErrors(['name' => 'This link does not open an invitation.']);
        }

        try {
            $user = $invitations->accept($invitation, $data['name'], $data['password']);
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->to(ConsoleHome::for($user));
    }
}
