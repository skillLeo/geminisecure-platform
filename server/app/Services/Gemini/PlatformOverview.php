<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Guard;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;
use App\Support\PlatformPricing;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide figures for the Gemini Console dashboard.
 *
 * Reads ONLY from gs_platform. No method here may open an estate database:
 * a cross-tenant figure that fans out across estates would break both the
 * isolation model and the rule that every cross-tenant report is an aggregate
 * derived from central data. If a number cannot be produced centrally, it is
 * not a cross-tenant figure and does not belong on this dashboard.
 *
 * The shape is the approved board's, not a shape of my own: four KPI cards,
 * MRR split by subscription tier, and a recent-activity list. Screen
 * super-admin-02, and the full activity feed behind it, screen super-admin-03.
 *
 * WHO IS LOOKING NARROWS WHAT IS COUNTED AND NAMED (bug hunt B007, D-015). Every
 * public method takes the viewer as its FIRST argument, and it is required. Every
 * read goes through `OperatorEstates::narrow()` — the demonstration estate out,
 * and for a role confined to its assigned sites, every other site out too — so a
 * Head of Security assigned to one estate is not told which clients are
 * onboarding elsewhere, which officers were hired at them or whose licence lapsed
 * there. Officers not yet posted belong to no estate and stay, as they do on that
 * role's own workforce screens (`OperatorEstates::narrowOfficers()`).
 *
 * REQUIRED, NOT DEFAULTED, because the default was the leak. The viewer used to be
 * optional, and a caller that left it out was given every estate: exactly what
 * B007 fixed, one omitted argument away, and `recentEstates()` — never called —
 * took no viewer at all. RevenueReports was made to require its reader for the same
 * reason. A caller that wants the company's own figures asks as a reader whose
 * scope is every site, the Director; the answer is the same rows, and nobody gets
 * them by forgetting to say who is looking.
 *
 * AND THE LABELS SAY WHOSE FIGURES THEY ARE. A reader confined to their sites is
 * counted over their sites, so the one card that names the platform, "Platform
 * MRR", is "MRR at your sites" for them (`sitesOnly()`).
 */
class PlatformOverview
{
    /**
     * Rows the activity feed shows — board screen super-admin-03 draws eight.
     *
     * A feed length, not a page size, and the board draws no pager to make it
     * one. The eight most recent platform events are what this screen is: the
     * standing ledger of who did what is the Access & audit log, which is its
     * own module with its own board.
     */
    public const FEED_ROWS = 8;

    /**
     * How recent an event has to be for the feed to name the day.
     *
     * The board writes "Sep 4" for the last few weeks and "May 2026" for
     * anything older, because a day number stops meaning anything once it is
     * months back and the month is the fact worth reading.
     */
    private const FEED_DAY_PRECISION = 60;

    /**
     * Rows the dashboard's activity panel shows — board screen super-admin-02 draws three.
     */
    public const PANEL_ROWS = 3;

    /**
     * How long the dashboard's headline figures are kept before they are summed again (M11 A2).
     *
     * Five minutes. Long enough that a platform's worth of COUNT and SUM is not repeated on every
     * page load of the screen everybody at Gemini opens first; short enough that a client
     * activated or a guard stood down is on the cards before the person who did it has finished
     * their next task — and the page prints the time the figures were taken, so nobody reads a
     * five-minute-old count as this minute's.
     */
    public const DASHBOARD_TTL_SECONDS = 300;

    /**
     * The cache keys' stems, versioned so a change to the shape of either entry starts clean.
     * Each entry is kept per SCOPE — see `cacheKeys()` — never once for everybody.
     */
    public const COUNTS_CACHE_KEY = 'gemini:dashboard:counts:v2';

    public const REVENUE_CACHE_KEY = 'gemini:dashboard:revenue:v2';

    /**
     * Are this viewer's figures their assigned sites' rather than the platform's?
     *
     * True for a role confined to its assigned sites (D-015), whose every figure here is
     * narrowed to them. The page is told, so that a panel whose heading would otherwise speak
     * for the platform — "MRR by subscription tier" — says whose tiers they are.
     */
    public function sitesOnly(User $viewer): bool
    {
        return $viewer->widestScope() === AccessScope::AssignedSites;
    }

