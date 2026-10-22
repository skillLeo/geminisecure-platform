<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Role;
use App\Services\Gemini\BillingOverview;
use App\Services\Gemini\ClientDirectory;
use App\Services\Gemini\InvoiceActions;
use App\Services\Gemini\PlatformNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A credited invoice is only overdue by what it still asks for (B080)
|--------------------------------------------------------------------------
|
| A credit note never touches the invoice — its total is what was charged, and
| what it is worth now is that total less its credit notes. But every overdue
| reading looked at the status and the due date alone, and recording a payment
| is not built (D-093), so a fully credited invoice read "Overdue $171,000" on
| the billing KPI, "Overdue" on its row and on the client's record, and "Invoice
| past due" in the bell, for ever — with the client's 1100 at zero.
|
| Every other issued invoice is marked paid inside this file's transaction, so
| the platform-wide figures below are about this client's invoice alone.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'America/Jamaica'));

    DB::connection('mysql')->table('invoices')->whereNotIn('status', ['paid', 'void'])->update(['status' => 'paid']);

    $this->client = 'creditedtest';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->client,
        'name' => 'Credited Test Estate',
        'receipt_prefix' => 'CRT',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Raised in error; fell due on 15 October.
    $this->invoice = Invoice::query()->create([
        'tenant_id' => $this->client,
        'reference' => 'CRT-INV-202609',
        'period' => 'Sep 2026',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'subtotal_minor' => 171_000_00,
        'total_minor' => 171_000_00,
        'currency' => 'JMD',
        'due_on' => '2026-10-15',
        'status' => 'issued',
    ]);

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** Everything that says whether this invoice is overdue, read the way each screen reads it. */
function overdueReadings(object $test): array
{
    $kpi = collect(app(BillingOverview::class)->kpis())->firstWhere('key', 'overdue');
    $row = collect(app(BillingOverview::class)->invoiceRows())->firstWhere('key', 'invoice-'.$test->invoice->id);
    $status = collect(app(ClientDirectory::class)->detail($test->client)['stats'])->firstWhere('label', 'Billing status');
    $bell = collect(app(PlatformNotifications::class)->attention($test->director)['items'])->pluck('key');

    return [
        'kpi' => $kpi['value'],
        'kpi_alert' => $kpi['alert'],
        'row' => $row['status_label'],
        'badge' => $row['status'],
        'model' => $test->invoice->fresh()->isOverdue(),
        'client' => $status['value'],
        'bell' => $bell->contains('invoice:'.$test->invoice->id),
    ];
}

it('reads an unpaid invoice past its due date as overdue, the premise', function () {
    expect(overdueReadings($this))->toBe([
        'kpi' => '$171,000',
        'kpi_alert' => true,
        'row' => 'Overdue',
        'badge' => 'overdue',
        'model' => true,
        'client' => 'Overdue',
        'bell' => true,
    ]);
});

it('stops calling an invoice overdue once credit notes have taken it to nothing', function () {
    app(InvoiceActions::class)->creditNote($this->invoice->id, '171000.00', 'Raised in error', $this->director);

    expect(overdueReadings($this))->toBe([
        'kpi' => '$0',
        'kpi_alert' => false,
        'row' => 'Credited',
        'badge' => 'ok',
        'model' => false,
        'client' => 'Current',
        'bell' => false,
    ]);
});

it('counts only what a partly credited invoice still asks for', function () {
    app(InvoiceActions::class)->creditNote($this->invoice->id, '21000.00', 'Two guard-months not worked', $this->director);

    expect(overdueReadings($this))->toBe([
        'kpi' => '$150,000',
        'kpi_alert' => true,
        'row' => 'Overdue',
        'badge' => 'overdue',
        'model' => true,
        'client' => 'Overdue',
        'bell' => true,
    ]);
});

