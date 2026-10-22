<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;
use App\Support\PlatformMoney;
use App\Support\PlatformPricing;
use App\Support\TableQuery;
use Brick\Money\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The client directory and one client's detail — board screens super-admin-04
 * and super-admin-05.
 *
 * A "client" is an estate that subscribes to GeminiSecure. Everything here is
 * read from gs_platform and nothing else: an estate database holds residents,
 * households and their money, and none of that belongs on a Gemini Console
 * screen. A figure that cannot be produced centrally is not a cross-tenant
 * figure and is not shown.
 *
 * Search, filtering, sorting and paging all happen in SQL rather than in the
 * page, so a viewer scoped to assigned sites cannot reach an estate outside
 * that scope by paging or sorting past what the first page happened to show.
 */
class ClientDirectory
{
    public const DEFAULT_SORT = 'name';

    public const DEFAULT_DIRECTION = 'asc';

    /**
     * The estate status that means "not live yet".
     *
     * Named rather than spelled out at each use because it is the switch the
     * client detail screen turns on, and a typo in one of those places would
     * silently show a live client's panels to an estate that has none of that
     * data.
     */
    public const ONBOARDING = 'onboarding';

    /**
     * Sortable columns, mapped to what they actually order by.
     *
     * `tier` orders by the plan's own sort column rather than its name, so
     * Essential / Standard / Premium come out in tier order instead of
     * alphabetically. `guards` and `mrr` are select aliases, computed below.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'name' => 'tenants.name',
        'units' => 'subscriptions.unit_count',
        'tier' => 'plans.sort',
        'guards' => 'guards_deployed',
        'mrr' => 'mrr_minor',
        'status' => 'tenants.status',
    ];

    /**
     * The board's three status chips, and the tenant statuses each covers.
     *
     * "At risk" is a commercial state, not an access one: `dunning` and
     * `suspended` gate billing features and never entry or a safety function.
     *
     * @var array<string, list<string>>
     */
    private const STATUS_FILTERS = [
        'active' => ['active'],
        'onboarding' => ['onboarding'],
        'at-risk' => ['dunning', 'suspended'],
    ];

    /**
     * The columns and the chip that are Billing & subscriptions' own (M10 §5).
     *
     * `tier` and `mrr` are what a client is on and what it pays. `at-risk` is the chip that lists
     * the clients in dunning — which is to say, the clients behind on their invoices — so a
     * reader who may not see billing status may not filter by it either.
     *
     * @var list<string>
     */
    private const MONEY_SORTS = ['tier', 'mrr'];

    /** @var list<string> */
    private const MONEY_STATUS_FILTERS = ['at-risk'];

    /**
     * The only values `status` may carry — without "At risk" for a viewer who may
     * not read billing status, for the reason given on MONEY_SORTS.
     *
     * @return list<string>
     */
    public static function statusKeys(bool $maySeeMoney = true): array
    {
        $keys = array_keys(self::STATUS_FILTERS);

        return $maySeeMoney ? $keys : array_values(array_diff($keys, self::MONEY_STATUS_FILTERS));
    }

    /**
     * Everything the directory screen renders.
     *
     * `$maySeeMoney` is the caller's answer from `PlatformMoney::maySeeRevenue()` (M10 §5). Without
     * it every row loses its tier and its MRR — the KEYS, not only the values — the two headings
     * are not built, the "At risk" chip is not offered, and a client in dunning or suspended for
     * non-payment reads as the live client it operationally is. Defaults to the Director's view so
     * a caller that was never asked keeps the board.
     *
     * @param  array{q: string, status: list<string>}  $criteria  the search and the chips; the order and the page are `$table`'s
     * @return array<string, mixed>
     */
    public function directory(User $viewer, array $criteria, bool $maySeeMoney = true, ?TableQuery $table = null): array
    {
        /*
         * NARROWED AGAIN HERE, not only in the controller. This method is public and the
         * controller is one caller of it; the chip that lists the clients in dunning arriving by
         * any other route is put back to what it would have been had the reader asked for
         * something that does not exist. The money sorts are not in such a reader's table at all
         * (`tableSorts()`), so `?sort=mrr` from them falls back to the directory's own order.
         */
        if (! $maySeeMoney) {
            $criteria['status'] = array_values(array_intersect($criteria['status'], self::statusKeys(false)));
        }

        $table ??= self::table(null, $maySeeMoney);
        $query = $this->baseQuery($viewer, $criteria, $maySeeMoney);

        /*
         * Counted before the ordering is applied.
         *
         * COUNT(*) replaces the select list, so an ORDER BY that names a select
         * alias — `mrr_minor`, `guards_deployed` — would reference a column the
         * count query no longer has, and MySQL would reject it outright.
         */
        $total = (clone $query)->count();

        // `tenants.id` last, always (`TableQuery::order`), so two estates on the same tier keep a stable order across pages.
        $table->order($query, 'tenants.id');

        $rows = $query->forPage($table->page, $table->per)->get();
        $page = new LengthAwarePaginator($rows, $total, $table->per, $table->page);

        return [
            'clients' => $rows->map(fn (object $row): array => $this->row($row, $maySeeMoney))->all(),
            'filters' => $this->chips($criteria, $maySeeMoney, $table),
            'table' => $table->meta($page),
            // Whether the Tier and MRR columns are this reader's (M10 §5): the page draws them only then.
            'moneyColumns' => $maySeeMoney,
            'total' => $total,
            'isFiltered' => $criteria['q'] !== '' || $criteria['status'] !== [],
            'search' => $criteria['q'],
        ];
    }

