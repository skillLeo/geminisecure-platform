<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Exports\Exporter;
use App\Services\Gemini\ClientHealth;
use App\Services\Gemini\CrossTenantReports;
use App\Services\Gemini\RevenueReports;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cross-tenant reports, Super Admin screen 36.
 *
 * The landing screen is a catalogue: it launches reports rather than rendering
 * figures of its own. The viewer is passed to the service because which reports
 * can be opened depends on the role — a report kept in a module this role
 * cannot reach is offered as unavailable, with the reason, instead of linking
 * into a 403.
 */
class ReportController extends Controller
{
    /**
     * Why an Export on these reports can be refused.
     *
     * A cross-tenant export puts every client's commercial position into a file
     * somebody can forward. That is what the export verb is for, and what the
     * audit entry is for — the entry names the clients in the file (12 §1). A
     * role without the verb is told that, and not told a feature is missing.
     */
    private const NO_EXPORT_ACCESS = 'A cross-tenant export puts every client\'s figures into a file that leaves the platform, so it needs Cross-tenant reports export access. You are able to read this report.';

    /**
     * The permission both exports are behind — the routes read this constant, and so does the
     * button that links to them (bug hunt B010).
     *
     * The button used to ask for `gemini.reports.export`, a permission that does not exist: the
     * module is `cross_tenant_reports`. Spatie answers a permission it has never heard of with
     * "no", so Export was greyed out for every role, the Director included, with a reason telling
     * them their role lacked access it held — while the route beside it checked the real name
     * and worked for anybody who typed its URL. One name in one place is the whole fix.
     */
    public const EXPORT_PERMISSION = 'gemini.cross_tenant_reports.export';

    public function index(Request $request, CrossTenantReports $reports): Response
    {
        return inertia('Gemini/Reports/Index', [
            'groups' => $reports->catalogue($request->user()),
        ]);
    }

    /*
     * EVERY REPORT BELOW IS READ AS ITS VIEWER MAY SEE IT (bug hunt B003). The service is handed
     * the person asking, and narrows every query to their scope — see `RevenueReports`. The
     * revenue three are also behind Billing & subscriptions, in the routes.
     */

