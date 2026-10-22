<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\DuressAlert;
use App\Services\Dispatch\NearestGuards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The three officers nearest an open alert, for the dispatcher's map and the alert's page (Hassam, 12 Oct 2026). Read
 * only: it never assigns anybody; a dispatcher sends an officer with the alert's own Dispatch act.
 */
final class NearestGuardsController extends Controller
{
    public function show(Request $request, DuressAlert $alert, NearestGuards $nearest): JsonResponse
    {
        $viewer = $request->user();

        // 404, not 403, as the alert's own page: a site-scoped dispatcher does not learn that an alert exists elsewhere.
        abort_unless($viewer->canAccessEstate((string) $alert->tenant_id), 404);

        return response()->json($nearest->forAlert($alert, $viewer));
    }
}
