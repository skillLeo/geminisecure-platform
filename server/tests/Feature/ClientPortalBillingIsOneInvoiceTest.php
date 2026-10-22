<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Gemini\BillingOverview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The client portal shows one invoice the one way (M79, M91 Block 1)
|--------------------------------------------------------------------------
|
| Omar is the client, and these are his screens: Settings, Billing & subscription, the itemised invoice and its PDF.
| BIL-B-04: an overdue invoice read "Issued", in green, on all three; one status now, from one source, the overdue
| treatment when it is overdue.
|
| On the estate's billing page and its itemised view, with a subscription of its own inside the transaction, and three
| invoices on Gemini's calendar at 20 October 2026: October's past its date and unpaid, November's not yet due,
| September's paid. Each carries the subscription line and the guard add-on, as the emailed invoice does.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'America/Jamaica'));

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'portal-billing-test'],
        ['name' => 'Portal Test', 'price_per_unit_minor' => 340_00, 'currency' => 'JMD', 'min_units' => 1, 'is_active' => false, 'sort' => 98],
    );

    Subscription::query()->updateOrCreate(
        ['tenant_id' => FacilitiesFixture::ESTATE],
        ['plan_id' => $plan->id, 'unit_count' => 2000, 'status' => 'active', 'started_on' => '2026-01-01', 'renews_on' => '2026-11-01', 'term_months' => 12],
    );

    $this->october = portalInvoice('FT-INV-202610', '2026-10-01', '2026-10-15', 'issued');
    $this->november = portalInvoice('FT-INV-202611', '2026-11-01', '2026-11-15', 'issued');
    $this->september = portalInvoice('FT-INV-202609', '2026-09-01', '2026-09-15', 'paid', '2026-09-10');
    $this->admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    $this->travelBack();
    DB::connection('mysql')->rollBack();
});

/** An invoice of the subscription and the guard add-on: $680,000.00 and $90,000.00, $770,000.00 posted. */
function portalInvoice(string $reference, string $periodStart, string $dueOn, string $status, ?string $paidOn = null): int
{
    $central = DB::connection('mysql');
    $now = now();
    $start = Carbon::parse($periodStart);

    $id = (int) $central->table('invoices')->insertGetId([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'reference' => $reference,
        'period' => $start->format('M Y'),
        'period_start' => $start->toDateString(),
        'period_end' => $start->copy()->endOfMonth()->toDateString(),
        'total_minor' => 770_000_00,
        'currency' => 'JMD',
        'due_on' => $dueOn,
        'status' => $status,
        'paid_on' => $paidOn,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $central->table('invoice_lines')->insert([
        [
            'invoice_id' => $id, 'description' => 'Premium subscription', 'quantity' => 2000,
            'unit_price_minor' => 340_00, 'total_minor' => 680_000_00, 'currency' => 'JMD',
            'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'invoice_id' => $id, 'description' => 'Security Provider add-on', 'quantity' => 20,
            'unit_price_minor' => 4_500_00, 'total_minor' => 90_000_00, 'currency' => 'JMD',
            'created_at' => $now, 'updated_at' => $now,
        ],
    ]);

    return $id;
}

/**
 * This estate's billing page's row for one invoice, found by its link.
 *
 * @return array<string, mixed>
 */
function portalRow(object $test, int $invoiceId): array
{
    $invoices = $test->actingAs($test->admin)->get(FacilitiesFixture::url('/settings/billing'))->assertOk()->viewData('page')['props']['invoices'];

    $row = collect($invoices)->first(static fn (array $r): bool => str_ends_with((string) $r['view_href'], '/settings/billing/invoices/'.$invoiceId));
    expect($row)->not->toBeNull();

    return $row;
}

it('says one status for an invoice, from one source, on the billing page, the itemised view and the PDF (BIL-B-04)', function () {
    // The billing page: the invoice past its date is Overdue, not "Issued"; the others say what they are.
    expect(portalRow($this, $this->october))->toMatchArray(['status_label' => 'Overdue', 'status_tone' => 'overdue'])
        // "Unpaid", the money list's word since Hassam's ruling of 11 Oct (it read "Due"); the tone's key is unchanged.
        ->and(portalRow($this, $this->november))->toMatchArray(['status_label' => 'Unpaid', 'status_tone' => 'due'])
        ->and(portalRow($this, $this->september))->toMatchArray(['status_label' => 'Paid', 'status_tone' => 'paid']);

    // The itemised view says the same word, with the same treatment.
    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$this->october))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoice.status_label', 'Overdue')
            ->where('invoice.status_tone', 'overdue'));

    // And so does the PDF, the document the estate downloads and Gemini emails.
    $pdf = view('documents.invoice', ['invoice' => app(BillingOverview::class)->invoice($this->october), 'notes' => [], 'creditedMinor' => 0])->render();

    expect(str_contains($pdf, '>Overdue<'))->toBeTrue()
        ->and(str_contains($pdf, '>Issued<'))->toBeFalse()
        ->and(str_contains($pdf, 'status-overdue'))->toBeTrue();
});

it('shows the whole invoice on the billing page, the subscription and the guard add-on, as the email and the PDF do (BIL-B-05)', function () {
    // The page listed the subscription lines alone ($680,000.00 here) beside an emailed invoice for $770,000.00.
    expect(portalRow($this, $this->october)['amount'])->toBe('$770,000.00');

    // The itemised view and the PDF carry the same whole total.
    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$this->october))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invoice.total', '$770,000.00'));

    // And the page no longer tells the estate its guard staffing is billed elsewhere: it is a line on this invoice.
    $note = (string) $this->actingAs($this->admin)->get(FacilitiesFixture::url('/settings/billing'))->viewData('page')['props']['subscription']['security_services_note'];
    $screen = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Billing.vue'));

    expect(str_contains($note, 'not here'))->toBeFalse()
        ->and(str_contains($note, 'same invoice'))->toBeTrue()
        ->and(str_contains($screen, 'billed separately'))->toBeFalse();
});

it('gives an invoice one number, the one it was issued with, on the billing page, the itemised view and the PDF (BIL-B-03)', function () {
    // The page derived its own "INV-2026-10" beside the issued "FT-INV-202610" the PDF and the email carry.
    expect(portalRow($this, $this->october)['number'])->toBe('FT-INV-202610');

    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$this->october))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoice.number', 'FT-INV-202610')
            ->where('invoice.reference', 'FT-INV-202610'));

    $pdf = view('documents.invoice', ['invoice' => app(BillingOverview::class)->invoice($this->october), 'notes' => [], 'creditedMinor' => 0])->render();
    $screen = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/BillingInvoice.vue'));

    // Said once on the itemised view, in its title, not again as a "Reference" line under it.
    expect(str_contains($pdf, 'FT-INV-202610'))->toBeTrue()
        ->and(str_contains($pdf, 'INV-2026-10'))->toBeFalse()
        ->and(str_contains($screen, 'Reference {{ invoice.reference }}'))->toBeFalse();
});
