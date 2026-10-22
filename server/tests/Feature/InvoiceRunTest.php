<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Gemini\InvoiceActions;
use App\Services\Gemini\InvoiceRun;
use App\Services\Gemini\PlatformLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The invoice run — 13 B1
|--------------------------------------------------------------------------
|
| "Raise client invoice: nothing in app/ writes invoices; only the seeder
| does. Build the invoice run: period, unit count, per-unit price, module
| add-ons, subtotal, total. Posts Dr AR / Cr Service revenue."
|
| A client of its own, inside a transaction, so no other test's invoices move
| the period this one raises.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->client = 'invoiceruntest';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->client,
        'name' => 'Invoice Run Test Estate',
        'receipt_prefix' => 'IRT',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->plan = Plan::query()->create([
        'key' => 'invoiceruntest',
        'name' => 'Run Test',
        'price_per_unit_minor' => 300_00,
        'currency' => 'JMD',
        'min_units' => 1,
        'is_active' => true,
        'sort' => 99,
    ]);

    Subscription::query()->create([
        'tenant_id' => $this->client,
        'plan_id' => $this->plan->id,
        'unit_count' => 40,
        'contracted_guards' => 2,
        'status' => 'active',
        'started_on' => now()->startOfMonth()->toDateString(),
    ]);

    // Only this test's guard rate is active, so the add-on line is known.
    DB::connection('mysql')->table('platform_rates')->update(['is_active' => false]);
    DB::connection('mysql')->table('platform_rates')->insert([
        'key' => 'invoiceruntest_guard',
        'label' => 'Security Provider add-on',
        'applies_to' => 'Per guard deployed',
        'amount_minor' => 4_500_00,
        'currency' => 'JMD',
        'basis' => 'guard',
        'is_active' => true,
        'sort' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([1, 2] as $n) {
        Guard::create([
            'full_name' => 'Run Test Guard '.$n,
            'employee_number' => 'GS-IRT'.$n,
            'psra_number' => 'PSRA-IRT'.$n,
            'psra_expires_on' => now()->addYear()->toDateString(),
            'employment_type' => 'full_time',
            'status' => 'active',
            'tenant_id' => $this->client,
        ]);
    }

    // A discount in force, and a one-off that took effect before this period.
    DB::connection('mysql')->table('subscription_line_items')->insert([
        [
            'tenant_id' => $this->client, 'type' => 'discount', 'description' => 'Pilot discount',
            'reason' => 'Pilot terms', 'amount_minor' => -1_000_00, 'currency' => 'JMD', 'recurrence' => 'monthly',
            'effective_from' => now()->startOfMonth()->toDateString(), 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'tenant_id' => $this->client, 'type' => 'one_off', 'description' => 'Migration fee (last period)',
            'reason' => 'Billed once', 'amount_minor' => 9_999_00, 'currency' => 'JMD', 'recurrence' => 'one_off',
            'effective_from' => now()->subMonths(2)->startOfMonth()->toDateString(), 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('raises the projected period line by line, posts it to the client\'s receivable, and never twice', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $ledger = app(PlatformLedger::class);

    $draft = app(InvoiceRun::class)->draft($this->client);

    // THE LINES ARE THE RECORD: tier, the per-guard add-on, the discount — and
    // not the one-off, which belonged to an earlier period.
    expect(collect($draft['lines'])->map(fn (array $l) => [$l['description'], $l['quantity'], $l['total_minor']])->all())->toBe([
        ['Run Test subscription', 40, 12_000_00],
        ['Security Provider add-on', 2, 9_000_00],
        ['Pilot discount', 1, -1_000_00],
    ])
        ->and($draft['subtotal_minor'])->toBe(20_000_00)
        ->and($draft['tax_minor'])->toBe(0)
        ->and($draft['total_minor'])->toBe(20_000_00)
        ->and($draft['reference'])->toBe('IRT-INV-'.now()->format('Ym'))
        ->and($draft['period_start']->toDateString())->toBe(now()->startOfMonth()->toDateString());

    // The preview offers it to a role that may raise it.
    $this->actingAs($director)
        ->get('/billing/preview/'.$this->client)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canRaise', true)
            ->where('raiseBlockedReason', null));

    // The press carries what its confirmation quoted (B076).
    $this->actingAs($director)
        ->post('/billing/preview/'.$this->client.'/raise', [
            'period_start' => $draft['period_start']->toDateString(),
            'total_minor' => $draft['total_minor'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $invoice = Invoice::query()->where('tenant_id', $this->client)->sole();

    expect($invoice->status)->toBe('issued')
        ->and($invoice->total_minor)->toBe(20_000_00)
        ->and($invoice->subtotal_minor)->toBe(20_000_00)
        ->and((int) $invoice->lines()->sum('total_minor'))->toBe(20_000_00)
        ->and($invoice->journal_ref)->not->toBeNull()
        ->and($ledger->receivableFor($this->client))->toBe(20_000_00);

    // Dr 1100 for this client, Cr 4000 — one balanced entry.
    $lines = DB::connection('mysql')->table('platform_journal_lines as l')
        ->join('platform_accounts as a', 'a.id', '=', 'l.platform_account_id')
        ->where('l.entry_ref', $invoice->journal_ref)
        ->orderBy('l.line_no')
        ->get(['a.code', 'l.debit_minor', 'l.credit_minor', 'l.tenant_id']);

    expect($lines->map(fn ($l) => [$l->code, (int) $l->debit_minor, (int) $l->credit_minor, $l->tenant_id])->all())->toBe([
        [PlatformLedger::RECEIVABLE, 20_000_00, 0, $this->client],
        [PlatformLedger::REVENUE, 0, 20_000_00, null],
    ]);

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'billing.invoice_raised')->where('entity_id', (string) $invoice->id)->exists())->toBeTrue();

    // The next period is a month on, and this one is never raised again.
    $next = app(InvoiceRun::class)->draft($this->client);

    expect($next['period_start']->toDateString())->toBe(now()->startOfMonth()->addMonthNoOverflow()->toDateString());

    // Two months ahead is refused, not numbered.
    app(InvoiceRun::class)->raise($this->client, $director);

    expect(fn () => app(InvoiceRun::class)->raise($this->client, $director))
        ->toThrow(DomainException::class, 'at most a month ahead');
});

it('refuses the raise to a role that reads billing and may not change what a client owes', function () {
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    $this->actingAs($dispatcher)->post('/billing/preview/'.$this->client.'/raise')->assertForbidden();

    if (! $accountant->can('gemini.billing_subscriptions.raise_invoices')) {
        $this->actingAs($accountant)
            ->get('/billing/preview/'.$this->client)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canRaise', false));
    }

    expect(Invoice::query()->where('tenant_id', $this->client)->exists())->toBeFalse();
});

it('posts a credit note against a raised invoice back out of the receivable', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $invoice = app(InvoiceRun::class)->raise($this->client, $director);

    app(InvoiceActions::class)->creditNote($invoice->id, '2500.00', 'One guard was not deployed for a week.', $director);

    $note = DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->sole();

    expect($note->journal_ref)->not->toBeNull()
        ->and(app(PlatformLedger::class)->receivableFor($this->client))->toBe(17_500_00);
});

it('holds Gemini\'s books to double entry in the database: balanced, and never edited', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $invoice = app(InvoiceRun::class)->raise($this->client, $director, Carbon::today());

    $lines = DB::connection('mysql')->table('platform_journal_lines')->where('entry_ref', $invoice->journal_ref);
    $header = DB::connection('mysql')->table('platform_journals')->where('reference', $invoice->journal_ref);

    expect(fn () => (clone $lines)->update(['debit_minor' => 1]))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => (clone $lines)->delete())->toThrow(QueryException::class, 'append-only')
        ->and(fn () => (clone $header)->update(['memo' => 'changed']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => (clone $header)->delete())->toThrow(QueryException::class, 'append-only');

    // An unbalanced entry never gets a header.
    $account = DB::connection('mysql')->table('platform_accounts')->where('code', PlatformLedger::RECEIVABLE)->value('id');

    DB::connection('mysql')->table('platform_journal_lines')->insert([
        ['entry_ref' => 'PROBE-1', 'platform_account_id' => $account, 'line_no' => 1, 'debit_minor' => 100, 'credit_minor' => 0, 'currency' => 'JMD'],
        ['entry_ref' => 'PROBE-1', 'platform_account_id' => $account, 'line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 99, 'currency' => 'JMD'],
    ]);

    expect(fn () => DB::connection('mysql')->table('platform_journals')->insert([
        'reference' => 'PROBE-1', 'memo' => 'probe', 'source' => 'invoice', 'amount_minor' => 100,
        'currency' => 'JMD', 'posted_on' => now()->toDateString(),
    ]))->toThrow(QueryException::class, 'debits must equal credits');
});
