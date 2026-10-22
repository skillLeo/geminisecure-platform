<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\Post;
use App\Models\Shift;
use App\Support\DemoMode;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

/**
 * WHETHER A GUARD IS AT THE POST THEY ARE ROSTERED TO — the one rule a clock-in is decided by (M12 §1).
 *
 * The pre-flight asks it before the tap, the clock-in asks it again with the fix the start was captured with, and
 * the supervisor override records it. The Guard App runs the same rule against the fence it cached at its last sync
 * when there is no signal (packages/core/lib/clockInFence.ts, which is tested against these numbers).
 *
 *   WHICH FENCE   the rostered POST's own — its surveyed centre and radius. A post nobody has placed has none, and
 *                 the estate's own fence (`estate_sites`) stands in, and the record says so. Neither placed: there
 *                 is no geofence, the shift starts and is marked "no geofence configured" (D-178) — a position is
 *                 never invented to fill the gap.
 *   THE FIX IS A CIRCLE, not a point. The accuracy a phone reports is the radius it is 68% sure of (Android's and
 *                 iOS's own definition); twice it is about 95%. So the margin counted is TWICE the accuracy, never more
 *                 than MAX_MARGIN_M (D-178) — which is what makes M12's own example come out as the client said: 30 m
 *                 accuracy, 35 m outside a 50 m fence, is inside within the margin of error (85 ≤ 50 + 50). A poor
 *                 fix still cannot carry a guard far outside, because the margin stops at 50 m. Past POOR_ACCURACY_M
 *                 the position is not established at all, and the guard is told exactly that — never "you are not
 *                 at the estate" — with the supervisor override offered.
 *   NO FIX        location refused, the phone's location switched off, or no answer from the satellites: the
 *                 position is not established. The phone says which, and how to put it right; the server knows only
 *                 that no position came.
 *   A MOCKED LOCATION is not judged here: it is recorded, flagged for the Head of Security and never refused alone
 *                 (ShiftClock) — the position it reports is still what the fence is asked about.
 */
final class ClockInFence
{
    /** The accuracy a fix is taken until it reaches (M12 §1.2: "aim for 50 m or better"). */
    public const GOOD_ACCURACY_M = 50;

    /** Past this the position is not established: tall buildings, a canopy, a phone that has not settled. */
    public const POOR_ACCURACY_M = 200;

    /** The most margin a fix's accuracy earns in the guard's favour. */
    public const MAX_MARGIN_M = 50;

    /** The margin is this many times the reported accuracy: the 68% radius a phone reports, widened to about 95%. */
    public const MARGIN_PER_ACCURACY = 2;

    public const SOURCE_POST = 'post';

    public const SOURCE_ESTATE = 'estate';

    public const SOURCE_NONE = 'none';

    public const INSIDE = 'inside';

    public const INSIDE_MARGIN = 'inside_margin';

    public const OUTSIDE = 'outside';

    public const POOR_FIX = 'poor_fix';

    public const NO_POSITION = 'no_position';

    public const NO_GEOFENCE = 'no_geofence';

    /** The outcomes a clock-in may start on without a supervisor. */
    public const ALLOWED = [self::INSIDE, self::INSIDE_MARGIN, self::NO_GEOFENCE];

    /** The outcomes a supervisor's PIN may start a shift over, and the override reason each is recorded as. */
    public const OVERRIDE_REASON = [
        self::OUTSIDE => SupervisorOverride::REASON_OUTSIDE_GEOFENCE,
        self::POOR_FIX => SupervisorOverride::REASON_POSITION_UNCONFIRMED,
        self::NO_POSITION => SupervisorOverride::REASON_POSITION_UNCONFIRMED,
    ];

    private const COMPASS = ['north', 'north-east', 'east', 'south-east', 'south', 'south-west', 'west', 'north-west'];

