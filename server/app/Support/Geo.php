<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Distance on the ground between two coordinates, in metres (13 D2).
 *
 * Haversine on a spherical Earth. At the scale of a gatehouse its error is well
 * under a centimetre, which is far inside a handset's GPS accuracy — the figure
 * that actually bounds whether a guard is on post.
 */
final class Geo
{
    private const EARTH_RADIUS_M = 6_371_008.8;

    public static function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * How far a position lies OUTSIDE a boundary, in metres: 0 inside it (M57.5 §4.5).
     *
     * The boundary is a list of [latitude, longitude] points (an estate's site plan). Worked on a flat projection about
     * the position itself, which at an estate's size (a few kilometres) is out by far less than a phone's own accuracy;
     * far away, the answer is still "kilometres outside", which is all it is asked for there.
     *
     * @param  list<array{0: float|int|string, 1: float|int|string}>  $points
     */
    public static function metresOutside(float $lat, float $lng, array $points): float
    {
        if (count($points) < 3) {
            return 0.0;
        }

        $kLat = M_PI / 180 * self::EARTH_RADIUS_M;
        $kLng = $kLat * cos(deg2rad($lat));
        $xy = array_map(static fn (array $p): array => [((float) $p[1] - $lng) * $kLng, ((float) $p[0] - $lat) * $kLat], $points);

        // Inside: an odd number of edges cross the ray from the position eastwards.
        $inside = false;
        for ($i = 0, $j = count($xy) - 1; $i < count($xy); $j = $i++) {
            [$xi, $yi] = $xy[$i];
            [$xj, $yj] = $xy[$j];

            if (($yi > 0) !== ($yj > 0) && 0 < ($xj - $xi) * (0 - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        if ($inside) {
            return 0.0;
        }

        // Outside: the distance to the nearest edge.
        $nearest = INF;
        for ($i = 0, $j = count($xy) - 1; $i < count($xy); $j = $i++) {
            [$ax, $ay] = $xy[$j];
            [$bx, $by] = $xy[$i];
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $t = $dx === 0.0 && $dy === 0.0 ? 0.0 : max(0.0, min(1.0, (-$ax * $dx - $ay * $dy) / ($dx * $dx + $dy * $dy)));
            $nearest = min($nearest, hypot($ax + $t * $dx, $ay + $t * $dy));
        }

        return $nearest;
    }
}
