<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\PlatformOverview;
use App\Support\PlatformMoney;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The full platform activity feed — board screen super-admin-03.
 *
 * Separate from DashboardController because it is a different screen with a
 * different board, and because that controller is invokable: bolting a second
 * action onto it would mean converting it and touching a screen that already
 * passes its diff at 0.71%.
 *
 * This is NOT the audit log. The audit log is the standing record of who did
 * what, append-only, with its own module and permission. This is the platform's
 * recent history in four kinds of event, and the dashboard's own panel shows
 * the first three rows of exactly this list.
 *
 * THE SAME QUESTION AS THE PANEL (M10 §5). The feed is the panel's "See all", read
 * from the same builder, so it asks the same thing of the viewer: a paid invoice and
 * a plan name are Billing & subscriptions' records, and a role without that module
 * gets the other three kinds of event and the unit counts, not a longer view of the
 * rows the panel already left out.
 */
class ActivityController extends Controller
{
    public function __invoke(Request $request, PlatformOverview $overview): Response
    {
        $viewer = $request->user();
        $maySeeRevenue = PlatformMoney::maySeeRevenue($viewer);

        return inertia('Gemini/Activity/Index', [
            /*
             * Narrowed to the viewer's scope as well (bug hunt B007): a Head of Security assigned
             * to one estate was reading which clients were onboarding elsewhere, which officers
             * were hired at them, and the PSRA numbers of the ones whose licence had lapsed.
             */
            'events' => $overview->activityFeed(maySeeRevenue: $maySeeRevenue, viewer: $viewer),

            // So the empty state stops promising billing events to a reader who will never be
            // shown one. Null when nothing is withheld.
            'withheld_reason' => $maySeeRevenue ? null : PlatformMoney::REVENUE_WITHHELD,
        ]);
    }
}
