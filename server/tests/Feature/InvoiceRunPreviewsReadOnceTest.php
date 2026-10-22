<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Gemini\InvoiceRun;
use App\Services\Platform\OperatorEstates;
use App\Support\PlatformPricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| Every client's next invoice, drafted from one set of reads — M11 A2
|--------------------------------------------------------------------------
|
| The billing screen quotes each client's next invoice at what the run would raise it at
| (B077), and asked the run one client at a time: eleven queries a client, a hundred and
| forty at twelve clients, eleven more for every client signed. `InvoiceRun::previews()`
| reads every fact a draft is built from for all of them at once, and `preview()` — what
| the preview screen shows and `raise()` posts — is that, for one.
|
| The oracle is the run as it was before M11, written out here line for line: every
| client's draft and refusal must be exactly what it gave, across every case its arithmetic
| has — first invoice with and without a start date, a latest invoice and its due day, a
| period more than a month away, a period already invoiced, a tier price and a guard rate
| changed before and after the period, guards on leave and suspended, no guards, and line
| items that start, end, were withdrawn, are one-off, are nought, or belong to another
| client — and every client there is nothing to invoice for must be refused in its words.
|
*/

beforeEach(function (): void {
    DB::connection('mysql')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/**
 * `InvoiceRun::preview()` exactly as it stood before M11 A2 (a342c56), one client at a time.
 *
 * @return array{draft: array<string, mixed>, refusal: string|null}
 */
function previewByTheOldRun(string $tenantId, Carbon $today): array
{
    $today = $today->copy()->startOfDay();

    if (OperatorEstates::isDemonstration($tenantId)) {
        throw new DomainException(
            'That is the demonstration estate the app stores\' reviewers sign in to. It is not a client, '.
            'it is never invoiced, and nothing it does belongs in the platform\'s books.'
        );
    }

    $subscription = Subscription::query()->with(['plan', 'estate'])->where('tenant_id', $tenantId)->whereIn('status', ['active', 'dunning'])->first();

    if ($subscription === null) {
        throw new DomainException('This client has no billing subscription, so there is no period to invoice.');
    }

    $latest = Invoice::query()->where('tenant_id', $tenantId)->orderByDesc('period_start')->first();

    $start = $latest !== null
        ? $latest->period_end->copy()->addDay()->startOfDay()
        : ($subscription->started_on?->copy()->startOfMonth() ?? $today->copy()->startOfMonth());
    $end = $start->copy()->endOfMonth()->startOfDay();

    $refusal = null;

    if ($start->gt($today->copy()->startOfMonth()->addMonthNoOverflow())) {
        $refusal = sprintf('%s is the next period to invoice, and it is more than a month away. A period is billed at most a month ahead.', $start->format('F Y'));
    } elseif (Invoice::query()->where('tenant_id', $tenantId)->whereDate('period_start', $start)->exists()) {
        $refusal = 'This client already has an invoice for '.$start->format('F Y').'. A correction is a credit note, not a second invoice.';
    }

    $line = static fn (string $description, int $quantity, int $unit, string $kind, int $source): array => [
        'description' => $description, 'quantity' => $quantity, 'unit_price_minor' => $unit, 'total_minor' => $quantity * $unit, 'kind' => $kind, 'source_id' => $source,
    ];

    $lines = [$line(
        $subscription->plan->name.' subscription',
        (int) $subscription->unit_count,
        PlatformPricing::planPriceMinorOn((int) $subscription->plan->id, (int) $subscription->plan->price_per_unit_minor, $start),
        InvoiceRun::KIND_TIER,
        (int) $subscription->plan->id,
    )];

    $guards = DB::connection('mysql')->table('guards')->where('tenant_id', $tenantId)->whereNotIn('status', ['on_leave', 'suspended'])->count();
    $rates = DB::connection('mysql')->table('platform_rates')->where('is_active', true)->where('basis', 'guard')->orderBy('sort')->get();

    foreach ($rates as $rate) {
        $perGuard = PlatformPricing::rateMinorOn((int) $rate->id, (int) $rate->amount_minor, $start);

        if ($guards > 0 && $perGuard > 0) {
            $lines[] = $line((string) $rate->label, $guards, $perGuard, InvoiceRun::KIND_MODULE, (int) $rate->id);
        }
    }

    $overrides = DB::connection('mysql')->table('subscription_line_items')
        ->where('tenant_id', $tenantId)
        ->whereDate('effective_from', '<=', $end)
        ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
        ->where(fn ($query) => $query->whereNull('effective_to')->orWhereColumn('effective_to', '>=', 'effective_from'))
        ->orderBy('effective_from')
        ->orderBy('id')
        ->get();

    foreach ($overrides as $override) {
        $amount = (int) $override->amount_minor;

        if ($override->recurrence === 'one_off' && Carbon::parse((string) $override->effective_from)->lt($start)) {
            continue;
        }

        $signed = $override->type === 'removal' ? -abs($amount) : $amount;

        if ($signed !== 0) {
            $lines[] = $line((string) $override->description, 1, $signed, InvoiceRun::KIND_OVERRIDE, (int) $override->id);
        }
    }

    $subtotal = array_sum(array_column($lines, 'total_minor'));
    $estate = $subscription->estate;

    if ($latest !== null) {
        $month = $start->copy()->startOfMonth();
        $due = $month->addDays(min($latest->due_on->day, $month->daysInMonth) - 1);
    } else {
        $due = $start->copy()->addDays(14);
    }

    return [
        'draft' => [
            'tenant_id' => $tenantId,
            'estate' => $estate->name ?? $tenantId,
            'period' => $start->format('M Y'),
            'period_start' => $start,
            'period_end' => $end,
            'due_on' => $due,
            'reference' => ($estate->receipt_prefix ?? strtoupper(substr($tenantId, 0, 6))).'-INV-'.$start->format('Ym'),
            'currency' => (string) $subscription->plan->currency,
            'lines' => $lines,
            'subtotal_minor' => $subtotal,
            'tax_minor' => 0,
            'total_minor' => $subtotal,
        ],
        'refusal' => $refusal,
    ];
}

/** A draft with its dates written out, so two can be compared value for value. */
function comparableDraft(array $preview): array
{
    $draft = $preview['draft'];

    foreach (['period_start', 'period_end', 'due_on'] as $date) {
        $draft[$date] = $draft[$date]->toIso8601String();
    }

    return ['draft' => $draft, 'refusal' => $preview['refusal']];
}

/**
 * Clients covering every case the run's arithmetic has, around one "today".
 *
 * @return array{today: Carbon, clients: array<string, string>, other: string}
 */
function invoiceRunWorld(): array
{
    $db = DB::connection('mysql');
    $now = Carbon::now();
    $today = Carbon::parse('2026-09-25');
    $tag = (string) random_int(100000, 999999);

    $plan = static fn (string $name, int $price, int $sort): int => (int) $db->table('plans')->insertGetId([
        'key' => 'm11run'.$tag.$sort, 'name' => $name.' '.$tag, 'price_per_unit_minor' => $price, 'currency' => 'JMD',
        'min_units' => 10, 'is_active' => true, 'sort' => 800 + $sort, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $standard = $plan('Standard', 340_00, 1);
    $premium = $plan('Premium', 420_00, 2);

    // The premium tier was repriced: J$400 until 1 October, J$420 from it — so September and
    // October bill at different prices, and a period before the history began at its first `from`.
    foreach ([['2026-08-01', 380_00, 400_00], ['2026-10-01', 400_00, 420_00]] as [$from, $was, $is]) {
        $db->table('plan_price_changes')->insert([
            'plan_id' => $premium, 'from_minor' => $was, 'to_minor' => $is, 'currency' => 'JMD', 'effective_from' => $from,
            'reason' => 'Volume', 'changed_by_name' => 'Test', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // A second per-guard rate of this test's own, repriced from 1 October; the platform's own
    // per-guard rate, whatever it is, is on every draft beside it.
    $rate = (int) $db->table('platform_rates')->insertGetId([
        'key' => 'm11rate'.$tag, 'label' => 'Night patrol '.$tag, 'applies_to' => 'Per guard', 'amount_minor' => 1_200_00,
        'currency' => 'JMD', 'basis' => 'guard', 'is_active' => true, 'sort' => 50, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $db->table('platform_rate_changes')->insert([
        'platform_rate_id' => $rate, 'from_minor' => 1_000_00, 'to_minor' => 1_200_00, 'currency' => 'JMD', 'effective_from' => '2026-10-01',
        'reason' => 'Volume', 'changed_by_name' => 'Test', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $clients = [];

    $client = static function (string $label, ?int $planId, string $status, ?string $startedOn, bool $demo = false, ?string $prefix = null) use ($db, $now, $tag, &$clients): string {
        $id = 'm11run'.$tag.substr(md5($label), 0, 6);
        $clients[$label] = $id;

        $db->table('tenants')->insert([
            'id' => $id, 'name' => 'Run Estate '.$tag.' '.$label, 'status' => 'active', 'demo' => $demo ? 1 : 0, 'data' => '{}',
            'receipt_prefix' => $prefix, 'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($planId !== null) {
            $db->table('subscriptions')->insert([
                'tenant_id' => $id, 'plan_id' => $planId, 'unit_count' => 100 + strlen($label), 'contracted_guards' => 2, 'status' => $status,
                'started_on' => $startedOn, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $id;
    };

    $invoice = static function (string $tenant, string $start, string $end, string $due, string $reference) use ($db, $now): void {
        $db->table('invoices')->insert([
            'tenant_id' => $tenant, 'reference' => $reference, 'period' => Carbon::parse($start)->format('M Y'),
            'period_start' => $start, 'period_end' => $end, 'subtotal_minor' => 10_000_00, 'total_minor' => 10_000_00, 'currency' => 'JMD',
            'due_on' => $due, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now,
        ]);
    };

    $guard = static function (string $tenant, string $status) use ($db, $now): void {
        $n = random_int(100000, 999999);
        $db->table('guards')->insert([
            'full_name' => 'Run Officer '.$n, 'employee_number' => 'GS-RUN-'.$n, 'psra_number' => 'PSRA-RUN-'.$n,
            'employment_type' => 'full_time', 'status' => $status, 'tenant_id' => $tenant, 'reviewer' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };

    $item = static function (string $tenant, string $type, int $amount, string $from, ?string $to, string $recurrence = 'monthly') use ($db, $now): void {
        $db->table('subscription_line_items')->insert([
            'tenant_id' => $tenant, 'type' => $type, 'description' => ucfirst($type).' '.$from.' '.($to ?? 'open').' '.$recurrence.' '.$amount,
            'amount_minor' => $amount, 'currency' => 'JMD', 'recurrence' => $recurrence, 'effective_from' => $from, 'effective_to' => $to,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };

    // First invoice, dated by the subscription's start; and first invoice with no start at all.
    $firstDated = $client('first dated', $standard, 'active', '2026-09-12', prefix: 'F'.substr($tag, 0, 5));
    $client('first undated', $premium, 'dunning', null);

    // A history: the latest invoice sets the period and the due day (the 31st, clamped to the 30th).
    $history = $client('history', $premium, 'active', '2025-01-01', prefix: 'H'.substr($tag, 0, 5));
    $invoice($history, '2026-07-01', '2026-07-31', '2026-07-31', 'HI'.$tag.'-A');
    $invoice($history, '2026-08-01', '2026-08-31', '2026-08-31', 'HI'.$tag.'-B');

    // October next: the repriced tier and rate, and line items that start, end and were withdrawn around it.
    $october = $client('october', $premium, 'active', '2025-01-01');
    $invoice($october, '2026-08-01', '2026-08-31', '2026-08-30', 'OC'.$tag.'-A');
    $invoice($october, '2026-09-01', '2026-09-30', '2026-09-30', 'OC'.$tag.'-B');

    // A period more than a month away.
    $ahead = $client('ahead', $standard, 'active', '2025-01-01');
    $invoice($ahead, '2026-11-01', '2026-11-30', '2026-11-15', 'AH'.$tag.'-A');

    // A period already invoiced: a row whose own dates put its successor on a month that is taken.
    $taken = $client('taken', $standard, 'active', '2025-01-01');
    $invoice($taken, '2026-09-01', '2026-08-31', '2026-09-10', 'TK'.$tag.'-A');

    // Guards: two on duty, one on leave, one suspended, one inactive — "deployed" is not on leave or suspended.
    foreach (['active', 'active', 'on_leave', 'suspended', 'inactive'] as $status) {
        $guard($october, $status);
    }
    $guard($history, 'active');
    $guard($firstDated, 'active');

    // Line items on the October client.
    $item($october, 'addition', 2_500_00, '2026-06-01', null);
    $item($october, 'removal', 1_000_00, '2026-10-15', null);
    $item($october, 'addition', 3_000_00, '2026-10-01', '2026-10-31', 'one_off');
    $item($october, 'addition', 4_000_00, '2026-09-01', null, 'one_off');
    $item($october, 'addition', 5_000_00, '2026-06-01', '2026-09-30');
    $item($october, 'addition', 6_000_00, '2026-10-20', '2026-10-05');
    $item($october, 'addition', 7_000_00, '2026-11-01', null);
    $item($october, 'addition', 0, '2026-10-01', null);
    $item($october, 'removal', -800_00, '2026-10-01', null);

    // And on another client, which must not reach the October client's draft.
    $item($firstDated, 'addition', 9_900_00, '2026-09-01', null);

    // Nothing to invoice: the demonstration estate, a client on no plan, a cancelled one.
    $client('demonstration', $standard, 'active', '2025-01-01', demo: true);
    $client('no subscription', null, 'active', null);
    $client('cancelled', $standard, 'cancelled', '2025-01-01');

    return ['today' => $today, 'clients' => $clients, 'other' => $firstDated];
}

it('drafts every client\'s next invoice exactly as the run did one client at a time, and refuses the same clients in the same words', function (): void {
    $w = invoiceRunWorld();
    $run = app(InvoiceRun::class);

    $answer = $run->previews(array_values($w['clients']), $w['today']);

    foreach ($w['clients'] as $label => $id) {
        try {
            $old = previewByTheOldRun($id, $w['today']);
        } catch (DomainException $refused) {
            expect($answer['unbillable'][$id] ?? null)->toBe($refused->getMessage(), "{$label} was not refused as the run refused it")
                ->and($answer['previews'])->not->toHaveKey($id);

            // And `preview()` refuses it with the same sentence.
            expect(fn () => $run->preview($id, $w['today']))->toThrow(DomainException::class, $refused->getMessage());

            continue;
        }

        expect(comparableDraft($answer['previews'][$id]))->toBe(comparableDraft($old), "{$label}'s draft moved")
            ->and(comparableDraft($run->preview($id, $w['today'])))->toBe(comparableDraft($old), "{$label}'s single preview moved");
    }

    // The comparison is only a proof if every case happened.
    $p = $answer['previews'];
    $history = $p[$w['clients']['history']]['draft'];
    $october = $p[$w['clients']['october']]['draft'];
    $tier = static fn (array $draft): int => collect($draft['lines'])->firstWhere('kind', InvoiceRun::KIND_TIER)['unit_price_minor'];

    expect(array_keys($answer['unbillable']))->toEqualCanonicalizing([$w['clients']['demonstration'], $w['clients']['no subscription'], $w['clients']['cancelled']])
        ->and($p[$w['clients']['first dated']]['draft']['period_start']->toDateString())->toBe('2026-09-01')
        ->and($p[$w['clients']['first dated']]['draft']['due_on']->toDateString())->toBe('2026-09-15')
        ->and($p[$w['clients']['first undated']]['draft']['period_start']->toDateString())->toBe('2026-09-01')
        ->and($history['period_start']->toDateString())->toBe('2026-09-01')
        ->and($history['due_on']->toDateString())->toBe('2026-09-30')
        ->and($october['period_start']->toDateString())->toBe('2026-10-01')
        ->and($october['due_on']->toDateString())->toBe('2026-10-30')
        // September at J$400 and October at J$420: the price in force on each period's first day.
        ->and($tier($history))->toBe(400_00)
        ->and($tier($october))->toBe(420_00)
        ->and($p[$w['clients']['ahead']]['refusal'])->toContain('more than a month away')
        ->and($p[$w['clients']['taken']]['refusal'])->toContain('already has an invoice')
        ->and($p[$w['clients']['october']]['refusal'])->toBeNull()
        // Two of the five officers are on leave or suspended.
        ->and(collect($october['lines'])->where('kind', InvoiceRun::KIND_MODULE)->pluck('quantity')->unique()->values()->all())->toBe([3])
        // The line items in force during October, and only they: 2,500, −1,000, the October one-off and −800.
        ->and(collect($october['lines'])->where('kind', InvoiceRun::KIND_OVERRIDE)->pluck('total_minor')->all())->toBe([2_500_00, 3_000_00, -800_00, -1_000_00])
        // The other client's line item stayed on the other client's draft.
        ->and(collect($october['lines'])->pluck('description')->filter(static fn (string $d): bool => str_contains($d, '990000'))->all())->toBe([])
        ->and(collect($p[$w['other']]['draft']['lines'])->pluck('description')->filter(static fn (string $d): bool => str_contains($d, '990000'))->count())->toBe(1);
});

it('drafts October the same way too, where the tier price, the guard rate and the line items change', function (): void {
    $w = invoiceRunWorld();
    $today = Carbon::parse('2026-10-03');

    $answer = app(InvoiceRun::class)->previews(array_values($w['clients']), $today);
    $billed = 0;

    foreach ($w['clients'] as $label => $id) {
        if (isset($answer['unbillable'][$id])) {
            continue;
        }

        expect(comparableDraft($answer['previews'][$id]))->toBe(comparableDraft(previewByTheOldRun($id, $today)), "{$label}'s October draft moved");
        $billed++;
    }

    expect($billed)->toBe(6);
});

it('asks the same number of queries for twenty-four clients as for twelve, and one client\'s preview no more than it did', function (): void {
    $run = app(InvoiceRun::class);
    $today = Carbon::parse('2026-09-25');

    $clients = [];
    $grow = static function (int $count) use (&$clients): void {
        for ($n = 0; $n < $count; $n++) {
            $clients = [...$clients, ...array_values(invoiceRunWorld()['clients'])];
        }
    };

    $grow(1);
    $twelve = QueryBudget::measure(fn () => $run->previews($clients, $today));

    $grow(1);
    $twentyFour = QueryBudget::measure(fn () => $run->previews($clients, $today));

    // One client, through `preview()`: what the old run asked for it, and no more.
    $one = $clients[array_key_first($clients)];
    $single = QueryBudget::measure(fn () => $run->preview($one, $today));
    $old = QueryBudget::measure(fn () => previewByTheOldRun($one, $today));

    expect(count($twentyFour['result']['previews']))->toBe(2 * count($twelve['result']['previews']))
        ->and($twentyFour['count'])->toBe($twelve['count'])
        ->and(QueryBudget::worstRepeat($twentyFour))->toBe(1)
        ->and($single['count'])->toBeLessThanOrEqual($old['count']);
});
