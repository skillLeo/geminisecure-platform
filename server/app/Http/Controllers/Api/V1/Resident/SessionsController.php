<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\ResidentApp\ResidentSessions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The phones signed in to this Resident App account — Security, "Signed-in phones" (the client, 26 Sep).
 *
 *   GET    /me/sessions             every sign-in of this account, this phone first
 *   DELETE /me/sessions/{session}   sign one of the OTHER phones out
 *   DELETE /me/sessions             sign every OTHER phone out
 *
 * See `ResidentSessions` for what is listed and why a timed-out sign-in still is.
 */
class SessionsController extends Controller
{
    public function __construct(private readonly ResidentSessions $sessions) {}

    public function index(DeviceContext $context): JsonResponse
    {
        return response()->json(['items' => $this->sessions->of($context->residentOrFail(), self::current())]);
    }

    public function destroy(int $session, DeviceContext $context): JsonResponse
    {
        return response()->json($this->sessions->end($context->residentOrFail(), $session, self::current()));
    }

    /** Sign out every OTHER phone at once (the client, 27 Sep). */
    public function destroyOthers(DeviceContext $context): JsonResponse
    {
        return response()->json($this->sessions->endOthers($context->residentOrFail(), self::current()));
    }

    /** The token this request was made with — "this phone". */
    private static function current(): ?int
    {
        $principal = Auth::guard('sanctum')->user();
        $token = $principal !== null && method_exists($principal, 'currentAccessToken') ? $principal->currentAccessToken() : null;

        return $token instanceof Model ? (int) $token->getKey() : null;
    }
}
