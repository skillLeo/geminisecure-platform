<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Platform\OperatorEstates;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;
use App\Support\PlatformPricing;
use App\Support\Seeded\Retirements;
use App\Support\TableQuery;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gemini Security billing its client estates — board screen super-admin-32.
 *
 * Reads ONLY gs_platform. Resident dues are a different ledger in a different
 * database and never appear here; conflating the two is how a platform invoice
 * ends up restricting a resident, which must never happen.
 *
 * The shape is the approved board's: four KPI cards, then one table whose rows
 * are each estate's billing position. The board's own table mixes raised
 * invoices with a row marked "Not yet invoiced", so the default view here is
 * the same thing built from real records — the latest raised invoice per
 * estate, followed by the period each billing subscription will be invoiced
 * next. History is not lost: the top-bar search runs over every invoice.
 */
class BillingOverview
{
    /**
     * Subscription states that still produce an invoice next period.
     *
     * `dunning` is in the list deliberately. Chasing an unpaid invoice does not
     * stop the service, so the next period is still billable — and it never
     * withholds access either. `onboarding` has not started billing,
     * `suspended` has billing gated, and `cancelled` will not bill again.
     *
     * @var list<string>
     */
    private const BILLING_STATES = ['active', 'dunning'];

    /**
     * The table's status column in the order a reader acts on it (M32 F5): what is late, what is due, what is not yet
     * billed, then what is settled one way or another.
     *
     * @var array<string, int>
     */
    private const STATUS_ORDER = ['Overdue' => 0, 'Unpaid' => 1, 'Not yet invoiced' => 2, 'Draft' => 3, 'Credited' => 4, 'Paid' => 5, 'Void' => 6];

    public function __construct(private readonly InvoiceRun $run) {}

    /**
     * WHAT THE INVOICE TABLE ORDERS BY (M32 F5), the two ways it is read. Without a search it is the billing position —
     * each client's latest invoice and the period each subscription is billed next, two rows a client at most — built
     * whole and ordered by `TableQuery::pageOf()`. With one it is every matching invoice, ordered and paged in SQL. The
     * same five columns sort either way, and the same way.
     *
     * @return array<string, string|\Closure>
     */
    public static function invoiceSorts(bool $searching): array
    {
        if (! $searching) {
            return [
                'client' => 'estate',
                'period' => 'period_sort',
                'amount' => 'amount_minor',
                'due' => 'due_sort',
                'status' => static fn (array $row): int => self::STATUS_ORDER[$row['status_label']] ?? count(self::STATUS_ORDER),
            ];
        }

        return [
            'client' => static function ($query, string $dir): void {
                $query->orderBy(Tenant::query()->select('name')->whereColumn('tenants.id', 'invoices.tenant_id'), $dir);
            },
            'period' => 'invoices.period_start',
            'amount' => 'invoices.total_minor',
            'due' => 'invoices.due_on',
            // `statusLabel()` in SQL: paid, void and draft as stored; an issued invoice credited to nothing, late, or due.
            'status' => static function ($query, string $dir): void {
                $query->orderByRaw(
                    "CASE WHEN invoices.status = 'paid' THEN 5 WHEN invoices.status = 'void' THEN 6 WHEN invoices.status = 'draft' THEN 3"
                    .' WHEN '.Invoice::outstandingSql().' <= 0 THEN 4'
                    .' WHEN invoices.due_on < ? THEN 0 ELSE 1 END '.$dir,
                    [PlatformCalendar::today()->toDateString()],
                );
            },
        ];
    }

    /** The invoice table as its address asks for it (M32 F5): newest period first. */
    public static function invoiceTable(?Request $request = null): TableQuery
    {
        $request ??= new Request;
        $q = $request->query('q');

        return TableQuery::from($request, self::invoiceSorts(is_string($q) && trim($q) !== ''), 'period', 'desc');
    }

