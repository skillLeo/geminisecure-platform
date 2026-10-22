<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Support\Geo;
use Illuminate\Support\Facades\DB;

/**
 * IS THIS POSITION ON THE ESTATE? (Hassam, 12 Oct: one bad coordinate must not stretch the whole view.)
 *
 * A map fits itself to everything it draws, so one wrong position pulled Phoenix Park's map across two continents: a
 * gate stored 36 km from the estate, and panics pressed on a development phone in Pakistan. A position is CONFIRMED
 * when it lies on the estate, inside the boundary its site plan draws (a metre's grace for a point drawn on the line
 * itself). "Anything outside it, alert or gate" (Hassam, 12 Oct) is listed beside the map as "Position not confirmed",
 * with how far out it is, and never plotted, so the map frames the estate. Nothing is corrected and nothing is hidden:
 * the record keeps the position it was given, and the list names it; an open alert there keeps its link.
 *
 * Officers on duty are not judged here: a fix more than `GuardTracking::IMPOSSIBLE_OUTSIDE_M` out is refused when it
 * arrives, and one just outside is an officer on the perimeter road, drawn where they are.
 *
 * An estate with no boundary has nothing to judge a position against, so its positions are drawn as they always were.
 */
final class EstateBounds
{
    /**
     * Each estate's boundary from its site plan ([latitude, longitude] points), for the estates that have one.
     *
     * @param  list<string>  $tenantIds
     * @return array<string, list<array{0: float, 1: float}>>
     */
    public static function of(array $tenantIds): array
    {
        return self::sites($tenantIds)['boundaries'];
    }

    /**
     * Each estate's boundary, with its guards' No signal tolerance (GuardFreshness::tolerances, the one place that is
     * read): what a map that frames its estates and judges its guards' pins needs, in one read.
     *
     * @param  list<string>  $tenantIds
     * @return array{boundaries: array<string, list<array{0: float, 1: float}>>, tolerances: array<string, int>}
     */
    public static function sites(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return ['boundaries' => [], 'tolerances' => []];
        }

        $bounds = [];

        foreach (DB::connection('mysql')->table('estate_sites')->whereIn('tenant_id', $tenantIds)->whereNotNull('boundary')->get(['tenant_id', 'boundary']) as $site) {
            $points = json_decode((string) $site->boundary, true);

            if (is_array($points) && count($points) >= 3) {
                $bounds[(string) $site->tenant_id] = array_values($points);
            }
        }

        return ['boundaries' => $bounds, 'tolerances' => GuardFreshness::tolerances($tenantIds)];
    }

    /** Closer to the line than this is on it: the boundary is drawn, not surveyed to the millimetre. */
    private const ON_THE_LINE_M = 1.0;

    /**
     * Why a position cannot be drawn on its estate's map ("36 km outside the estate's boundary"), or null when it can:
     * it lies on the estate, or the estate has no boundary to judge it against.
     *
     * @param  list<array{0: float, 1: float}>|null  $boundary
     */
    public static function notConfirmed(?array $boundary, float $lat, float $lng): ?string
    {
        if ($boundary === null) {
            return null;
        }

        $outside = Geo::metresOutside($lat, $lng, $boundary);

        if ($outside < self::ON_THE_LINE_M) {
            return null;
        }

        // Metres under a kilometre; then the words a refused guard fix uses (GuardTracking): whole kilometres from ten on.
        return (round($outside) < 1_000
            ? number_format($outside).' m'
            : number_format($outside / 1000, $outside >= 10_000 ? 0 : 1).' km').' outside the estate\'s boundary';
    }
}