    /**
     * The four KPI cards, in the order the board draws them.
     *
     * THREE FOR A VIEWER WHO MAY NOT READ REVENUE (M10 §5). `$maySeeRevenue` is the caller's answer
     * from `PlatformMoney::maySeeRevenue()`, asked of every caller rather than assumed.
     *
     * FRESH, EVERY TIME. The dashboard reads its cards through `dashboard()`, which keeps them for
     * a few minutes; this answers for this instant, from the same `countFigures()`,
     * `revenueFigures()` and `cards()`, so the cached page and a live answer cannot be drawn
     * differently.
     *
     * @return list<array<string, mixed>>
     */
    public function kpis(User $viewer, bool $maySeeRevenue): array
    {
        return $this->cards($viewer, $this->countFigures($viewer), $maySeeRevenue ? $this->revenueFigures($viewer) : null);
    }

    /**
     * THE DASHBOARD'S HEADLINE FIGURES, FROM A CACHE AT MOST `DASHBOARD_TTL_SECONDS` OLD, AND
     * WHEN THEY WERE WORKED OUT (M11 A2).
     *
     * Every card on the board is a total over every estate the viewer looks at — every active
     * client, every contracted unit, every active guard, the recurring revenue — and the
     * dashboard is the page every Gemini user lands on. Summing them on each of those page
     * loads is the cost A2 names, so the figures are kept for a few minutes and the page says
     * when they were computed: a reader is never shown a stale figure as though it were this
     * instant's.
     *
     * KEPT PER SCOPE, NEVER ONCE FOR EVERYBODY (B007, D-015). A Head of Security's cards count
     * their assigned sites, so an entry filled on the Director's request must never be read back
     * on theirs, nor one of theirs on another Head of Security's with different sites. The key
     * says whose figures an entry holds — see `cacheKeys()`.
     *
     * TWO ENTRIES, AND THE SECOND IS NEVER FILLED FOR A READER WHO MAY NOT SEE REVENUE (M10 §5).
     * The counts are nobody's secret; the MRR and its tier split are Billing's. A single cached
     * array would put the revenue in memory on the Admin Assistant's request, one edit away from
     * the wire — so `revenueFigures()` is computed only on a revenue viewer's request, and cached
     * apart. When both are shown, the earlier of their two times is the one reported, so the
     * label never claims a figure is newer than it is.
     *
     * @return array{kpis: list<array<string, mixed>>, tiers?: list<array{key: string, label: string, amount: string, percent: float}>, computed_at: Carbon}
     */
    public function dashboard(User $viewer, bool $maySeeRevenue): array
    {
        $keys = $this->cacheKeys($viewer);

        /** @var array{clients: int, units: int, guards: int, computed_at: string} $counts */
        $counts = Cache::remember($keys['counts'], self::DASHBOARD_TTL_SECONDS, fn (): array => $this->countFigures($viewer));

        /** @var array{mrr_minor: int, currency: string, tiers: list<array{key: string, label: string, minor: int}>, computed_at: string}|null $revenue */
        $revenue = $maySeeRevenue
            ? Cache::remember($keys['revenue'], self::DASHBOARD_TTL_SECONDS, fn (): array => $this->revenueFigures($viewer))
            : null;

        $computedAt = Carbon::parse($counts['computed_at']);

        if ($revenue !== null && Carbon::parse($revenue['computed_at'])->lessThan($computedAt)) {
            $computedAt = Carbon::parse($revenue['computed_at']);
        }

        return [
            'kpis' => $this->cards($viewer, $counts, $revenue),
            ...($revenue !== null ? ['tiers' => $this->tierBars($revenue)] : []),
            'computed_at' => $computedAt,
        ];
    }

    /**
     * The two cache keys this viewer's figures are kept under.
     *
     * The figures are a function of WHICH ESTATES are counted and nothing else: `narrow()` and
     * `narrowOfficers()` narrow a reader confined to their assigned sites to those sites, and
     * leave every other reader on every estate but the demonstration one. So every reader whose
     * scope is not `AssignedSites` shares the one "all" entry, and a site-scoped reader's entry
     * is named for the exact set of estates they are assigned — sorted, so the order the rows
     * come back in cannot split one set across two entries. A new assignment is a new set, and
     * so a new entry, worked out afresh on the next page load.
     *
     * @return array{counts: string, revenue: string}
     */
    public function cacheKeys(User $viewer): array
    {
        $scope = 'all';

        if ($this->sitesOnly($viewer)) {
            $estates = array_map('strval', $viewer->accessibleEstateIds());
            sort($estates, SORT_STRING);
            $scope = 'sites:'.hash('sha256', implode("\n", $estates));
        }

        return [
            'counts' => self::COUNTS_CACHE_KEY.':'.$scope,
            'revenue' => self::REVENUE_CACHE_KEY.':'.$scope,
        ];
    }

