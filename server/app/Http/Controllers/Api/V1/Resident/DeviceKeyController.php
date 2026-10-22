<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\ResidentApp\ResidentDeviceKeys;
use App\Services\ResidentApp\ResidentSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "Face ID / fingerprint" on THIS phone (Security, board 19; M49) — the key that signs it back in with no emailed code.
 *
 *   POST   /me/device-key   switched on: a new key for this install, returned once (the phone keeps it behind its own
 *                           biometric prompt); any earlier key of this install or handset stops working
 *   DELETE /me/device-key   switched off: this install's key stops working
 *
 * Only for the install the request is made from — the token's own install id, never one the body names — so a session
 * cannot hand out a key for another phone. Not from a demonstration session, whose account is shared.
 */
class DeviceKeyController extends Controller
{
    public function __construct(private readonly ResidentDeviceKeys $keys) {}

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'string', 'in:ios,android'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'hardware_id' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $account = $context->residentOrFail();
        $install = self::install();

        if ($install === null) {
            throw ApiError::forbidden('not_this_phone', 'Fingerprint sign-in can only be turned on from the phone it is for, signed in with your own account.');
        }

        $key = $this->keys->issue($account, $install, $data['platform'], $data['device_name'] ?? null, $data['hardware_id'] ?? null);

        return response()->json(['key' => $key, 'on' => true], 201);
    }

    public function destroy(DeviceContext $context): JsonResponse
    {
        $account = $context->residentOrFail();
        $install = self::install();

        return response()->json(['on' => false, 'revoked' => $install === null ? 0 : $this->keys->revoke($account, $install, 'switched_off')]);
    }

    /** The install this request comes from: its token's own name. Null for a demonstration session or no token. */
    private static function install(): ?string
    {
        $principal = Auth::guard('sanctum')->user();
        $token = $principal !== null && method_exists($principal, 'currentAccessToken') ? $principal->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $name = (string) $token->name;

        if (! str_starts_with($name, ResidentSessions::TOKEN_PREFIX)) {
            return null;
        }

        $install = substr($name, strlen(ResidentSessions::TOKEN_PREFIX));

        return $install === '' ? null : $install;
    }
}