it('does not call a fully credited invoice due before its date either', function () {
    $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'America/Jamaica'));

    app(InvoiceActions::class)->creditNote($this->invoice->id, '171000.00', 'Raised in error', $this->director);

    $row = collect(app(BillingOverview::class)->invoiceRows())->firstWhere('key', 'invoice-'.$this->invoice->id);
    $status = collect(app(ClientDirectory::class)->detail($this->client)['stats'])->firstWhere('label', 'Billing status');

    expect($row['status_label'])->toBe('Credited')
        ->and($status['value'])->toBe('Current');
});

it('says on the invoice itself that nothing is due once it is credited in full, and still gives the date when it is not', function () {
    $overview = app(BillingOverview::class);

    // Part credited: still owed, so still due on its date.
    app(InvoiceActions::class)->creditNote($this->invoice->id, '21000.00', 'Two guard-months not worked', $this->director);

    // The detail says the list row's word (BIL-B-04, register DR-150): past its date and still owed, it is Overdue.
    expect($overview->invoice($this->invoice->id))->toMatchArray([
        'statusLabel' => 'Overdue',
        'settlement' => 'DUE OCT 15, 2026',
    ]);

    // The rest credited: the list row says "Credited", and so do the detail and the PDF it prints.
    app(InvoiceActions::class)->creditNote($this->invoice->id, '150000.00', 'Raised in error', $this->director);

    expect($overview->invoice($this->invoice->id))->toMatchArray([
        'status' => 'issued',
        'statusLabel' => 'Credited',
        'status_label' => 'Credited',
        'settlement' => 'CREDITED IN FULL · NOTHING DUE',
        'total_minor' => 171_000_00,
    ]);
});

it('does not label the invoice total "Total due" on its screen once a credit note is against it', function () {
    /*
     * Static template copy, the way the pay-run screen's test reads Show.vue: the
     * line table's footer said "Total due $166,500.00" directly under a credit-note
     * panel reading "$0.00 payable". Once anything is credited, the footer is the
     * invoice's total and the panel says what is payable.
     */
    $screen = (string) file_get_contents(resource_path('js/Pages/Gemini/Billing/Invoice.vue'));
    $template = preg_match('/<template>(.*)<\/template>/s', $screen, $found) === 1 ? $found[1] : $screen;
    $template = (string) preg_replace('/<!--.*?-->/s', '', $template);

    expect($template)->not->toContain('<span>Total due</span>')
        // M98: nor on a paid invoice (seen on the local dev server: "Total due" under a Paid badge).
        ->and($template)->toContain("credited_minor > 0 || invoice.status === 'paid' ? 'Invoice total' : 'Total due'");
});

it('is not overdue on its due date, only after it — one rule in every reading', function () {
    // The morning of the due date in Kingston: payable today, not late.
    $this->travelTo(Carbon::parse('2026-10-15 09:00:00', 'America/Jamaica'));

    $readings = overdueReadings($this);

    // "Unpaid", the money list's word since Hassam's ruling of 11 Oct (it read "Due").
    expect($readings['kpi'])->toBe('$0')
        ->and($readings['row'])->toBe('Unpaid')
        ->and($readings['model'])->toBeFalse()
        ->and($readings['client'])->toBe('Unpaid')
        ->and($readings['bell'])->toBeFalse();

    // Still the due date in Kingston at 8 p.m., though UTC has moved on to the 16th.
    $this->travelTo(Carbon::parse('2026-10-15 20:00:00', 'America/Jamaica'));

    expect(overdueReadings($this))->toMatchArray(['kpi' => '$0', 'row' => 'Unpaid', 'model' => false, 'client' => 'Unpaid', 'bell' => false]);

    // The day after it, in Kingston: late.
    $this->travelTo(Carbon::parse('2026-10-16 09:00:00', 'America/Jamaica'));

    expect(overdueReadings($this)['model'])->toBeTrue()
        ->and(overdueReadings($this)['row'])->toBe('Overdue');
});
