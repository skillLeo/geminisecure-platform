<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\Bill;
use App\Models\Estate\Journal;
use App\Models\Estate\Vendor;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Estate\GeminiBills;
use App\Services\Gemini\InvoiceRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Gemini is the estate's vendor, and each invoice it issues is a draft bill to 5050 (EST-S-05, M85 A4)
|--------------------------------------------------------------------------
|
| M82 answer 2: "Gemini becomes a default vendor and each issued invoice raises a DRAFT bill to account 5050. Draft,
| never posted automatically, so a person always approves it before it reaches the books." Account 5050 read $0 for the
| year while Gemini had invoiced the estate about $6.8M (M81 §3.4).
|
| Both databases are rolled back: the central one, and the shared fixture estate's, which is opened first the way a
| request opens it, so the bill is drafted inside this file's transaction.
|
*/

beforeEach(function (): void {
    $estate = FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    // Before the estate's transaction begins: asked for a user, the fixture re-points its estate connection, and a
    // transaction open on it then is dropped and everything after it is committed to the shared estate.
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    tenancy()->initialize($estate);

    DB::connection('tenant')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('tenant')->rollBack();
    DB::connection('mysql')->rollBack();
    tenancy()->end();
});

/** An invoice Gemini has issued to an estate, as the run stores one. */
function m85IssuedInvoice(string $tenantId, string $reference, int $totalMinor, string $status = 'issued'): Invoice
{
    return Invoice::query()->create([
        'tenant_id' => $tenantId,
        'reference' => $reference,
        'period' => 'Oct 2026',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'subtotal_minor' => $totalMinor,
        'total_minor' => $totalMinor,
        'currency' => 'JMD',
        'due_on' => '2026-10-15',
        'status' => $status,
    ]);
}

it('drafts one bill to 5050 against Gemini Security Limited for an issued invoice, posts nothing, and never twice', function (): void {
    $journals = Journal::query()->count();
    $invoice = m85IssuedInvoice(FacilitiesFixture::ESTATE, 'FXT-INV-M85A4', 684_000_00);

    $bill = app(GeminiBills::class)->draftFor($invoice);

    $gemini = Vendor::query()->where('name', 'Gemini Security Limited')->sole();

    expect($bill)->toBeInstanceOf(Bill::class)
        ->and($bill->status)->toBe(Bill::DRAFT)
        ->and($bill->vendor_id)->toBe($gemini->id)
        ->and($bill->account_id)->toBe(Account::query()->where('code', '5050')->value('id'))
        ->and($bill->amount_minor)->toBe(684_000_00)
        ->and($bill->currency)->toBe('JMD')
        ->and($bill->due_on->toDateString())->toBe('2026-10-15')
        ->and($bill->description)->toBe('Security services, Oct 2026 (Gemini invoice FXT-INV-M85A4)')
        ->and($bill->journal_ref)->toBeNull()
        ->and((int) $bill->platform_invoice_id)->toBe($invoice->id)
        ->and($gemini->status)->toBe(Vendor::ACTIVE)
        ->and($gemini->category)->toBe('Security services')
        // A draft is not a liability: nothing reaches the books until a person approves it.
        ->and(Journal::query()->count())->toBe($journals);

    // Asked again (a retry, or the command after the raise), it is the same bill, and the vendor is still one row.
    $again = app(GeminiBills::class)->draftFor($invoice);
    $next = app(GeminiBills::class)->draftFor(m85IssuedInvoice(FacilitiesFixture::ESTATE, 'FXT-INV-M85A4-NOV', 690_000_00));

    expect($again->id)->toBe($bill->id)
        ->and(Bill::query()->where('platform_invoice_id', $invoice->id)->count())->toBe(1)
        ->and($next->vendor_id)->toBe($gemini->id)
        ->and(Vendor::query()->whereRaw('LOWER(name) = ?', ['gemini security limited'])->count())->toBe(1);
});

