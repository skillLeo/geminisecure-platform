<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\DeviceClock;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\Dispatch\ClockInFence;
use App\Services\Dispatch\ShiftClock;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/shifts/{shift}/clock-in and /clock-out.
 *
 * What board guard-app-01 does when a guard taps "Clock in", and what makes the
 * dispatch map say a post is manned. Until this existed every post on that map
 * was rostered and none was worked, because `shifts.actual_start` had no writer.
 *
 * INVARIANT 2. A shift carries a guard, a post and two timestamps. It does not
 * carry a rate — `guards.standard_rate_minor` is central payroll's and is never
 * read here — so nothing a handset can call returns what anybody is paid.
 *
 * THE RESPONSE TELLS THE HANDSET WHAT THE SERVER THINKS THE TIME IS. A guard
 * clocking on from a device with a wrong clock has to be able to find out, and
 * the shift's own start is the server's. That is the same reason an alert echoes
 * `server_time`: the device is not the authority on when something happened.
 */
class ShiftController extends Controller
{
    public function clockIn(Request $request, Shift $shift, ShiftClock $clock, DeviceContext $context): JsonResponse
    {
        $this->assertTheirs($shift, $context);

        $data = $request->validate([
            /*
             * THE FIX THE START IS JUDGED ON (M12 §1): where the phone was when the guard tapped, and how sure it was.
             * The server judges it itself (ClockInFence) — the phone's own verdict is never taken on trust.
             */
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:65535'],

            // The run of the app and its steady clock at the tap (DeviceClock): whether the phone's clock was changed
            // while the clock-in waited. Their presence is also how a build that judges the fence is told apart from
            // an older one, which is recorded as before and never refused.
            'captured_run' => ['nullable', 'string', 'max:64'],
            'captured_mono_ms' => ['nullable', 'integer', 'min:0'],

            /*
             * Distance from the post, in metres, as an OLDER build worked it out. Stored as it was; a build that sends
             * a position has its distance worked out here instead.
             */
            'geofence_distance_m' => ['nullable', 'integer', 'min:0', 'max:100000'],

            /*
             * The handset saying its own location provider is being spoofed.
             * Recorded, never refused — see `ShiftClock`. A post reading as
             * unmanned while somebody stands at it is worse than a flag a
             * supervisor has to look at, and refusing would tell whoever
             * spoofed it that they had been caught.
             */
            'mock_location' => ['nullable', 'boolean'],

            /*
             * `app` AND NOTHING ELSE (M4 §3.4). This endpoint is the start the PHONE
             * CONFIRMED. `manual` used to be accepted here and was how a handset with no
             * fingerprint or face set up started a shift nobody had identified — the
             * "silent unconfirmed start" the client ruled out. A start the phone cannot
             * confirm now goes to `shifts.clock_in_override`, where a supervisor's PIN is
             * checked by this server and the shift is flagged, audited and reported.
             * `ShiftClock::METHOD_MANUAL` stays for the console and the simulator, which
             * do not come through here.
             */
            'method' => ['nullable', 'string', 'in:app'],

            // The simulator saying so, the way it does on an alert or a gate
            // event — so the coverage board's source badge can tell.
            'simulated' => ['nullable', 'boolean'],
        ]);

        if ($shift->actual_start !== null) {
            // The first clock-in is the one that happened; a replay is answered with it, and judged no further.
            return response()->json($this->payload($shift));
        }

        $judges = array_key_exists('captured_run', $data) && $data['captured_run'] !== null;
        $when = DeviceClock::read($request, $context, $data['captured_run'] ?? null, isset($data['captured_mono_ms']) ? (int) $data['captured_mono_ms'] : null);
        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $accuracy = isset($data['accuracy_m']) ? (int) $data['accuracy_m'] : null;

        $verdict = null;
        $review = $when['review'];

        if ($judges) {
            $verdict = ClockInFence::judge(ClockInFence::fenceFor($shift), $latitude, $longitude, $accuracy);

            if (! $verdict['allowed']) {
                /*
                 * REFUSED WHILE THE GUARD IS STANDING THERE; RECORDED AND FLAGGED WHEN IT WAITED. A live start outside
                 * the fence — or with no position — is refused with the distance, and the phone offers the retry and
                 * the supervisor's PIN (M12 §1.3). A start captured with no signal was judged inside by the phone
                 * against the fence it had cached; refusing it hours later would leave a post the guard worked
                 * reading as unmanned, so it starts and a supervisor is shown why it needs a look.
                 */
                if ($when['live']) {
                    throw ApiError::conflict(
                        $verdict['outcome'] === ClockInFence::OUTSIDE ? 'outside_geofence' : 'position_unconfirmed',
                        ClockInFence::sentence($verdict, $shift->post?->label() ?? 'your post'),
                    );
                }

                $review[] = ShiftClock::REVIEW_OUTSIDE_AT_CAPTURE;
            }
        }

        $shift = $clock->clockIn(
            shift: $shift,
            // When the guard tapped, on the server's clock — an older build's start is the moment it arrived, as before.
            at: $judges ? $when['captured_at'] : null,
            method: $data['method'] ?? ShiftClock::METHOD_APP,
            geofenceDistanceMetres: ! $judges && isset($data['geofence_distance_m']) ? (int) $data['geofence_distance_m'] : null,
            mockLocation: $request->boolean('mock_location'),
            isSimulated: $request->boolean('simulated'),
            evidence: $judges ? [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_m' => $accuracy,
                'verdict' => $verdict,
                'device_at' => $when['device_at'],
                'offset_s' => $when['offset_s'],
                'review' => $review,
            ] : [],
        );

        return response()->json($this->payload($shift));
    }

