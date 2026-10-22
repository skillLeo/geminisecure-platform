<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Role;
use App\Rules\PersonName;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The technician on a ticket is named, not typed at (E2E-M1, register DR-111)
|--------------------------------------------------------------------------
|
| Assign vendor took "djskb" as the technician's name, and the household was told it. It is now refused under the
| field, in the standard's words, and nothing is saved; a real name is taken as before.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the ticket here stands on a scratch lot of this file's own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('refuses a technician\'s name that is not a name, saving nothing, and takes a real one', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $ticket = app(Maintenance::class)->report(
        title: 'Pump cutting out',
        locationLabel: 'Lot MNT-111',
        unit: FacilitiesFixture::scratchUnit('MNT-111'),
        reportedByName: 'Named Household',
        reportedAt: SiteTime::now()->subHours(2),
    );
    $vendor = FacilitiesFixture::vendor('AquaTech Pool Services');
    $assign = fn (string $technician) => $this->actingAs($manager)
        ->from(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
        ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/assign'), [
            'vendor_id' => $vendor->id,
            'technician_name' => $technician,
        ]);

    try {
        $assign('djskb')->assertSessionHasErrors(['technician_name' => PersonName::MESSAGE]);

        FacilitiesFixture::boot();
        expect($ticket->refresh()->assigned_vendor_id)->toBeNull()
            ->and($ticket->technician_name)->toBeNull();

        $assign('Owen Grant')->assertSessionHasNoErrors()->assertRedirect();

        FacilitiesFixture::boot();
        expect($ticket->refresh()->technician_name)->toBe('Owen Grant')
            ->and($ticket->status)->toBe(MaintenanceTicket::ASSIGNED);
    } finally {
        FacilitiesFixture::boot();
    }
});
