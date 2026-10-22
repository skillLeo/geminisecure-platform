<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\PlatformRateChange;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;
use App\Support\PlatformPricing;
use Brick\Money\Money;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Platform settings — board screens super-admin-42, 43 and 44.
 *
 * Three screens about one thing: what GeminiSecure charges, and for what.
 *
 *   42  the rate card — every price the platform quotes, and who holds a
 *       platform account
 *   43  the package template — which tier includes which feature, for every
 *       current and future client on it
 *   44  one client's overrides — what has been added above their tier or
 *       removed from it
 *
 * Everything is read from gs_platform. That is not a preference on this module:
 * a plan prices households, and an estate's own household records live in the
 * estate database. Nothing here needs them, and nothing here opens one.
 *
 * EVERY WRITE HERE IS PRIVILEGED, DATED WHERE A DATE MEANS SOMETHING, AND
 * AUDITED. These screens configure money that applies to clients other than
 * the one in front of you — a tier price re-prices every estate on it, the
 * per-guard add-on re-prices every estate with guards deployed, a package row
 * changes what every current and future client on that tier receives — so each
 * write is `configure`, and each is its own method with its own refusals
 * rather than a side effect of the screen that displays it. (This docblock
 * said "nothing here writes" until M10 §3; that stopped being true in Wave 4.)
 *
 * A PRICE CHANGE NEVER REWRITES AN ISSUED INVOICE, and nothing here needs to
 * try: an invoice's lines store the amounts it was raised at, and no reader of
 * an issued invoice looks back at `plans` or `platform_rates`. What a change
 * does decide is which invoices NOT YET RAISED carry it — every period whose
 * first day is on or after its date, resolved by `PlatformPricing` — and
 * `changeImpact()` says so, in words, before the change is saved.
 *
 * "TODAY" IS GEMINI'S (B082). Every date a write is judged by — never
 * retroactive, applied from today, in force today, not started yet — is
 * `PlatformCalendar::today()`, the day it is in Gemini's office. It was the UTC
 * date, which is tomorrow from 7 p.m. in Kingston: a change dated the Director's
 * own today was refused as retroactive, and one dated tomorrow was applied to
 * the live price at once.
 *
 * @phpstan-import-type Draft from InvoiceRun
 */
class PlatformSettings
{
    /** Subscriptions the invoice run bills — `InvoiceRun`'s and the billing screen's definition. */
    private const BILLING_STATES = ['active', 'dunning'];

    /** The one sentence both price writes, and the panel before them, refuse a backdated change with. */
    private const NEVER_RETROACTIVE = 'A price change is never retroactive. Invoices already raised were raised at the price in force, and re-pricing the past would make the ledger disagree with the paper a client holds.';

    /** How the change panel writes a subscription that is not being billed yet. */
    private const STATUS_NOTES = [
        'onboarding' => 'onboarding, billed at this price once live',
        'suspended' => 'suspended, billed at this price if restored',
    ];

    /* ------------------------------------------------------------------ */
    /* the pricing writes (12 §2, Wave 4; M10 §3) */
    /* ------------------------------------------------------------------ */

