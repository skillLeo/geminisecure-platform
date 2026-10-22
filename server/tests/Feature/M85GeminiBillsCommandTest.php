<?php

declare(strict_types=1);

use App\Models\Estate\Bill;
use App\Models\Estate\Vendor;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| billing:estate-bills: the draft bills for the invoices Gemini issued before EST-S-05 (M85 A4)
|--------------------------------------------------------------------------
|
| From EST-S-05 on, raising an invoice drafts its bill in the estate (M85GeminiDraftBillTest). The invoices issued before
| it never did, so 5050 still reads nothing for them. This drafts each one's bill once, as the raise would; it is a dry
| run unless told --confirm, and it is run on production by a person who has read the dry run.
|
| Both databases are rolled back, the fixture estate opened first so the command finds it open (see that file).
|
*/

beforeEach(function (): void {
    $estate = FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    tenancy()->initialize($estate);

    DB::connection('tenant')->beginTransaction();

    $invoice = static fn (string $reference, string $period, string $start, string $status): Invoice => Invoice::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'reference' => $reference, 'period' => $period,
        'period_start' => $start, 'period_end' => date('Y-m-t', (int) strtotime($start)),
        'subtotal_minor' => 684_000_00, 'total_minor' => 684_000_00, 'currency' => 'JMD',
        'due_on' => date('Y-m-15', (int) strtotime($start)), 'status' => $status,
    ]);

    $this->paid = $invoice('FXT-INV-M85C-0826', 'Aug 2026', '2026-08-01', 'paid');
    $this->issued = $invoice('FXT-INV-M85C-0926', 'Sep 2026', '2026-09-01', 'issued');
});

afterEach(function (): void {
    DB::connection('tenant')->rollBack();
    DB::connection('mysql')->rollBack();
    tenancy()->end();
});

it('lists what it would draft and writes nothing without --confirm', function (): void {
    expect(Artisan::call('billing:estate-bills', ['--estate' => FacilitiesFixture::ESTATE]))->toBe(0);
    $dry = Artisan::output();

    expect(str_contains($dry, 'FXT-INV-M85C-0926'))->toBeTrue()
        ->and(str_contains($dry, 'would draft'))->toBeTrue()
        ->and(str_contains($dry, 'FXT-INV-M85C-0826'))->toBeTrue()
        ->and(str_contains($dry, 'paid, not drafted'))->toBeTrue()
        ->and(str_contains($dry, 'Nothing is written without --confirm'))->toBeTrue()
        ->and(Bill::query()->whereIn('platform_invoice_id', [$this->paid->id, $this->issued->id])->exists())->toBeFalse()
        ->and(Vendor::query()->where('name', 'Gemini Security Limited')->exists())->toBeFalse();
});

it('drafts each issued invoice\'s bill once with --confirm, says so on the audit log, and finds them drafted the next time', function (): void {
    expect(Artisan::call('billing:estate-bills', ['--estate' => FacilitiesFixture::ESTATE, '--confirm' => true]))->toBe(0);

    $bill = Bill::query()->where('platform_invoice_id', $this->issued->id)->sole();

    expect($bill->status)->toBe(Bill::DRAFT)
        ->and($bill->amount_minor)->toBe(684_000_00)
        ->and($bill->vendor->name)->toBe('Gemini Security Limited')
        ->and(Bill::query()->where('platform_invoice_id', $this->paid->id)->exists())->toBeFalse()
        ->and(DB::connection('mysql')->table('audit_log')
            ->where('action', 'billing.estate_bill_drafted')
            ->where('entity_id', (string) $this->issued->id)
            ->where('tenant_id', FacilitiesFixture::ESTATE)
            ->exists())->toBeTrue();

    expect(Artisan::call('billing:estate-bills', ['--estate' => FacilitiesFixture::ESTATE, '--confirm' => true]))->toBe(0);
    $again = Artisan::output();

    expect(str_contains($again, 'drafted already'))->toBeTrue()
        ->and(str_contains($again, 'Nothing to draft'))->toBeTrue()
        ->and(Bill::query()->where('platform_invoice_id', $this->issued->id)->count())->toBe(1);
});

it('refuses an estate it does not know, and changes nothing', function (): void {
    expect(Artisan::call('billing:estate-bills', ['--estate' => 'no-such-estate', '--confirm' => true]))->not->toBe(0)
        ->and(str_contains(Artisan::output(), 'Nothing was changed'))->toBeTrue();
});
