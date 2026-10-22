<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\TicketActivity;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Acknowledged and In progress are each stamped from the ticket (FAC-10, register DR-082)
|--------------------------------------------------------------------------
|
| The ticket's spine has six stages and the office could stamp four of them: there was no Acknowledge and no Start
| work, so "Acknowledged" and "In progress" stayed grey on every job raised or reported, whatever had happened. Both
| are now acts on the ticket, each writing its own entry under the name of whoever pressed it, each refused when it is
| already done or the job is closed, and each needing Facilities update, as every other act on the ticket does.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the tickets here stand on a scratch lot of this file's own.
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

function stagedTicket(Maintenance $maintenance, string $title): MaintenanceTicket
{
    return $maintenance->report(
        title: $title,
        locationLabel: 'Lot MNT-82',
        unit: FacilitiesFixture::scratchUnit('MNT-82'),
        reportedByName: 'Staged Household',
        reportedAt: SiteTime::now()->subHours(3),
    );
}

/** @return array<string, mixed> the stage on the ticket's spine */
function stageOf(Maintenance $maintenance, MaintenanceTicket $ticket, string $key): array
{
    return collect($maintenance->ticketBoard($ticket->refresh())['timeline'])->firstWhere('key', $key);
}

it('stamps Acknowledged, then In progress, each under the name of whoever pressed it', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $ticket = stagedTicket($this->maintenance, 'Standpipe hissing');

    try {
        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/acknowledge'))
            ->assertRedirect(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
            ->assertSessionHas('success', 'Ticket #'.$ticket->number.' acknowledged.');

        FacilitiesFixture::boot();

        expect($ticket->refresh()->status)->toBe(MaintenanceTicket::ACKNOWLEDGED)
            ->and(stageOf($this->maintenance, $ticket, MaintenanceTicket::ACKNOWLEDGED)['line'])->toContain($manager->name);

        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/start'))
            ->assertRedirect(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
            ->assertSessionHas('success', 'Work on ticket #'.$ticket->number.' has started.');

        FacilitiesFixture::boot();

        expect($ticket->refresh()->status)->toBe(MaintenanceTicket::IN_PROGRESS)
            ->and(stageOf($this->maintenance, $ticket, MaintenanceTicket::IN_PROGRESS)['line'])->toContain($manager->name)
            ->and(TicketActivity::query()->where('maintenance_ticket_id', $ticket->id)->pluck('event')->all())
            ->toContain(TicketActivity::ACKNOWLEDGED, TicketActivity::STARTED);
    } finally {
        FacilitiesFixture::boot();
    }
});

it('refuses each when it is already done, or the job is closed, and writes nothing', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $ticket = stagedTicket($this->maintenance, 'Gate arm sticking');

    try {
        $this->maintenance->acknowledge($ticket);
        $this->maintenance->start($ticket->refresh());
        $entries = TicketActivity::query()->where('maintenance_ticket_id', $ticket->id)->count();

        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/acknowledge'))
            ->assertSessionHasErrors(['stage' => 'Ticket #'.$ticket->number.' is already in progress.']);

        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/start'))
            ->assertSessionHasErrors(['stage' => 'Work on ticket #'.$ticket->number.' has already started.']);

        FacilitiesFixture::boot();
        $this->maintenance->resolve($ticket->refresh(), 'Arm re-greased.');
        $closed = TicketActivity::query()->where('maintenance_ticket_id', $ticket->id)->count();

        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/start'))
            ->assertSessionHasErrors('stage');

        FacilitiesFixture::boot();

        expect($closed)->toBe($entries + 1)
            ->and(TicketActivity::query()->where('maintenance_ticket_id', $ticket->id)->count())->toBe($closed)
            ->and($ticket->refresh()->status)->toBe(MaintenanceTicket::COMPLETED);
    } finally {
        FacilitiesFixture::boot();
    }
});

it('refuses both to a role that reads tickets and cannot change them', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $ticket = stagedTicket($this->maintenance, 'Bollard light out');

    try {
        $this->actingAs($president)
            ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/acknowledge'))
            ->assertForbidden();

        $this->actingAs($president)
            ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/start'))
            ->assertForbidden();

        FacilitiesFixture::boot();

        expect($ticket->refresh()->status)->toBe(MaintenanceTicket::SUBMITTED);
    } finally {
        FacilitiesFixture::boot();
    }
});

it('tells the ticket page which of the two it can take', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $ticket = stagedTicket($this->maintenance, 'Drain grate loose');

    try {
        $this->actingAs($manager)
            ->get(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ticket.status', MaintenanceTicket::SUBMITTED)
                ->where('ticket.can_acknowledge', true)
                ->where('ticket.can_start', true));

        $this->maintenance->start($ticket->refresh());

        $this->actingAs($manager)
            ->get(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ticket.can_acknowledge', false)
                ->where('ticket.can_start', false));
    } finally {
        FacilitiesFixture::boot();
    }
});
