<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\PlatformNotifications;
use App\Services\Gemini\PlatformOverview;
use App\Support\PlatformMoney;
use App\Support\SiteTime;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Gemini Console dashboard, Super Admin screen 1.
 *
 * Thin by design: the figures come from a service, so the same numbers are
 * available to the /api/v1 endpoints the mobile apps will consume without
 * either surface reimplementing them.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, PlatformOverview $overview, PlatformNotifications $notifications): Response
    {
        /*
         * M10 §5. Every platform role holds this Dashboard in full, and two of its panels are
         * Billing & subscriptions' figures: the Platform MRR card and the MRR-by-tier bars. Asked
         * once, of the permission, and handed down — see `PlatformMoney`.
         */
        $viewer = $request->user();
        $maySeeRevenue = PlatformMoney::maySeeRevenue($viewer);

        /*
         * AND THE VIEWER, for their scope (bug hunt B007, D-015). A Head of Security assigned to
         * one estate holds this Dashboard in full and every other estate not at all; the cards
         * count their sites and the activity names only what happened there. The service will
         * not answer without it.
         *
         * THE CARDS ARE TOTALS, KEPT FOR A FEW MINUTES PER SCOPE (M11 A2) — see
         * `PlatformOverview::dashboard()`. The page says when they were taken, in the console's
         * own clock, so a cached figure is never read as this instant's.
         */
        $figures = $overview->dashboard($viewer, $maySeeRevenue);
        $computedAt = SiteTime::local($figures['computed_at']);

        return inertia('Gemini/Dashboard', [
            'kpis' => $figures['kpis'],

            /*
             * Whether those figures are the viewer's sites' rather than the platform's, so the tier
             * panel's heading says whose tiers it shows — the MRR card already says so in its own
             * label. A sum over one role's sites is not the platform's, and /billing, one link
             * away, prints the platform's figure under the board's words.
             */
            'sites_only' => $overview->sitesOnly($viewer),

            /*
             * OMITTED, NOT EMPTIED. An empty `tiers` is what a platform with no active
             * subscription sends, and the page answers it with "No active subscriptions" — a
             * false statement about the business to a reader who is simply not shown it. So the
             * key is absent for that reader, `withheld_reason` says why in a sentence that names
             * no figure, and the page draws the refusal instead of the empty state.
             */
            ...($maySeeRevenue ? ['tiers' => $figures['tiers'] ?? []] : []),

            'figures_computed_at' => [
                'iso' => SiteTime::iso($figures['computed_at']),
                'label' => 'Figures as of '.SiteTime::fmt($computedAt, $computedAt->isSameDay(SiteTime::now()) ? 'g:i A' : 'M j, g:i A'),
            ],
            'withheld_reason' => $maySeeRevenue ? null : PlatformMoney::REVENUE_WITHHELD,

            'activity' => $overview->recentActivity($viewer, $maySeeRevenue),

            // The bell's count (12 §2, item 41) — derived, like the centre itself.
            'unread' => $notifications->attention($viewer)['unread'],
        ]);
    }
}
