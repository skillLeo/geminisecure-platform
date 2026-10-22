<?php

declare(strict_types=1);

use App\Models\Estate\Bill;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Role;
use App\Services\Estate\Ledger;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Payables;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| What a job has cost, on the work order (M98, maintenance and vendors: cost capture)
|--------------------------------------------------------------------------
|
| A bill could already name a work order (Bills & payments, `bills.ticket_id` holding the ticket's number), and the work
| order never showed it. Its page now lists every bill recorded against the job and what they come to, to the cent, for
| whoever holds Vendor costs: D-010 split that module from Accounting so the Property Manager sees what the work they
| commission costs, and never what a household owes. Record a bill sits on the same page for whoever may record one in
| Accounting, and comes back to the job.
|
| The total is proven against the bills' own minor units in raw SQL, so a cent lost on the way is red.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->maintenance = app(Maintenance::class);
    $this->payables = app(Payables::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** A job of this test's own, with no lot: the fixture estate is shared. */
function costsProbe(Maintenance $maintenance, string $title): MaintenanceTicket
{
    return $maintenance->report(title: $title, locationLabel: 'Club house', priority: MaintenanceTicket::MEDIUM);
}

it('shows the Property Manager what the job has cost, to the cent, and nothing of another job', function () {
    $ticket = costsProbe($this->maintenance, 'Costs probe, pump');
    $other = costsProbe($this->maintenance, 'Costs probe, another job');
    $vendor = FacilitiesFixture::vendor('FitFix Equipment Repair');

    foreach (['6200.50', '1499.99'] as $amount) {
        $this->payables->recordBill(vendor: $vendor, amount: Money::of($amount, 'JMD'), description: 'Pump repair', dueOn: Ledger::today(), account: '5100', ticketId: $ticket->number, ticketLabel: $ticket->label());
    }
    $this->payables->recordBill(vendor: $vendor, amount: Money::of('999.00', 'JMD'), description: 'Not this job', dueOn: Ledger::today(), account: '5100', ticketId: $other->number, ticketLabel: $other->label());

    // The bills' own minor units, summed by the database, not by the code under test.
    $raw = (int) DB::connection('tenant')->table('bills')->where('ticket_id', $ticket->number)->where('status', '!=', Bill::VOID)->sum('amount_minor');
    expect($raw)->toBe(770049);

    $this->withoutVite()->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))
        ->get(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('costs.rows', 2)
            ->where('costs.total_minor', $raw)
            ->where('costs.currency', 'JMD')
            ->where('costs.rows.0.amount_minor', 149999)
            ->where('costs.rows.0.supplier', 'FitFix Equipment Repair')
            ->where('costs.rows.0.status_label', 'Draft')
            ->where('costs.rows.1.amount_minor', 620050)
            // The Property Manager may see what a job costs and may not record a bill: Accounting is not theirs (D-010).
            ->where('costForm', null));
});

it('draws no costs for a role without Vendor costs, and the bill form only for one that may record a bill', function () {
    $ticket = costsProbe($this->maintenance, 'Costs probe, gate motor');

    $this->withoutVite()->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))
        ->get(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('costs', null)->where('costForm', null));
    FacilitiesFixture::boot();

    $this->withoutVite()->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('costs.total_minor', 0)
            ->where('costForm.account', '5100')
            ->where('costForm.description', $ticket->label())
            ->where('costForm.today', Ledger::today()->toDateString())
            ->has('costForm.suppliers')
            ->has('costForm.accounts'));
});

it('records a bill from the work order as a draft against it, and comes back to the job', function () {
    $ticket = costsProbe($this->maintenance, 'Costs probe, floodlight');
    $vendor = FacilitiesFixture::vendor('FitFix Equipment Repair');

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->post(FacilitiesFixture::url('/accounting/bills'), [
            'vendor_id' => $vendor->id,
            'description' => $ticket->label(),
            'amount' => '2500.01',
            'due_on' => Ledger::today()->toDateString(),
            'account' => '5100',
            'ticket_number' => $ticket->number,
            'from_ticket' => 1,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number));
    FacilitiesFixture::boot();

    $bill = Bill::query()->where('ticket_id', $ticket->number)->sole();
    expect($bill->status)->toBe(Bill::DRAFT)
        ->and($bill->amount_minor)->toBe(250001)
        ->and($bill->vendor_id)->toBe($vendor->id);

    // The Property Manager holds no Accounting, so the route refuses them whatever the page draws.
    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))
        ->post(FacilitiesFixture::url('/accounting/bills'), [
            'vendor_id' => $vendor->id, 'description' => 'No', 'amount' => '1.00', 'due_on' => Ledger::today()->toDateString(),
            'account' => '5100', 'ticket_number' => $ticket->number, 'from_ticket' => 1,
        ])
        ->assertForbidden();
    FacilitiesFixture::boot();

    expect(Bill::query()->where('ticket_id', $ticket->number)->count())->toBe(1);
});
