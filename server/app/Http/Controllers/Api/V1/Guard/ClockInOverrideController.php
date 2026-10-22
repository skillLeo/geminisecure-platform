<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceClock;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\ClockInFence;
use App\Services\Dispatch\ShiftClock;
use App\Services\Dispatch\SupervisorOverride;
use App\Support\Geo;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/shifts/{shift}/clock-in/override — a supervisor's PIN in place of the
 * phone's identity check (M4 §3.4). Board guard-app-39 draws the route to it.
 *
 * "A guard is never stranded, and an unverified clock-in is never silently accepted."
 * When the phone cannot say who is holding it — three failed matches, or no fingerprint
 * or face set up at all — the shift starts only if a supervisor vouches for the guard
 * with a PIN this server set and this server checks.
 *
 * WHO, AND SINCE M12 §1.3 WHERE. The PIN stands in for the phone's identity check
 * (`biometric_failed`, `no_biometrics`) or for the geofence: a guard the phone placed
 * outside the rostered post (`outside_geofence`), or whose position could not be
 * established (`position_unconfirmed`), is started on a supervisor vouching that they are
 * on post. The fix is judged by the same rule as any clock-in (`ClockInFence`) and kept on
 * the shift; a mocked location is recorded and flagged. Every one is flagged, audited and
 * reported to the Head of Security with the distance and the phone's accuracy.
 *
 * ORDER OF CHECKS: ability → device → whose shift (somebody else's and an unknown id are
 * the same 404) → the body → the shift's state → the PIN. The PIN is last on purpose: a
 * malformed request or a shift that has already started must not cost a supervisor one
 * of their five tries.
 *
 * ONLINE ONLY. A PIN cannot be checked without the server, so this is not in
 * `sync/batch`, and the app neither queues nor keeps what was typed.
 *
 * INVARIANT 2. The answer is a shift and who vouched for it. No rate, no pay.
 */
class ClockInOverrideController extends Controller
{
    public function store(Request $request, int $shift, ShiftClock $clock, SupervisorOverride $override, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $record = Shift::query()->whereKey($shift)->where('guard_id', $guard->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No such shift.');
        }

        $data = $request->validate([
            'supervisor' => ['required', 'string', 'max:190'],
            'pin' => ['required', 'string', 'regex:/^\d{'.SupervisorOverride::PIN_DIGITS.'}$/'],
            'reason' => ['required', 'string', 'in:'.implode(',', SupervisorOverride::REASONS)],

            // Exactly what the ordinary start carries: where the phone is, how sure it is, and whether it looks mocked.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'geofence_distance_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'mock_location' => ['nullable', 'boolean'],
        ], [
            // Said without echoing what was typed.
            'pin.regex' => 'A supervisor PIN is '.SupervisorOverride::PIN_DIGITS.' digits.',
        ]);

        if ($record->actual_start !== null) {
            throw ApiError::conflict('shift_already_started', 'This shift has already started, so there is nothing to override. Nothing was recorded against a supervisor.');
        }

        $supervisor = $override->verify((string) $data['supervisor'], (string) $data['pin'], $context->tenantId);

        if (! $supervisor instanceof User) {
            /*
             * ONE SENTENCE FOR EVERY "NO" — an address nobody has, somebody who is not a
             * supervisor here, no PIN set, a locked PIN, a wrong PIN. It names the lock
             * as a possibility for all of them, so a supervisor whose right PIN is being
             * refused knows where to look, and nobody else learns anything.
             */
            throw ApiError::forbidden('override_refused', 'That supervisor and PIN were not accepted. Check both and try again. After '.SupervisorOverride::MAX_FAILED_ATTEMPTS.' wrong tries a PIN is locked until the supervisor sets a new one in the console.');
        }

        $distance = $this->distance($record, $data);

        // What the phone saw, judged by the same rule as any clock-in (M12 §1) and kept on the shift the PIN started.
        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $accuracy = isset($data['accuracy_m']) ? (int) $data['accuracy_m'] : null;
        $verdict = ClockInFence::judge(ClockInFence::fenceFor($record), $latitude, $longitude, $accuracy);

        $started = DB::connection('mysql')->transaction(function () use ($record, $clock, $override, $supervisor, $guard, $data, $distance, $request, $context, $latitude, $longitude, $accuracy, $verdict): ?Shift {
            $locked = Shift::query()->whereKey($record->id)->lockForUpdate()->first();

            // Started by another request while the PIN was being checked: that start stands, unflagged.
            if ($locked === null || $locked->actual_start !== null) {
                return null;
            }

            $when = DeviceClock::read($request, $context);

            $locked = $clock->clockIn(
                shift: $locked,
                method: SupervisorOverride::START_METHOD,
                geofenceDistanceMetres: $distance,
                mockLocation: $request->boolean('mock_location'),
                evidence: [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'accuracy_m' => $accuracy,
                    'verdict' => $verdict,
                    'device_at' => $when['device_at'],
                    'offset_s' => $when['offset_s'],
                    'review' => $when['review'],
                ],
            );

            return $override->record($locked, $supervisor, $guard, (string) $data['reason']);
        });

        if ($started === null) {
            throw ApiError::conflict('shift_already_started', 'This shift has already started, so there is nothing to override. Nothing was recorded against a supervisor.');
        }

        return response()->json([
            'id' => $started->id,
            'status' => (string) $started->status,
            'actual_start' => SiteTime::iso($started->actual_start),
            'actual_end' => SiteTime::iso($started->actual_end),
            'mock_location_flag' => (bool) $started->mock_location_flag,
            'overridden' => true,
            'override_reason' => (string) $started->override_reason,
            'overridden_at' => SiteTime::iso($started->overridden_at),
            'overridden_by' => $supervisor->name,
        ]);
    }

    /**
     * Metres from the post. The server's own sum where the phone sent a position and the
     * post has been surveyed; otherwise the phone's figure, or null — recorded, never enforced.
     *
     * @param  array<string, mixed>  $data
     */
    private function distance(Shift $shift, array $data): ?int
    {
        $post = $shift->post;
        $metres = null;

        if ($post !== null && $post->latitude !== null && $post->longitude !== null && isset($data['latitude'], $data['longitude'])) {
            $metres = (int) round(Geo::metresBetween((float) $post->latitude, (float) $post->longitude, (float) $data['latitude'], (float) $data['longitude']));
        } elseif (isset($data['geofence_distance_m'])) {
            $metres = (int) $data['geofence_distance_m'];
        }

        // `shifts.geofence_distance_m` is an unsigned small integer.
        return $metres === null ? null : min($metres, 65535);
    }
}
