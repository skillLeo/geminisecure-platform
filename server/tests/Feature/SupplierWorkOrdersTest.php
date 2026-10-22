<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Payables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A supplier's page lists the jobs it has been given (M98, maintenance and vendors)
|--------------------------------------------------------------------------
|
| The supplier's page showed its bills and nothing of its work: which jobs the estate had given it, which were still
| open, which were late. It now lists every work order assigned to it, open ones first, in the queue's own words, each a
| link to the job, for a reader who may open a work order.
|
| A supplier of this test's own, so the counts are exact on the shared fixture estate.
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

it('lists the jobs a supplier has been given, open ones first, in the queue words', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $supplier = $this->payables->addVendor(
        name: 'Probe Works '.Str::upper(Str::random(6)),
        category: 'Electrical contractor',
        contactName: 'Probe Person',
        contactPhone: '876 555 0100',
        contactEmail: 'probe-works@example.com',
    );

    $finished = $this->maintenance->report(title: 'Supplier probe, finished', locationLabel: 'Club house', priority: MaintenanceTicket::LOW);
    $this->maintenance->assign($finished, $supplier, by: $manager);
    $this->maintenance->resolve($finished->refresh(), 'Replaced the lamp.', $manager);

    $open = $this->maintenance->report(title: 'Supplier probe, open', locationLabel: 'Pool deck', priority: MaintenanceTicket::HIGH);
    $this->maintenance->assign($open, $supplier, by: $manager);

    $this->withoutVite()->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/accounting/vendors/'.$supplier->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workOrders.total', 2)
            ->where('workOrders.open', 1)
            ->has('workOrders.rows', 2)
            ->where('workOrders.rows.0.number', $open->number)
            ->where('workOrders.rows.0.status_label', 'Assigned')
            ->where('workOrders.rows.0.priority_label', 'High')
            ->where('workOrders.rows.0.location', 'Pool deck')
            ->where('workOrders.rows.1.number', $finished->number)
            ->where('workOrders.rows.1.status_label', 'Completed')
            ->where('workOrders.rows.1.is_overdue', false));
});

it('lists none for a supplier the estate has not given a job', function () {
    $supplier = $this->payables->addVendor(
        name: 'Probe Idle '.Str::upper(Str::random(6)),
        category: 'Plumbing contractor',
        contactName: 'Idle Person',
        contactPhone: '876 555 0101',
        contactEmail: 'probe-idle@example.com',
    );

    $this->withoutVite()->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/accounting/vendors/'.$supplier->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workOrders.total', 0)
            ->where('workOrders.open', 0)
            ->where('workOrders.rows', []));
});