    public function clockOut(Request $request, Shift $shift, ShiftClock $clock, DeviceContext $context): JsonResponse
    {
        $this->assertTheirs($shift, $context);

        $data = $request->validate([
            'handover_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $shift = $clock->clockOut($shift, handoverNote: $data['handover_note'] ?? null);
        } catch (DomainException $e) {
            /*
             * 409 rather than 422. The request is well-formed and the handset
             * did nothing wrong — the shift is simply in a state that cannot
             * accept an ending yet, and the queued clock-in it still holds is
             * what resolves it. A validation error would send the guard looking
             * at their own input.
             */
            throw ApiError::conflict('shift_not_started', $e->getMessage());
        }

        return response()->json($this->payload($shift));
    }

    /**
     * A handset clocks its own guard's shifts and nobody else's (13 D1). Before
     * this, any guard token could start any shift on the platform.
     */
    private function assertTheirs(Shift $shift, DeviceContext $context): void
    {
        /*
         * ANOTHER ESTATE'S SHIFT IS NOT A SHIFT. `shifts` is central, so route binding
         * resolves an id from any estate on the platform; a 403 for one that exists beside a
         * 404 for one that does not let a single handset enumerate every estate's roster.
         * The estate is the TOKEN's. A colleague's shift at this guard's own site keeps the
         * documented `403 not_your_shift` — that is dispatch, not another community.
         */
        if ((string) $shift->tenant_id !== $context->tenantId) {
            throw ApiError::notFound('not_found', 'No such shift.');
        }

        if ($shift->guard_id !== $context->guardOrFail()->id) {
            throw ApiError::forbidden('not_your_shift', 'This shift is rostered to another guard. A handset clocks its own guard\'s shifts.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'actual_start' => SiteTime::iso($shift->actual_start),
            'actual_end' => SiteTime::iso($shift->actual_end),
            'mock_location_flag' => (bool) $shift->mock_location_flag,
            // What the start was judged on (M12 §1), so the phone can say it: `inside`, `inside_margin`, `no_geofence`…
            'geofence_outcome' => $shift->geofence_outcome,
            'review_reasons' => $shift->review_reasons ?? [],
        ];
    }
}
