<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Meeting;
use App\Services\Estate\ActivityLog;
use App\Services\Estate\DashboardTiles;
use App\Services\Estate\Dues;
use App\Support\SiteTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Response;

/**
 * The Estate Console dashboard — board community-admin-02.
 *
 * EVERY FIGURE ON THIS SCREEN ALREADY HAD A HOME BEFORE THIS SCREEN EXISTED.
 * The five KPI tiles, the arrears-by-phase bars and the outstanding-dues
 * total are each read straight off a service that shipped earlier: `Residents`
 * for the unit counts, `Dues` for every money figure, `Maintenance` for the
 * open-ticket count, and the `Meeting` register for what is next. Nothing
 * here sums a charge or a payment a second time — composing this screen from
 * those services rather than recomputing anything is the whole point of
 * building it last.
 *
 * TWO CONTENT RESIDUALS ARE DRAWN AS THE DATA SAYS, NOT AS THE BOARD SAYS.
 * The board's "441 Occupied units, 98%" cannot be reproduced: this estate is
 * seeded 450 units and 433 occupied households — the same class of residual
 * D-056 already records on Phase 5's own card (78-of-70), recorded rather
 * than reshaping the estate to match a headline. And the board's "↑ 4%" trend
 * chip on Outstanding dues needs a PRIOR-PERIOD snapshot of the arrears
 * control total that nothing in this schema stores — inventing one would be
 * exactly the "invent a column for a number" failure D-033 exists to name, so
 * the chip is simply absent here rather than fabricated. See DECISIONS.md.
 *
 * THE ACTIVITY FEED AND THE BELL ARE `ActivityLog`'s, and neither keeps a
 * table of its own — the same discipline D-035's cross-tenant feed keeps. A
 * payment, an incident, a verification, a closed ticket and a booking are each
 * read from the table that already owns that fact; what still needs somebody is
 * derived the same way, so a claim approved on board 34 leaves the bell by
 * itself rather than leaving a stale notification standing. A category with
 * nothing to show is simply absent; never an invented row to fill the gap.
 *
 * THE MONEY ON IT IS THE ESTATE'S POSITION, AND NOT EVERY READER MAY HAVE IT
 * (M10 §5). Every estate role holds this dashboard. Until §5 the "Outstanding
 * dues" tile, the arrears-by-phase bars and `arrears.total_minor` were computed
 * for all of them and shipped to all of them — the Admin Assistant, and the
 * Property Manager, the Secretary and the Head of Security, who hold no ledger
 * at all — with `canView` gating only the "View ledger" link. They are now
 * asked of `Dues::maySeeEstatePosition()` once, and for a viewer who may not
 * read them the tile is absent, the bars are an empty list, the total is null
 * and the panel is not drawn. The figures are not computed at all for that viewer,
 * so a later edit to the payload cannot leak a number that was never read.
 *
 * EVERY TILE, LINK AND ACTION IS THE ROLE'S OWN, OR ABSENT (M98, Hassam, 12 October: "a Property Manager is never sent
 * to a page that will refuse them"). A tile is sent only to a role that holds the module it summarises, by the
 * sidebar's own rule (`EstateNavigation`: the module's view); a quick action or "View ledger" only to a role whose
 * gate would let it through. Nothing is greyed with a reason: a role can never post a notice it holds no Governance
 * for, so the button is not there at all (M96 level 3).
 *
 * TOTAL UNITS IS EVERY ROLE'S (Hassam, 12 October): "A count is not estate structure data. A Treasurer cannot read dues
 * coverage without knowing how many units exist." It is sent to all, the Treasurer and the Admin Assistant too, who
 * hold no Estate structure; no tile links anywhere, so nothing on it leads into a module the reader cannot open.
 */
class DashboardController extends Controller
{
    /** Which module each tile summarises: a role that cannot open the module is not shown its tile. Total units is not here. */
    private const TILE_MODULES = [
        'occupied' => 'residents',
        'maintenance' => 'facilities',
        'meeting' => 'governance',
    ];

