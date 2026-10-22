<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\GeminiBills;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;
use App\Support\PlatformPricing;
use App\Support\Seeded\Retirements;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Raising a client's invoice — the invoice run (13 B1).
 *
 * Until this existed, nothing in `app/` wrote `invoices`: the seeder did, and a
 * pilot client could not be billed. This raises the period the billing screen
 * already projects — the month after the client's latest invoice, or the
 * subscription's first — from the same reading of the bill the preview shows,
 * and posts it: Dr 1100 Accounts Receivable (this client), Cr 4000 Revenue.
 *
 * THE LINES ARE THE RECORD, the subtotal is their sum, and the total is the
 * subtotal plus tax — of which there is none ruled (Q-019). In order:
 *
 *   the tier            units × the plan's per-unit price in force on the
 *                       period's first day
 *   module add-ons      each active per-guard rate in force on the period's first
 *                       day × the guards deployed to the client today, and each
 *                       addition in force during the period
 *   removals, discounts negative lines, never a netted-down tier price
 *   one-off charges     only in the period they take effect
 *
 * ONE PERIOD, ONCE. A second invoice for a period the client already has is
 * refused rather than numbered differently; a correction is a credit note.
 * And a period is not billed before it is at most a month away — billing in
 * advance is a client's contract, billing two months ahead is a mistake.
 *
 * @phpstan-type DraftLine array{description: string, quantity: int, unit_price_minor: int, total_minor: int, kind: string, source_id: int}
 * @phpstan-type Draft array{tenant_id: string, estate: string, period: string, period_start: Carbon, period_end: Carbon, due_on: Carbon, reference: string, currency: string, lines: list<DraftLine>, subtotal_minor: int, tax_minor: int, total_minor: int}
 */
class InvoiceRun
{
    /** Subscriptions that are billed — the billing screen's own definition. */
    private const BILLING_STATES = ['active', 'dunning'];

    /** Days from a period's first day to its due date, for a client with no invoice history. */
    private const FIRST_INVOICE_DUE_DAYS = 14;

    /*
     * What a draft line is, and `source_id` is the row it came from: the plan,
     * the platform rate, or the client's line item. Not stored on the invoice —
     * the line's words and amounts are the record — but the preview draws its
     * sections from it rather than guessing from a line's position.
     */
    public const KIND_TIER = 'tier';

    public const KIND_MODULE = 'module';

    public const KIND_OVERRIDE = 'override';

    public function __construct(
        private readonly PlatformLedger $ledger,
        private readonly AuditLogger $audit,
        private readonly GeminiBills $estateBills,
    ) {}

    /**
     * The invoice as it would be raised, without raising it. The run's own
     * arithmetic, so what the raise posts is what this returned.
     *
     * Refuses exactly when `raise()` would: see `preview()` for the reasons.
     *
     * @return Draft
     *
     * @throws DomainException
     */
    public function draft(string $tenantId, ?Carbon $today = null): array
    {
        $preview = $this->preview($tenantId, $today);

        if ($preview['refusal'] !== null) {
            throw new DomainException($preview['refusal']);
        }

        return $preview['draft'];
    }

    /**
     * The client's next invoice as the run would raise it, and — separately —
     * whether it may be raised today.
     *
     * THE ONE READING OF A CLIENT'S NEXT INVOICE (B076). The billing screen's
     * "Not yet invoiced" row, the preview behind it and its "Raise … at J$X?"
     * all quote this draft, and the raise posts it. They used to be priced in
     * two places — the preview read the client's line items in force TODAY
     * while the run bills the ones in force during the PERIOD — so on 25
     * September an addition dated 1 October was quoted as nothing and posted,
     * and a discount ended on 30 September was quoted and not posted. An issued
     * invoice is corrected only by a credit note; the figure a person confirms
     * has to be the figure that is posted.
     *
     * A period the run may not raise yet still has a draft: the row still has
     * something to quote and the preview something to show. `refusal` is the
     * sentence the raise would answer with, and null when it would go through.
     *
     * Throws only where there is no period to invoice at all: the demonstration
     * estate, or a client with no billing subscription.
     *
     * @return array{draft: Draft, refusal: string|null}
     *
     * @throws DomainException
     */
    public function preview(string $tenantId, ?Carbon $today = null): array
    {
        $answer = $this->previews([$tenantId], $today);

        if (isset($answer['unbillable'][$tenantId])) {
            throw new DomainException($answer['unbillable'][$tenantId]);
        }

        return $answer['previews'][$tenantId];
    }