    /**
     * The three counts behind the cards, and the instant they were taken.
     *
     * @return array{clients: int, units: int, guards: int, computed_at: string}
     */
    private function countFigures(User $viewer): array
    {
        /*
         * THE DEMONSTRATION ESTATE IS NOT A CLIENT (M5 §3).
         *
         * "Active clients" counted it, so the number the company quotes about
         * itself was one too high — and its two invented guard accounts were in
         * "Guards deployed" beside people who actually stand a post. Every card
         * here answers a question about the running business, and the answer has
         * to be true.
         *
         * AND A SITE-SCOPED READER'S CARDS COUNT THEIR SITES (B007): "Active
         * clients" is how many of their estates are live, not how many clients
         * the company has.
         */
        $clientQuery = Tenant::query()->where('status', 'active');
        OperatorEstates::narrow($clientQuery, $viewer, 'id');
        $activeClients = $clientQuery->count();

        $unitQuery = Subscription::query()->where('status', 'active');
        OperatorEstates::narrow($unitQuery, $viewer, 'tenant_id');
        $units = (int) $unitQuery->sum('unit_count');

        $guardQuery = Guard::query()->where('status', 'active');
        OperatorEstates::narrowOfficers($guardQuery, $viewer, 'tenant_id');
        $guards = $guardQuery->count();

        return [
            'clients' => (int) $activeClients,
            'units' => $units,
            'guards' => (int) $guards,
            'computed_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * The revenue these cards may show, split by tier, and the currency it is printed in —
     * Billing's figures, asked for only on behalf of a reader who may see them (M10 §5), over
     * the estates that reader looks at (B007).
     *
     * @return array{mrr_minor: int, currency: string, tiers: list<array{key: string, label: string, minor: int}>, computed_at: string}
     */
    private function revenueFigures(User $viewer): array
    {
        /*
         * The query builder, not Eloquent.
         *
         * This is an aggregate over two tables and hydrates no Subscription:
         * the columns it selects (plan_key, plan_name, minor) belong to no
         * model, and asking Eloquent for them produces Subscription objects
         * carrying attributes the class does not have.
         */
        $rows = DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->tap(static fn ($query) => OperatorEstates::narrow($query, $viewer, 'subscriptions.tenant_id'))
            ->where('subscriptions.status', 'active')
            ->groupBy('plans.id', 'plans.key', 'plans.name', 'plans.sort')
            ->orderBy('plans.sort')
            ->selectRaw('plans.key as plan_key, plans.name as plan_name, SUM(subscriptions.unit_count * plans.price_per_unit_minor) as minor')
            ->get();

        return [
            'mrr_minor' => $this->monthlyRecurringMinor($viewer),

            /*
             * "$243,900" — the board's format for headline money, printed by MoneyFormatter,
             * the formatter the client directory uses. This once hand-picked the symbol — "J$"
             * for anything non-USD — while the directory took the locale's, so the dashboard
             * wrote "J$243,900" and the directory "$88,800" for the same currency on adjacent
             * screens. Two formatters is how a system ends up disagreeing with itself about
             * money. The currency is the plans' own, asked once for every figure on the page
             * where it used to be asked once per figure (M11 A2).
             */
            'currency' => (string) (Plan::query()->value('currency') ?? MoneyFormatter::DEFAULT_CURRENCY),
            'tiers' => $rows
                ->map(static fn ($row): array => [
                    'key' => (string) $row->plan_key,
                    'label' => (string) $row->plan_name,
                    'minor' => (int) $row->minor,
                ])
                ->values()
                ->all(),
            'computed_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * The KPI cards, in the order the board draws them, from figures already taken.
     *
     * @param  array{clients: int, units: int, guards: int, computed_at: string}  $counts
     * @param  array{mrr_minor: int, currency: string, tiers: list<array{key: string, label: string, minor: int}>, computed_at: string}|null  $revenue  null for a reader who may not see revenue
     * @return list<array<string, mixed>>
     */
    private function cards(User $viewer, array $counts, ?array $revenue): array
    {
        return [
            [
                'key' => 'clients',
                'icon' => 'clients',
                'value' => (string) $counts['clients'],
                'label' => 'Active clients',
                'trend' => null,
            ],
            [
                'key' => 'units',
                'icon' => 'units',
                'value' => number_format($counts['units']),
                'label' => 'Units under management',
                'trend' => null,
            ],
            /*
             * PLATFORM MRR IS BILLING'S FIGURE (M10 §5). ABSENT, NOT BLANKED — the rule the
             * sidebar keeps for a module a role cannot reach. The Admin Assistant holds this
             * Dashboard in full and Billing & subscriptions not at all, and this card was the one
             * place the platform's earnings reached them. A card reading "Platform MRR —" would
             * still say there is a figure being kept from them; there is simply no card.
             *
             * And not computed: `revenueFigures()` is never called for this viewer, so no later
             * edit to the card can put the sum back on the wire by accident.
             *
             * NAMED FOR WHAT IT SUMS. For a reader confined to their sites the figure is their
             * sites' MRR, and under "Platform MRR" it was a false label: /billing prints the
             * platform's figure under those words to the same reader, one link away. The board's
             * words stay for everyone whose figure is the platform's. Decided here, on every
             * request, never read back from a cached entry.
             */
            ...($revenue !== null ? [[
                'key' => 'mrr',
                'icon' => 'billing',
                'value' => MoneyFormatter::whole($revenue['mrr_minor'], $revenue['currency']),
                'label' => $this->sitesOnly($viewer) ? 'MRR at your sites' : 'Platform MRR',
                'trend' => null,
            ]] : []),
            [
                'key' => 'guards',
                'icon' => 'guards',
                'value' => (string) $counts['guards'],
                'label' => 'Guards deployed',
                'trend' => null,
            ],
        ];
    }

    /**
     * The tier bars, widest first, from figures already taken.
     *
     * The bar width is each tier's share of total MRR, computed here rather
     * than in the template: a percentage worked out in Vue would be a second
     * place the money maths lives, and the two would drift.
     *
     * @param  array{mrr_minor: int, currency: string, tiers: list<array{key: string, label: string, minor: int}>, computed_at: string}  $revenue
     * @return list<array{key: string, label: string, amount: string, percent: float}>
     */
    private function tierBars(array $revenue): array
    {
        $total = $revenue['mrr_minor'];

        return collect($revenue['tiers'])
            ->map(static fn (array $tier): array => [
                'key' => $tier['key'],
                'label' => $tier['label'],
                'amount' => MoneyFormatter::whole($tier['minor'], $revenue['currency']),
                'percent' => $total > 0 ? round(($tier['minor'] / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('percent')
            ->values()
            ->all();
    }

    /**
     * MRR split by subscription tier, widest bar first — fresh, like `kpis()`.
     *
     * @return list<array{key: string, label: string, amount: string, percent: float}>
     */
    public function mrrByTier(User $viewer): array
    {
        return $this->tierBars($this->revenueFigures($viewer));
    }

    /**
     * The dashboard's activity panel — board screen super-admin-02.
     *
     * Three rows, and no column of its own for the time, so each row ends with
     * when it happened: "320 units · 2 days ago". The feed screen has a time
     * column and spends that space on a second fact instead.
     *
     * See `platformEvents()` for what `$maySeeRevenue` withholds and what the viewer narrows.
     *
     * (A `recentEstates()` sat beside this, listing recently provisioned estates. Nothing
     * called it, it took no viewer, and it named every estate on the platform to whoever
     * would have: removed rather than left for the next caller to find.)
     *
     * @return list<array{icon: string, title: string, meta: string}>
     */
    public function recentActivity(User $viewer, bool $maySeeRevenue, int $limit = self::PANEL_ROWS): array
    {
        return array_map(
            fn (array $event): array => [
                'icon' => $event['icon'],
                'title' => $event['title'],
                'meta' => $this->join([$event['context'], $event['stamp']]),
            ],
            $this->platformEvents($viewer, $maySeeRevenue, $limit)
        );
    }

    /**
     * The full activity feed — board screen super-admin-03.
     *
     * The same events as the panel above, from the same builder. Two readings
     * of "what has been happening on this platform" that could disagree with
     * each other, on a screen reached by a "See all" link from the other, is
     * exactly the drift one service exists to prevent.
     *
     * @return list<array{key: string, icon: string, title: string, meta: string, when: string}>
     */
    public function activityFeed(User $viewer, bool $maySeeRevenue, int $limit = self::FEED_ROWS): array
    {
        return array_map(
            fn (array $event): array => [
                'key' => $event['key'],
                'icon' => $event['icon'],
                'title' => $event['title'],
                'meta' => $this->join([$event['context'], $event['extra']]),
                'when' => $this->feedTime($event['at']),
            ],
            $this->platformEvents($viewer, $maySeeRevenue, $limit)
        );
    }

    /**
     * Everything both activity screens are built from, newest first.
     *
     * Four kinds of event, all of them real central records with a real date
     * of their own:
     *
     *   an estate onboarded          tenants.provisioned_at
     *   a platform invoice paid      invoices.paid_on
     *   a guard deployed             guards.hired_on
     *   a guard stood down           guards.psra_expires_on
     *
     * They are merged and re-sorted on those dates rather than shown in fixed
     * blocks, because a feed that always lists one of each is a layout, not a
     * feed. Each source is asked for at most $limit rows: the merged list can
     * never need more than that from any single one.
     *
     * Every row is central. Nothing here opens an estate database — a feed
     * that fanned out across estates to find events would be a tenant-isolation
     * breach wearing a report's clothes.
     *
     * `context` is the fact that rides beside the title on both screens;
     * `extra` is the second fact only the feed has room for; `stamp` is the
     * time as the dashboard panel writes it, inline.
     *
     * FOR A VIEWER WHO MAY NOT READ REVENUE (M10 §5), two things change and nothing else does:
     *
     *   an invoice paid   DROPPED WHOLE, not stripped of its amount. "Phoenix Park — invoice
     *                     paid" with the figure taken out is still billing status, and §5
     *                     withholds that too.
     *   an onboarding     keeps its row and its unit count, and loses the plan name beside them.
     *                     "Premium plan" is the subscription tier, and a tier is a price list.
     *
     * The feed is then filled from the other three kinds, so the reader still gets `$limit` rows
     * of what happened rather than a shorter list with gaps where the billing was.
     *
     * FOR A VIEWER CONFINED TO THEIR ASSIGNED SITES (B007, D-015), every source is narrowed in
     * its own query, before its own LIMIT — never filtered after the merge, which would hand the
     * reader fewer rows than there are and leave the rest one refresh away. What they read is
     * what happened at their sites, and to officers not yet posted anywhere.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function platformEvents(User $viewer, bool $maySeeRevenue, int $limit): array
    {
        $events = [
            ...$this->onboardings($viewer, $maySeeRevenue, $limit),
            ...($maySeeRevenue ? $this->invoicesPaid($viewer, $limit) : []),
            ...$this->guardsDeployed($viewer, $limit),
            ...$this->licencesLapsed($viewer, $limit),
        ];

        // DateTimeInterface compares chronologically under <=>, so the newest
        // event sorts first without reducing either side to a string.
        usort($events, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($events, 0, $limit);
    }

    /**
     * Estates that have been provisioned.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function onboardings(User $viewer, bool $maySeeRevenue, int $limit): array
    {
        // A block body, not an arrow function: the callable is declared to
        // return void, and an arrow function would return the Builder.
        $provisioned = OperatorEstates::estates(function ($query) use ($limit, $viewer): void {
            // `estates()` has already taken the demonstration estate out; this adds the scope.
            OperatorEstates::narrow($query, $viewer, 'tenants.id');

            $query->whereNotNull('provisioned_at')->orderByDesc('provisioned_at')->limit($limit);
        });

        $plans = $this->contractedPlans($viewer);
        $events = [];

        foreach ($provisioned as $tenant) {
            $at = $tenant->provisioned_at;

            if ($at === null) {
                continue;
            }

            $id = (string) $tenant->getTenantKey();
            $contract = $plans[$id] ?? null;

            $events[] = [
                'key' => 'estate:'.$id,
                'at' => $at,
                'icon' => 'clients',
                'title' => $tenant->name.' — onboarding started',
                'context' => $contract === null ? '' : number_format($contract['units']).' units',

                // The plan is the tier, and the tier is Billing's (M10 §5). The unit count beside
                // it is the estate's size, which the Clients directory shows this reader anyway.
                'extra' => $contract === null || ! $maySeeRevenue ? null : $contract['plan'].' plan',
                'stamp' => $at->diffForHumans(short: false),
            ];
        }

        return $events;
    }

    /**
     * Platform invoices that have been settled.
     *
     * This is Gemini Security billing its clients. An estate's own ledger lives
     * in that estate's database and never appears on a Gemini Console screen.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function invoicesPaid(User $viewer, int $limit): array
    {
        $rows = DB::connection('mysql')
            ->table('invoices')
            ->join('tenants', 'tenants.id', '=', 'invoices.tenant_id')
            ->tap(static fn ($query) => OperatorEstates::narrow($query, $viewer, 'invoices.tenant_id'))
            ->where('invoices.status', 'paid')
            ->whereNotNull('invoices.paid_on')
            ->orderByDesc('invoices.paid_on')
            ->limit($limit)
            ->select(
                'invoices.id',
                'invoices.reference',
                'invoices.total_minor',
                'invoices.currency',
                'invoices.paid_on',
                'tenants.name'
            )
            ->get();

        return $rows
            ->map(function (object $invoice): array {
                $at = Carbon::parse((string) $invoice->paid_on);

                return [
                    'key' => 'invoice:'.$invoice->id,
                    'at' => $at,
                    'icon' => 'billing',
                    'title' => $invoice->name.' — invoice paid',
                    /*
                     * The invoice's OWN currency, not the plan's.
                     *
                     * Both are JMD today, and the day they are not is the day a
                     * settled invoice would be reprinted in a currency nobody
                     * was billed in.
                     */
                    'context' => MoneyFormatter::whole(
                        (int) $invoice->total_minor,
                        $this->currency($invoice->currency)
                    ),
                    'extra' => 'Invoice '.$invoice->reference,
                    'stamp' => $at->format('M j'),
                ];
            })
            ->all();
    }

    /**
     * Guards taken on.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function guardsDeployed(User $viewer, int $limit): array
    {
        /*
         * Left joins, not the estate relation.
         *
         * A guard's tenant_id is nullable — an unassigned guard is a real
         * state, not a broken row — so the estate name has to survive being
         * absent, and so does the post. A left join says that in the query;
         * eager loading the relation would hand back a null the relation's own
         * type says cannot happen.
         */
        $rows = DB::connection('mysql')
            ->table('guards')
            ->leftJoin('tenants', 'tenants.id', '=', 'guards.tenant_id')
            ->leftJoin('posts', 'posts.id', '=', 'guards.post_id')
            /*
             * An unassigned guard has a null tenant_id and belongs on this feed;
             * `OperatorEstates::exclude()` keeps a null rather than letting SQL's
             * three-valued NOT IN quietly drop it. The demonstration estate's two
             * invented officers go; the real ones waiting to be posted stay — and
             * stay for a site-scoped reader too, while an officer hired at a site
             * that reader is not assigned to goes (B007).
             */
            ->tap(static fn ($query) => OperatorEstates::narrowOfficers($query, $viewer, 'guards.tenant_id'))
            ->whereNotNull('guards.hired_on')
            ->orderByDesc('guards.hired_on')
            ->limit($limit)
            ->select(
                'guards.id',
                'guards.full_name',
                'guards.employment_type',
                'guards.hired_on',
                'tenants.name as estate_name',
                DB::raw(Post::labelSql().' as post_name'),
            )
            ->get();

        return $rows
            ->map(function (object $guard): array {
                $at = Carbon::parse((string) $guard->hired_on);

                return [
                    'key' => 'hire:'.$guard->id,
                    'at' => $at,
                    'icon' => 'guards',
                    'title' => $guard->full_name.' deployed — '
                        .str_replace('_', ' ', (string) $guard->employment_type),
                    'context' => (string) ($guard->estate_name ?? 'Unassigned'),
                    'extra' => $guard->post_name === null ? null : (string) $guard->post_name,
                    'stamp' => $at->format('M j'),
                ];
            })
            ->all();
    }

    /**
     * Guards standing down because their PSRA licence has run out.
     *
     * The date is the licence's own expiry, which is the day the guard stopped
     * being deployable — there is no separate "stood down on" column and this
     * screen is not a reason to invent one, because the licence date already
     * says exactly when it happened.
     *
     * Only a guard whose status records the lapse appears, and only once the
     * date has actually passed: a licence expiring next month is a compliance
     * warning for the workforce screen, not something that has happened.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function licencesLapsed(User $viewer, int $limit): array
    {
        $rows = DB::connection('mysql')
            ->table('guards')
            ->leftJoin('tenants', 'tenants.id', '=', 'guards.tenant_id')
            ->tap(static fn ($query) => OperatorEstates::narrowOfficers($query, $viewer, 'guards.tenant_id'))
            ->where('guards.status', 'licence_expired')
            ->whereNotNull('guards.psra_expires_on')
            ->whereDate('guards.psra_expires_on', '<=', PlatformCalendar::today())
            ->orderByDesc('guards.psra_expires_on')
            ->limit($limit)
            ->select(
                'guards.id',
                'guards.full_name',
                'guards.psra_number',
                'guards.psra_expires_on',
                'tenants.name as estate_name'
            )
            ->get();

        return $rows
            ->map(function (object $guard): array {
                $at = Carbon::parse((string) $guard->psra_expires_on);

                return [
                    'key' => 'licence:'.$guard->id,
                    'at' => $at,
                    'icon' => 'shield',
                    'title' => $guard->full_name.' stood down — PSRA licence expired',
                    'context' => (string) ($guard->estate_name ?? 'Unassigned'),
                    'extra' => (string) $guard->psra_number,
                    'stamp' => $at->format('M j'),
                ];
            })
            ->all();
    }

    /**
     * Each estate's contracted units and plan name, in one query.
     *
     * Asked for once rather than per estate: eight rows on the feed is eight
     * round trips the moment this is done inside the loop, and the answer is
     * the same query every time.
     *
     * @return array<string, array{units: int, plan: string}>
     */
    private function contractedPlans(User $viewer): array
    {
        return DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->tap(static fn ($query) => OperatorEstates::narrow($query, $viewer, 'subscriptions.tenant_id'))
            ->select('subscriptions.tenant_id', 'subscriptions.unit_count', 'plans.name as plan_name')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->tenant_id => [
                    'units' => (int) $row->unit_count,
                    'plan' => (string) $row->plan_name,
                ],
            ])
            ->all();
    }

    /**
     * "Sep 4" for something recent, "May 2026" for something older.
     *
     * The board writes both, and the difference is not decoration: a day
     * number months back tells the reader nothing they can place, whereas the
     * month does.
     */
    private function feedTime(Carbon $at): string
    {
        return $at->greaterThanOrEqualTo(Carbon::now()->subDays(self::FEED_DAY_PRECISION))
            ? $at->format('M j')
            : $at->format('M Y');
    }

    /**
     * The board's separator, with the empty parts dropped.
     *
     * An estate provisioned before it has a plan has no unit count to show, and
     * the row must read as one fact rather than as a dangling middot.
     *
     * @param  list<string|null>  $parts
     */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter(
            $parts,
            static fn (?string $part): bool => $part !== null && $part !== ''
        ));
    }

    private function currency(mixed $currency): string
    {
        return is_string($currency) && $currency !== ''
            ? $currency
            : MoneyFormatter::DEFAULT_CURRENCY;
    }

    /**
     * Total active MRR in minor units.
     *
     * Demonstration revenue is not revenue (M5 §3). The demonstration estate
     * carries no subscription today, so this excludes nothing — and that is
     * precisely why the filter belongs here rather than being left out on the
     * grounds that it currently makes no difference. The day somebody gives the
     * demonstration estate a plan so a reviewer can see a billing screen, the
     * platform's headline MRR must not move.
     */
    private function monthlyRecurringMinor(User $viewer): int
    {
        $query = Subscription::query()
            ->where('subscriptions.status', 'active')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id');

        OperatorEstates::narrow($query, $viewer, 'subscriptions.tenant_id');

        /*
         * THE SECURITY ADD-ON IS REVENUE, and leaving it out made this card disagree with the
         * invoice for the same month. It summed `unit_count * price_per_unit_minor` alone, so
         * Phoenix Park's September invoice totalled J$756,500 while this read J$680,000 — the
         * difference being exactly its 17 guards at J$4,500 each.
         *
         * The boards settle it: super-admin-05 draws "450 units · 4 guards deployed · $171,000
         * MRR", and 450 x J$340 is J$153,000. The missing J$18,000 is the guarding.
         *
         * The arithmetic lives in `PlatformPricing` because the client directory computes the
         * same figure, and two revenue formulas drift exactly the way the two currency
         * formatters below this method once did.
         */
        return (int) $query->sum(DB::raw(PlatformPricing::mrrSql()));
    }
}