    public function __invoke(Request $request, DashboardTiles $tiles, ActivityLog $log): Response
    {
        $tenant = tenant();
        $user = $request->user();

        /*
         * THE ESTATE-WIDE TILES ARE KEPT, NOT RECOMPUTED ON EVERY LOAD (M11 A2).
         * `DashboardTiles` serves the unit totals, the open-ticket count and the
         * estate's position from `dashboard_snapshots` while the data they were
         * computed from is unchanged, and recomputes a part the moment a charge,
         * a payment, a lot or a ticket moves it. The figures are still the
         * services' own: the unit totals `Residents::structureBoard()` prints, the
         * open tile of board 17, and `Dues::position()`. The screen says when.
         */
        $structure = $tiles->structure();
        $units = $structure['units'];
        $occupied = $structure['occupied'];
        $openMaintenance = $structure['open_maintenance'];

        /*
         * M10 §5. The tile and the bars are the ESTATE'S position, not a lot's
         * ledger — see `Dues::maySeeEstatePosition()`. Read only for a viewer
         * who may have them: for anybody else the position is neither computed
         * nor fetched from the snapshot, exactly as before it was kept.
         */
        $maySeePosition = Dues::maySeeEstatePosition($user);

        $position = $maySeePosition ? $tiles->position() : null;
        $outstandingMinor = $position['outstanding_minor'] ?? null;
        $phaseTotals = $position['phases'] ?? [];

        /*
         * A MEETING THE HOUSEHOLDS HAVE BEEN TOLD OF (B054). Publication is "the
         * moment households are told" (`Meeting`), and until then a row is a
         * Secretary's sketch that may never be called — the governance board
         * itself marks it "Draft — not yet published". Read without this, the
         * tile announced a draft AGM, and a cancelled one, to every role as the
         * estate's next meeting. Scheduled AND published: the same pair the
         * reminder push asks before it tells a phone a meeting is coming.
         */
        $holds = static fn (string $tile): bool => $user->can('estate.'.self::TILE_MODULES[$tile].'.view');

        $nextMeeting = $holds('meeting') ? Meeting::query()
            ->where('status', Meeting::SCHEDULED)
            ->whereNotNull('published_at')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first() : null;

        $canViewLedger = $user->can('estate.dues_ledger.view');

        return inertia('Estate/Dashboard', [
            'estate' => ['name' => (string) $tenant->name],

            'kpis' => array_values(array_filter([
                ['key' => 'units', 'value' => $units, 'label' => 'Total units'],
                [
                    'key' => 'occupied',
                    'value' => $occupied,
                    'label' => 'Occupied units',
                    'trend' => $units === 0 ? null : (int) round($occupied / $units * 100),
                ],
                /*
                 * ABSENT, NOT BLANK, for a viewer who may not read the estate's
                 * position — a greyed "Outstanding dues" tile still tells them
                 * there is a figure, and cross-cutting rule 9 draws a withheld
                 * thing as missing rather than masked. The row reflows to four.
                 */
                ...($maySeePosition
                    ? [['key' => 'dues', 'value_minor' => $outstandingMinor, 'label' => 'Outstanding dues']]
                    : []),
                /*
                 * The maintenance page's Open card, word for word in what it counts (Hassam, 10 October): since the QA
                 * session's status model (fed643d5, M68 §2.3), "Open" is the tickets nobody has the job for yet. "Open
                 * maintenance" stays at the front: the browser journeys find the tile by it.
                 */
                ['key' => 'maintenance', 'value' => $openMaintenance, 'label' => 'Open maintenance, no vendor yet'],
                [
                    'key' => 'meeting',
                    'value' => $nextMeeting?->starts_at->format('M j') ?? '—',
                    'label' => $nextMeeting === null
                        ? 'No meeting scheduled'
                        : 'Next meeting: '.$nextMeeting->typeLabel(),
                ],
            ], static fn (array $kpi): bool => ! isset(self::TILE_MODULES[$kpi['key']]) || $holds($kpi['key']))),

            'arrears' => [
                // Null and empty rather than nought: "$0 outstanding" would be
                // a false statement about the estate, not a withheld one.
                'total_minor' => $maySeePosition ? $outstandingMinor : null,
                'phases' => $maySeePosition ? $this->phaseBars($phaseTotals) : [],

                /*
                 * A DIFFERENT QUESTION FROM THE TWO BELOW. `positionWithheld` is
                 * whether this panel may draw the estate's figures;
                 * `canView` and `ledgerHref` are whether the reader may open
                 * the register at all. The Admin Assistant is the one role
                 * with the second and not the first: the link stays, the bars
                 * do not. A role with neither is sent no link, and no panel is
                 * drawn in the bars' place.
                 */
                'positionWithheld' => ! $maySeePosition,
                'canView' => $canViewLedger,
                'ledgerHref' => $canViewLedger ? $this->path('/finance/arrears') : null,
            ],

            'activity' => [
                'rows' => $log->feed(ActivityLog::DASHBOARD_ROWS, 0, $user)['rows'],
                'seeAllHref' => $this->path('/activity'),
            ],

            // Each a link for whoever holds the gate its page's save carries, and nothing at all for the rest.
            'quickActions' => [
                'notice' => [
                    'href' => $user->can('estate.governance.create') ? $this->path('/governance/notices') : null,
                ],
                'addResident' => [
                    // The register's own modal (Residents, 731199f5); absent for a role that cannot add (Governance, b9ea9b58).
                    'href' => $user->can('estate.residents.create') ? $this->path('/residents?add=1') : null,
                ],
                'newCharge' => [
                    'href' => $user->can('estate.dues_ledger.create') ? $this->path('/finance/charges/new') : null,
                ],
            ],

            /*
             * THE BELL COUNTS WHAT THIS VIEWER HAS NOT SEEN, and only what
             * this viewer may see: a notification is a summary of a record,
             * and a role that may not read the record may not read the summary
             * either. Nothing here is stored — see `ActivityLog::attention()`.
             */
            'notifications' => [
                'count' => $log->attention($user)['unread'],
                'href' => $this->path('/notifications'),
            ],

            /*
             * WHEN THE KEPT FIGURES WERE COMPUTED (M11 A2), on the estate's own
             * clock — "show when the figure was computed". The position line is
             * null for a viewer it is withheld from, so the time a figure they
             * cannot see was taken is not theirs either.
             */
            'computed' => [
                // M78 §2: when the figures are from, and nothing about how they are kept.
                'structure' => 'Estate totals as at '.$this->at($structure['computed_at']).'.',
                'position' => $position === null
                    ? null
                    : 'As at '.$this->at($position['computed_at']).'.',
            ],
        ]);
    }