    /**
     * The table's page (M32 F5): the billing position without a search, every matching invoice with one — a search used
     * to stop at fifty, and the fifty-first was a match nobody could reach.
     *
     * @return array{rows: list<array<string, mixed>>, table: array<string, mixed>}
     */
    public function invoicePage(TableQuery $table): array
    {
        if ($table->search === '') {
            $page = $table->pageOf($this->invoiceRows(), 'key');

            return ['rows' => array_values($page->items()), 'table' => $table->meta($page)];
        }

        $query = $this->matching($table->search);
        $table->order($query, 'invoices.id');
        $page = $table->paginate($query);

        return [
            'rows' => array_values($page->getCollection()->map(fn (Invoice $invoice): array => $this->rowFromInvoice($invoice))->all()),
            'table' => $table->meta($page),
        ];
    }

    /**
     * One invoice, taken apart line by line — board screen super-admin-33.
     *
     * THE LINES ARE THE RECORD, and the total is their sum. An invoice
     * carrying only a total is a number nobody can query: a client asking
     * "why is this J$171,000" needs the two lines that make it up, and this
     * screen exists to answer exactly that.
     *
     * So the total shown is `total_minor` as posted, and the lines are read
     * beside it. Where the lines do not add up to the posted total the screen
     * SAYS SO rather than quietly showing one and hiding the other — a posted
     * invoice cannot be edited to make the arithmetic work, and an invoice
     * whose breakdown disagrees with its total is a real finding.
     *
     * @return array<string, mixed>|null
     */
    public function invoice(int $invoiceId): ?array
    {
        $invoice = DB::connection('mysql')
            ->table('invoices')
            ->join('tenants', 'tenants.id', '=', 'invoices.tenant_id')
            ->where('invoices.id', $invoiceId)
            ->select([
                'invoices.id',
                'invoices.reference',
                'invoices.period',
                'invoices.period_start',
                'invoices.period_end',
                'invoices.total_minor',
                'invoices.currency',
                'invoices.due_on',
                'invoices.status',
                'invoices.paid_on',
                'tenants.id as tenant_id',
                'tenants.name as client',
            ])
            // What it still asks for once its credit notes are off (B080), for its label and caption.
            ->selectRaw(Invoice::outstandingSql().' AS outstanding_minor')
            ->first();

        if ($invoice === null) {
            return null;
        }

        // The model, for its one status word and badge (BIL-B-04), made from the row already read and carrying its
        // credited total, so asking it costs no query (GeminiConsoleQueryBudgetTest holds this page to its budget).
        $model = (new Invoice)->newFromBuilder([...(array) $invoice, 'credited_minor' => (int) $invoice->total_minor - (int) $invoice->outstanding_minor]);

        $currency = $invoice->currency ?? MoneyFormatter::DEFAULT_CURRENCY;

        $lines = DB::connection('mysql')
            ->table('invoice_lines')
            ->where('invoice_id', $invoice->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $line): array => [
                'description' => (string) $line->description,
                /*
                 * "450 units × $340.00/unit". The unit word comes from the
                 * line's own description rather than a column, because a
                 * subscription is priced per unit and the guard add-on per
                 * guard, and inventing a `unit_label` column to hold two
                 * values the description already implies would be a column
                 * for a string.
                 */
                'detail' => number_format((int) $line->quantity).' × '
                    .MoneyFormatter::fromMinor((int) $line->unit_price_minor, $currency),
                'amount' => MoneyFormatter::fromMinor((int) $line->total_minor, $currency),
                'total_minor' => (int) $line->total_minor,
            ])
            ->all();

        $lineSum = array_sum(array_column($lines, 'total_minor'));
        $postedTotal = (int) $invoice->total_minor;

