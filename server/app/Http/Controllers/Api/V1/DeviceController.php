<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Devices\DeviceResume;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Reviewer\ReviewerMode;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/devices/enrol, and collecting a rebind (13 D1). See `DeviceEnrolment`.
 * And a bound phone signing back in without a code: /devices/challenge, /devices/resume (M32 F3, `DeviceResume`).
 *
 * Unauthenticated by necessity — this is where a handset gets its token — and
 * throttled per address, because an enrolment code is a secret worth guessing.
 */
class DeviceController extends Controller
{
    public function enrol(Request $request, DeviceEnrolment $enrolment): JsonResponse
    {
        $data = $request->validate([
            'enrolment_code' => ['required', 'string', 'max:32'],
            'device_uid' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'public_key' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:120'],
            // The handset's own identity (M46 §1): stored only as a hash, it is what lets this phone recover after a reinstall.
            'hardware_id' => ['nullable', 'string', 'max:128'],
        ]);

        $result = $enrolment->enrolWithCode($data);
        $guard = $result['guard'];

        $body = [
            'status' => $result['status'],

            // A spent code taking back the binding its own handset already had (M46 §1), not a new enrolment.
            'recovered' => (bool) ($result['recovered'] ?? false),

            /*
             * REVIEWER MODE (M5 §3). True only for an account the SERVER has flagged, and it
             * is how the Guard App knows it may accept this phone's passcode instead of a
             * fingerprint it has never been shown. An ordinary handset is told `false` and
             * has no path to the reviewer behaviour at all — there is no build flag to find.
             */
            'reviewer' => ReviewerMode::isReviewer($guard),

            'guard' => [
                'id' => $guard->id,
                'name' => $guard->full_name,
                'employee_number' => $guard->employee_number,
            ],
            'site' => [
                'id' => (string) $guard->tenant_id,
                'name' => (string) ($guard->estate->name ?? $guard->tenant_id),

                // Every time the API sends is a UTC instant; this is the zone to SHOW it in (M4 §3.1).
                'timezone' => SiteTime::zone((string) $guard->tenant_id),
            ],
        ];

        if ($result['status'] === 'enrolled') {
            return response()->json([...$body, 'token' => $result['token'], 'abilities' => $result['abilities']], 201);
        }

        return response()->json([
            ...$body,
            'rebind_request_id' => $result['rebind_request_id'],
            'claim_secret' => $result['claim_secret'],
        ], 202);
    }

    public function collect(Request $request, int $rebind, DeviceEnrolment $enrolment): JsonResponse
    {
        $data = $request->validate([
            'claim_secret' => ['required', 'string', 'max:64'],
            'device_uid' => ['required', 'string', 'max:120'],
        ]);

        return response()->json($enrolment->collect($rebind, $data['claim_secret'], $data['device_uid']));
    }

    /** A one-time challenge for a bound phone to sign (M32 F3). See `DeviceResume`. */
    public function challenge(Request $request, DeviceResume $resume): JsonResponse
    {
        $data = $request->validate(['device_uid' => ['required', 'string', 'max:120']]);

        return response()->json($resume->challenge($data['device_uid']));
    }

    /**
     * A bound phone signing back in without a code (M32 F3): its own key's signature, and the guard's fingerprint on
     * the phone or a supervisor's PIN here. `check` asks only whether the phone is still bound. See `DeviceResume`.
     */
    public function resume(Request $request, DeviceResume $resume): JsonResponse
    {
        $data = $request->validate([
            'device_uid' => ['required', 'string', 'max:120'],
            'challenge' => ['required', 'string', 'max:64'],
            'signature' => ['required', 'string', 'max:128'],
            'method' => ['required', 'string', 'in:'.implode(',', DeviceResume::METHODS)],
            'supervisor' => ['nullable', 'required_if:method,supervisor_pin', 'string', 'max:190'],
            'pin' => ['nullable', 'required_if:method,supervisor_pin', 'string', 'regex:/^\d{'.SupervisorOverride::PIN_DIGITS.'}$/'],
            'hardware_id' => ['nullable', 'string', 'max:128'],
        ], [
            // Said without echoing what was typed (the clock-in override's own words).
            'pin.regex' => 'A supervisor PIN is '.SupervisorOverride::PIN_DIGITS.' digits.',
        ]);

        $result = $resume->resume($data);
        $guard = $result['guard'];

        return response()->json([
            'state' => $result['state'],
            'reviewer' => ReviewerMode::isReviewer($guard),
            'guard' => [
                'id' => $guard->id,
                'name' => $guard->full_name,
                'employee_number' => $guard->employee_number,
            ],
            'site' => [
                'id' => (string) $guard->tenant_id,
                'name' => (string) ($guard->estate->name ?? $guard->tenant_id),
                'timezone' => SiteTime::zone((string) $guard->tenant_id),
            ],
            ...(isset($result['token']) ? ['token' => $result['token'], 'abilities' => $result['abilities'] ?? []] : []),
        ]);
    }
}
