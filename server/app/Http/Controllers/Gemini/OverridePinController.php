<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Dispatch\SupervisorOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * A supervisor's own clock-in override PIN (M4 §3.4). No board draws this screen.
 *
 * "Server sets and verifies the PIN." This is the setting half: one small form, reached
 * from the supervisor's own name in the sidebar, behind the permission that makes them
 * a supervisor (`gemini.guard_workforce.update` — see `SupervisorOverride`).
 *
 * ONLY THEIR OWN. There is no user id in the route: nobody sets, reads or resets
 * somebody else's PIN, not even a Director — a PIN another person chose is a PIN two
 * people know, and "who vouched for this guard" stops meaning anything.
 *
 * THE ACCOUNT PASSWORD IS ASKED FOR AGAIN. A console left signed in on a gatehouse desk
 * must not be enough to mint a credential that starts shifts.
 *
 * THE PIN IS NEVER SHOWN, because it is never kept: only its hash is stored. The screen
 * says whether one is set, when, when it was last used, and whether it is locked.
 */
class OverridePinController extends Controller
{
    public function show(Request $request, SupervisorOverride $override): Response
    {
        return inertia('Gemini/Account/OverridePin', [
            'pin' => $override->status($request->user()),
            // What the supervisor types on the guard's phone beside the PIN.
            'identifier' => (string) $request->user()->email,
            'digits' => SupervisorOverride::PIN_DIGITS,
            'max_attempts' => SupervisorOverride::MAX_FAILED_ATTEMPTS,
        ]);
    }

    public function update(Request $request, SupervisorOverride $override): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'pin' => ['required', 'string', 'digits:'.SupervisorOverride::PIN_DIGITS, 'confirmed'],
        ], [
            'current_password.current_password' => 'That is not your console password.',
            'pin.digits' => 'Choose exactly '.SupervisorOverride::PIN_DIGITS.' digits.',
            'pin.confirmed' => 'The two PINs do not match.',
        ]);

        $pin = (string) $data['pin'];

        if ($this->guessable($pin)) {
            throw ValidationException::withMessages(['pin' => 'Choose a PIN that is harder to guess — not one digit repeated, and not a run like 123456.']);
        }

        $override->setPin($request->user(), $pin);

        return back()->with('success', 'Your override PIN is set. Any earlier PIN has stopped working.');
    }

    /** One digit repeated, or a straight run up or down: the first six a stranger would try. */
    private function guessable(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        $digits = array_map('intval', str_split($pin));
        $steps = [];

        for ($i = 1, $n = count($digits); $i < $n; $i++) {
            $steps[] = $digits[$i] - $digits[$i - 1];
        }

        return array_unique($steps) === [1] || array_unique($steps) === [-1];
    }
}