    /** MRR over time, and what moved it — board screen 37. */
    public function mrr(Request $request, RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Mrr', [
            ...$reports->mrrTrend($request->user()),
            'exportHref' => $request->user()->can(self::EXPORT_PERMISSION) ? route('gemini.cross_tenant_reports.mrr.export', absolute: false) : null,
            'exportDisabledReason' => self::NO_EXPORT_ACCESS,
        ]);
    }

    /** Where the money comes from — board screen 38. */
    public function revenue(Request $request, RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Revenue', [
            ...$reports->revenueByTier($request->user()),
            'exportHref' => $request->user()->can(self::EXPORT_PERMISSION) ? route('gemini.cross_tenant_reports.revenue.export', absolute: false) : null,
            'exportDisabledReason' => self::NO_EXPORT_ACCESS,
        ]);
    }

    /**
     * The MRR trend as a file — board 37's Export (12 §1).
     *
     * THE CHART, NOT A SECOND ARITHMETIC. The rows are the same `mrrTrend()`
     * the screen draws: a report and its export that computed their figures
     * separately would disagree the first time either changed, and the reader
     * would have two numbers and no way to tell which is the platform's.
     */
    public function exportMrr(Request $request, RevenueReports $reports, Exporter $exporter): StreamedResponse
    {
        $viewer = $request->user();
        $trend = $reports->mrrTrend($viewer);

        /*
         * EACH MONTH'S EXACT TOTAL, NOT THE BAR'S LABEL (Hassam, 12 October). The file wrote "$243.9k", the chart's
         * compression: a figure no spreadsheet can add and up to $50 from the month's real total. It writes the exact
         * figure the bar gives when it is pointed at, and the month with its year.
         */
        $rows = array_map(static fn (array $bar): array => [
            $bar['month'],
            $bar['exact'],
            $bar['annotation'] ?? '',
        ], $trend['bars']);

        /*
         * WHAT THE FILE COVERS, SAID AS IT IS. For a platform-wide reader that is the
         * platform's whole book, and the per-client file below is where each client is
         * named. For a reader confined to their sites it is those sites and no others, and
         * the audit entry names them: a record that said "every client on the platform" of
         * a file that held three would be a false record of what left.
         */
        $covered = $reports->clientsCovered($viewer);

        return $exporter->csv(
            scope: $covered === null
                ? 'MRR trend, '.count($rows).' months across every client on the platform'
                : 'MRR trend, '.count($rows).' months across the '.count($covered).' client(s) this role is assigned to',
            headers: ['Month', 'Invoiced', 'What moved it'],
            rows: $rows,
            filename: 'mrr-trend-'.now()->format('Y-m-d').'.csv',
            tenants: $covered ?? ['(every client on the platform)'],
        );
    }

    /** Revenue by tier, and every client's contribution — board 38's Export. */
    public function exportRevenue(Request $request, RevenueReports $reports, Exporter $exporter): StreamedResponse
    {
        $revenue = $reports->revenueByTier($request->user());

        $rows = [];

        /*
         * THE TIERS AND THE CLIENTS IN ONE FILE, labelled by a Row column. The
         * screen draws a donut over a table and both are one dataset shown
         * twice; splitting them into two downloads would let somebody
         * reconcile a tier total against a client list taken a week apart.
         */
        // The per-guard add-on is the split's last line (Hassam, 12 October), named as what it is rather than as a tier.
        foreach ($revenue['tiers'] as $tier) {
            $rows[] = [$tier['key'] === 'guard_addon' ? 'Add-on' : 'Tier', $tier['name'], '', '', $tier['amount'], $tier['percent'].'%'];
        }

        foreach ($revenue['clients'] as $client) {
            $rows[] = [
                'Client',
                $client['client'],
                $client['tier'],
                $client['units'],
                $client['contribution'],
                $client['share'],
            ];
        }

        // The total the screen's heading gives, so the file states what its lines add up to.
        $rows[] = ['Total', 'Monthly recurring revenue', '', '', $revenue['total'], ''];

        $tenants = array_column($revenue['clients'], 'client');
        sort($tenants);

        // The clients are named either way; the sentence says whose book this was.
        $platformWide = $reports->clientsCovered($request->user()) === null;

        return $exporter->csv(
            // The audit log shows this to a person: a comma, never an em dash (Law 3).
            scope: $platformWide
                ? 'Revenue by tier, with every client\'s contribution, total '.$revenue['total']
                : 'Revenue by tier, with the contribution of each client this role is assigned to, total '.$revenue['total'],
            headers: ['Row', 'Name', 'Tier', 'Units', 'Monthly', 'Share'],
            rows: $rows,
            filename: 'revenue-by-tier-'.now()->format('Y-m-d').'.csv',
            tenants: $tenants,
        );
    }

    /** Who stayed — board screen 39. */
    public function churn(Request $request, RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Churn', $reports->churn($request->user()));
    }

    /** Are the posts we are paid for actually staffed — board screen 40. */
    public function utilisation(Request $request, RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Utilisation', $reports->utilisation($request->user()));
    }

    /**
     * Is each client using what they bought — board screen 07.
     *
     * Reads the central adoption roll-up and nothing else. Adoption of an
     * estate's OWN modules is recorded in that estate's database, and a console
     * that opened each one in turn to build a cross-client report would be a
     * tenant-isolation breach wearing a report's clothes — so the owner of each
     * fact rolls it up centrally and this reads the roll-up.
     */
    public function clientHealth(Request $request, ClientHealth $health): Response
    {
        return inertia('Gemini/Reports/ClientHealth', $health->forViewer($request->user()));
    }
}