    /**
     * The fence a shift's clock-in is judged against: the post's, else the estate's, else none.
     *
     * A PATROL CLOCKS IN AT ITS POST, LIKE ANYBODY (M27, D-225). The client's patrol is a vehicle across five phases
     * that starts at the Main Gate, so its shift is posted at the Main Gate and judged by the Main Gate's own fence;
     * the patrol after it is evidenced by checkpoint scans and the position trail, never by a circle. The fence is
     * named as the estate names the post (M26), "Back Gate (Gate 5, Phase 5 Gate)".
     *
     * @return array{source: string, name: string, latitude: float, longitude: float, radius_m: int}|null
     */
    public static function fenceFor(Shift $shift): ?array
    {
        $post = $shift->post;

        if ($post instanceof Post && $post->isPlaced()) {
            return [
                'source' => self::SOURCE_POST,
                'name' => $post->label(),
                'latitude' => (float) $post->latitude,
                'longitude' => (float) $post->longitude,
                'radius_m' => max(1, (int) $post->geofence_radius_m),
            ];
        }

        $site = DB::connection('mysql')->table('estate_sites')->where('tenant_id', (string) $shift->tenant_id)->first();

        if ($site !== null && $site->latitude !== null && $site->longitude !== null && $site->geofence_radius_m !== null) {
            return [
                'source' => self::SOURCE_ESTATE,
                'name' => (string) ($shift->estate->name ?? 'the estate'),
                'latitude' => (float) $site->latitude,
                'longitude' => (float) $site->longitude,
                'radius_m' => max(1, (int) $site->geofence_radius_m),
            ];
        }

        return null;
    }

    /**
     * The judgement, and everything the screen and the record need to say about it.
     *
     * @param  array{source: string, name: string, latitude: float, longitude: float, radius_m: int}|null  $fence
     * @return array{outcome: string, allowed: bool, source: string, fence_name: string|null, radius_m: int|null, distance_m: int|null, beyond_m: int|null, accuracy_m: int|null, margin_m: int|null, bearing_deg: int|null, direction: string|null, override_reason: string|null}
     */
    public static function judge(?array $fence, ?float $latitude, ?float $longitude, ?int $accuracyM): array
    {
        $base = [
            'source' => $fence['source'] ?? self::SOURCE_NONE,
            'fence_name' => $fence['name'] ?? null,
            'radius_m' => $fence['radius_m'] ?? null,
            'distance_m' => null,
            'beyond_m' => null,
            'accuracy_m' => $accuracyM,
            'margin_m' => null,
            'bearing_deg' => null,
            'direction' => null,
        ];

        if ($fence === null) {
            /*
             * A POST NOBODY HAS SURVEYED. A position is never invented to stand in for the
             * missing fence (that was always so, D-178). What changes at launch (M16 §4): the
             * clock-in is no longer waved through "marked for the office" — it is REFUSED, the
             * guard is told the post has no surveyed position, and the supervisor override is
             * offered (the same escape hatch an out-of-fence clock-in gets). On a demonstration
             * server the shift still starts and is marked, so a half-surveyed demo is not stuck.
             */
            $allowed = DemoMode::enabled();

            return [...$base, 'outcome' => self::NO_GEOFENCE, 'allowed' => $allowed, 'override_reason' => $allowed ? null : self::OVERRIDE_REASON[self::NO_POSITION]];
        }

        if ($latitude === null || $longitude === null) {
            return [...$base, 'outcome' => self::NO_POSITION, 'allowed' => false, 'override_reason' => self::OVERRIDE_REASON[self::NO_POSITION]];
        }

        $distance = (int) round(Geo::metresBetween($fence['latitude'], $fence['longitude'], $latitude, $longitude));
        $bearing = self::bearing($latitude, $longitude, $fence['latitude'], $fence['longitude']);
        $placed = [
            ...$base,
            'distance_m' => $distance,
            'beyond_m' => max(0, $distance - $fence['radius_m']),
            'bearing_deg' => $bearing,
            'direction' => self::COMPASS[(int) round($bearing / 45) % 8],
        ];

        if ($accuracyM !== null && $accuracyM > self::POOR_ACCURACY_M) {
            return [...$placed, 'outcome' => self::POOR_FIX, 'allowed' => false, 'override_reason' => self::OVERRIDE_REASON[self::POOR_FIX]];
        }

        if ($distance <= $fence['radius_m']) {
            return [...$placed, 'outcome' => self::INSIDE, 'allowed' => true, 'margin_m' => 0, 'override_reason' => null];
        }

        $margin = min(self::MARGIN_PER_ACCURACY * max(0, (int) $accuracyM), self::MAX_MARGIN_M);

        if ($distance <= $fence['radius_m'] + $margin) {
            return [...$placed, 'outcome' => self::INSIDE_MARGIN, 'allowed' => true, 'margin_m' => $margin, 'override_reason' => null];
        }

        return [...$placed, 'outcome' => self::OUTSIDE, 'allowed' => false, 'margin_m' => $margin, 'override_reason' => self::OVERRIDE_REASON[self::OUTSIDE]];
    }

