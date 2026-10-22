<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Vendor;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A work order is offered the suppliers that take maintenance work, and given to no other (DR-035; step 4)
|--------------------------------------------------------------------------
|
| Assign vendor offered every active supplier, the electricity company among them (M26, M62 A8): it bills the estate
| every month, but nobody assigns it a broken gate. The ticket's list now reads `Vendor::takesMaintenanceWork()` (the
| m56 session's scope: the active suppliers, less those filed under Utilities), and a supplier outside it, posted by
| hand, is refused in one line; an inactive one too. A job already with a supplier keeps its visit changes, whatever
| becomes of that supplier's filing.
|
| The fixture estate is committed and shared, so this file's two suppliers are its own and removed afterwards.
|
*/

const WOOMS_UTILITY = 'Ticket Test Power Co';
const WOOMS_RETIRED = 'Ticket Test Retired Ltd';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    Vendor::query()->whereIn('name', [WOOMS_UTILITY, WOOMS_RETIRED])->delete();
    $this->utility = Vendor::query()->create(['name' => WOOMS_UTILITY, 'category' => Vendor::UTILITIES, 'status' => Vendor::ACTIVE]);
    $this->retired = Vendor::query()->create(['name' => WOOMS_RETIRED, 'category' => null, 'status' => 'inactive']);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    Vendor::query()->whereIn('name', [WOOMS_UTILITY, WOOMS_RETIRED])->delete();
});

it('lists only the suppliers that take maintenance work on a ticket, and refuses the others when posted', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $title = 'Supplier list test '.bin2hex(random_bytes(3));

    $this->actingAs($manager)->post(FacilitiesFixture::url('/facilities/maintenance'), [
        'title' => $title, 'location' => 'Main Gate', 'category' => 'Other', 'priority' => 'low',
    ])->assertRedirect();
    FacilitiesFixture::boot();
    $ticket = MaintenanceTicket::query()->where('title', $title)->firstOrFail();

    $page = $this->actingAs($manager)->get(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))->assertOk();
    FacilitiesFixture::boot();
    $offered = collect($page->viewData('page')['props']['vendors'])->pluck('name')->all();

    expect($offered)->toContain('Island Electric Services')
        ->and(in_array(WOOMS_UTILITY, $offered, true))->toBeFalse('a utility is offered a work order')
        ->and(in_array(WOOMS_RETIRED, $offered, true))->toBeFalse('an inactive supplier is offered a work order');

    foreach ([$this->utility, $this->retired] as $vendor) {
        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/assign'), ['vendor_id' => $vendor->id])
            ->assertSessionHasErrors(['vendor_id' => $vendor->name.' does not take maintenance work. Choose another supplier.']);
        FacilitiesFixture::boot();
    }

    expect($ticket->refresh()->assigned_vendor_id)->toBeNull();
});