    /**
     * `preview()` for many clients at once, in the same dozen reads however many there are (M11 A2).
     *
     * THE BILLING SCREEN QUOTES EVERY CLIENT'S NEXT INVOICE (B077), and asked this one client at
     * a time: eleven queries a client, a hundred and forty on a platform of twelve, and eleven more
     * for every client signed after. Here every fact a draft is built from — the subscription and
     * its tier and estate, the latest invoice, whether the next period is already invoiced, the
     * tier's and the rates' dated prices, the guards deployed, the line items in force — is read
     * for all of them together, and each draft is then built by `compose()`, the one arithmetic.
     *
     * AND IT IS THE ONLY PATH. `preview()` is this for one client, and `raise()` posts what that
     * returns, so the screen, the preview behind its row and the raise cannot read a client's next
     * invoice in two different ways.
     *
     * A client there is nothing to invoice for — the demonstration estate, or one with no billing
     * subscription — is under `unbillable`, with the sentence `preview()` refuses it in.
     *
     * @param  list<string>  $tenantIds
     * @return array{previews: array<string, array{draft: Draft, refusal: string|null}>, unbillable: array<string, string>}
     */
    public function previews(array $tenantIds, ?Carbon $today = null): array
    {
        // Gemini's today, not UTC's (B082): "at most a month ahead" is counted
        // from the month it is in Kingston, whatever the server's date says.
        $today = ($today?->copy() ?? PlatformCalendar::today())->startOfDay();

        $ids = array_values(array_unique(array_map('strval', $tenantIds)));
        $previews = [];
        $unbillable = [];

        if ($ids === []) {
            return ['previews' => $previews, 'unbillable' => $unbillable];
        }

        /*
         * THE DEMONSTRATION ESTATE IS NEVER BILLED (M5 §3).
         *
         * It is out of every billing picker on the console, so nothing offers
         * this — but raising an invoice is the act that creates a real money row
         * and posts to Gemini's own ledger, and a refusal at the act is worth
         * more than a filter on the menu. Without it the answer would be "this
         * client has no billing subscription", which reads as an instruction to
         * go and give it one.
         *
         * `OperatorEstates::isDemonstration()`'s own question — this estate, with
         * the flag set — asked of every estate here at once, and at this moment.
         */
        /** @var array<string, true> $demonstrations */
        $demonstrations = DB::connection('mysql')->table('tenants')
            ->whereIn('id', $ids)
            ->where('demo', true)
            ->pluck('id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();

        $candidates = array_values(array_filter($ids, static fn (string $id): bool => ! isset($demonstrations[$id])));

        // One live subscription per estate: `subscriptions.tenant_id` is unique.
        $subscriptions = $candidates === [] ? collect() : Subscription::query()
            ->with(['plan', 'estate'])
            ->whereIn('tenant_id', $candidates)
            ->whereIn('status', self::BILLING_STATES)
            ->get()
            ->keyBy(static fn (Subscription $subscription): string => (string) $subscription->tenant_id);

        $billed = [];

        foreach ($ids as $id) {
            if (isset($demonstrations[$id])) {
                $unbillable[$id] = 'That is the demonstration estate the app stores\' reviewers sign in to. It is not a client, '.
                    'it is never invoiced, and nothing it does belongs in the platform\'s books.';
            } elseif (! $subscriptions->has($id)) {
                $unbillable[$id] = 'This client has no billing subscription, so there is no period to invoice.';
            } else {
                $billed[] = $id;
            }
        }

        if ($billed === []) {
            return ['previews' => $previews, 'unbillable' => $unbillable];
        }

        /*
         * Each client's latest invoice, by period — whatever its status — as the next period is
         * counted from. The latest period's own row; `id` settles two rows for one period, which
         * one invoice per period per client (the reference is its month) leaves no room for.
         */
        $latest = Invoice::query()
            ->whereIn('tenant_id', $billed)
            // A retired sample invoice is not the latest of anything (M33 §5).
            ->whereRaw('invoices.period_start = (select max(`latest`.`period_start`) from `invoices` as `latest` where `latest`.`tenant_id` = `invoices`.`tenant_id`'.Retirements::and('mysql', ['latest' => 'invoices']).')')
            ->orderByDesc('id')
            ->get()
            ->unique(static fn (Invoice $invoice): string => (string) $invoice->tenant_id)
            ->keyBy(static fn (Invoice $invoice): string => (string) $invoice->tenant_id);

        // The period each client is invoiced for next, and whether it is more than a month away.
        $periods = [];
        $taken = [];

        foreach ($billed as $id) {
            $subscription = $subscriptions->get($id);
            $last = $latest->get($id);

            $start = $last !== null
                ? $last->period_end->copy()->addDay()->startOfDay()
                : ($subscription->started_on?->copy()->startOfMonth() ?? $today->copy()->startOfMonth());

            $periods[$id] = [
                'start' => $start,
                'end' => $start->copy()->endOfMonth()->startOfDay(),
                'too_far' => $start->gt($today->copy()->startOfMonth()->addMonthNoOverflow()),
            ];
        }

        // Whether a period not too far ahead is already invoiced: every client's own question, in one read.
        $asked = array_filter($periods, static fn (array $period): bool => ! $period['too_far']);

        if ($asked !== []) {
            $taken = Invoice::query()
                ->where(static function ($any) use ($asked): void {
                    foreach ($asked as $id => $period) {
                        $any->orWhere(static fn ($one) => $one->where('tenant_id', $id)->whereDate('period_start', $period['start']));
                    }
                })
                ->distinct()
                ->pluck('tenant_id')
                ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
                ->all();
        }

        // By estate id, one at a time: an Eloquent collection's `only()` would take these for model keys.
        $planHistories = PlatformPricing::planPriceHistories(array_map(
            static fn (string $id): int => (int) $subscriptions->get($id)->plan->id,
            $billed,
        ));

        /*
         * Module add-ons billed per guard — the same definition of "deployed" the
         * preview and the client directory use, counted when the invoice is
         * raised. A client with no guards gets no line reading zero.
         */
        /** @var array<string, int> $guards */
        $guards = DB::connection('mysql')
            ->table('guards')
            ->whereIn('tenant_id', $billed)
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, COUNT(*) as deployed')
            ->pluck('deployed', 'tenant_id')
            ->mapWithKeys(static fn (mixed $n, mixed $id): array => [(string) $id => (int) $n])
            ->all();

        $rates = DB::connection('mysql')
            ->table('platform_rates')
            ->where('is_active', true)
            ->where('basis', 'guard')
            ->orderBy('sort')
            ->get();

        $rateHistories = PlatformPricing::rateHistories($rates->map(static fn (object $rate): int => (int) $rate->id)->all());

        // Every client's line items in force during its own period, in one read.
        $overrides = DB::connection('mysql')
            ->table('subscription_line_items')
            ->where(static function ($any) use ($periods): void {
                foreach ($periods as $id => $period) {
                    $any->orWhere(static fn ($one) => $one
                        ->where('tenant_id', $id)
                        ->whereDate('effective_from', '<=', $period['end'])
                        ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period['start'])));
                }
            })
            // Withdrawn before it started — ended on a day before its first
            // (M10 §3). It was never in force, so it is on no invoice.
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereColumn('effective_to', '>=', 'effective_from'))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->groupBy('tenant_id');

        foreach ($billed as $id) {
            $subscription = $subscriptions->get($id);
            $period = $periods[$id];

            $refusal = null;

            if ($period['too_far']) {
                $refusal = sprintf(
                    '%s is the next period to invoice, and it is more than a month away. A period is billed at most a month ahead.',
                    $period['start']->format('F Y'),
                );
            } elseif (isset($taken[$id])) {
                $refusal = 'This client already has an invoice for '.$period['start']->format('F Y').'. A correction is a credit note, not a second invoice.';
            }

            $previews[$id] = [
                'draft' => $this->compose(
                    tenantId: $id,
                    subscription: $subscription,
                    latest: $latest->get($id),
                    start: $period['start'],
                    end: $period['end'],
                    planHistory: $planHistories[(int) $subscription->plan->id] ?? collect(),
                    guards: $guards[$id] ?? 0,
                    rates: $rates,
                    rateHistories: $rateHistories,
                    overrides: $overrides->get($id) ?? collect(),
                ),
                'refusal' => $refusal,
            ];
        }

        return ['previews' => $previews, 'unbillable' => $unbillable];
    }

    /**
     * One client's draft, from the facts `previews()` read — the run's arithmetic, in one place.
     *
     * @param  Collection<int, stdClass>  $planHistory  the tier's dated prices (`PlatformPricing::planPriceHistories()`)
     * @param  Collection<int, stdClass>  $rates  the active per-guard platform rates, in `sort` order
     * @param  array<int, Collection<int, stdClass>>  $rateHistories  their dated amounts, by rate id
     * @param  Collection<int, stdClass>  $overrides  this client's line items in force during the period, oldest first
     * @return Draft
     */
    private function compose(
        string $tenantId,
        Subscription $subscription,
        ?Invoice $latest,
        Carbon $start,
        Carbon $end,
        Collection $planHistory,
        int $guards,
        Collection $rates,
        array $rateHistories,
        Collection $overrides,
    ): array {
        /*
         * THE PRICE OF A PERIOD IS THE PRICE IN FORCE ON ITS FIRST DAY (M10 §3).
         *
         * Not the plan's column, which is only TODAY's price. "The change applies
         * from the next billing period" is the client's rule, and reading the
         * column made it mean "from whatever day an invoice happens to be raised":
         * October billed in advance on 25 September would have gone out at
         * September's price, and a catch-up invoice for a month already past would
         * have been raised at a price that month never had. Both the tier and the
         * per-guard add-on are resolved from their dated history for `$start`.
         *
         * An issued invoice is never re-read from here. Its lines are stored
         * below, with the amounts this resolved, and nothing recomputes them.
         */
        $currency = (string) $subscription->plan->currency;
        $lines = [$this->line(
            $subscription->plan->name.' subscription',
            (int) $subscription->unit_count,
            PlatformPricing::inForceFrom($planHistory, (int) $subscription->plan->price_per_unit_minor, $start),
            self::KIND_TIER,
            (int) $subscription->plan->id,
        )];

        // Each per-guard rate in force on the period's first day, times the guards deployed.
        foreach ($rates as $rate) {
            $perGuard = PlatformPricing::inForceFrom($rateHistories[(int) $rate->id] ?? collect(), (int) $rate->amount_minor, $start);

            if ($guards > 0 && $perGuard > 0) {
                $lines[] = $this->line((string) $rate->label, $guards, $perGuard, self::KIND_MODULE, (int) $rate->id);
            }
        }

        foreach ($overrides as $override) {
            $amount = (int) $override->amount_minor;

            // A one-off belongs to the period it takes effect in, and no other.
            if ($override->recurrence === 'one_off' && Carbon::parse((string) $override->effective_from)->lt($start)) {
                continue;
            }

            $signed = $override->type === 'removal' ? -abs($amount) : $amount;

            if ($signed !== 0) {
                $lines[] = $this->line((string) $override->description, 1, $signed, self::KIND_OVERRIDE, (int) $override->id);
            }
        }

        $subtotal = array_sum(array_column($lines, 'total_minor'));

        // ASSUMPTION Q-019 — no GCT is charged on Gemini's subscription invoices.
        // Nobody has ruled whether General Consumption Tax applies to the
        // platform's services, and adding 15% to a client's bill is not a
        // default to guess at. The column is here so the ruling is one line.
        $tax = 0;

        $estate = $subscription->estate;

        return [
            'tenant_id' => $tenantId,
            'estate' => $estate->name ?? $tenantId,
            'period' => $start->format('M Y'),
            'period_start' => $start,
            'period_end' => $end,
            'due_on' => $latest !== null
                ? $this->sameDayOfMonth($latest->due_on, $start)
                : $start->copy()->addDays(self::FIRST_INVOICE_DUE_DAYS),
            'reference' => $this->reference($estate, $tenantId, $start),
            'currency' => $currency,
            'lines' => $lines,
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $subtotal + $tax,
        ];
    }

    /**
     * Raise it: the invoice, its lines and its journal entry, together or not at all.
     *
     * ONLY WHAT WAS CONFIRMED (B076). The preview's "Raise … for October at
     * J$X?" is a promise about what is posted, and a preview is read some time
     * before it is confirmed. Given the period and the total a confirmation
     * quoted, the raise posts exactly that or nothing: a price or a line item
     * changed since the preview was opened, or the period was raised by somebody
     * else in the meantime — after which "the next period" is the month after —
     * and the raise is refused in words instead of posting a figure, or a month,
     * nobody confirmed. The console's raise always passes both.
     *
     * ONE RAISE PER CLIENT AT A TIME. The client's subscription row is taken FOR
     * UPDATE (`subscriptions` is a mutable table) before anything is read, and
     * the draft is read under it. Two raises for one client used to both read
     * "October is next", and the second died on the invoice reference's unique
     * key with a 500; now the second waits for the first to commit and then
     * reads the invoice it raised.
     *
     * @throws DomainException
     */
    public function raise(string $tenantId, User $by, ?Carbon $today = null, ?string $confirmedPeriodStart = null, ?int $confirmedTotalMinor = null): Invoice
    {
        /*
         * POSTED ON GEMINI'S DATE (B082). The journal's date and the month in its
         * reference are the day it is in Gemini's office. Posted on the UTC date,
         * an invoice raised after 7 p.m. in Kingston on the last of a month was
         * journalled in the next month, under the next month's reference.
         */
        $today = ($today?->copy() ?? PlatformCalendar::today())->startOfDay();

        [$invoice, $draft] = DB::connection('mysql')->transaction(function () use ($tenantId, $by, $today, $confirmedPeriodStart, $confirmedTotalMinor): array {
            $subscriptionId = Subscription::query()->where('tenant_id', $tenantId)->lockForUpdate()->value('id');

            ['draft' => $draft, 'refusal' => $refusal] = $this->preview($tenantId, $today);

            // What was confirmed is checked first: a stale preview is the answer
            // even where the period it now reaches would also be refused.
            $this->assertConfirmed($draft, $confirmedPeriodStart, $confirmedTotalMinor);

            if ($refusal !== null) {
                throw new DomainException($refusal);
            }

            if ($draft['total_minor'] <= 0) {
                throw new DomainException('This invoice would total '.MoneyFormatter::fromMinor($draft['total_minor'], $draft['currency']).'. An invoice for nothing, or for less, is not raised — check the client\'s line items.');
            }

            $invoice = Invoice::create([
                'tenant_id' => $draft['tenant_id'],
                'subscription_id' => $subscriptionId,
                'reference' => $draft['reference'],
                'period' => $draft['period'],
                'period_start' => $draft['period_start']->toDateString(),
                'period_end' => $draft['period_end']->toDateString(),
                'subtotal_minor' => $draft['subtotal_minor'],
                'total_minor' => $draft['total_minor'],
                'currency' => $draft['currency'],
                'due_on' => $draft['due_on']->toDateString(),
                'status' => 'issued',
            ]);

            // The record is the line itself. `kind` and `source_id` only say where
            // a draft line came from, for the preview that draws it.
            foreach ($draft['lines'] as $line) {
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'total_minor' => $line['total_minor'],
                    'currency' => $draft['currency'],
                ]);
            }

            $journal = $this->ledger->invoiceRaised(
                tenantId: $draft['tenant_id'],
                invoiceId: $invoice->id,
                invoiceReference: $draft['reference'],
                amountMinor: $draft['total_minor'],
                currency: $draft['currency'],
                on: $today,
                by: $by,
            );

            $invoice->forceFill(['journal_ref' => $journal])->save();

            return [$invoice, $draft];
        });

        $this->audit->record(
            action: 'billing.invoice_raised',
            entityType: 'Invoice',
            entityId: (string) $invoice->id,
            after: [
                'reference' => $invoice->reference,
                'period' => $draft['period'],
                'total_minor' => $draft['total_minor'],
                'lines' => count($draft['lines']),
                'journal' => $invoice->journal_ref,
            ],
            tenantId: $draft['tenant_id'],
        );

        $this->draftTheEstatesBill($invoice);

        return $invoice;
    }

    /**
     * THE ESTATE'S SIDE OF IT: the invoice as a draft bill to 5050 in that estate's own books (EST-S-05, M82 answer 2).
     *
     * After the invoice is committed, never inside its transaction: the bill is in the estate's database, and Gemini's
     * invoice and journal are Gemini's act whether or not the estate's side can be written. When it cannot (no 5050 in
     * that estate's chart, its database unreachable), the raise stands, the reason is on the audit log, and
     * `billing:estate-bills` drafts it later.
     */
    private function draftTheEstatesBill(Invoice $invoice): void
    {
        try {
            $bill = $this->estateBills->draftFor($invoice);
        } catch (Throwable $failed) {
            report($failed);

            $this->audit->record(
                action: 'billing.estate_bill_not_drafted',
                entityType: 'Invoice',
                entityId: (string) $invoice->id,
                after: ['reference' => $invoice->reference, 'reason' => mb_substr($failed->getMessage(), 0, 300)],
                tenantId: (string) $invoice->tenant_id,
            );

            return;
        }

        if ($bill !== null) {
            $this->audit->record(
                action: 'billing.estate_bill_drafted',
                entityType: 'Invoice',
                entityId: (string) $invoice->id,
                after: ['reference' => $invoice->reference, 'bill' => $bill->reference, 'amount_minor' => $bill->amount_minor, 'account' => GeminiBills::ACCOUNT],
                tenantId: (string) $invoice->tenant_id,
            );
        }
    }

    /**
     * Refuse a raise whose confirmation quoted another period or another total (B076).
     *
     * Null for either means the caller confirmed nothing about it — a test, a
     * command — and it is not checked. The console's raise passes both.
     *
     * @param  Draft  $draft
     *
     * @throws DomainException
     */
    private function assertConfirmed(array $draft, ?string $confirmedPeriodStart, ?int $confirmedTotalMinor): void
    {
        $period = $draft['period_start']->format('F Y');

        if ($confirmedPeriodStart !== null && $draft['period_start']->toDateString() !== $confirmedPeriodStart) {
            throw new DomainException(sprintf(
                'The preview you confirmed was for %s, but the next period to invoice for %s is now %s. Nothing was posted. '
                .'The preview is the current one — confirm it again if it is right.',
                Carbon::parse($confirmedPeriodStart)->format('F Y'),
                $draft['estate'],
                $period,
            ));
        }

        if ($confirmedTotalMinor !== null && $draft['total_minor'] !== $confirmedTotalMinor) {
            throw new DomainException(sprintf(
                'The preview you confirmed quoted %s for %s, but it now comes to %s: a price or a line item changed after it was opened. '
                .'Nothing was posted. The preview is the current one — confirm it again if it is right.',
                MoneyFormatter::fromMinor($confirmedTotalMinor, $draft['currency']),
                $period,
                MoneyFormatter::fromMinor($draft['total_minor'], $draft['currency']),
            ));
        }
    }

    /** @return DraftLine */
    private function line(string $description, int $quantity, int $unitPriceMinor, string $kind, int $sourceId): array
    {
        return [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price_minor' => $unitPriceMinor,
            'total_minor' => $quantity * $unitPriceMinor,
            'kind' => $kind,
            'source_id' => $sourceId,
        ];
    }

    /**
     * "PPV-INV-202610" — the client's receipt prefix, the month.
     *
     * The prefix is unique across the platform (D-089), so two clients whose
     * names begin alike cannot collide the way two-letter stems could.
     */
    private function reference(?Tenant $estate, string $tenantId, Carbon $start): string
    {
        $prefix = $estate->receipt_prefix ?? strtoupper(substr($tenantId, 0, 6));

        return $prefix.'-INV-'.$start->format('Ym');
    }

    private function sameDayOfMonth(Carbon $reference, Carbon $target): Carbon
    {
        $month = $target->copy()->startOfMonth();

        return $month->addDays(min($reference->day, $month->daysInMonth) - 1);
    }
}
