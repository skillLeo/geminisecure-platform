<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\TicketActivity;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The vendor shown is the vendor chosen, everywhere on the ticket (M68 §2.2, FAC-8)
|--------------------------------------------------------------------------
|
| Assign Island Electric, reassign to AquaTech: the card and the queue said
| AquaTech and the stage strip still said Island Electric, because the strip
| kept the first assignment. It now names the assignment that stands, with who it
| was taken from; the thread (FAC-7) shows each one. A seeded "reassignment"
| from a vendor to itself reads as what it was, and pressing Assign for the
| vendor that already has the job, with nothing changed, is refused.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the tickets here stand on a
| scratch lot of this file's own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->maintenance = app(Maintenance::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function vendorChosenTicket(Maintenance $maintenance, string $title): MaintenanceTicket
{
    return $maintenance->report(
        title: $title,
        locationLabel: 'Lot MNT-69',
        unit: FacilitiesFixture::scratchUnit('MNT-69'),
        reportedByName: 'Vendor Household',
        reportedAt: SiteTime::now()->subHours(5),
    );
}

it('names the vendor that has the job on the stage strip, with who it was taken from', function () {
    $ticket = vendorChosenTicket($this->maintenance, 'Pump will not start');

    try {
        $this->maintenance->assign($ticket, FacilitiesFixture::vendor('Island Electric Services'));
        $this->maintenance->assign($ticket->refresh(), FacilitiesFixture::vendor('AquaTech Pool Services'));

        $board = $this->maintenance->ticketBoard($ticket->refresh());
        $assigned = collect($board['timeline'])->firstWhere('key', MaintenanceTicket::ASSIGNED);

        expect($board['vendor']['name'])->toBe('AquaTech Pool Services')
            ->and($assigned['line'])->toContain('AquaTech Pool Services')
            ->and($assigned['line'])->not->toContain('Island Electric')
            ->and($assigned['note'])->toBe('Reassigned from Island Electric Services');

        // The visit as it stands, for the form to start from.
        expect($board['vendor'])->toHaveKeys(['technician_phone', 'eta_starts_local', 'eta_ends_local']);
    } finally {
        FacilitiesFixture::boot();
    }
});

it('reads a seeded reassignment from a vendor to itself as the same vendor again', function () {
    $ticket = vendorChosenTicket($this->maintenance, 'Gate motor grinding');

    try {
        $this->maintenance->assign($ticket, FacilitiesFixture::vendor('Island Electric Services'));

        // As the seed wrote ticket #1041's second reassignment, before the same supplier stopped being one (26 Sep).
        $this->maintenance->record(
            ticket: $ticket,
            event: TicketActivity::REASSIGNED,
            note: 'Reassigned from Island Electric Services',
            actorKind: TicketActivity::VENDOR,
            actorName: 'Island Electric Services',
        );

        $history = collect($this->maintenance->ticketBoard($ticket->refresh())['history']);

        expect($history->last()['what'])->toBe('Assigned to Island Electric Services again');
    } finally {
        FacilitiesFixture::boot();
    }
});

it('refuses Assign for the vendor that already has the job when nothing about the visit changes', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $ticket = vendorChosenTicket($this->maintenance, 'Pool light flickers');

    try {
        $vendor = FacilitiesFixture::vendor('AquaTech Pool Services');
        $this->maintenance->assign($ticket, $vendor, technicianName: 'Owen Grant');
        $entries = TicketActivity::query()->where('maintenance_ticket_id', $ticket->id)->count();

        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/assign'), [
                'vendor_id' => $vendor->id,
                'technician_name' => 'Owen Grant',
            ])
            ->assertSessionHasErrors(['vendor_id' => 'AquaTech Pool Services already has this job. Change the technician, the phone or the visit time, or choose another supplier.']);

        FacilitiesFixture::boot();

        expect(TicketActivity::query()->where('maintenance_ticket_id', $ticket->id)->count())->toBe($entries);
    } finally {
        FacilitiesFixture::boot();
    }
});