it('drafts nothing for an invoice that asks for nothing: not issued, or credited to nothing; a part credit drafts what is left', function (): void {
    $paid = m85IssuedInvoice(FacilitiesFixture::ESTATE, 'FXT-INV-M85A4-PAID', 100_000_00, 'paid');
    $credited = m85IssuedInvoice(FacilitiesFixture::ESTATE, 'FXT-INV-M85A4-CRED', 100_000_00);
    $part = m85IssuedInvoice(FacilitiesFixture::ESTATE, 'FXT-INV-M85A4-PART', 100_000_00);

    foreach ([[$credited, 100_000_00], [$part, 25_000_00]] as [$invoice, $minor]) {
        DB::connection('mysql')->table('credit_notes')->insert([
            'tenant_id' => FacilitiesFixture::ESTATE, 'invoice_id' => $invoice->id, 'reference' => $invoice->reference.'-CN1',
            'amount_minor' => $minor, 'currency' => 'JMD', 'reason' => 'M85 A4 test', 'issued_by_name' => 'Test',
            'issued_on' => '2026-10-02', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(app(GeminiBills::class)->draftFor($paid))->toBeNull()
        ->and(app(GeminiBills::class)->draftFor($credited))->toBeNull()
        ->and(app(GeminiBills::class)->draftFor($part)?->amount_minor)->toBe(75_000_00)
        ->and(Bill::query()->whereIn('platform_invoice_id', [$paid->id, $credited->id])->exists())->toBeFalse();
});

it('drafts the bill when the invoice run raises the invoice, and says so on the audit log', function (): void {
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'America/Jamaica'));

    $plan = Plan::query()->create([
        'key' => 'm85a4', 'name' => 'M85 A4', 'price_per_unit_minor' => 300_00, 'currency' => 'JMD',
        'min_units' => 1, 'is_active' => true, 'sort' => 98,
    ]);

    Subscription::query()->updateOrCreate(['tenant_id' => FacilitiesFixture::ESTATE], [
        'plan_id' => $plan->id, 'unit_count' => 40, 'contracted_guards' => 0, 'status' => 'active', 'started_on' => '2026-10-01',
    ]);

    // Only the tier: no per-guard rate is in force in this transaction.
    DB::connection('mysql')->table('platform_rates')->update(['is_active' => false]);

    $invoice = app(InvoiceRun::class)->raise(FacilitiesFixture::ESTATE, $this->director);

    $bill = Bill::query()->where('platform_invoice_id', $invoice->id)->sole();

    expect($invoice->status)->toBe('issued')
        ->and($bill->status)->toBe(Bill::DRAFT)
        ->and($bill->amount_minor)->toBe($invoice->total_minor)
        ->and($bill->account_id)->toBe(Account::query()->where('code', '5050')->value('id'))
        ->and($bill->vendor->name)->toBe('Gemini Security Limited')
        ->and(tenant()?->getTenantKey())->toBe(FacilitiesFixture::ESTATE)
        ->and(DB::connection('mysql')->table('audit_log')
            ->where('action', 'billing.estate_bill_drafted')
            ->where('entity_id', (string) $invoice->id)
            ->where('tenant_id', FacilitiesFixture::ESTATE)
            ->exists())->toBeTrue();
});

it('drafts only in the invoice\'s own estate: one it cannot open gets nothing, the open one keeps nothing, and the raise stands', function (): void {
    DB::connection('mysql')->table('tenants')->insert([
        'id' => 'm85a4elsewhere', 'name' => 'Elsewhere Test Estate', 'receipt_prefix' => 'MAE', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'key' => 'm85a4elsewhere', 'name' => 'M85 A4 Elsewhere', 'price_per_unit_minor' => 300_00, 'currency' => 'JMD',
        'min_units' => 1, 'is_active' => true, 'sort' => 97,
    ]);

    Subscription::query()->create([
        'tenant_id' => 'm85a4elsewhere', 'plan_id' => $plan->id, 'unit_count' => 10, 'contracted_guards' => 0,
        'status' => 'active', 'started_on' => now()->startOfMonth()->toDateString(),
    ]);

    DB::connection('mysql')->table('platform_rates')->update(['is_active' => false]);

    // That estate has no database here: drafting into it fails, and the estate that was open is open again. (A class,
    // not Throwable: Pest reads an interface's name as a message to look for.)
    expect(fn () => app(GeminiBills::class)->draftFor(m85IssuedInvoice('m85a4elsewhere', 'MAE-INV-M85A4', 50_000_00)))
        ->toThrow(Exception::class);

    expect(tenant()?->getTenantKey())->toBe(FacilitiesFixture::ESTATE);

    // The raise is Gemini's own act and is not undone by the estate's side failing; the failure is on the audit log.
    $invoice = app(InvoiceRun::class)->raise('m85a4elsewhere', $this->director);

    expect($invoice->status)->toBe('issued')
        ->and(tenant()?->getTenantKey())->toBe(FacilitiesFixture::ESTATE)
        ->and(DB::connection('mysql')->table('audit_log')
            ->where('action', 'billing.estate_bill_not_drafted')
            ->where('entity_id', (string) $invoice->id)
            ->where('tenant_id', 'm85a4elsewhere')
            ->exists())->toBeTrue()
        // Nothing of either invoice reached the estate that was open.
        ->and(Bill::query()->where('description', 'like', '%MAE-INV-%')->exists())->toBeFalse()
        ->and(Vendor::query()->where('name', 'Gemini Security Limited')->exists())->toBeFalse();
});