    /** A moment the figures were computed, on the estate's clock and saying so: "Sep 24, 4:32 PM JA" (M19A §4.2). */
    private function at(Carbon $moment): string
    {
        return SiteTime::fmt(SiteTime::local($moment, (string) tenant()->getTenantKey()), 'M j, g:i A');
    }

    /** The full activity log — the dashboard panel's "See all" (12 §2, Wave 2). */
    public function activity(Request $request, ActivityLog $log): Response
    {
        $page = max(1, (int) $request->integer('page', 1));

        // The viewer, so a payment's amount and a dunning notice reach only
        // the roles that may read them — the dashboard panel's rule (M10 §5).
        $feed = $log->feed(ActivityLog::PAGE, ($page - 1) * ActivityLog::PAGE, $request->user());

        return inertia('Estate/Activity/Index', [
            'estate' => ['name' => (string) tenant()->name],
            'rows' => $feed['rows'],
            'page' => $page,
            'hasMore' => $feed['has_more'],
            'dashboardHref' => $this->path('/'),
            'path' => $this->path('/activity'),
        ]);
    }

    /** The notification centre — the topbar bell (12 §2, Wave 2). */
    public function notifications(Request $request, ActivityLog $log): Response
    {
        $attention = $log->attention($request->user());

        return inertia('Estate/Notifications/Index', [
            'estate' => ['name' => (string) tenant()->name],
            'items' => $attention['items'],
            'unread' => $attention['unread'],
            'dashboardHref' => $this->path('/'),
            'path' => $this->path('/notifications'),
        ]);
    }

    /** Mark items seen. Per viewer — two officers do not share an inbox. */
    public function markNotificationsRead(Request $request, ActivityLog $log): RedirectResponse
    {
        $keys = $request->validate([
            'keys' => ['required', 'array', 'max:200'],
            'keys.*' => ['string', 'max:64'],
        ])['keys'];

        $log->markRead($request->user(), $keys);

        return redirect()->to($this->path('/notifications'));
    }

    /* ------------------------------------------------------------------ */
    /* the bar chart */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, int>  $totals  phase name => minor units owed
     * @return list<array{key: string, label: string, value_minor: int, height_pct: int, over: bool}>
     */
    private function phaseBars(array $totals): array
    {
        if ($totals === []) {
            return [];
        }

        $max = max($totals);
        $sorted = array_values($totals);
        sort($sorted);
        $count = count($sorted);

        /*
         * The board's own amber cut needs a definition nothing on it states —
         * "phase.arrears_over_threshold ... NOT derivable from magnitude
         * alone ... an explicit flag or per-phase target is required". Rather
         * than invent a target dollar figure nobody set, the two phases the
         * board colours amber are exactly the two ABOVE THE ESTATE'S OWN
         * MEDIAN PHASE — a relative severity rule that needs no stored
         * column and reproduces the board on the seeded data. See
         * DECISIONS.md.
         */
        $median = $count % 2 === 1
            ? $sorted[intdiv($count, 2)]
            : ($sorted[intdiv($count, 2) - 1] + $sorted[intdiv($count, 2)]) / 2;

        $bars = [];

        foreach ($totals as $phase => $value) {
            $bars[] = [
                'key' => $phase,
                'label' => $phase,
                'value_minor' => $value,

                /*
                 * The board's tallest bar (Phase 2, its own largest phase)
                 * sits at 88% with headroom above it; every other bar is the
                 * same proportion of THIS ESTATE'S OWN largest phase, not of
                 * an invented chart maximum. Reproduces the board's 62/88/44/
                 * 71/11 exactly on the seeded arrears and rescales sensibly
                 * if they ever change.
                 */
                'height_pct' => $max === 0 ? 0 : (int) round($value / $max * 88),
                'over' => $value > $median,
            ];
        }

        return $bars;
    }

    /**
     * Where a screen lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all
     * from one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
