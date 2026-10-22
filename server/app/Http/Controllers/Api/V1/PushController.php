<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\Push\ExpoPush;
use App\Services\Push\PushTokens;
use App\Services\Push\PushTypes;
use App\Services\Ring\RingReadiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Where to reach this phone when the app is closed (M4 §3.2). Both apps.
 *
 *   POST   /push/token   this install's Expo push token, its platform, and the
 *                        notification categories switched off ON THIS PHONE
 *   DELETE /push/token   sign-out: stop pushing to this sign-in's phone. Called
 *                        BEFORE the app drops its API token
 *
 * THE OWNER COMES FROM THE API TOKEN AND NOTHING ELSE — the estate, the guard or
 * the account the middleware resolved. No id in the body is read, so a phone can
 * register itself and only itself. The Expo token is never returned, by either
 * endpoint: an answer that echoed it would put a delivery address into the
 * idempotency store and every log that records a response.
 *
 * PREFERENCES RIDE ON THE REGISTRATION. The phones keep their notification
 * switches per phone (they are the offline truth); the server needs them only to
 * decide a push the phone never sees arrive. So the phone sends `muted` whenever it
 * registers, and registers again whenever a switch changes. Panic, duress, an
 * alertness check and somebody at the gate have no category: they cannot be muted,
 * and a name that is not one of the app's switchable categories is dropped.
 */
class PushController extends Controller
{
    public function register(Request $request, DeviceContext $context, PushTokens $tokens): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:190'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'muted' => ['nullable', 'array', 'max:32'],
            'muted.*' => ['string', 'max:40'],
        ]);

        if (! ExpoPush::validToken($data['token'])) {
            throw ApiError::unprocessable('push_token_invalid', 'That is not an Expo push token. It looks like ExponentPushToken[…].');
        }

        /*
         * WHETHER THIS PHONE CAN RING LIKE A CALL (the guard ring, spec item 3) — Guard App only.
         *
         * NEVER VALIDATED, ON PURPOSE (G1). One rule above refusing one unexpected value — a
         * readiness state a newer app knows, a maker with a space in it — would answer 422 and
         * store NO token, and that guard would then get nothing at all, today's panic push
         * included. So `ring` is read raw and parsed leniently: a doubtful report lands on the
         * side that is also sent the ordinary alert, and only `token`, `platform` and `muted`
         * can ever refuse a registration, exactly as before.
         */
        $ring = $context->app === AppMatrix::GUARD ? RingReadiness::parse($request->input('ring')) : null;

        // Quiet hours on this phone (M50 §4.2 #5), read leniently for the same reason as `ring`: never a refusal.
        $row = $tokens->register($context, $this->accessTokenId(), $data['token'], $data['platform'], array_values($data['muted'] ?? []), $ring, $request->input('quiet'));

        return response()->json([
            'registered' => true,
            'muted' => $row->muted ?? [],
            'always_on' => self::alwaysOn($context->app),
            'ring_state' => $row->ring_state,
            'quiet' => $row->quiet_from === null ? null : ['from' => $row->quiet_from, 'to' => $row->quiet_to],
        ]);
    }

    public function unregister(DeviceContext $context, PushTokens $tokens): JsonResponse
    {
        $tokens->unregister($context, $this->accessTokenId());

        // The same answer whether or not there was anything to remove: signing out twice is not an error.
        return response()->json(['registered' => false]);
    }

    /** The Sanctum token this request was made with — what ties a push address to a sign-in. */
    private function accessTokenId(): ?int
    {
        $principal = Auth::guard('sanctum')->user();
        $token = $principal !== null && method_exists($principal, 'currentAccessToken') ? $principal->currentAccessToken() : null;

        return $token instanceof Model ? (int) $token->getKey() : null;
    }

    /**
     * The types this app's phone cannot switch off — so the settings screen can say so.
     *
     * @return list<string>
     */
    private static function alwaysOn(string $app): array
    {
        return array_keys(array_filter(PushTypes::TYPES, static fn (array $type): bool => $type['app'] === $app && $type['category'] === null));
    }
}
