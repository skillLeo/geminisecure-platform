<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Dispatch\PanicWatch;
use App\Services\Estate\EstateSecurity;
use App\Services\Estate\EstateSecurityMap;
use App\Support\SourceBadge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The estate's own security screen — M10 §2.
 *
 * The community whose gate it is could not see its own security picture: panic
 * alerts, incidents, gate events and guard presence are all central records, and
 * every surface that drew them was a Gemini console screen. This is the estate's
 * side of the same facts, scoped to one tenant.
 *
 * NO WRITES. Acknowledging an alert, closing an incident and admitting somebody
 * at a gate are all acts with a record behind them, and every one of them
 * happens where it already happens — the dispatcher's console and the Guard App.
 * A second door onto those records would be a second place for the rules to
 * drift. What this screen does is let a committee SEE what is happening at their
 * own estate, which is what the section asks for and what they had no way to do.
 */
class SecurityController extends Controller
{
    public function index(Request $request, EstateSecurity $security, EstateSecurityMap $map): Response
    {
        $estateId = (string) tenant()->getTenantKey();

        // M32 F5: three tables, each ordered and paged by the server under its own words in the address.
        $board = $security->board($estateId, $request);

        return inertia('Estate/Security/Index', [
            'estate' => ['name' => (string) tenant()->name],
            ...$board,

            // The map: guards on duty here, open panics, who is going and who is nearest (EstateSecurityMap).
            'map' => fn (): array => $map->for($estateId, onDuty: $security->onDuty($estateId), site: $security->site($estateId) ?? false),

            /*
             * Category B (13 C3). Every row on this screen originates on a
             * handset — a resident's panic, a guard's incident, a scan at the
             * gate — so the badge says whether what is being read came from real
             * devices or from the simulator. Asked of the alerts actually on the
             * screen rather than of the table.
             */
            'sourceBadge' => SourceBadge::guard(
                SourceBadge::anySimulatedIn('mysql', 'duress_alerts', array_column($board['alerts'], 'id')),
            ),
        ]);
    }

    /**
     * THE SECURITY MAP'S ONE FEED (12 Oct 2026; GUARD_TRACKING.md 5.4), for this estate only: what the page's map draws,
     * read again when the live channel says a guard moved or an alert changed. Only the guard fields an estate may see
     * (EstateSecurityMap copies each by name). `no-store`: where a guard is, is stale the moment it is read.
     */
    public function map(EstateSecurityMap $map): JsonResponse
    {
        return response()->json($map->for((string) tenant()->getTenantKey()))->header('Cache-Control', 'no-store');
    }

    /** One alert, end to end, read-only (26 Sep, E4). Another estate's alert is a 404, as one that does not exist. */
    public function alert(int $alert, EstateSecurity $security): Response
    {
        $estateId = (string) tenant()->getTenantKey();
        $read = $security->alert($estateId, $alert);

        abort_if($read === null, 404);

        return inertia('Estate/Security/Alert', [
            'estate' => ['name' => (string) tenant()->name],
            'alert' => $read,
        ]);
    }

    /** One incident, read-only (Batch 1 item 10, 12 Oct). Another estate's incident is a 404, as one that does not exist. */
    public function incident(int $incident, EstateSecurity $security): Response
    {
        $estateId = (string) tenant()->getTenantKey();
        $read = $security->incident($estateId, $incident);

        abort_if($read === null, 404);

        return inertia('Estate/Security/Incident', [
            'estate' => ['name' => (string) tenant()->name],
            'incident' => $read,
        ]);
    }

    /**
     * THE ONE QUESTION THE ESTATE'S PANIC POP-UP ASKS (M10 §1.2 item 6; B039, B103).
     *
     * "Is it still outstanding, and has anything arrived that I missed?" — asked every fifteen
     * seconds while an alert is outstanding or the live channel is down, and never otherwise. The
     * socket carries an alert the moment it is raised; it carries nothing when the control room
     * ACKNOWLEDGES one, so without this the bar could not stop when it should.
     *
     * A READ, on this screen's own gate (`estate.security.view`), about THIS estate only — the
     * ids the browser names are answered here or not at all. JSON rather than a partial reload,
     * for `PanicWatchController`'s reason: the reader may be on any estate page, and this answers
     * only the question. `no-store`: an answer about who has responded to a panic is stale the
     * moment it is read.
     */
    public function panicWatch(Request $request, PanicWatch $watch): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['sometimes', 'array', 'max:'.PanicWatch::MAX_ASKED],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return response()
            ->json($watch->pollEstate((string) tenant()->getTenantKey(), array_map('intval', $data['ids'] ?? [])))
            ->header('Cache-Control', 'no-store');
    }
}