        return [
            'id' => (int) $invoice->id,
            'reference' => (string) $invoice->reference,
            'client' => (string) $invoice->client,
            // Whose invoice it is, for who a resend would mail (DR-074): already read above, so no second query.
            'tenant_id' => (string) $invoice->tenant_id,
            'clientHref' => route('gemini.clients.show', ['tenant' => $invoice->tenant_id], absolute: false),
            'period' => (string) $invoice->period,
            'periodLabel' => $this->periodLabel($invoice),
            'amount' => MoneyFormatter::fromMinor($postedTotal, $currency),

            // The same figure and the raw minor units. The PDF nets a credit
            // note off it, and a template doing arithmetic on a formatted
            // string is how a total loses its cents.
            'total' => MoneyFormatter::fromMinor($postedTotal, $currency),
            'total_minor' => $postedTotal,
            'due_on' => Carbon::parse((string) $invoice->due_on)->format('F j, Y'),

            'status' => (string) $invoice->status,
            /*
             * The model's one word for its standing (BIL-B-04): "Overdue" past its date and unpaid, "Credited" once its
             * credit notes have taken it to nothing (B080), never the stored status capitalised, so the list, this
             * screen and the PDF it prints never disagree about it. The tone is the badge the list draws.
             */
            'statusLabel' => $model->statusLabel(),
            'status_label' => $model->statusLabel(),
            'status_tone' => $model->statusBadge(),
            'settlement' => $this->settlement($invoice),
            'lines' => array_map(
                static fn (array $line): array => [
                    'description' => $line['description'],
                    'detail' => $line['detail'],
                    'amount' => $line['amount'],
                ],
                $lines,
            ),
            /*
             * Null when the breakdown reconciles, which is the ordinary case.
             * A string when it does not, and the screen shows it: the posted
             * total is what the client owes and cannot be edited, so a
             * mismatch is reported rather than resolved.
             */
            'reconciliation' => $lines !== [] && $lineSum !== $postedTotal
                ? sprintf(
                    'These lines total %s, which does not match the %s posted on this invoice. The posted amount stands — a posted invoice cannot be edited, and a correction is a credit note.',
                    MoneyFormatter::fromMinor($lineSum, $currency),
                    MoneyFormatter::fromMinor($postedTotal, $currency),
                )
                : null,
        ];
    }

    /**
     * The subscription tiers, priced and populated — board screen 34.
     *
     * Two vocabularies, deliberately kept apart. `highlights` is what a tier is
     * SOLD as, in the customer's words; `plan_features` is what it CONTAINS, a
     * toggle grid the runtime resolves an estate's feature set from. Deriving
     * one from the other would mean either putting toggle keys on a pricing
     * card or writing marketing copy into the table the platform runs on.
     *
     * The client line under each price is counted here rather than stored, so a
     * client moving tier moves between cards without anyone editing a number.
     *
     * @return array{plans: list<array<string, mixed>>, addon: array<string, mixed>|null}
     */
    public function plans(): array
    {
        $subscriptions = DB::connection('mysql')
            ->table('subscriptions')
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->tap(static fn ($query) => OperatorEstates::exclude($query, 'subscriptions.tenant_id'))
            ->orderBy('tenants.name')
            ->select('subscriptions.plan_id', 'tenants.name')
            ->get()
            ->groupBy('plan_id');

        $plans = Plan::query()->where('is_active', true)->orderBy('sort')->get();
        $topPlanId = $plans->isEmpty() ? null : (int) $plans->last()->id;

        return [
            'plans' => $plans->map(function (Plan $plan) use ($subscriptions, $topPlanId): array {
                $clients = $subscriptions->get($plan->id, collect())->pluck('name')->all();

                return [
                    'key' => (string) $plan->key,
                    'name' => (string) $plan->name,
                    'price' => $this->wholeUnits($plan->price_per_unit_minor, $plan->currency),
                    'clients' => $this->clientLine($clients),
                    'highlights' => $plan->highlights ?? [],

                    /*
                     * The board tints the top card. Taken from the ordering
                     * rather than matched on the name "Premium", so renaming
                     * the flagship or adding one above it moves the emphasis
                     * with it instead of leaving it on a tier that is no
                     * longer the top.
                     */
                    'emphasised' => (int) $plan->id === $topPlanId,
                ];
            })->all(),
            'addon' => $this->guardAddOn(),
        ];
    }

    /**
     * "1 client · Coral Bay Residences", and the two cases either side of it.
     *
     * Named while the list is short and counted once it is not. Four names on
     * a pricing card is a paragraph; the count is the fact by then.
     *
     * @param  list<string>  $clients
     */
    private function clientLine(array $clients): string
    {
        $count = count($clients);

        if ($count === 0) {
            return 'No clients on this tier';
        }

        $noun = $count === 1 ? '1 client' : $count.' clients';

        return $count <= 3
            ? $noun.' · '.implode(', ', $clients)
            : $noun;
    }

    /**
     * The per-guard charge that sits on top of any tier — board 34's footer.
     *
     * Null when no such rate is on file, and the card is then omitted rather
     * than drawn with an em dash: a platform that does not charge per guard
     * has no add-on to describe.
     *
     * @return array<string, mixed>|null
     */
    private function guardAddOn(): ?array
    {
        $rate = DB::connection('mysql')
            ->table('platform_rates')
            ->where('key', 'security_provider_guard')
            ->where('is_active', true)
            ->first();

        if ($rate === null) {
            return null;
        }

        $deployed = DB::connection('mysql')
            ->table('guards')
            ->whereNotNull('tenant_id')
            ->tap(static fn ($query) => OperatorEstates::exclude($query, 'guards.tenant_id'))
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->selectRaw('COUNT(*) as guards, COUNT(DISTINCT tenant_id) as clients')
            ->first();

        $guards = (int) ($deployed->guards ?? 0);
        $clients = (int) ($deployed->clients ?? 0);

        return [
            'name' => (string) $rate->label,
            'detail' => sprintf(
                '%s · %s deployed across %s',
                $rate->applies_to,
                $guards === 1 ? '1 guard' : $guards.' guards',
                $clients === 1 ? '1 client' : $clients.' clients',
            ),
            'price' => $this->wholeUnits((int) $rate->amount_minor, (string) $rate->currency),
        ];
    }

    /**
     * Every client's settlement instrument — board screen 35.
     *
     * Driven from the CLIENT list, left-joined to their method, not from the
     * methods table. A client with no method on file is the state the board
     * spends a row on — "Not yet on file · Needed before go-live" — and
     * listing only the rows that exist would silently drop exactly the client
     * an operator needs to chase.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentMethods(): array
    {
        return DB::connection('mysql')
            ->table('tenants')
            /*
             * NOT the demonstration estate (M5 §3), and this is the one on this
             * screen with a cost rather than a blemish. Driven from the client
             * list, a client with no method on file gets the row "Not yet on
             * file · Needed before go-live" and an "Add" button — which is a
             * standing instruction to somebody in accounts to go and chase bank
             * details for a community that does not exist.
             */
            ->tap(static fn ($query) => OperatorEstates::exclude($query, 'tenants.id'))
            ->leftJoin('payment_methods', function ($join): void {
                $join->on('payment_methods.tenant_id', '=', 'tenants.id')
                    ->where('payment_methods.is_default', '=', true)
                    ->where('payment_methods.status', '=', 'active');
            })
            ->orderBy('tenants.name')
            ->select([
                'tenants.id',
                'tenants.name',
                'tenants.status as tenant_status',
                'payment_methods.kind',
                'payment_methods.institution',
                'payment_methods.last_four',
            ])
            ->get()
            ->map(function (object $row): array {
                $onFile = $row->institution !== null;

                return [
                    'id' => (string) $row->id,
                    'estate' => (string) $row->name,
                    'initials' => $this->initials((string) $row->name),
                    'method' => $onFile ? 'Bank transfer' : 'Not yet on file',
                    // Four dots and the last four, as the board writes it.
                    'detail' => $onFile
                        ? sprintf('%s •••• %s', $row->institution, $row->last_four)
                        : '—',
                    'status' => $onFile ? 'active' : 'pending',
                    // The vocabulary's words (M96, M99): a method on file is Active; one missing before go-live needs
                    // somebody to act. They read "Default" and "Needed before go-live".
                    'status_label' => $onFile ? 'Active' : 'Action required',
                    'action' => $onFile ? 'Edit' : 'Add',
                ];
            })
            ->all();
    }

    /**
     * Board 35 a page at a time (M32 F5), by client name until a heading asks otherwise: the whole client list is read,
     * ordered and cut, so a client with nothing on file is on some page whatever the order.
     *
     * @return array{rows: list<array<string, mixed>>, table: array<string, mixed>}
     */
    public function paymentMethodsPage(TableQuery $table): array
    {
        $page = $table->pageOf($this->paymentMethods());

        return ['rows' => array_values($page->items()), 'table' => $table->meta($page)];
    }

    /** Board 35 as the address asks for it (M32 F5): a register, so by client name. */
    public static function paymentMethodsTable(?Request $request = null): TableQuery
    {
        // Needed before go-live first when the status heading is asked for: it is the row somebody has to chase.
        $status = static fn (array $row): int => $row['status'] === 'pending' ? 0 : 1;

        return TableQuery::from($request ?? new Request, ['client' => 'estate', 'method' => 'method', 'status' => $status], 'client');
    }

    /** "$180" and "$4,500" — a rate card figure, decimals dropped when there are none. */
    private function wholeUnits(int $minor, ?string $currency): string
    {
        return MoneyFormatter::whole($minor, $currency ?? MoneyFormatter::DEFAULT_CURRENCY);
    }

    /** "Billing period: Aug 1–31, 2026", as the board writes it. */
    private function periodLabel(object $invoice): string
    {
        $start = Carbon::parse((string) $invoice->period_start);
        $end = Carbon::parse((string) $invoice->period_end);

        return sprintf(
            'Invoice #%s · Billing period: %s–%s, %s',
            $invoice->reference,
            $start->format('M j'),
            $end->format('j'),
            $end->format('Y'),
        );
    }

    /**
     * How and when it was settled, or when it falls due.
     *
     * The board's caption under the amount. A paid invoice says when and by
     * what means; an unpaid one says when it is due, because that is the fact
     * a reader of an outstanding invoice is looking for.
     */
    private function settlement(object $invoice): string
    {
        /*
         * NOTHING IS DUE ON AN INVOICE ITS CREDIT NOTES HAVE TAKEN TO NOTHING
         * (B080), so its caption does not give a date to pay it by. A partly
         * credited one is still due on its date; the credit-note panel beside it
         * says how much.
         */
        if ($this->isCreditedInFull($invoice)) {
            return 'CREDITED IN FULL · NOTHING DUE';
        }

        if ($invoice->status === 'paid' && $invoice->paid_on !== null) {
            /*
             * Bank transfer, stated rather than stored.
             *
             * D-023: manual recording is the day-one path and every settlement
             * on this platform is a transfer someone reconciled by hand. When
             * a gateway exists it will record its own method and this reads it
             * instead; asserting a method column now would be a column holding
             * one value.
             */
            return strtoupper(sprintf(
                'Paid %s · bank transfer',
                Carbon::parse((string) $invoice->paid_on)->format('M j, Y'),
            ));
        }

        return strtoupper(sprintf(
            'Due %s',
            Carbon::parse((string) $invoice->due_on)->format('M j, Y'),
        ));
    }

    /** The detail's status word: the stored status, except "Credited" for one credited to nothing. */
    /** `Invoice::isCreditedInFull()`, for the detail's row read with `outstanding_minor` beside it. */
    private function isCreditedInFull(object $invoice): bool
    {
        return $invoice->status === 'issued' && (int) $invoice->outstanding_minor <= 0;
    }

    /**
     * The four KPI cards, in the order the board draws them.
     *
     * No estate scoping anywhere in this class, and that is not an oversight:
     * the only site-scoped Gemini role is Head of Security, whose access to
     * `billing_subscriptions` is '-' in the role matrix. Nobody who can reach
     * this screen sees a subset, so a scope filter here would be a branch that
     * can never run and a "Platform MRR" that could quietly mean something
     * narrower than its label.
     *
     * @return list<array{key: string, icon: string, value: string, label: string, alert: bool}>
     */
    public function kpis(): array
    {
        $overdue = $this->overdue();

        return [
            [
                'key' => 'mrr',
                'icon' => 'billing',
                'value' => $this->whole($this->monthlyRecurring()),
                'label' => 'Platform MRR',
                'alert' => false,
            ],
            [
                'key' => 'overdue',
                'icon' => 'alert',
                'value' => $this->whole($overdue),
                'label' => 'Overdue',
                'alert' => $overdue->isPositive(),
            ],
            [
                'key' => 'next-run',
                'icon' => 'calendar',
                'value' => $this->nextInvoiceRun(),
                'label' => 'Next invoice run',
                'alert' => false,
            ],
            [
                'key' => 'billable',
                'icon' => 'clients',
                'value' => (string) $this->billableClients(),
                'label' => 'Billable clients',
                'alert' => false,
            ],
        ];
    }

    /**
     * The current billing position, whole: each estate's most recent raised
     * invoice, then the period each billing subscription is due to be invoiced
     * next. `invoicePage()` orders and pages it.
     *
     * @return list<array<string, mixed>>
     */
    public function invoiceRows(): array
    {
        $latest = $this->latestInvoicePerEstate();

        $raised = array_map(
            fn (Invoice $invoice): array => $this->rowFromInvoice($invoice),
            array_values($latest)
        );

        return array_merge($raised, $this->projectedRows());
    }

    /* --- rows ------------------------------------------------------------ */

    /**
     * Each estate's most recent raised invoice, keyed by estate id.
     *
     * A correlated subquery rather than loading every invoice and picking the
     * newest per estate in PHP: this screen is a platform-wide view, and the
     * invoice table grows by one row per estate per month forever.
     *
     * @return array<string, Invoice>
     */
    private function latestInvoicePerEstate(): array
    {
        $invoices = Invoice::query()
            ->with('estate')
            // Each row's credit notes, for its status (B080) without a query per row.
            ->withCredited()
            ->tap(static fn ($query) => OperatorEstates::exclude($query))
            ->whereRaw(
                'invoices.period_start = ('
                .'select max(latest.period_start) from invoices as latest '
                // A retired sample invoice is not the latest of anything (M33 §5).
                .'where latest.tenant_id = invoices.tenant_id'.Retirements::and('mysql', ['latest' => 'invoices']).')'
            )
            ->orderBy('tenant_id')
            ->get();

        $latest = [];

        foreach ($invoices as $invoice) {
            $latest[$invoice->tenant_id] = $invoice;
        }

        return $latest;
    }

    /**
     * The period each billing subscription will be invoiced for next.
     *
     * These rows are not invoices and are never presented as one: they carry
     * the board's "Not yet invoiced" badge, and their action is a preview
     * rather than a link to a document that does not exist.
     *
     * AT WHAT THE INVOICE RUN WOULD RAISE IT AT (B077). Period, amount and due
     * date are `InvoiceRun::preview()`'s draft — the tier and the per-guard
     * add-on priced on the period's first day, and the line items in force
     * during it — which is what the preview this row opens shows and what
     * raising it posts. The row used to quote the tier alone: J$153,000 for a
     * Phoenix Park period the raise posts at J$171,000 or more. A period the
     * run may not raise yet is still quoted, with the same arithmetic; the
     * preview says why it cannot be raised.
     *
     * EVERY CLIENT'S DRAFT FROM ONE SET OF READS (M11 A2). This asked the run for
     * each client in turn — eleven queries a client, a hundred and forty on
     * twelve — and `InvoiceRun::previews()` gives every one of them the draft
     * `preview()` gives it, in the same dozen queries however many there are.
     *
     * @return list<array<string, mixed>>
     */
    private function projectedRows(): array
    {
        $subscriptions = Subscription::query()
            ->whereIn('status', self::BILLING_STATES)
            ->tap(static fn ($query) => OperatorEstates::exclude($query))
            ->orderBy('tenant_id')
            ->get();

        $previews = $this->run->previews(
            $subscriptions->map(static fn (Subscription $subscription): string => (string) $subscription->tenant_id)->all(),
        )['previews'];

        $rows = [];

        foreach ($subscriptions as $subscription) {
            // A client the run has no period for (`unbillable`) has nothing to quote.
            $draft = ($previews[(string) $subscription->tenant_id] ?? null)['draft'] ?? null;

            if ($draft === null) {
                continue;
            }

            $estate = $draft['estate'];

            $rows[] = [
                'key' => 'projected-'.$subscription->tenant_id,
                'estate' => $estate,
                'initials' => $this->initials($estate),
                'period' => $draft['period_start']->format('F Y'),
                'amount' => $this->exact($draft['total_minor'], $draft['currency']),
                'due_on' => $draft['due_on']->format('M j, Y'),
                // What the table orders by (M32 F5).
                'period_sort' => $draft['period_start']->toDateString(),
                'amount_minor' => (int) $draft['total_minor'],
                'due_sort' => $draft['due_on']->toDateString(),
                'status' => 'due',
                'status_label' => 'Not yet invoiced',
                'action' => 'Preview',
                /*
                 * A PREVIEW, NOT A DOCUMENT (12 §2, item 44). There is no posted
                 * record behind this row, so the preview screen says in words
                 * that it is a projection of what raising it would post,
                 * carries no invoice number, and owes nobody anything.
                 */
                'href' => route('gemini.billing_subscriptions.preview', ['tenant' => $subscription->tenant_id], absolute: false),
                'action_reason' => null,
            ];
        }

        return $rows;
    }

    /**
     * Every invoice matching a search term, for the table to order and page.
     *
     * @return Builder<Invoice>
     */
    private function matching(string $term): Builder
    {
        // Escape the wildcards before wrapping in them, so a term containing %
        // searches for a percent sign instead of matching every invoice.
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return Invoice::query()
            ->with('estate')
            ->withCredited()
            ->tap(static fn ($query) => OperatorEstates::exclude($query))
            ->where(function ($query) use ($like): void {
                $query->where('reference', 'like', $like)
                    ->orWhere('period', 'like', $like)
                    ->orWhereHas('estate', function ($estate) use ($like): void {
                        $estate->where('name', 'like', $like);
                    });
            });
    }

    /** @return array<string, mixed> */
    private function rowFromInvoice(Invoice $invoice): array
    {
        $estate = $invoice->estate->name ?? $invoice->tenant_id;

        return [
            'key' => 'invoice-'.$invoice->id,
            'estate' => $estate,
            'initials' => $this->initials($estate),
            // The period column, formatted from period_start rather than
            // echoed from the free-text `period` string, so every row reads
            // the same way whatever a seeder or an importer wrote.
            'period' => $invoice->period_start->format('F Y'),
            'amount' => $this->exact($invoice->total_minor, $invoice->currency),
            'due_on' => $invoice->due_on->format('M j, Y'),
            // What the table orders by (M32 F5).
            'period_sort' => $invoice->period_start->toDateString(),
            'amount_minor' => (int) $invoice->total_minor,
            'due_sort' => $invoice->due_on->toDateString(),
            'status' => $invoice->statusBadge(),
            'status_label' => $this->statusLabel($invoice),
            'action' => 'View invoice',
            // A raised invoice has a detail screen. The row below it — a
            // period not yet invoiced — has nothing to open, which is why
            // `href` is per-row rather than a property of the table.
            'href' => route('gemini.billing_subscriptions.invoice', ['invoice' => $invoice->id], absolute: false),
            'action_reason' => null,
        ];
    }

    /**
     * "CREDITED" FOR AN INVOICE ITS CREDIT NOTES HAVE TAKEN TO NOTHING (B080).
     * Neither "Due" nor "Overdue" is true of it — nothing is owed — and it is
     * still `issued`, because a credit note never touches the invoice it corrects.
     */
    private function statusLabel(Invoice $invoice): string
    {
        // The model's own word (BIL-B-04), so this list, the detail, the estate's screens and the PDF say one thing.
        return $invoice->statusLabel();
    }

    /* --- figures ---------------------------------------------------------- */

    /**
     * Platform MRR.
     *
     * Active subscriptions only — the same rule PlatformOverview uses for the
     * dashboard card of the same name. Two screens printing the same label must
     * print the same number, so if that definition ever changes it changes in
     * both places or the platform contradicts itself.
     *
     * AND THE SAME FORMULA, `PlatformPricing::mrrSql()` (B077): the software on
     * the units plus the guards on each contract at the platform rate. This sum
     * used to be units × tier price alone, so /billing printed "Platform MRR
     * $153,000" while the dashboard printed "$171,000" for the same Phoenix Park
     * at the same moment — its 4 guards at J$4,500 being the difference.
     *
     * OPEN QUESTION. That rule excludes `dunning`, so an estate whose invoices
     * are being chased is billed in the table below this card but is absent
     * from the figure on it. Most definitions of MRR would count it: dunning is
     * a collection state, not a cancellation. Deliberately not changed here,
     * because the fix belongs wherever the definition is ruled on, and one of
     * the two screens quietly disagreeing with the other is worse than both
     * being conservative.
     */
    private function monthlyRecurring(): Money
    {
        $minor = (int) Subscription::query()
            ->where('subscriptions.status', 'active')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->tap(static fn ($query) => OperatorEstates::exclude($query, 'subscriptions.tenant_id'))
            ->sum(DB::raw(PlatformPricing::mrrSql()));

        return Money::ofMinor($minor, $this->platformCurrency());
    }

    /**
     * Money past its due date and still unpaid.
     *
     * Overdue is derived from the due date, never read from a stored status, so
     * an invoice that passes midnight is overdue the moment it is asked about
     * rather than whenever a job next runs. Invoice::isOverdue() says the same
     * thing one record at a time.
     *
     * WHAT IS STILL OWED, NOT WHAT WAS CHARGED (B080). A credit note leaves the
     * invoice's total as it was, so this sums each invoice's total less its
     * credit notes, and leaves out an invoice they have taken to nothing. It
     * summed the totals, so a J$171,000 invoice credited in full read "Overdue
     * $171,000" for ever. The day is Gemini's (B082).
     */
    private function overdue(): Money
    {
        $total = Money::zero($this->platformCurrency());

        $unpaid = Invoice::query()
            ->where('status', 'issued')
            ->tap(static fn ($query) => OperatorEstates::exclude($query))
            ->whereDate('due_on', '<', PlatformCalendar::today())
            ->whereRaw(Invoice::outstandingSql().' > 0')
            ->select(['invoices.id', 'invoices.currency'])
            ->selectRaw(Invoice::outstandingSql().' AS outstanding_minor')
            ->get();

        foreach ($unpaid as $invoice) {
            /*
             * brick refuses to add two currencies, and that refusal is the
             * point: a single total across currencies is not a number. Summing
             * minor units in SQL would invent one silently.
             */
            $total = $total->plus(Money::ofMinor((int) $invoice->getAttribute('outstanding_minor'), $invoice->currency));
        }

        return $total;
    }

    /** "Oct 1" — the earliest renewal date across the billing subscriptions. */
    private function nextInvoiceRun(): string
    {
        $next = Subscription::query()
            ->whereIn('status', self::BILLING_STATES)
            ->tap(static fn ($query) => OperatorEstates::exclude($query))
            ->whereNotNull('renews_on')
            ->orderBy('renews_on')
            ->first();

        return $next?->renews_on?->format('M j') ?? '—';
    }

    /** Estates that will be invoiced. One live subscription per estate. */
    private function billableClients(): int
    {
        return Subscription::query()
            ->whereIn('status', self::BILLING_STATES)
            ->tap(static fn ($query) => OperatorEstates::exclude($query))
            ->distinct()
            ->count('tenant_id');
    }

    /* --- formatting ------------------------------------------------------- */

    /**
     * "$243,900" — the board's format for a headline figure.
     *
     * brick's own whole-number flag, not a hand-rolled trim: the decimals are
     * dropped only when the amount really has no minor units, and the scaling
     * stays with the currency. Nothing here divides by 100.
     */
    private function whole(Money $money): string
    {
        if (! extension_loaded('intl')) {
            return MoneyFormatter::format($money);
        }

        return $money->formatToLocale(MoneyFormatter::DEFAULT_LOCALE, true);
    }

    /** An invoice amount, to the cent. A bill is never rounded for display. */
    private function exact(int $minor, string $currency): string
    {
        return MoneyFormatter::fromMinor($minor, $currency);
    }

    private function platformCurrency(): string
    {
        $currency = Plan::query()->value('currency');

        return is_string($currency) ? $currency : MoneyFormatter::DEFAULT_CURRENCY;
    }

    /* --- helpers ---------------------------------------------------------- */

    /** "Phoenix Park" -> "PP". The board's two-letter estate avatar. */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }
}