    /**
     * Change a tier price, from a date.
     *
     * NEVER RETROACTIVE. A date before today is refused: an invoice already
     * raised was raised at the price in force, and re-pricing the past would
     * make the ledger disagree with the paper a client holds.
     *
     * THE REASON IS REQUIRED. A price change re-prices every client on the
     * tier, and an unexplained one is the entry an auditor stops at.
     *
     * FROM THE NEXT BILLING PERIOD (M10 §3). A period is invoiced at the price
     * in force on its first day, so a change dated 24 September is first billed
     * for October — and the audit entry and the returned impact say which
     * clients, and which of them already hold an invoice for that period that
     * stays exactly as it was issued.
     *
     * @return array{applied: bool, clients: int, impact: array<string, mixed>}
     */
    public function changePlanPrice(int $planId, string $amount, string $effectiveFrom, string $reason, User $by): array
    {
        // Today's column must BE today's before it is compared with or copied
        // from — a change that came due overnight and was never swept would
        // otherwise be recorded as the price this one moves away from.
        $this->applyDuePriceChanges();

        $plan = DB::connection('mysql')->table('plans')->where('id', $planId)->first();

        if ($plan === null) {
            throw new DomainException('That tier is not on the rate card.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why. A price change re-prices every client on this tier, and an unexplained one is the entry an auditor stops at.');
        }

        $minor = Money::of($amount, $this->currency($plan->currency))->getMinorAmount()->toInt();

        if ($minor <= 0) {
            throw new DomainException('A tier price is a positive amount per unit.');
        }

        $date = Carbon::parse($effectiveFrom)->startOfDay();

        if ($date->lessThan(PlatformCalendar::today())) {
            throw new DomainException(self::NEVER_RETROACTIVE);
        }

        /*
         * COMPARED WITH THE PRICE IN FORCE ON THAT DATE, not with today's. With
         * a change already pending for 1 October, "back to today's price from 1
         * October" is exactly how that change is withdrawn — and comparing with
         * today's column would refuse it as a change nobody made.
         */
        $inForce = PlatformPricing::planPriceMinorOn($planId, (int) $plan->price_per_unit_minor, $date);

        if ($minor === $inForce) {
            throw new DomainException('That is the price already in force on that date. Saving it again would record a change nobody made.');
        }

        $impact = $this->changeImpact('tier', $planId, $date->toDateString());
        $applyNow = $date->lessThanOrEqualTo(PlatformCalendar::today());

        DB::connection('mysql')->transaction(function () use ($planId, $plan, $inForce, $minor, $date, $reason, $by, $applyNow): void {
            DB::connection('mysql')->table('plan_price_changes')->insert([
                'plan_id' => $planId,
                'from_minor' => $inForce,
                'to_minor' => $minor,
                'currency' => (string) $plan->currency,
                'effective_from' => $date->toDateString(),
                'reason' => $reason,
                'changed_by_id' => $by->getKey(),
                'changed_by_name' => (string) $by->name,
                'applied_at' => $applyNow ? Carbon::now() : null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($applyNow) {
                DB::connection('mysql')->table('plans')->where('id', $planId)->update([
                    'price_per_unit_minor' => $minor,
                    'updated_at' => Carbon::now(),
                ]);
            }
        });

        $this->audit->record(
            action: 'platform.tier_price_changed',
            entityType: 'Plan',
            entityId: (string) $planId,
            before: ['price_per_unit_minor' => $inForce],
            after: [
                'tier' => (string) $plan->name,
                'price_per_unit_minor' => $minor,
                'effective_from' => $date->toDateString(),
                'first_billed_period' => $impact['period'],
                'reason' => $reason,
                'clients_repriced' => count($impact['clients']),
                'clients' => $impact['clients'],
                'already_invoiced' => $impact['already_invoiced'],
                'applied' => $applyNow,
                'by' => (string) $by->name,
            ],
            tenantId: null,
        );

        return ['applied' => $applyNow, 'clients' => count($impact['clients']), 'impact' => $impact];
    }

    /**
     * Change a platform rate — the per-guard add-on — from a date (M10 §3).
     *
     * THE TWIN OF `changePlanPrice()`, refusal for refusal, so the two cannot
     * drift: a rate change re-prices every client with guards deployed exactly
     * as a tier price re-prices every client on the tier. Never retroactive,
     * never unexplained, first billed for the period whose first day is on or
     * after its date, and audited with who it reaches.
     *
     * ONLY A RATE SOMETHING BILLS. The invoice run bills `basis = 'guard'` rates
     * and nothing else, so a per-unit or per-client platform rate would be a
     * number that changes no invoice — and a screen that let it be "changed"
     * would be claiming something it cannot do.
     *
     * @return array{applied: bool, clients: int, impact: array<string, mixed>}
     */
    public function changeRate(int $rateId, string $amount, string $effectiveFrom, string $reason, User $by): array
    {
        $this->applyDuePriceChanges();

        $rate = DB::connection('mysql')->table('platform_rates')->where('id', $rateId)->where('is_active', true)->first();

        if ($rate === null) {
            throw new DomainException('That rate is not on the rate card.');
        }

        if ((string) $rate->basis !== 'guard') {
            throw new DomainException('Only the per-guard add-on is invoiced today. Changing a rate nothing bills would record a price nobody pays.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why. A rate change re-prices every client with guards deployed, and an unexplained one is the entry an auditor stops at.');
        }

        $minor = Money::of($amount, $this->currency($rate->currency))->getMinorAmount()->toInt();

        if ($minor <= 0) {
            throw new DomainException('The per-guard add-on is a positive amount per guard.');
        }

        $date = Carbon::parse($effectiveFrom)->startOfDay();

        if ($date->lessThan(PlatformCalendar::today())) {
            throw new DomainException(self::NEVER_RETROACTIVE);
        }

        $inForce = PlatformPricing::rateMinorOn($rateId, (int) $rate->amount_minor, $date);

        if ($minor === $inForce) {
            throw new DomainException('That is the rate already in force on that date. Saving it again would record a change nobody made.');
        }

        $impact = $this->changeImpact('rate', $rateId, $date->toDateString());
        $applyNow = $date->lessThanOrEqualTo(PlatformCalendar::today());

        DB::connection('mysql')->transaction(function () use ($rateId, $rate, $inForce, $minor, $date, $reason, $by, $applyNow): void {
            PlatformRateChange::query()->create([
                'platform_rate_id' => $rateId,
                'from_minor' => $inForce,
                'to_minor' => $minor,
                'currency' => (string) $rate->currency,
                'effective_from' => $date->toDateString(),
                'reason' => $reason,
                'changed_by_id' => $by->getKey(),
                'changed_by_name' => (string) $by->name,
                'applied_at' => $applyNow ? Carbon::now() : null,
            ]);

            if ($applyNow) {
                DB::connection('mysql')->table('platform_rates')->where('id', $rateId)->update([
                    'amount_minor' => $minor,
                    'updated_at' => Carbon::now(),
                ]);
            }
        });

        // Every MRR figure bakes the remembered rate into its SQL. See PlatformPricing.
        PlatformPricing::forgetGuardRate();

        $this->audit->record(
            action: 'platform.rate_changed',
            entityType: 'PlatformRate',
            entityId: (string) $rateId,
            before: ['amount_minor' => $inForce],
            after: [
                'rate' => (string) $rate->label,
                'basis' => (string) $rate->basis,
                'amount_minor' => $minor,
                'effective_from' => $date->toDateString(),
                'first_billed_period' => $impact['period'],
                'reason' => $reason,
                'clients_repriced' => count($impact['clients']),
                'clients' => $impact['clients'],
                'already_invoiced' => $impact['already_invoiced'],
                'applied' => $applyNow,
                'by' => (string) $by->name,
            ],
            tenantId: null,
        );

        return ['applied' => $applyNow, 'clients' => count($impact['clients']), 'impact' => $impact];
    }

    /**
     * Apply any dated price or rate change that has come due.
     *
     * CALLED ON READ rather than left to a scheduler, and deliberately: a
     * pending change that silently never applied would be a price the platform
     * believes it charges and does not. Reading the rate card is the moment
     * somebody would notice, so it is the moment it is made true.
     *
     * WHAT "APPLIED" MEANS, since M10 §3. It brings TODAY's columns —
     * `plans.price_per_unit_minor` and `platform_rates.amount_minor`, which every
     * projection reads — up to date. It does not decide what an invoice is
     * raised at: the invoice run resolves the price in force on each period's
     * first day from the dated history, whether or not anybody has opened this
     * screen since the change came due.
     *
     * IDEMPOTENT. `applied_at` is the guard, and it is claimed before the price
     * is written — two readers arriving together cannot both apply one change,
     * and the row after it in the same queue applies on its own date. No row is
     * locked to do it: the conditional update is the claim.
     */
    public function applyDuePriceChanges(): int
    {
        $applied = 0;

        $due = DB::connection('mysql')
            ->table('plan_price_changes')
            ->whereNull('applied_at')
            ->whereDate('effective_from', '<=', PlatformCalendar::today())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        foreach ($due as $change) {
            $applied += DB::connection('mysql')->transaction(function () use ($change): int {
                $claimed = DB::connection('mysql')->table('plan_price_changes')
                    ->where('id', $change->id)
                    ->whereNull('applied_at')
                    ->update(['applied_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

                if ($claimed === 0) {
                    return 0;
                }

                DB::connection('mysql')->table('plans')->where('id', $change->plan_id)->update([
                    'price_per_unit_minor' => (int) $change->to_minor,
                    'updated_at' => Carbon::now(),
                ]);

                return 1;
            });
        }

        $dueRates = PlatformRateChange::query()
            ->whereNull('applied_at')
            ->whereDate('effective_from', '<=', PlatformCalendar::today())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        $ratesApplied = 0;

        foreach ($dueRates as $change) {
            $ratesApplied += DB::connection('mysql')->transaction(function () use ($change): int {
                $claimed = PlatformRateChange::query()
                    ->whereKey($change->id)
                    ->whereNull('applied_at')
                    ->update(['applied_at' => Carbon::now()]);

                if ($claimed === 0) {
                    return 0;
                }

                DB::connection('mysql')->table('platform_rates')->where('id', $change->platform_rate_id)->update([
                    'amount_minor' => $change->to_minor,
                    'updated_at' => Carbon::now(),
                ]);

                return 1;
            });
        }

        if ($ratesApplied > 0) {
            PlatformPricing::forgetGuardRate();
        }

        return $applied + $ratesApplied;
    }

    /**
     * Price and rate changes not yet in force, so the screen can say one is coming.
     *
     * TWO TABLES, ONE LIST, and `kind` says which each row came from. The ids
     * are NOT unique across the list — both tables count from 1 — so a row's
     * identity is `key`, and the screen keys on it.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingPriceChanges(): array
    {
        $tiers = DB::connection('mysql')
            ->table('plan_price_changes')
            ->join('plans', 'plans.id', '=', 'plan_price_changes.plan_id')
            ->whereNull('plan_price_changes.applied_at')
            ->select('plan_price_changes.*', 'plans.name as plan_name')
            ->get()
            ->map(fn (object $row): array => $this->pendingRow($row, 'tier', (string) $row->plan_name, 'unit'));

        $rates = DB::connection('mysql')
            ->table('platform_rate_changes')
            ->join('platform_rates', 'platform_rates.id', '=', 'platform_rate_changes.platform_rate_id')
            ->whereNull('platform_rate_changes.applied_at')
            ->select('platform_rate_changes.*', 'platform_rates.label as rate_label', 'platform_rates.basis as rate_basis')
            ->get()
            ->map(fn (object $row): array => $this->pendingRow($row, 'rate', (string) $row->rate_label, (string) $row->rate_basis));

        return $tiers->concat($rates)
            ->sortBy([['effective_on', 'asc'], ['kind', 'desc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * One pending row. `plan` keeps its name from when only tiers were listed —
     * it is the tier's name or the rate's label, and `kind` says which.
     *
     * @return array<string, mixed>
     */
    private function pendingRow(object $row, string $kind, string $name, string $per): array
    {
        $date = Carbon::parse((string) $row->effective_from)->startOfDay();

        return [
            'key' => $kind.'-'.$row->id,
            'kind' => $kind,
            'id' => (int) $row->id,
            'plan' => $name,
            'per' => $per,
            'from' => MoneyFormatter::whole((int) $row->from_minor, $this->currency($row->currency)),
            'to' => MoneyFormatter::whole((int) $row->to_minor, $this->currency($row->currency)),
            'effective_from' => $date->format('M j, Y'),
            'effective_on' => $date->toDateString(),
            'first_billed' => $this->firstBilledPeriod($date)->format('F Y'),
            'reason' => (string) $row->reason,
            'by' => (string) $row->changed_by_name,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* what a price change will affect, and from when (M10 §3) */
    /* ------------------------------------------------------------------ */

    /**
     * What a change to this tier or this rate, from this date, would affect.
     *
     * THE CLIENT'S SENTENCE: "Both screens show clearly what a change will
     * affect and from when." So this is asked of the server, never worked out
     * in the browser: the panel shows it before the change is saved, the flash
     * repeats it after, and the audit entry records it — one reading, three
     * places, and none of them can disagree with the invoice run because the
     * rule here is the rule it applies.
     *
     * FROM WHEN. The first billing period whose first day is on or after the
     * date — a period is invoiced at the price in force on its first day — so
     * a change dated 24 September is first billed for October, and one dated
     * 1 October is too.
     *
     * WHAT. The clients who will be invoiced at it, by name: everyone on the
     * tier, or everyone with guards deployed for the per-guard add-on. And by
     * name again, anyone who already holds an invoice for that period or later,
     * because theirs is issued and stays exactly as it was — their first
     * invoice at the new price is the one after it.
     *
     * Null when the tier or rate is not one a change can be made against.
     *
     * @return array{kind: string, id: int, name: string, effective_from: string, period: string, clients: list<string>, already_invoiced: list<string>, lines: list<string>, sentence: string, refused: string|null}|null
     */
    public function changeImpact(string $kind, int $id, string $effectiveFrom): ?array
    {
        /*
         * A retired tier still prices the clients who stayed on it, so it is not
         * narrowed to active ones; a rate is, because the invoice run bills only
         * active per-guard rates and a change to any other would change no bill.
         */
        $name = match ($kind) {
            'tier' => DB::connection('mysql')->table('plans')->where('id', $id)->value('name'),
            'rate' => DB::connection('mysql')->table('platform_rates')->where('id', $id)->where('is_active', true)->where('basis', 'guard')->value('label'),
            default => null,
        };

        if ($name === null) {
            return null;
        }

        $date = Carbon::parse($effectiveFrom)->startOfDay();
        $today = PlatformCalendar::today();
        $period = $this->firstBilledPeriod($date);

        $shape = [
            'kind' => $kind,
            'id' => $id,
            'name' => (string) $name,
            'effective_from' => $date->toDateString(),
            'period' => $period->format('Y-m'),
            'clients' => [],
            'already_invoiced' => [],
            'lines' => [],
            'sentence' => '',
            'refused' => null,
        ];

        if ($date->lessThan($today)) {
            return [...$shape, 'refused' => self::NEVER_RETROACTIVE, 'lines' => [self::NEVER_RETROACTIVE], 'sentence' => self::NEVER_RETROACTIVE];
        }

        $clients = $kind === 'tier' ? $this->clientsOnTier($id) : $this->clientsWithGuards();

        /*
         * Who already holds an invoice for that period or later. Read from the
         * invoices themselves — the latest period each client has been billed
         * for — because that is the fact that decides whether their first
         * invoice at the new price is this period's or a later one.
         */
        $latest = DB::connection('mysql')
            ->table('invoices')
            ->whereIn('tenant_id', array_column($clients, 'tenant_id'))
            ->groupBy('tenant_id')
            ->select('tenant_id', DB::raw('MAX(period_start) as latest'))
            ->pluck('latest', 'tenant_id');

        /** @var array<string, list<string>> $invoicedAhead first new period => names */
        $invoicedAhead = [];

        foreach ($clients as $client) {
            $last = $latest[$client['tenant_id']] ?? null;

            if ($last !== null && Carbon::parse((string) $last)->startOfMonth()->greaterThanOrEqualTo($period)) {
                $firstNew = Carbon::parse((string) $last)->startOfMonth()->addMonthNoOverflow()->format('F Y');
                $invoicedAhead[$firstNew][] = $client['name'];
            }
        }

        $lines = [$this->whenLine($date, $period, $today)];
        $lines[] = $this->whoLine($kind, $clients);

        foreach ($invoicedAhead as $firstNew => $names) {
            $lines[] = sprintf(
                'Already invoiced for %s and not changed: %s. %s first invoice at the new price is %s\'s.',
                $period->format('F Y'),
                $this->nameList($names),
                count($names) === 1 ? 'Its' : 'Their',
                $firstNew,
            );
        }

        $lines[] = 'Invoices already issued are not changed.';

        /*
         * THE PROJECTIONS MOVE ON THE DATE, the invoices from the period. Said
         * because a Director who changes a rate and then sees the dashboard's
         * revenue move will otherwise read it as the invoices having been
         * rewritten — they have not; the dashboard projects at today's price.
         */
        $lines[] = $date->isSameDay($today)
            ? 'Revenue projections on the dashboards use the price in force, so they move today.'
            : 'Revenue projections on the dashboards use the price in force, so they move on '.$date->format('j F Y').'.';

        return [
            ...$shape,
            'clients' => array_column($clients, 'name'),
            'already_invoiced' => array_merge(...array_values($invoicedAhead)),
            'lines' => $lines,
            'sentence' => implode(' ', $lines),
        ];
    }

    /**
     * The first billing period a change dated `$date` is invoiced in.
     *
     * Periods are calendar months — the invoice run's own — and a period is
     * priced on its first day, so a change on the 1st is first billed for that
     * month and a change on any later day for the month after.
     */
    private function firstBilledPeriod(Carbon $date): Carbon
    {
        $month = $date->copy()->startOfMonth();

        return $date->day === 1 ? $month : $month->addMonthNoOverflow();
    }

    /** "Takes effect for invoices for October 2026 onward (the next billing period)." */
    private function whenLine(Carbon $date, Carbon $period, Carbon $today): string
    {
        $next = $today->copy()->startOfMonth()->addMonthNoOverflow();
        $month = $period->format('F Y');

        /*
         * The months in between are "not affected by it" rather than "invoiced
         * at the current price": another change may already be pending for one
         * of them, and this sentence is only about this change.
         */
        $line = match (true) {
            $period->equalTo($today->copy()->startOfMonth()) => "Takes effect for invoices for {$month} onward (this billing period, which starts today).",
            $period->equalTo($next) => "Takes effect for invoices for {$month} onward (the next billing period).",
            default => "Takes effect for invoices for {$month} onward. "
                .($period->copy()->subMonthNoOverflow()->equalTo($next)
                    ? 'Invoices for '.$next->format('F Y').' are not affected by it.'
                    : 'Invoices for '.$next->format('F Y').' to '.$period->copy()->subMonthNoOverflow()->format('F Y').' are not affected by it.'),
        };

        if ($date->day !== 1) {
            $line .= ' It is dated '.$date->format('j F Y').', and a period is invoiced at the price in force on its first day.';
        }

        return $line;
    }

    /**
     * "3 clients on this tier: A, B, C."
     *
     * @param  list<array{tenant_id: string, name: string}>  $clients
     */
    private function whoLine(string $kind, array $clients): string
    {
        if ($clients === []) {
            return $kind === 'tier'
                ? 'No client is on this tier, so no invoice changes.'
                : 'No client has guards deployed, so no invoice changes yet. Guards are counted when each invoice is raised.';
        }

        $names = $this->nameList(array_column($clients, 'label'));

        if ($kind === 'tier') {
            return (count($clients) === 1 ? '1 client on this tier: ' : count($clients).' clients on this tier: ').$names.'.';
        }

        return (count($clients) === 1 ? '1 client has guards deployed: ' : count($clients).' clients have guards deployed: ')
            .$names.'. Guards are counted when each invoice is raised.';
    }

    /**
     * Every client on a tier who is, or will be, invoiced at its price.
     *
     * Not cancelled, and never the demonstration estate (M5 §3), which is not a
     * client and is never invoiced. A subscription not yet billed is named with
     * what it is, rather than left out or counted as if it were billed today.
     *
     * @return list<array{tenant_id: string, name: string, label: string}>
     */
    private function clientsOnTier(int $planId): array
    {
        return DB::connection('mysql')
            ->table('subscriptions')
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->where('subscriptions.plan_id', $planId)
            ->where('subscriptions.status', '!=', 'cancelled')
            ->tap(static fn ($query) => OperatorEstates::exclude($query, 'subscriptions.tenant_id'))
            ->orderBy('tenants.name')
            ->get(['subscriptions.tenant_id', 'subscriptions.status', 'tenants.name'])
            ->map(fn (object $row): array => [
                'tenant_id' => (string) $row->tenant_id,
                'name' => (string) $row->name,
                'label' => $this->withStatus((string) $row->name, (string) $row->status),
            ])
            ->all();
    }

    /**
     * Every client the per-guard add-on reaches: guards deployed, and a
     * subscription that is not cancelled.
     *
     * "Deployed" is the invoice run's own definition — not on leave, not
     * suspended — counted today, and the invoice counts again when it is
     * raised. The count is in the label because it is what the rate multiplies.
     *
     * @return list<array{tenant_id: string, name: string, label: string}>
     */
    private function clientsWithGuards(): array
    {
        $guards = DB::connection('mysql')
            ->table('guards')
            ->whereNotNull('tenant_id')
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->groupBy('tenant_id')
            ->select('tenant_id', DB::raw('COUNT(*) as deployed'))
            ->pluck('deployed', 'tenant_id');

        if ($guards->isEmpty()) {
            return [];
        }

        return DB::connection('mysql')
            ->table('subscriptions')
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->whereIn('subscriptions.tenant_id', $guards->keys()->all())
            ->where('subscriptions.status', '!=', 'cancelled')
            ->tap(static fn ($query) => OperatorEstates::exclude($query, 'subscriptions.tenant_id'))
            ->orderBy('tenants.name')
            ->get(['subscriptions.tenant_id', 'subscriptions.status', 'tenants.name'])
            ->map(function (object $row) use ($guards): array {
                $count = (int) $guards[$row->tenant_id];

                return [
                    'tenant_id' => (string) $row->tenant_id,
                    'name' => (string) $row->name,
                    'label' => $this->withStatus(
                        $row->name.' ('.$count.' '.($count === 1 ? 'guard' : 'guards').')',
                        (string) $row->status,
                    ),
                ];
            })
            ->all();
    }

    /** "Palm Grove (onboarding, billed at this price once live)". */
    private function withStatus(string $label, string $status): string
    {
        if (in_array($status, self::BILLING_STATES, true)) {
            return $label;
        }

        $note = self::STATUS_NOTES[$status] ?? $status;

        return str_ends_with($label, ')')
            ? substr($label, 0, -1).', '.$note.')'
            : $label.' ('.$note.')';
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param  list<string>  $names
     */
    private function nameList(array $names): string
    {
        if (count($names) <= 1) {
            return implode('', $names);
        }

        return implode(', ', array_slice($names, 0, -1)).' and '.end($names);
    }

    /**
     * Turn a package feature on or off for a tier.
     *
     * WHAT EVERY CURRENT AND FUTURE CLIENT ON THAT TIER RECEIVES, which is the
     * old reason's own words. It takes effect at once and says so: unlike a
     * price, a capability is not something a client is invoiced for on a date,
     * and pretending otherwise would be a date that meant nothing.
     */
    public function setPackageFeature(int $planId, int $featureId, bool $included, User $by): void
    {
        $plan = DB::connection('mysql')->table('plans')->where('id', $planId)->first();
        $feature = DB::connection('mysql')->table('package_features')->where('id', $featureId)->first();

        if ($plan === null || $feature === null) {
            throw new DomainException('That tier or that feature is not on the package template.');
        }

        $existing = DB::connection('mysql')
            ->table('plan_features')
            ->where('plan_id', $planId)
            ->where('package_feature_id', $featureId)
            ->first();

        /*
         * A CORE FEATURE IS NOT A SETTING. It is in every package and no package
         * may drop it — the cell is drawn `locked` for exactly that reason — and
         * a write that reached one would be the switch that must not exist.
         */
        if ((bool) $feature->is_core) {
            throw new DomainException((string) $feature->label.' is in every package by definition. A tier that dropped it would not be a tier of this platform.');
        }

        if ($existing !== null && (bool) $existing->included === $included) {
            return;
        }

        DB::connection('mysql')->table('plan_features')->updateOrInsert(
            ['plan_id' => $planId, 'package_feature_id' => $featureId],
            ['included' => $included, 'updated_at' => Carbon::now(), 'created_at' => Carbon::now()],
        );

        $this->audit->record(
            action: 'platform.package_feature_changed',
            entityType: 'Plan',
            entityId: (string) $planId,
            before: ['included' => $existing === null ? false : (bool) $existing->included],
            after: [
                'tier' => (string) $plan->name,
                'feature' => (string) $feature->label,
                'included' => $included,
                'by' => (string) $by->name,
            ],
            tenantId: null,
        );
    }

    /**
     * Add a dated override to one client's subscription.
     *
     * DATED AND NEVER RETROACTIVE, which is the old reason's own rule: an
     * override that started before today would change an invoice already
     * raised. An addition costs the client more; a removal costs them less, and
     * both are stored as positive amounts with a type — a signed column would
     * let a removal be entered as a negative addition and read as a discount
     * nobody agreed.
     *
     * @param  array<string, mixed>  $fields
     */
    public function addLineItem(string $tenantId, array $fields, User $by): void
    {
        $name = trim((string) ($fields['name'] ?? ''));
        $type = (string) ($fields['type'] ?? 'addition');

        if ($name === '') {
            throw new DomainException('An override has a name. A line on an invoice that says nothing is one a client will ring about.');
        }

        if (! in_array($type, ['addition', 'removal'], true)) {
            throw new DomainException('An override adds to what a client pays or takes away from it.');
        }

        $amount = Money::of((string) ($fields['amount'] ?? '0'), 'JMD');

        if ($amount->isNegativeOrZero()) {
            throw new DomainException('An override is a positive amount. Whether it adds or subtracts is the type, not the sign: a signed amount would let a removal be entered as a negative addition and read as a discount nobody agreed.');
        }

        $from = Carbon::parse((string) ($fields['effective_from'] ?? PlatformCalendar::today()->toDateString()))->startOfDay();

        if ($from->lessThan(PlatformCalendar::today())) {
            throw new DomainException('An override is never retroactive. It would change an invoice already raised.');
        }

        $why = trim((string) ($fields['reason'] ?? '')) ?: null;

        DB::connection('mysql')->table('subscription_line_items')->insert([
            'tenant_id' => $tenantId,
            'description' => $name,
            'reason' => $why,
            'type' => $type,
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'currency' => 'JMD',
            'recurrence' => 'monthly',
            'effective_from' => $from->toDateString(),
            'effective_to' => null,
            'created_by_user_id' => $by->getKey(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        /*
         * WHO, WHAT, FROM WHEN AND WHY (M10 §3: "every change is audited"). The
         * actor is on the entry already when a person made it through the
         * console; `by` keeps it there when a service made it on their behalf.
         */
        $this->audit->record(
            action: 'platform.line_item_added',
            entityType: 'Subscription',
            entityId: $tenantId,
            after: [
                'name' => $name,
                'type' => $type,
                'amount_minor' => $amount->getMinorAmount()->toInt(),
                'effective_from' => $from->toDateString(),
                'first_billed_period' => $this->lineItemImpact($tenantId, $from->toDateString(), ending: false)['period'],
                'reason' => $why,
                'by' => (string) $by->name,
            ],
            tenantId: $tenantId,
        );
    }

    /**
     * End an override, from a date.
     *
     * ENDED, NEVER DELETED. The override belongs to the invoices it was billed
     * on; removing the row would make those invoices unexplainable. It stops
     * applying from the date given, and a date before today is refused for the
     * same reason adding one is.
     *
     * WITHDRAWN, IF IT HAS NOT STARTED (M10 §3). An override dated ahead and
     * ended before its date was never in force: it is stored with an end before
     * its start, which the invoice run reads as "on no invoice", and it stays on
     * the record like every other. Returns whether that is what happened, so
     * the screen can say which of the two it did.
     */
    public function endLineItem(int $id, string $endsOn, User $by): bool
    {
        $item = DB::connection('mysql')->table('subscription_line_items')->where('id', $id)->first();

        if ($item === null) {
            throw new DomainException('That override is not on this client\'s subscription.');
        }

        if ($item->effective_to !== null) {
            throw new DomainException('That override has already been ended.');
        }

        $date = Carbon::parse($endsOn)->startOfDay();

        if ($date->lessThan(PlatformCalendar::today())) {
            throw new DomainException('An override is ended from today onward. Ending it in the past would change an invoice already raised.');
        }

        $withdrawn = Carbon::parse((string) $item->effective_from)->startOfDay()->greaterThan($date);

        DB::connection('mysql')->table('subscription_line_items')->where('id', $id)->update([
            'effective_to' => $date->toDateString(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record(
            action: 'platform.line_item_ended',
            entityType: 'Subscription',
            entityId: (string) $item->tenant_id,
            before: [
                'effective_to' => null,
                'effective_from' => Carbon::parse((string) $item->effective_from)->toDateString(),
                'type' => (string) $item->type,
                'amount_minor' => (int) $item->amount_minor,
            ],
            after: [
                'name' => (string) $item->description,
                'effective_to' => $date->toDateString(),
                'withdrawn' => $withdrawn,
                // The last period it can still be billed for: the one it ends in.
                'last_billable_period' => $withdrawn ? null : $date->format('Y-m'),
                'by' => (string) $by->name,
            ],
            tenantId: (string) $item->tenant_id,
        );

        return $withdrawn;
    }

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The tiers a price change can be made against, with what they cost now.
     *
     * @return list<array<string, mixed>>
     */
    public function editablePlans(): array
    {
        return DB::connection('mysql')
            ->table('plans')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->map(fn (object $plan): array => [
                'id' => (int) $plan->id,
                'name' => (string) $plan->name,
                'price_minor' => (int) $plan->price_per_unit_minor,
                'price' => MoneyFormatter::whole((int) $plan->price_per_unit_minor, $this->currency($plan->currency)),
                'amount' => number_format((int) $plan->price_per_unit_minor / 100, 2, '.', ''),
            ])
            ->all();
    }

    /**
     * The platform rates a change can be made against (M10 §3) — the per-guard
     * add-on — in the same five keys `editablePlans()` returns, so the panel
     * treats a tier and a rate alike, plus the basis the amount is per.
     *
     * Only a rate the invoice run bills: active, and per guard. See `changeRate()`.
     *
     * @return list<array{id: int, name: string, price_minor: int, price: string, amount: string, basis: string}>
     */
    public function editableRates(): array
    {
        return DB::connection('mysql')
            ->table('platform_rates')
            ->where('is_active', true)
            ->where('basis', 'guard')
            ->orderBy('sort')
            ->get()
            ->map(fn (object $rate): array => [
                'id' => (int) $rate->id,
                'name' => (string) $rate->label,
                'price_minor' => (int) $rate->amount_minor,
                'price' => MoneyFormatter::whole((int) $rate->amount_minor, $this->currency($rate->currency)),
                'amount' => number_format((int) $rate->amount_minor / 100, 2, '.', ''),
                'basis' => (string) $rate->basis,
            ])
            ->all();
    }

    /**
     * Board 42's left panel: every rate the platform quotes.
     *
     * Two sources, deliberately, and in this order. A tier price is a property
     * of a plan and lives on `plans`; a per-guard charge applies on top of ANY
     * tier and therefore cannot be a column on a plan without asserting that a
     * guard costs a different amount depending on the estate's subscription.
     *
     * EACH ROW SAYS WHAT CHANGING IT MEANS (M10 §3): `target` is the change
     * panel's key for it — `tier-3`, `rate-1` — and `editable` is false for a
     * platform rate the invoice run does not bill, whose box stays a display.
     *
     * @return list<array{name: string, detail: string, amount: string, kind: string, target: string, editable: bool}>
     */
    public function rateCard(): array
    {
        /*
         * How many clients each tier prices.
         *
         * Every subscription counts, not only the active ones: the question
         * this line answers is "how much of the book moves if I change this
         * number", and an estate in dunning is still billed against its tier.
         */
        $clients = DB::connection('mysql')
            ->table('subscriptions')
            ->select('plan_id', DB::raw('COUNT(*) as clients'))
            ->groupBy('plan_id')
            ->pluck('clients', 'plan_id');

        $rows = DB::connection('mysql')
            ->table('plans')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->map(fn (object $plan): array => [
                'name' => (string) $plan->name,
                'detail' => $this->clientCount((int) ($clients[$plan->id] ?? 0)),
                'amount' => MoneyFormatter::whole(
                    (int) $plan->price_per_unit_minor,
                    $this->currency($plan->currency)
                ).' /unit',
                'kind' => 'tier',
                'target' => 'tier-'.$plan->id,
                'editable' => true,
            ])
            ->all();

        foreach ($this->platformRates() as $rate) {
            $rows[] = [
                'name' => (string) $rate->label,
                'detail' => (string) $rate->applies_to,
                'amount' => MoneyFormatter::whole(
                    (int) $rate->amount_minor,
                    $this->currency($rate->currency)
                ).' /'.$rate->basis,
                'kind' => 'rate',
                'target' => 'rate-'.$rate->id,
                'editable' => (string) $rate->basis === 'guard',
            ];
        }

        return $rows;
    }

    /**
     * Is anything priced but retired?
     *
     * The difference between a rate card that is empty because it was emptied
     * and one that is empty because it was never filled. Those are two
     * different screens — one says retired plans are still on file and how to
     * bring one back, the other says nothing has ever been priced — and
     * neither can be told from a row count alone.
     *
     * @param  bool  $includeRates  whether retired platform rates count too;
     *                              false on the package builder, which draws
     *                              plan columns and no rate rows
     */
    public function hasRetiredPricing(bool $includeRates = true): bool
    {
        $plans = DB::connection('mysql')->table('plans')->where('is_active', false)->exists();

        if ($plans || ! $includeRates) {
            return $plans;
        }

        return DB::connection('mysql')->table('platform_rates')->where('is_active', false)->exists();
    }

    /**
     * Board 42's right panel: who holds an account on this console.
     *
     * "Platform administrator" is not a separate list to maintain — it is
     * anyone holding a Gemini Console role, which is the same fact the sidebar
     * and the `can:` middleware are generated from. A second list would be a
     * second thing to keep in step, and the one that drifted would be this one.
     *
     * @return list<array{initials: string, name: string, detail: string}>
     */
    public function administrators(): array
    {
        $roles = DB::connection('mysql')
            ->table('roles')
            ->where('console', Console::Gemini->value)
            ->orderBy('sort')
            ->get()
            ->keyBy('id');

        $moduleCount = DB::connection('mysql')
            ->table('modules')
            ->where('console', Console::Gemini->value)
            ->count();

        /** @var array<int, string> $summaries */
        $summaries = [];

        /*
         * Every role's cells in ONE read (M11 A2) — it was a query per role, so a console
         * with a dozen roles asked a dozen times to draw one panel. Grouped here, summarised
         * exactly as before.
         */
        $cellsByRole = RoleModuleAccess::query()
            ->whereIn('role_id', $roles->keys()->map(static fn ($id): int => (int) $id)->all())
            ->get()
            ->groupBy('role_id');

        foreach ($roles as $roleId => $role) {
            $summaries[(int) $roleId] = $this->accessSummary($cellsByRole->get((int) $roleId, new EloquentCollection), $moduleCount);
        }

        return DB::connection('mysql')
            ->table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.console', Console::Gemini->value)
            ->orderBy('roles.sort')
            ->orderBy('users.name')
            ->select('users.name', 'users.status', 'roles.id as role_id', 'roles.label', 'roles.name as role_name')
            ->get()
            ->map(fn (object $row): array => [
                'initials' => $this->initials((string) $row->name),
                'name' => (string) $row->name,
                /*
                 * Role, then reach. The board writes "Head of Platform Ops ·
                 * Full access", and the second half is the half that matters:
                 * a title is what someone is called, and what they can open is
                 * what a reader of this panel is checking.
                 *
                 * A suspended account is called out. Someone reading a list of
                 * who holds platform access needs to see that a row is dormant
                 * without opening it.
                 */
                'detail' => implode(' · ', array_filter([
                    (string) ($row->label ?? $row->role_name),
                    $summaries[(int) $row->role_id] ?? null,
                    $row->status === 'active' ? null : 'account '.$row->status,
                ])),
            ])
            ->all();
    }

    /**
     * Board 43: the package template, one column per active plan.
     *
     * The grid is not a report about packages — it is `plan_features` printed,
     * which is the same table an estate's available feature set is resolved
     * from. Nothing here recomputes what a tier includes, so this screen cannot
     * say one thing while a provisioned estate gets another.
     *
     * @return array{plans: list<array{id: int, name: string, price: string, emphasised: bool}>, features: list<array{key: string, label: string, sub: string|null, kind: string, cells: list<array{variant: string, value: string|null, emphasised: bool}>}>}
     */
    public function packageTemplate(): array
    {
        $plans = DB::connection('mysql')
            ->table('plans')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();

        /*
         * The top tier carries the board's emphasis — a tinted column heading
         * and a tinted branding tag. Derived from the ordering rather than
         * matched on the name "Premium", so renaming the top tier or adding one
         * above it moves the emphasis with it instead of leaving it on a plan
         * that is no longer the flagship.
         */
        $topPlanId = $plans->isEmpty() ? null : (int) $plans->last()->id;

        $features = DB::connection('mysql')
            ->table('package_features')
            ->orderBy('sort')
            ->get();

        $matrix = DB::connection('mysql')
            ->table('plan_features')
            ->whereIn('plan_id', $plans->pluck('id'))
            ->get()
            ->keyBy(fn (object $row): string => $row->plan_id.':'.$row->package_feature_id);

        return [
            'plans' => $plans->map(fn (object $plan): array => [
                'id' => (int) $plan->id,
                'name' => (string) $plan->name,
                'price' => MoneyFormatter::whole(
                    (int) $plan->price_per_unit_minor,
                    $this->currency($plan->currency)
                ).'/unit',
                'emphasised' => (int) $plan->id === $topPlanId,
            ])->all(),
            'features' => $features->map(fn (object $feature): array => [
                'key' => (string) $feature->key,
                'label' => (string) $feature->label,
                'sub' => $feature->sub_label === null ? null : (string) $feature->sub_label,
                'kind' => (string) $feature->kind,
                // The feature's own id travels with it (12 §2, Wave 4): the
                // toggle posts a plan and a feature, and a key would mean the
                // write looking one up that the read already had.
                'id' => (int) $feature->id,
                'is_core' => (bool) $feature->is_core,

                'cells' => $plans->map(fn (object $plan): array => [
                    ...$this->cell(
                        $feature,
                        $matrix->get($plan->id.':'.$feature->id),
                        (int) $plan->id === $topPlanId,
                    ),
                    'plan_id' => (int) $plan->id,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * The clients the line-items screen can be pointed at.
     *
     * Every estate, not only the billable ones: an estate mid-onboarding is
     * exactly when a negotiated addition gets recorded, and leaving it out of
     * the picker would mean the override could not be entered until after the
     * conversation that produced it.
     *
     * @return list<array{id: string, name: string}>
     */
    public function clients(): array
    {
        return DB::connection('mysql')
            ->table('tenants')
            // Every estate EXCEPT the demonstration one (M5 §3). A negotiated
            // billing line for a community that does not exist is a line that
            // will be invoiced to nobody and reconciled by somebody.
            ->tap(static fn ($query) => OperatorEstates::exclude($query, 'id'))
            ->orderBy('name')
            ->select('id', 'name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * What adding an override dated `$date` — or ending one on it — does to
     * this client's invoices, said before it is done (M10 §3).
     *
     * THE INVOICE RUN'S RULE FOR AN OVERRIDE IS NOT A PRICE'S, and the sentence
     * says so. A price is read on a period's first day; an override is billed
     * on every invoice whose period it overlaps, at its full monthly amount — it
     * is not pro-rated. So one added on 24 September is billed in full for
     * September, if September has not been invoiced yet, and one ended on 24
     * September is still billed for September and on nothing after it.
     *
     * AN INVOICE ALREADY ISSUED IS NOT CHANGED, and the client's own latest
     * invoice is what decides where that bites: added, the override first
     * appears on the invoice after it; ended, an invoice already raised for a
     * later month still carries it, and the correction is a credit note.
     *
     * @return array{period: string, lines: list<string>, sentence: string, refused: string|null}
     */
    public function lineItemImpact(string $tenantId, string $date, bool $ending): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $month = $day->copy()->startOfMonth();

        if ($day->lessThan(PlatformCalendar::today())) {
            $refused = $ending
                ? 'An override is ended from today onward. Ending it in the past would change an invoice already raised.'
                : 'An override is never retroactive. It would change an invoice already raised.';

            return ['period' => $month->format('Y-m'), 'lines' => [$refused], 'sentence' => $refused, 'refused' => $refused];
        }

        $latest = DB::connection('mysql')->table('invoices')->where('tenant_id', $tenantId)->max('period_start');
        $invoicedThrough = $latest === null ? null : Carbon::parse((string) $latest)->startOfMonth();
        $invoiced = $invoicedThrough !== null && $invoicedThrough->greaterThanOrEqualTo($month);

        if ($ending) {
            if (! $invoiced) {
                $lines = [
                    'Still billed on this client\'s invoice for '.$month->format('F Y').', the month it ends in, and on no invoice after it. An override is billed in full for every month it touches, not pro-rated.',
                ];
            } else {
                $lines = [
                    'On no invoice raised from now on: this client is already invoiced for '.$month->format('F Y')
                        .($invoicedThrough->greaterThan($month) ? ' to '.$invoicedThrough->format('F Y') : '')
                        .', and its next invoice, for '.$invoicedThrough->copy()->addMonthNoOverflow()->format('F Y').', will not carry it.',
                    'An issued invoice that carried it still does. A correction to one is a credit note.',
                ];
            }

            $lines[] = 'Invoices already issued are not changed.';

            return ['period' => $month->format('Y-m'), 'lines' => $lines, 'sentence' => implode(' ', $lines), 'refused' => null];
        }

        $first = $invoiced ? $invoicedThrough->copy()->addMonthNoOverflow() : $month;

        $lines = [
            'First billed on this client\'s invoice for '.$first->format('F Y').', at the full monthly amount. An override is billed in full for every month it touches, not pro-rated.',
        ];

        if ($invoiced) {
            $lines[] = 'This client is already invoiced for '.$month->format('F Y')
                .($invoicedThrough->greaterThan($month) ? ' to '.$invoicedThrough->format('F Y') : '')
                .', and an issued invoice is not changed, so it starts with the next one.';
        }

        $lines[] = 'Invoices already issued are not changed.';

        return ['period' => $first->format('Y-m'), 'lines' => $lines, 'sentence' => implode(' ', $lines), 'refused' => null];
    }

    /**
     * Board 44: one client's monthly subscription, base plan and overrides.
     *
     * WHAT THIS IS NOT: an invoice. An invoice is what a client was billed for
     * a period that has closed, and it is already in `invoices` with its own
     * total. This is what they are billed going FORWARD — the thing the next
     * invoice will be generated from — which is why an override carries an
     * effective date and why ending one sets that date rather than deleting the
     * row. Editing history to change a future charge is the mistake the two
     * tables exist separately to prevent.
     *
     * Returns null for a client with no subscription: there is no base plan to
     * put overrides on top of, and a total of nought would be a claim rather
     * than an absence.
     *
     * TODAY'S PRICES AND TODAY'S LINE ITEMS, and nothing else. The invoice
     * preview used to borrow this with its period's first day for the prices
     * and still today for the line items, which is how its "Raise … at J$X?"
     * came to quote a total the raise did not post (B076). It now draws the
     * invoice run's own draft instead — see `previewBill()`.
     *
     * @return array{client: string, baseHead: string, base: list<array{name: string, detail: string, badge: string, badgeLabel: string, price: string}>, additions: list<array<string, mixed>>, removals: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, total: string}|null
     */
    public function lineItems(string $tenantId): ?array
    {
        $estate = DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('tenants.id', $tenantId)
            ->select([
                'tenants.id',
                'tenants.name',
                'subscriptions.unit_count',
                'plans.id as plan_id',
                'plans.name as plan_name',
                'plans.price_per_unit_minor',
                'plans.currency',
            ])
            ->first();

        if ($estate === null || $estate->plan_id === null) {
            return null;
        }

        $currency = $this->currency($estate->currency);
        $units = (int) $estate->unit_count;
        $perUnit = (int) $estate->price_per_unit_minor;
        $subscriptionMinor = $units * $perUnit;

        $base = [[
            'name' => $estate->plan_name.' tier subscription',
            // The console's middot, never an em dash (Law 3), here and in the preview's bill below.
            'detail' => number_format($units).' units × '.MoneyFormatter::whole($perUnit, $currency)
                .'/unit · includes '.$this->includedIn((int) $estate->plan_id),
            'badge' => 'base',
            'badgeLabel' => 'Base plan',
            'price' => MoneyFormatter::whole($subscriptionMinor, $currency).'/mo',
        ]];

        $totalMinor = $subscriptionMinor;

        /*
         * Guarding, billed per guard on top of the tier.
         *
         * Only drawn where this client actually has guards posted. An estate
         * that runs its own security buys the software alone, and a row reading
         * "0 guards × $4,500" would put a charge on the screen that is not on
         * the bill.
         */
        $guards = DB::connection('mysql')
            ->table('guards')
            ->where('tenant_id', $tenantId)
            // The same definition of "deployed" the client directory uses. Two
            // answers to one question on two screens about the same estate is
            // how a directory ends up contradicting a record.
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->count();

        foreach ($this->platformRates() as $rate) {
            if ($rate->basis !== 'guard' || $guards === 0) {
                continue;
            }

            $perGuard = (int) $rate->amount_minor;

            $amount = $guards * $perGuard;
            $totalMinor += $amount;

            $base[] = [
                'name' => (string) $rate->label,
                'detail' => $guards.' guards × '
                    .MoneyFormatter::whole($perGuard, $this->currency($rate->currency)).'/mo',
                'badge' => 'base',
                'badgeLabel' => 'Base plan',
                'price' => MoneyFormatter::whole($amount, $currency).'/mo',
            ];
        }

        $overrides = DB::connection('mysql')
            ->table('subscription_line_items')
            ->leftJoin('users', 'users.id', '=', 'subscription_line_items.created_by_user_id')
            ->where('subscription_line_items.tenant_id', $tenantId)
            /*
             * In force today. An override that has been end-dated is history —
             * it belongs to the invoices it was billed on, not to what this
             * client pays next month — and one dated ahead of today has not
             * started. Both would inflate a total nobody is being charged.
             */
            ->whereDate('subscription_line_items.effective_from', '<=', PlatformCalendar::today())
            ->where(function ($query): void {
                $query->whereNull('subscription_line_items.effective_to')
                    ->orWhereDate('subscription_line_items.effective_to', '>=', PlatformCalendar::today());
            })
            ->orderBy('subscription_line_items.effective_from')
            ->select('subscription_line_items.*', 'users.name as author')
            ->get();

        $additions = [];
        $removals = [];

        /*
         * WHERE EACH OVERRIDE'S FEATURE NORMALLY SITS, READ ONCE FOR ALL OF THEM (M11 A2). Every
         * row's second line names the tiers that include its feature, and each row used to ask
         * that of the database itself — a query per override tied to a feature.
         */
        $tiers = $this->plansIncluding(
            $overrides->pluck('package_feature_id')->filter(static fn (mixed $id): bool => $id !== null)->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all(),
        );

        foreach ($overrides as $override) {
            $amount = (int) $override->amount_minor;

            if ($override->type === 'removal') {
                $totalMinor -= $amount;
                $removals[] = $this->overrideRow($override, $currency, removal: true, tiers: $tiers);

                continue;
            }

            $totalMinor += $amount;
            $additions[] = $this->overrideRow($override, $currency, removal: false, tiers: $tiers);
        }

        /*
         * RECORDED AND NOT YET IN FORCE (M10 §3). An override dated ahead is left
         * out of the total above for the reason given there, and until this list
         * it was left off the screen altogether — so the Director who recorded
         * one could not see it again, or withdraw it, until it had started and
         * been billed. Withdrawn ones (ended before they began) are history.
         */
        $upcoming = DB::connection('mysql')
            ->table('subscription_line_items')
            ->where('tenant_id', $tenantId)
            ->whereDate('effective_from', '>', PlatformCalendar::today())
            ->where(function ($query): void {
                $query->whereNull('effective_to')->orWhereColumn('effective_to', '>=', 'effective_from');
            })
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->map(fn (object $override): array => [
                'id' => (int) $override->id,
                'name' => (string) $override->description,
                'detail' => 'Starts '.Carbon::parse((string) $override->effective_from)->format('j F Y')
                    .($override->reason === null ? '' : ' · '.$override->reason),
                'badge' => $override->type === 'removal' ? 'removed' : 'added',
                'badgeLabel' => $override->type === 'removal' ? '− Removal' : '+ Addition',
                'price' => ($override->type === 'removal' ? '−' : '')
                    .MoneyFormatter::whole(abs((int) $override->amount_minor), $currency).'/mo',
            ])
            ->all();

        return [
            'client' => (string) $estate->name,
            'baseHead' => 'Base plan · '.$estate->plan_name.', '.number_format($units).' units',
            'base' => $base,
            'additions' => $additions,
            'removals' => $removals,
            'upcoming' => $upcoming,
            'total' => MoneyFormatter::whole($totalMinor, $currency).'/mo',
        ];
    }

    /**
     * The invoice preview's bill: the invoice run's own draft, drawn the way
     * board 44 draws a bill (B076).
     *
     * EVERY FIGURE IS THE DRAFT'S. The rows are its lines, in its order, at its
     * amounts, and the total is its total — the figure the preview's "Raise … at
     * J$X?" quotes and the raise posts. Only the words around the figures come
     * from here: the tier's name and what it includes, and each line item's
     * provenance, read from the rows the draft names by id. So the preview can
     * no longer quote one total while the raise posts another, which it did
     * whenever a line item started or ended inside the period.
     *
     * A line item that takes money off the bill — a removal, or a discount
     * recorded as a negative amount — is drawn under removals with its sign in
     * the price, the way board 44 draws one.
     *
     * @param  Draft  $draft  `InvoiceRun::preview()['draft']`
     * @return array{baseHead: string, base: list<array{name: string, detail: string, badge: string, badgeLabel: string, price: string}>, additions: list<array{name: string, detail: string, badge: string, badgeLabel: string, price: string}>, removals: list<array{name: string, detail: string, badge: string, badgeLabel: string, price: string}>, total: string}
     */
    public function previewBill(array $draft): array
    {
        $currency = $this->currency($draft['currency']);
        $lines = $draft['lines'];

        $overrideIds = [];
        $planIds = [];

        foreach ($lines as $line) {
            if ($line['kind'] === InvoiceRun::KIND_OVERRIDE) {
                $overrideIds[] = $line['source_id'];
            } elseif ($line['kind'] === InvoiceRun::KIND_TIER) {
                $planIds[] = $line['source_id'];
            }
        }

        $overrides = DB::connection('mysql')
            ->table('subscription_line_items')
            ->leftJoin('users', 'users.id', '=', 'subscription_line_items.created_by_user_id')
            ->whereIn('subscription_line_items.id', $overrideIds)
            ->select('subscription_line_items.*', 'users.name as author')
            ->get()
            ->keyBy('id');

        $plans = DB::connection('mysql')->table('plans')->whereIn('id', $planIds)->pluck('name', 'id');

        // Where each line item's feature normally sits, read once for all of them (M11 A2) — as
        // `lineItems()` does, and for the same reason: a query per line item otherwise.
        $tiers = $this->plansIncluding(
            $overrides->pluck('package_feature_id')->filter(static fn (mixed $id): bool => $id !== null)->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all(),
        );

        $baseHead = 'Base plan';
        $base = [];
        $additions = [];
        $removals = [];

        foreach ($lines as $line) {
            if ($line['kind'] === InvoiceRun::KIND_TIER) {
                $plan = (string) ($plans[$line['source_id']] ?? $line['description']);
                $baseHead = 'Base plan · '.$plan.', '.number_format($line['quantity']).' units';

                $base[] = [
                    'name' => $plan.' tier subscription',
                    'detail' => number_format($line['quantity']).' units × '.MoneyFormatter::whole($line['unit_price_minor'], $currency)
                        .'/unit · includes '.$this->includedIn($line['source_id']),
                    'badge' => 'base',
                    'badgeLabel' => 'Base plan',
                    'price' => MoneyFormatter::whole($line['total_minor'], $currency).'/mo',
                ];

                continue;
            }

            if ($line['kind'] === InvoiceRun::KIND_MODULE) {
                $base[] = [
                    'name' => $line['description'],
                    'detail' => number_format($line['quantity']).' guards × '.MoneyFormatter::whole($line['unit_price_minor'], $currency).'/mo',
                    'badge' => 'base',
                    'badgeLabel' => 'Base plan',
                    'price' => MoneyFormatter::whole($line['total_minor'], $currency).'/mo',
                ];

                continue;
            }

            $removal = $line['total_minor'] < 0;
            $override = $overrides->get($line['source_id']);

            $row = [
                'name' => $line['description'],
                'detail' => $override === null ? '' : $this->overrideDetail($override, $removal, $tiers),
                'badge' => $removal ? 'removed' : 'added',
                'badgeLabel' => $removal ? '− Removed' : '+ Added',
                'price' => ($removal ? '−' : '').MoneyFormatter::whole(abs($line['total_minor']), $currency).'/mo',
            ];

            if ($removal) {
                $removals[] = $row;
            } else {
                $additions[] = $row;
            }
        }

        return [
            'baseHead' => $baseHead,
            'base' => $base,
            'additions' => $additions,
            'removals' => $removals,
            'total' => MoneyFormatter::whole($draft['total_minor'], $currency).'/mo',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* pieces */
    /* ------------------------------------------------------------------ */

    /**
     * One override row, as the board draws it.
     *
     * @param  array<int, list<string>>  $tiers  `plansIncluding()` for the board's overrides
     * @return array{name: string, detail: string, badge: string, badgeLabel: string, price: string}
     */
    private function overrideRow(object $override, string $currency, bool $removal, array $tiers): array
    {
        $amount = MoneyFormatter::whole(abs((int) $override->amount_minor), $currency).'/mo';

        return [
            'name' => (string) $override->description,
            'detail' => $this->overrideDetail($override, $removal, $tiers),
            'badge' => $removal ? 'removed' : 'added',
            'badgeLabel' => $removal ? '− Removed' : '+ Added',

            // The row's own id and whether it is still open, so the screen can
            // offer to end it. `effective_to` is set rather than the row
            // deleted — the invoices it was billed on would otherwise be
            // unexplainable.
            'id' => (int) $override->id,
            'open' => $override->effective_to === null,

            // The sign is the board's, and it is doing work: a row under
            // "Custom removals" that read "$0/mo" would look like a charge of
            // nought rather than an amount coming off the bill.
            'price' => ($removal ? '−' : '').$amount,
        ];
    }

    /**
     * The small second line on an override row.
     *
     * Assembled from what is recorded rather than stored as a sentence, so a
     * feature that later moves down a tier stops claiming to be above one.
     *
     * @param  array<int, list<string>>  $tiers  `plansIncluding()` for the board's overrides
     */
    private function overrideDetail(object $override, bool $removal, array $tiers): string
    {
        $when = Carbon::parse((string) $override->effective_from);

        if ($removal) {
            return implode(' · ', array_filter([
                trim($this->tierNote($override, removal: true, tiers: $tiers).', but '
                    .lcfirst((string) ($override->reason ?? 'removed'))),
                $when->format('M Y'),
            ]));
        }

        /*
         * "Normally Premium-only · added Sep 2 by Andrea Case ahead of their
         * upgrade conversation" — where the feature normally sits, then who put
         * it here, when, and why. The author is named because a commercial
         * override with no author is not something anyone can review later.
         */
        return implode(' · ', array_filter([
            $this->tierNote($override, removal: false, tiers: $tiers),
            trim('added '.$when->format('M j')
                .($override->author === null ? '' : ' by '.$override->author)
                .' '.lcfirst((string) ($override->reason ?? ''))),
        ]));
    }

    /**
     * Where this feature normally sits, read off the package template.
     *
     * Derived rather than written down, because "Premium-only" stops being true
     * the moment someone adds the feature to Standard, and a stored sentence
     * would go on saying it.
     *
     * @param  array<int, list<string>>  $tiers  `plansIncluding()` for the board's overrides
     */
    private function tierNote(object $override, bool $removal, array $tiers): string
    {
        if ($override->package_feature_id === null) {
            return $removal ? 'Included in the base plan' : 'Not part of any package';
        }

        $plans = $tiers[(int) $override->package_feature_id] ?? [];

        if ($plans === []) {
            return $removal ? 'Included in the base plan' : 'Not part of any package';
        }

        if ($removal) {
            return 'Included in '.$plans[0];
        }

        return count($plans) === 1
            ? 'Normally '.$plans[0].'-only'
            : 'Normally '.$plans[0].' and above';
    }

    /**
     * The active plans that include each of these features, lowest tier first — what
     * `tierNote()` reads a feature's usual home from, for every override on a board at once.
     *
     * The same question the note used to ask once per override: a plan counts when it is
     * active and its package template marks the feature included, and the plans come in
     * tier (`sort`) order. `plans.id` breaks a tie between two plans given the same sort, so
     * which of them is named first no longer depends on the order MySQL happens to return.
     *
     * @param  list<int>  $featureIds
     * @return array<int, list<string>> plan names by feature id; a feature no active plan includes is absent
     */
    private function plansIncluding(array $featureIds): array
    {
        if ($featureIds === []) {
            return [];
        }

        $plans = [];

        $rows = DB::connection('mysql')
            ->table('plan_features')
            ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
            ->whereIn('plan_features.package_feature_id', $featureIds)
            ->where('plan_features.included', true)
            ->where('plans.is_active', true)
            ->orderBy('plans.sort')
            ->orderBy('plans.id')
            ->select('plan_features.package_feature_id', 'plans.name')
            ->get();

        foreach ($rows as $row) {
            $plans[(int) $row->package_feature_id][] = (string) $row->name;
        }

        return $plans;
    }

    /**
     * "Guard App integration, e-Voting / elections, …" — what this tier adds.
     *
     * The core features are left out on purpose. Every package includes them,
     * so naming them here would pad the line with the four things that are true
     * of every client on every tier and bury the four that are not.
     */
    private function includedIn(int $planId): string
    {
        $names = DB::connection('mysql')
            ->table('plan_features')
            ->join('package_features', 'package_features.id', '=', 'plan_features.package_feature_id')
            ->where('plan_features.plan_id', $planId)
            ->where('plan_features.included', true)
            ->where('package_features.is_core', false)
            ->where('package_features.kind', 'toggle')
            ->orderBy('package_features.sort')
            ->pluck('package_features.label');

        return $names->isEmpty() ? 'the core resident features only' : $names->implode(', ');
    }

    /**
     * One cell of the package grid.
     *
     * @return array{variant: string, value: string|null, emphasised: bool}
     */
    private function cell(object $feature, ?object $row, bool $emphasised): array
    {
        if ($feature->kind === 'tier_value') {
            return [
                'variant' => 'value',
                'value' => $row?->tier_value === null ? '—' : (string) $row->tier_value,
                'emphasised' => $emphasised,
            ];
        }

        /*
         * `locked` is not "on, but darker".
         *
         * A core feature is in every package and no package may drop it, so the
         * cell is drawn as a fact rather than as a setting that happens to be
         * switched on. Rendering it as an ordinary tick would invite someone to
         * reach for the switch that must not exist.
         */
        if ($feature->is_core) {
            return ['variant' => 'locked', 'value' => null, 'emphasised' => $emphasised];
        }

        return [
            'variant' => $row !== null && (bool) $row->included ? 'on' : 'off',
            'value' => null,
            'emphasised' => $emphasised,
        ];
    }

    /**
     * What a role can reach, in one short phrase.
     *
     * Read from role_module_access rather than from the role's name, so a
     * matrix change is reflected here without anyone remembering to edit a
     * label. A role holding Full on every module with no narrowed scope is the
     * only one that gets to be called full access.
     *
     * READ FROM THE CELL'S VERBS, NOT ITS LEVEL (M10 §4). A custom cell reaches
     * its module only if it grants view, and counts toward "Full access" only if
     * it grants everything Full does — whatever it is called.
     */
    /** @param  EloquentCollection<int, RoleModuleAccess>  $cells  every cell the role holds */
    private function accessSummary(EloquentCollection $cells, int $moduleCount): string
    {
        $visible = $cells->filter(fn (RoleModuleAccess $cell): bool => $cell->grantsView());

        $narrowed = $cells->contains(fn (RoleModuleAccess $cell): bool => $cell->scope !== AccessScope::All);

        $full = $visible->count() === $moduleCount
            && $cells->every(fn (RoleModuleAccess $cell): bool => $cell->grantsFull());

        if ($full && ! $narrowed) {
            return 'Full access';
        }

        return $visible->count().' of '.$moduleCount.' modules'.($narrowed ? ', assigned sites' : '');
    }

    /** @return Collection<int, \stdClass> */
    private function platformRates(): Collection
    {
        return DB::connection('mysql')
            ->table('platform_rates')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();
    }

    /** "Applies to 2 clients", and the two cases either side of it. */
    private function clientCount(int $clients): string
    {
        return match (true) {
            $clients === 0 => 'Not in use by any client',
            $clients === 1 => 'Applies to 1 client',
            default => 'Applies to '.$clients.' clients',
        };
    }

    private function currency(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? $currency : MoneyFormatter::DEFAULT_CURRENCY;
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