    /**
     * What the guard is told — the post by name, how far, which way, and what to do. Never "not in the estate area".
     *
     * The distance is to the FENCE, by its own name, so a patrol officer at the Main Gate reads "You are 300m from Main
     * Gate" exactly as the gate officer does (M27). `$postName` is the shift's post as `Post::label()` gives it.
     *
     * @param  array<string, mixed>  $verdict  one `judge()` answer
     */
    public static function sentence(array $verdict, string $postName): string
    {
        $estate = $verdict['source'] === self::SOURCE_ESTATE;
        $fence = $estate ? 'the estate’s area' : (string) ($verdict['fence_name'] ?? $postName);

        return match ($verdict['outcome']) {
            self::INSIDE => sprintf('%dm from %s, within its %dm geofence.', $verdict['distance_m'], $fence, $verdict['radius_m']),
            self::INSIDE_MARGIN => sprintf('%dm from %s: %dm past its %dm geofence, inside the margin of error of your phone’s ±%dm reading.', $verdict['distance_m'], $fence, $verdict['beyond_m'], $verdict['radius_m'], $verdict['accuracy_m']),
            self::OUTSIDE => sprintf('You are %dm from %s — %dm outside its %dm geofence. %s is %s of you. Move closer to the gate and try again.', $verdict['distance_m'], $fence, $verdict['beyond_m'], $verdict['radius_m'], $estate ? 'The estate' : $fence, $verdict['direction']),
            self::POOR_FIX => sprintf('Your position could not be established: the phone is only sure to within %dm. Step into open sky and try again, or ask your supervisor to start the shift with their PIN.', $verdict['accuracy_m']),
            self::NO_POSITION => 'Your position could not be established: no location came from this phone. Turn location on and try again, or ask your supervisor to start the shift with their PIN.',
            // NO_GEOFENCE reads two ways: on a demo server the shift starts and is marked; at
            // launch it is refused until the post is surveyed (M16 §4), with the override offered.
            default => ($verdict['allowed'] ?? false)
                ? sprintf('%s has no geofence configured, so where you are cannot be checked. The shift starts and is marked for the office.', $postName)
                : sprintf('%s has no surveyed position yet, so a clock-in here cannot be checked against it. Ask the office to survey this post; until then your supervisor can start the shift with their PIN.', $postName),
        };
    }

    /** Initial bearing from one point to another, in whole degrees clockwise from north. */
    private static function bearing(float $fromLat, float $fromLng, float $toLat, float $toLng): int
    {
        $phi1 = deg2rad($fromLat);
        $phi2 = deg2rad($toLat);
        $dLambda = deg2rad($toLng - $fromLng);

        $y = sin($dLambda) * cos($phi2);
        $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dLambda);

        return ((int) round(rad2deg(atan2($y, $x))) + 360) % 360;
    }
}