    /**
     * What the directory orders by (M32 F5), and for whom. A viewer who may not read a column may not order by it either
     * (M10 §5): a directory sorted by MRR reports the revenue ranking without printing a single figure, so neither money
     * sort is in such a reader's table. And a status sort orders by what the reader is SHOWN: for a reader without billing
     * both commercial states sort as the `active` they are drawn as — ordered on the raw column, a client in dunning
     * would sort between the Active and Onboarding rows while reading "Active". The literals are the class's own
     * constants, not input.
     *
     * @return array<string, string|\Closure>
     */
    public static function tableSorts(bool $maySeeMoney = true): array
    {
        $sorts = self::SORTS;

        if (! $maySeeMoney) {
            foreach (self::MONEY_SORTS as $money) {
                unset($sorts[$money]);
            }

            $atRisk = "'".implode("', '", self::STATUS_FILTERS['at-risk'])."'";
            $sorts['status'] = static fn ($query, string $dir) => $query->orderByRaw("CASE WHEN tenants.status IN ({$atRisk}) THEN 'active' ELSE tenants.status END ".$dir);
        }

        return $sorts;
    }

    /** The directory as its address asks for it (M32 F5): name order by default — it is a register. */
    public static function table(?Request $request = null, bool $maySeeMoney = true): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::tableSorts($maySeeMoney), self::DEFAULT_SORT, self::DEFAULT_DIRECTION);
    }

    /**
     * One client's detail — board screens super-admin-05 and super-admin-09.
     *
     * TWO LIFECYCLES, ONE SCREEN.
     *
     * Both boards are the same component opened on a different client, and the
     * difference between them is not styling. A live client's record answers
     * "what are we billing, who is posted here, what have they paid". A client
     * still being onboarded cannot answer any of those, and asking it to
     * produces a screen of em dashes: an MRR of nought, an empty invoice list,
     * a guard panel reading "not staffed yet". So the board draws a different
     * set of panels — a checklist of what is left to do, the plan as prepared
     * rather than as billed, and the one contact who has been named — and this
     * returns the set that belongs to the estate's actual state.
     *
     * `lifecycle` is the switch, and it is read from the estate rather than
     * inferred from the absence of data, because an estate that is live and
     * genuinely has no guards is a different fact from one not yet staffed.
     *
     * AND A VIEWER WHO MAY NOT READ BILLING (M10 §5) gets the record without
     * any of it: no tier pill, no MRR or projected MRR, no billing status —
     * which is where the "Overdue" badge on /clients/phoenixparkvillage1 came
     * from — no plan prepared, no per-unit price, no invoice list and no
     * "payment method on file" step. Every one of those KEYS is absent rather
     * than blank, the figures behind them are never computed, and
     * `withheld_reason` carries the one sentence the page shows in their place.
     * `$maySeeMoney` defaults to the Director's view for a caller that was
     * never asked.
     *
     * `$mayPrice` is the caller's answer from `ClientPlan::mayPrice()`, and only
     * words the go-live button's refusal on an unplanned client: a reader who
     * cannot open the plan screen is told who can, not to set the plan. It
     * changes no figure, and defaults to the Director's view as the other does.
     *
     * @return array<string, mixed>
     */
    public function detail(string $tenantId, bool $maySeeMoney = true, bool $mayPrice = true): array
    {
        $estate = DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('tenants.id', $tenantId)
            ->select([
                'tenants.id',
                'tenants.name',
                'tenants.address_line',
                'tenants.parish',
                'tenants.gate_count',
                'tenants.phases',
                'tenants.status',
                'tenants.demo',
                'tenants.provisioned_at',
                'subscriptions.unit_count',

                // MRR is the software on the units PLUS the guards on the contract, so the guard
                // count has to come back with the row. Without it `contracted_guards` reads null
                // and this screen silently drops the security add-on from the figure while the
                // invoice beside it keeps charging for it.
                'subscriptions.contracted_guards',

                'subscriptions.status as subscription_status',
                'subscriptions.started_on',
                'subscriptions.renews_on',
                'plans.name as plan_name',
                'plans.min_units',
                'plans.price_per_unit_minor',
                'plans.currency',
            ])
            ->first();

        if ($estate === null) {
            return [];
        }

        $currency = $this->currency($estate->currency ?? null);
        $guards = $this->guards($tenantId);
        $contacts = $this->contacts($tenantId);
        $posts = DB::connection('mysql')->table('posts')->where('tenant_id', $tenantId)->count();

        $isOnboarding = $estate->status === self::ONBOARDING;

        // The homes the estate's site plan holds, where it has one (M57.5 §4.1); the contract's figure stays the contract's.
        $built = $isOnboarding ? null : $this->builtFromPlan($tenantId);

        // Software on the units PLUS the guards on the contract — see PlatformPricing for why the
        // add-on was missing from every MRR figure on the platform until 23 September 2026.
        // Never worked out at all for a viewer who may not read it (M10 §5).
        $mrrMinor = $maySeeMoney && $estate->subscription_status === 'active' && $estate->unit_count !== null
            ? PlatformPricing::monthlyMinorAt(
                (int) $estate->unit_count,
                (int) $estate->price_per_unit_minor,
                (int) ($estate->contracted_guards ?? 0),
            )
            : null;

        /*
         * What this client would bill at, once it does.
         *
         * Only ever shown on an onboarding record, and labelled "Projected"
         * there, so it can never be mistaken for revenue the platform has. The
         * MRR headline on the dashboard counts active subscriptions alone, and
         * this figure is deliberately not part of it.
         */
        $projectedMinor = ! $maySeeMoney || $estate->unit_count === null || $estate->price_per_unit_minor === null
            ? null
            : (int) $estate->unit_count * (int) $estate->price_per_unit_minor;

        return [
            'id' => (string) $estate->id,
            'name' => (string) $estate->name,
            'lifecycle' => $isOnboarding ? 'onboarding' : 'live',
            /*
             * Live:       "Waterloo Road, St. Andrew · 5 phases · Client since Mar 2024".
             * Onboarding: "Portmore, St. Catherine · Onboarding started Sep 2, 2026".
             *
             * Where the site is, and then the fact that dates the relationship
             * — which is a different fact in each state. "Client since" on an
             * estate that is not yet a client would be a claim about a
             * relationship that has not started; how a site is laid out is not
             * yet settled either, so the onboarding line leaves it out rather
             * than reporting a phase count nobody has confirmed.
             *
             * Each part falls away if unknown rather than printing a
             * placeholder, so a newly provisioned estate reads as incomplete
             * instead of wrong. The guard-post count is the fallback for the
             * middle slot: an estate with no phase structure recorded still
             * says something true about its shape.
             */
            /*
             * The demonstration estate's own record opens normally — this is the
             * "reachable on purpose" half of M5 §3, and it is what lets somebody
             * check the estate is seeded before a store reviewer finds out it is
             * not. It is labelled in the FIRST slot, before the address, because
             * every figure below it is invented and the reader should know that
             * before reading any of them.
             */
            'subtitle' => implode(' · ', array_filter($isOnboarding
                ? [
                    ((bool) ($estate->demo ?? false)) ? OperatorEstates::LABEL : null,
                    $this->siteAddress($estate) ?? (string) $estate->id,
                    $this->onboardingSince($estate),
                ]
                : [
                    ((bool) ($estate->demo ?? false)) ? OperatorEstates::LABEL : null,
                    $this->siteAddress($estate) ?? (string) $estate->id,
                    $this->layout($estate, $posts),
                    $this->clientSince($estate),
                ])),
            /*
             * The pill beside the name.
             *
             * A live client's is its commercial tier, which is the fact that
             * changes what it is owed. An onboarding one has no tier yet in any
             * meaningful sense — the plan is prepared, not in force — so the
             * pill carries the state instead, which is what the reader needs to
             * know before anything else on the screen.
             *
             * WITHOUT BILLING (M10 §5) there is no `tierLabel` at all, and the
             * page draws `statusLabel` in the same pill: Active or Onboarding —
             * the lifecycle, never the price list, and never the commercial
             * state `statusLabel()` explains.
             */
            ...($maySeeMoney ? [
                'tierLabel' => match (true) {
                    $isOnboarding => $this->statusLabel((string) $estate->status),
                    $estate->plan_name === null => 'No plan yet',
                    default => $estate->plan_name.' tier',
                },
            ] : []),
            'statusLabel' => $this->statusLabel((string) $estate->status, $maySeeMoney),
            'stats' => $isOnboarding
                ? [
                    [
                        'value' => $estate->unit_count === null ? '—' : number_format((int) $estate->unit_count),
                        'label' => 'Units',
                    ],
                    /*
                     * THE THREE BILLING STATS, EACH ABSENT WITHOUT BILLING (M10 §5). The
                     * prepared plan is the tier; the projection is a price; the status is
                     * whether they have paid. The hero reflows onto what is left rather than
                     * drawing a greyed tile that would still say a figure exists.
                     */
                    ...($maySeeMoney ? [
                        [
                            'value' => $estate->plan_name === null ? '—' : (string) $estate->plan_name,
                            'label' => 'Plan prepared',
                        ],
                        [
                            'value' => $projectedMinor === null ? '—' : $this->money($projectedMinor, $currency),
                            'label' => 'Projected MRR',
                        ],
                        [
                            'value' => $this->billingStatus($tenantId),
                            'label' => 'Billing status',
                        ],
                    ] : []),
                ]
                : [
                    [
                        'value' => $estate->unit_count === null ? '—' : number_format((int) $estate->unit_count),
                        'label' => $built === null ? 'Units' : 'Units contracted',
                    ],
                    // M57.5 §4.1: what the estate's site plan built, beside the contract's figure and never in place of it.
                    ...($built === null ? [] : [[
                        'value' => number_format($built),
                        'label' => 'Built (site plan)',
                    ]]),
                    [
                        'value' => (string) count($guards),
                        'label' => 'Guards deployed',
                    ],
                    // "Overdue" is `billingStatus()`'s word, and this is where it reached the
                    // Admin Assistant from. Not asked at all for a viewer without billing.
                    ...($maySeeMoney ? [
                        [
                            'value' => $mrrMinor === null ? '—' : $this->money($mrrMinor, $currency),
                            'label' => 'MRR',
                        ],
                        [
                            'value' => $this->billingStatus($tenantId),
                            'label' => 'Billing status',
                        ],
                    ] : []),
                ],
            /*
             * "Subscription" once it is in force; "Subscription — prepared"
             * while it is not. One word, and it is the difference between
             * quoting a client what they are paying and what they would pay.
             */
            'subscriptionHead' => $isOnboarding ? 'Subscription — prepared' : 'Subscription',

            /*
             * The plan with its per-unit price, and the invoice list with its amounts and
             * statuses — both Billing & subscriptions' records, both OMITTED for a viewer who
             * does not hold it (M10 §5). Omitted rather than sent empty: an empty list is what a
             * client with no plan sends, and the page would say "Not on a plan yet" about a
             * client that is. `withheld_reason` is what the page draws instead.
             */
            ...($maySeeMoney ? [
                'subscription' => $this->subscriptionRows($estate, $currency, $isOnboarding),
                'invoices' => $this->invoiceRows($tenantId),
            ] : []),
            'withheld_reason' => $maySeeMoney ? null : PlatformMoney::REVENUE_WITHHELD,

            'contacts' => $contacts,
            'guards' => $guards,
            'onboarding' => $isOnboarding
                ? $this->onboarding($estate, $tenantId, count($guards), count($contacts), $maySeeMoney, $mayPrice)
                : null,
        ];
    }

    /**
     * The onboarding panels — board screen super-admin-09.
     *
     * @return array{checklist: list<array{label: string, value: string, done: bool, note: string|null}>, canComplete: bool, blockedReason: string|null, primaryContact: list<array{label: string, value: string}>}
     */
    private function onboarding(object $estate, string $tenantId, int $guards, int $contacts, bool $maySeeMoney = true, bool $mayPrice = true): array
    {
        $checklist = $this->checklist($estate, $guards, $contacts, $maySeeMoney);

        /*
         * What actually blocks go-live, as against what is merely outstanding.
         *
         * The checklist is a progress report a human reads; it is not five
         * gates. Only one of its steps can make marking the client live WRONG,
         * and that is the prepared plan: completing onboarding starts billing,
         * and billing an estate with no plan has no amount to bill. Guards and
         * an invited admin are things this estate may legitimately go live
         * without — an estate that runs its own security buys the software
         * alone, and the directory already draws that case.
         *
         * "Payment method on file" is deliberately not a gate either, and not
         * only because nothing central records one. Withholding a client's
         * go-live over how they intend to pay is the same move as withholding
         * access over money owed, which this system does not do.
         */
        $planPrepared = $checklist[1]['done'];

        return [
            'checklist' => $checklist,
            'canComplete' => $planPrepared,

            // "Set the plan first" only to a reader who may open the plan screen; anyone else is
            // told who can (`ClientPlan::unplannedReason()`).
            'blockedReason' => $planPrepared ? null : ClientPlan::unplannedReason($mayPrice),
            'primaryContact' => $this->primaryContact($tenantId),
        ];
    }

    /**
     * The five onboarding steps, each answered from what is actually recorded.
     *
     * Every one of these is derived on read rather than stored as a tick. A
     * stored checklist drifts: someone ticks "guards assigned", the guard is
     * later moved to another estate, and the record still says it was done.
     *
     * THE ORDER IS LOAD-BEARING: `onboarding()` reads the plan step at index 1,
     * so the one step a viewer without billing does not get (M10 §5) is the one
     * AFTER it. "Payment method on file" is how a client intends to pay, which
     * is Billing's record; whether a plan has been prepared is not a price and
     * stays, because it is what go-live waits on.
     *
     * @return list<array{label: string, value: string, done: bool, note: string|null}>
     */
    private function checklist(object $estate, int $guards, int $contacts, bool $maySeeMoney = true): array
    {
        // A profile is more than a subdomain: an estate nobody can find on a
        // map is not a site a supervisor can be sent to.
        $profiled = $this->siteAddress($estate) !== null;
        $planned = $estate->subscription_status !== null && $estate->plan_name !== null;

        return [
            $this->step('Estate profile created', $profiled),
            $this->step('Subscription plan prepared', $planned),
            ...($maySeeMoney ? [$this->paymentMethodStep()] : []),
            $this->step('Guards assigned', $guards > 0),
            $this->step('Estate admin invited', $contacts > 0 && $this->adminInvited((string) $estate->id)),
        ];
    }

    /**
     * WHETHER AN ADMIN WAS REALLY INVITED (DESIGN §11 row 5). This said "Done" the moment a contact existed, and
     * the onboarding sent them nothing. Done now means an invitation to this estate was EMAILED — the mail server
     * took it — or somebody on its committee is already signing in.
     */
    private function adminInvited(string $tenantId): bool
    {
        return DB::connection('mysql')->table('invitations')->where('tenant_id', $tenantId)->whereNotNull('sent_at')->exists()
            || DB::connection('mysql')->table('estate_assignments')
                ->join('users', 'users.id', '=', 'estate_assignments.user_id')
                ->where('estate_assignments.tenant_id', $tenantId)
                ->where('estate_assignments.is_active', true)
                ->where('users.console', 'estate')
                ->where('users.status', 'active')
                ->exists();
    }

    /**
     * SCHEMA EXCEPTION. Nothing central records a payment instrument —
     * `plans`, `subscriptions`, `invoices` and `invoice_lines` are the
     * whole of central billing, and none of them holds a card, a
     * mandate or a bank instruction. That table belongs to the billing
     * module, which draws it on its own board.
     *
     * So this step reports what is true today, which is that no
     * instrument is on file, and says on the row why it cannot say
     * otherwise. Reading a paid invoice as a payment method would be
     * the wrong answer wearing the right shape: an estate can settle by
     * cheque or transfer and still have nothing standing on file.
     *
     * @return array{label: string, value: string, done: bool, note: string|null}
     */
    private function paymentMethodStep(): array
    {
        return $this->step(
            'Payment method on file',
            false,
            'No payment instrument is recorded centrally. Platform billing stores plans, subscriptions and invoices only.'
        );
    }

    /** @return array{label: string, value: string, done: bool, note: string|null} */
    private function step(string $label, bool $done, ?string $note = null): array
    {
        // "✓ Done" and "Pending" are the board's own words, including the tick.
        return [
            'label' => $label,
            'value' => $done ? '✓ Done' : 'Pending',
            'done' => $done,
            'note' => $note,
        ];
    }

    /**
     * The one person this client is represented by, as three labelled rows.
     *
     * The president, and not simply whoever sorts first. The committee's
     * president is the account the Estate Console is issued to and the person
     * a platform operator writes to; ranking by role and taking the top would
     * hand back whichever administrative account happened to be created first.
     * Where no president has been named yet the next-ranked committee member
     * stands in, and where nobody has, the rows say so rather than going blank.
     *
     * @return list<array{label: string, value: string}>
     */
    private function primaryContact(string $tenantId): array
    {
        $contact = DB::connection('mysql')
            ->table('estate_assignments')
            ->join('users', 'users.id', '=', 'estate_assignments.user_id')
            ->join('roles', 'roles.id', '=', 'estate_assignments.role_id')
            // The estate's own version of the President is still the President (M11 B4).
            ->leftJoin('roles as stands', 'stands.id', '=', 'roles.version_of_id')
            ->where('estate_assignments.tenant_id', $tenantId)
            ->where('estate_assignments.is_active', true)
            ->where('roles.console', 'estate')
            ->orderByRaw("CASE WHEN COALESCE(stands.name, roles.name) = 'estate.president' THEN 0 ELSE 1 END")
            ->orderBy('roles.sort')
            ->orderBy('estate_assignments.id')
            ->select('users.name', 'users.email', 'roles.label')
            ->first();

        return [
            ['label' => 'Name', 'value' => $contact === null ? 'Not yet on file' : (string) $contact->name],
            ['label' => 'Role', 'value' => $contact === null ? '—' : (string) $contact->label],
            ['label' => 'Email', 'value' => $contact === null ? '—' : (string) $contact->email],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the directory query */
    /* ------------------------------------------------------------------ */

    /**
     * The filtered, unordered directory query.
     *
     * The query builder rather than Eloquent: every column below belongs to a
     * join or is computed, so there is no Tenant to hydrate — asking Eloquent
     * for these would hand back Tenant objects carrying attributes the class
     * does not have.
     *
     * @param  array{q: string, status: list<string>}  $criteria
     */
    private function baseQuery(User $viewer, array $criteria, bool $maySeeMoney = true): Builder
    {
        $deployed = DB::connection('mysql')
            ->table('guards')
            ->whereNotNull('tenant_id')
            // The same definition of "deployed" the detail screen's panel
            // uses. Two answers to one question on two screens about the same
            // estate is how a directory ends up contradicting a record.
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->groupBy('tenant_id')
            ->select('tenant_id', DB::raw('COUNT(*) as deployed'));

        $query = DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->leftJoinSub($deployed, 'deployed', 'deployed.tenant_id', '=', 'tenants.id')
            ->select([
                'tenants.id',
                'tenants.name',
                'tenants.status',

                // Carried so a row that IS the demonstration estate can say so.
                // The estate's name says it too, but the name is data somebody
                // can change and the column is the fact.
                'tenants.demo',

                'tenants.provisioned_at',
                'subscriptions.unit_count',
                'subscriptions.status as subscription_status',
                'subscriptions.started_on',
                DB::raw('COALESCE(deployed.deployed, 0) as guards_deployed'),
            ]);

        /*
         * THE TIER AND THE MRR ARE NOT SELECTED AT ALL for a viewer who may not read them
         * (M10 §5). Not selected and then dropped by `row()` — not selected, so no later edit to
         * the row can reach for a column this query never fetched.
         */
        if ($maySeeMoney) {
            $query->addSelect([
                'plans.key as plan_key',
                'plans.name as plan_name',
                'plans.currency',
                /*
                 * MRR counts an ACTIVE subscription only, which is the same rule
                 * the platform dashboard's MRR uses. Were this screen to count
                 * dunning or suspended estates too, the directory would add up to
                 * more than the headline figure on the dashboard and one of the
                 * two would be wrong. A client without active recurring revenue
                 * shows an em dash, exactly as the board draws an onboarding one.
                 */
                DB::raw("CASE WHEN subscriptions.status = 'active'
                    THEN ".PlatformPricing::mrrSql().'
                    ELSE NULL END as mrr_minor'),
            ]);
        }

        /*
         * A role scoped to assigned sites sees only those, enforced in the
         * query. Filtering the rendered list instead would leave every other
         * estate reachable by paging, sorting or deep-linking.
         */
        if ($viewer->widestScope() === AccessScope::AssignedSites) {
            $query->whereIn('tenants.id', $viewer->accessibleEstateIds());
        }

        /*
         * THE DEMONSTRATION ESTATE IS OUT OF THE DEFAULT DIRECTORY, AND FOUND BY
         * NAMING IT (M5 §3).
         *
         * Two things are true at once and this is where they meet. It is not a
         * client: it pays nothing, it is staffed by nobody, and counting it
         * makes the "N clients" above this table wrong. But somebody at Gemini
         * has to be able to look at it — to check it is seeded before a
         * submission, and to notice it has broken before a reviewer does — and a
         * record that can only be reached by a URL nobody has written down is a
         * record nobody will check.
         *
         * So it is out of the list nobody asked for and in the list somebody
         * did. Typing its name or its id is the deliberate act; `$total` is
         * computed from this same query, so the count and the rows always agree,
         * and `row()` labels it whenever it appears.
         *
         * It is NOT excluded from `detail()`. A client record opened on purpose
         * is the "reachable on purpose" half of the rule, and refusing it there
         * would leave the estate unsupportable.
         */
        if ($criteria['q'] === '') {
            OperatorEstates::exclude($query, 'tenants.id');
        }

        if ($criteria['q'] !== '') {
            // The wildcards are escaped: a search for "50%" must look for that
            // text, not for everything.
            $term = '%'.addcslashes($criteria['q'], '%_\\').'%';

            $query->where(function (Builder $scoped) use ($term): void {
                $scoped->where('tenants.name', 'like', $term)
                    ->orWhere('tenants.id', 'like', $term);
            });
        }

        $statuses = [];

        foreach ($criteria['status'] as $key) {
            $statuses = array_merge($statuses, self::STATUS_FILTERS[$key] ?? []);

            /*
             * "Active" MEANS WHAT THE ROW SAYS (M10 §5). A reader without billing is shown a
             * client in dunning as Active, so the Active chip has to return it — a row that
             * vanished when its own label was clicked would say, by elimination, exactly what
             * the label was keeping back.
             */
            if (! $maySeeMoney && $key === 'active') {
                $statuses = array_merge($statuses, self::STATUS_FILTERS['at-risk']);
            }
        }

        if ($statuses !== []) {
            $query->whereIn('tenants.status', array_values(array_unique($statuses)));
        }

        return $query;
    }

    /**
     * One directory row.
     *
     * WITHOUT BILLING (M10 §5) the row has no `tierKey`, `tierLabel` or `mrr` key at all — the
     * columns were never selected — and its status is read without the commercial half: see
     * `statusLabel()`.
     *
     * @return array<string, mixed>
     */
    private function row(object $row, bool $maySeeMoney = true): array
    {
        $status = (string) $row->status;
        $guards = (int) $row->guards_deployed;

        return [
            'id' => (string) $row->id,
            'href' => '/clients/'.$row->id,
            'name' => (string) $row->name,
            'initials' => $this->initials((string) $row->name),
            'sub' => implode(' · ', array_filter([
                (string) $row->id,

                // ALWAYS LABELLED WHEN SHOWN. A row that reached this list did
                // so because somebody searched for it, and they should not have
                // to remember which of two similar names is the invented one.
                ((bool) ($row->demo ?? false)) ? OperatorEstates::LABEL : null,

                $this->clientSince($row),
            ])),
            'units' => $row->unit_count === null ? '—' : number_format((int) $row->unit_count),
            ...($maySeeMoney ? [
                'tierKey' => $row->plan_key === null ? null : (string) $row->plan_key,
                'tierLabel' => $row->plan_name === null ? '—' : (string) $row->plan_name,
            ] : []),
            'guards' => $this->guardCell($guards, $status),
            ...($maySeeMoney ? [
                'mrr' => $row->mrr_minor === null
                    ? '—'
                    : $this->money((int) $row->mrr_minor, $this->currency($row->currency ?? null)),
            ] : []),
            'statusBadge' => $this->statusBadge($status, $maySeeMoney),
            'statusLabel' => $this->statusLabel($status, $maySeeMoney),
        ];
    }

    /**
     * The guards column, in the board's own three states.
     *
     * Nought guards is two different facts: an onboarding estate has not been
     * staffed yet, whereas a live estate with no guards runs its own security
     * and buys the software alone. Printing a bare "0" for both loses the
     * difference, which is why the board draws them as different sentences.
     */
    private function guardCell(int $guards, string $status): string
    {
        if ($guards > 0) {
            return (string) $guards;
        }

        return $status === 'onboarding' ? 'Not yet assigned' : '0 · self-managed security';
    }

    /* ------------------------------------------------------------------ */
    /* links: chips, sortable headings, pages */
    /* ------------------------------------------------------------------ */

    /**
     * The four filter chips, each carrying the URL that toggles it.
     *
     * Built here rather than in the page because they are the same query
     * string the controller reads back: a second implementation in JavaScript
     * would be a second place for the rules to drift.
     *
     * No "At risk" chip for a viewer who may not read billing status (M10 §5):
     * the chip IS a list of the clients in dunning.
     *
     * @param  array{q: string, status: list<string>}  $criteria
     * @return array<string, array{href: string, active: bool}>
     */
    private function chips(array $criteria, bool $maySeeMoney, TableQuery $table): array
    {
        $selected = $criteria['status'];

        // "All clients" clears the status filter; it does not clear the search,
        // which is a different question the viewer has already asked — nor the
        // order or the page size (M32 F5). Back to page 1, as every filter is.
        $chips = [
            'all' => [
                'href' => $this->href($criteria, $table, ['status' => null]),
                'active' => $selected === [],
            ],
        ];

        foreach (self::statusKeys($maySeeMoney) as $key) {
            $isOn = in_array($key, $selected, true);

            // Chips combine: toggling one adds or removes it, leaving the rest.
            $next = $isOn
                ? array_values(array_diff($selected, [$key]))
                : [...$selected, $key];

            $chips[$key] = [
                'href' => $this->href($criteria, $table, ['status' => $next === [] ? null : $next]),
                'active' => $isOn,
            ];
        }

        return $chips;
    }

    /**
     * The directory URL carrying the current search, chips and order, with overrides applied — and never the page: a
     * chip starts the list again from the first (M32 F5). The headings and the pager are the shared table's.
     *
     * Defaults are omitted so an unfiltered directory is plain "/clients"
     * rather than a URL restating everything it is not filtering by.
     *
     * @param  array{q: string, status: list<string>}  $criteria
     * @param  array<string, mixed>  $overrides
     */
    private function href(array $criteria, TableQuery $table, array $overrides = []): string
    {
        $params = array_merge([
            'q' => $criteria['q'] !== '' ? $criteria['q'] : null,
            'status' => $criteria['status'] !== [] ? $criteria['status'] : null,
            'sort' => $table->sort,
            'dir' => $table->sort === null ? null : $table->dir,
            'per' => $table->per !== TableQuery::SIZES[0] ? $table->per : null,
        ], $overrides);

        $params = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []
        );

        return $params === [] ? '/clients' : '/clients?'.Arr::query($params);
    }

    /* ------------------------------------------------------------------ */
    /* detail panels */
    /* ------------------------------------------------------------------ */

    /**
     * The subscription panel.
     *
     * The board's second row is a security add-on priced per guard. There is no
     * guard add-on rate in the central schema, so the row shows the contracted
     * unit count instead rather than a number nobody stores.
     *
     * An onboarding client drops the billing-cycle row, and that is the board's
     * own doing as much as this system's: nothing has been invoiced, so
     * "Monthly, invoiced since March" would be describing a cycle that has not
     * run once.
     *
     * @return list<array{label: string, value: string}>
     */
    private function subscriptionRows(object $estate, string $currency, bool $isOnboarding = false): array
    {
        if ($estate->subscription_status === null) {
            return [];
        }

        $units = (int) $estate->unit_count;

        $rows = [
            [
                'label' => 'Plan',
                'value' => $estate->plan_name.' — '
                    .$this->money((int) $estate->price_per_unit_minor, $currency).'/unit/mo',
            ],
            [
                'label' => 'Units contracted',
                'value' => number_format($units).' of a '.number_format((int) $estate->min_units).' minimum',
            ],
        ];

        if (! $isOnboarding) {
            $rows[] = [
                'label' => 'Billing cycle',
                'value' => 'Monthly, since '.$this->month($estate->started_on),
            ];
        }

        $rows[] = [
            'label' => 'Contract renewal',
            'value' => $estate->renews_on === null ? 'Not set' : $this->month($estate->renews_on),
        ];

        return $rows;
    }

    /**
     * Recent platform invoices for this client.
     *
     * This is Gemini Security billing its client. It is not the estate's own
     * ledger, which lives in the estate database and never appears here.
     *
     * @return list<array{label: string, value: string}>
     */
    private function invoiceRows(string $tenantId): array
    {
        return DB::connection('mysql')
            ->table('invoices')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('period_start')
            ->limit(5)
            ->select('period', 'total_minor', 'currency', 'status')
            ->get()
            ->map(fn (object $invoice): array => [
                'label' => (string) $invoice->period,
                'value' => $this->money(
                    (int) $invoice->total_minor,
                    $this->currency($invoice->currency ?? null)
                ).' · '.ucfirst((string) $invoice->status),
            ])
            ->all();
    }

    /**
     * The estate's own committee, from the central assignment table.
     *
     * Gemini staff assigned to this estate are deliberately excluded: they are
     * colleagues, not the client's contacts.
     *
     * @return list<array{initials: string, name: string, detail: string}>
     */
    private function contacts(string $tenantId): array
    {
        return DB::connection('mysql')
            ->table('estate_assignments')
            ->join('users', 'users.id', '=', 'estate_assignments.user_id')
            ->join('roles', 'roles.id', '=', 'estate_assignments.role_id')
            ->where('estate_assignments.tenant_id', $tenantId)
            ->where('estate_assignments.is_active', true)
            ->where('roles.console', 'estate')
            ->orderBy('roles.sort')
            ->select('users.name', 'users.status', 'roles.label')
            ->get()
            ->map(fn (object $contact): array => [
                'initials' => $this->initials((string) $contact->name),
                'name' => (string) $contact->name,
                'detail' => $contact->status === 'active'
                    ? (string) $contact->label
                    : $contact->label.' · account '.$contact->status,
            ])
            ->all();
    }

    /**
     * Guards posted to this estate.
     *
     * @return list<array{initials: string, name: string, detail: string}>
     */
    private function guards(string $tenantId): array
    {
        return DB::connection('mysql')
            ->table('guards')
            ->leftJoin('posts', 'posts.id', '=', 'guards.post_id')
            ->where('guards.tenant_id', $tenantId)
            /*
             * Deployed means standing at a post here, not compliant.
             *
             * This required status = 'active', which hid a licence-expired
             * guard from the client whose gate they are on. That is the wrong
             * way round: an expired licence is exactly the thing a client
             * should be able to see about someone posted at their estate, and
             * the board lists that guard. On leave and suspended are excluded
             * because those people are genuinely not at the post.
             */
            ->whereNotIn('guards.status', ['on_leave', 'suspended'])
            ->orderBy('guards.full_name')
            ->select('guards.full_name', 'guards.psra_number', DB::raw(Post::labelSql().' as post_name'))
            ->get()
            ->map(fn (object $guard): array => [
                'initials' => $this->initials((string) $guard->full_name),
                'name' => (string) $guard->full_name,
                'detail' => ($guard->post_name ?? 'Unposted').' · '.$guard->psra_number,
            ])
            ->all();
    }

    /**
     * Where this client stands on its platform invoices.
     *
     * Derived on read rather than stored: a stored flag would still say
     * "Current" the morning after an invoice fell due.
     *
     * A client that has never been invoiced is NOT "Current". "Current" means
     * paid up, and a client with no ledger at all has not paid anything —
     * saying so on an estate mid-onboarding would be reassurance about a
     * relationship that has not started. It is read from the invoices rather
     * than from the estate's status, because an estate whose invoicing has
     * begun is billing whatever its lifecycle column says.
     *
     * UNPAID MEANS SOMETHING IS STILL OWED ON IT (B080): its total less its
     * credit notes, which never touch the invoice itself. An invoice credited
     * to nothing made this client "Overdue" for ever, with its 1100 at zero.
     * Overdue is from the day after the due date, on Gemini's calendar — the
     * rule `Invoice::isOverdue()` and the billing KPI keep.
     */
    private function billingStatus(string $tenantId): string
    {
        $invoices = DB::connection('mysql')->table('invoices')->where('tenant_id', $tenantId);

        if (! (clone $invoices)->exists()) {
            return 'Not billing yet';
        }

        $unpaid = (clone $invoices)
            ->whereNotIn('status', ['paid', 'void'])
            ->whereRaw(Invoice::outstandingSql().' > 0');

        if ((clone $unpaid)->whereDate('due_on', '<', PlatformCalendar::today())->exists()) {
            return 'Overdue';
        }

        // The money list's word (Hassam, 11 Oct): it read "Due".
        return $unpaid->exists() ? 'Unpaid' : 'Current';
    }

    /* ------------------------------------------------------------------ */
    /* small formatters */
    /* ------------------------------------------------------------------ */

    /**
     * The status pill's colour class.
     *
     * WITHOUT BILLING (M10 §5) both commercial states take the live client's class, for the
     * reason `statusLabel()` gives. `overdue` in particular is the billing word, and it would ship
     * in the payload as one.
     */
    private function statusBadge(string $status, bool $maySeeMoney = true): string
    {
        if (! $maySeeMoney && $this->isCommercialState($status)) {
            return 'active';
        }

        return match ($status) {
            'active' => 'active',
            'onboarding' => 'onboarding',
            'dunning' => 'due',
            'suspended' => 'overdue',
            default => 'ok',
        };
    }

    /**
     * The lifecycle, as the pill reads it.
     *
     * "AT RISK" AND "SUSPENDED" ARE BILLING STATUS UNDER OTHER NAMES (M10 §5). STATUS_FILTERS says
     * so above: they are commercial states, not access ones — a tenant is `dunning` because its
     * invoices are past due and `suspended` when that has gone on long enough, and both gate
     * billing features and never entry or a safety function. For a reader who may not see billing
     * status the client therefore reads as what it is in every other respect: live, with its gates
     * guarded. That is not a softer word for the same fact; it is the part of the fact that reader
     * is entitled to.
     */
    private function statusLabel(string $status, bool $maySeeMoney = true): string
    {
        if (! $maySeeMoney && $this->isCommercialState($status)) {
            return 'Active';
        }

        return match ($status) {
            'active' => 'Active',
            'onboarding' => 'Onboarding',
            'dunning' => 'At risk',
            'suspended' => 'Suspended',
            default => ucfirst($status),
        };
    }

    /** The tenant statuses "At risk" filters on — Billing's to know about. */
    private function isCommercialState(string $status): bool
    {
        return in_array($status, self::STATUS_FILTERS['at-risk'], true);
    }

    /** "Client since Mar 2024", from the subscription if there is one. */
    private function clientSince(object $row): string
    {
        $since = $row->started_on ?? $row->provisioned_at ?? null;

        return $since === null ? '' : 'Client since '.$this->month($since);
    }

    /**
     * "Onboarding started Sep 2, 2026", from the day the estate was provisioned.
     *
     * To the day, unlike every other date on these two screens. A client
     * relationship measured in years reads in months; an onboarding measured in
     * days does not, and "Onboarding started Sep 2026" would hide whether this
     * has been sitting for a fortnight.
     */
    private function onboardingSince(object $row): string
    {
        $at = $row->provisioned_at ?? null;

        // Empty rather than a bare "Onboarding started": array_filter drops the
        // segment, and the subtitle reads as a site with no date instead of a
        // sentence that stops halfway.
        return $at === null
            ? ''
            : 'Onboarding started '.Carbon::parse((string) $at)->format('M j, Y');
    }

    /** "Waterloo Road, St. Andrew", or null when the site has no address yet. */
    /**
     * How many homes the estate's site plan holds (its phases' runs of lots, M57.5 §4.1), or null for an estate whose
     * phases are not runs of lots, or whose database cannot be read now. Read from the estate's own phases, never kept
     * beside the contract: the contract is a billing figure, the plan is the ground.
     */
    private function builtFromPlan(string $tenantId): ?int
    {
        $estate = \App\Models\Tenant::query()->find($tenantId);

        if (! $estate instanceof \App\Models\Tenant) {
            return null;
        }

        try {
            $ranges = $estate->run(static fn (): array => \App\Services\Estate\PhasesFromLots::ranges());
        } catch (\Throwable) {
            tenancy()->end();

            return null;
        }

        return $ranges === [] ? null : array_sum(array_map(static fn (array $r): int => $r['last'] - $r['first'] + 1, $ranges));
    }

    private function siteAddress(object $row): ?string
    {
        $parts = array_filter([$row->address_line ?? null, $row->parish ?? null]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * "5 phases", or the guard-post count when no phase structure is recorded.
     *
     * The count is derived from the stored structure and never held beside it.
     * An estate laid out in phases says so; one that is not still says
     * something true about its shape rather than nothing at all.
     */
    private function layout(object $row, int $posts): string
    {
        $phases = json_decode((string) ($row->phases ?? '[]'), true);
        $count = is_array($phases) ? count($phases) : 0;

        if ($count > 0) {
            return $count === 1 ? '1 phase' : "{$count} phases";
        }

        return $posts === 1 ? '1 guard post' : "{$posts} guard posts";
    }

    private function month(mixed $value): string
    {
        return $value === null ? '—' : Carbon::parse((string) $value)->format('M Y');
    }

    private function currency(mixed $currency): string
    {
        return is_string($currency) && $currency !== ''
            ? $currency
            : MoneyFormatter::DEFAULT_CURRENCY;
    }

    /**
     * "$171,000" — every amount on both of these screens.
     *
     * The decimals are dropped only when there are none to drop. A per-unit
     * platform figure never has a fraction, so the board's clean headline is
     * what appears; an amount that does have one is printed in full, because a
     * rounded invoice total is a different number wearing the same symbol.
     *
     * The library decides all of that from the currency. Nothing here divides
     * minor units by a hundred and nothing here becomes a float: the number of
     * decimal places belongs to the currency, and a hand-rolled divide is
     * silently wrong for any currency that does not have two.
     *
     * The symbol is the locale's, via the same formatter and the same locale
     * MoneyFormatter uses, rather than picked by hand from the currency code.
     */
    private function money(int $minor, string $currency): string
    {
        return MoneyFormatter::whole($minor, $currency);
    }

    /** Two characters, uppercase, as the board draws an avatar. */
    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }
}
