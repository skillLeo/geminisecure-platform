<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * A REAL SIGN-OUT (M6, Q-M42). Both apps.
 *
 *   DELETE /api/v1/session   destroy the token this request was made with
 *
 * Until this existed, "Sign out" was a local act: the phone deleted its own copy of the
 * token and the row on the server stayed valid for ever. A token ever copied off a
 * handset — a backup, a cable, a phone sold — kept working. The button said a thing that
 * was not true, and the app's own code said so in as many words: "There is no server
 * 'log out'." Now there is, and the word on the button is true.
 *
 * IT DESTROYS ONE TOKEN AND NOTHING ELSE. Not the guard's other handsets (there is only
 * ever one, but that is enrolment's rule, not this endpoint's), not the account, not the
 * estate's record of anything. A resident signing out on a borrowed phone ends THAT
 * sign-in; their own phone is untouched.
 *
 * AND IT TAKES THE PUSH ADDRESS WITH IT, without being asked to. `device_push_tokens`
 * rows carry the `access_token_id` they were registered with, and `PushNotifier` drops
 * any address whose API token no longer exists — so a phone that has been signed out
 * cannot be pushed to even if it never reached `DELETE /push/token` first. That call
 * still happens first from both apps (it is the tidier record and it works when this one
 * cannot be reached), but sign-out no longer DEPENDS on it.
 *
 * WHAT IT IS NOT. Not a write in the catalogue's sense: no Idempotency-Key, no
 * X-Device-Time, never queued. Signing out twice is not an error and there is nothing to
 * replay — the second attempt simply has no token to present and is answered 401, which
 * is the truth. It is not behind a capability of its own either, for the same reason
 * `push.register` is not: a capability invented today is missing from every token already
 * issued, and every one of those handsets would be unable to sign out.
 */
class SessionController extends Controller
{
    public function destroy(): JsonResponse
    {
        $principal = Auth::guard('sanctum')->user();
        $token = $principal !== null && method_exists($principal, 'currentAccessToken') ? $principal->currentAccessToken() : null;

        /*
         * `instanceof Model` rather than a blind `->delete()`: Sanctum hands back a
         * `TransientToken` for a first-party session and a mock under `Sanctum::actingAs`,
         * and neither can be deleted. On this API the token is always a real row, but an
         * endpoint whose whole job is to destroy a credential should be the one place
         * that checks what it has been handed.
         */
        if ($token instanceof Model) {
            $token->delete();
        }

        return response()->json(['signed_out' => true]);
    }
}
