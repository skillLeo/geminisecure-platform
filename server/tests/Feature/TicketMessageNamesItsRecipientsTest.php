<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A message to a household names who reads it, before and after it is sent (Hassam, batch 1 item 1, 12 Oct)
|--------------------------------------------------------------------------
|
| On a ticket, "Message the resident" said only "They read it on this request in the app.": nobody could tell who
| receives it. It reaches the people the notification reaches: every active Resident App account on the lot whose
| level reaches requests. The dialog names them, the person who raised it first and said to be, and the ticket's
| history names who it went to, as they were when it was sent. A lot where nobody reads requests in the app says so.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the lots here are this file's own scratch lots, and the people on them
| are taken away afterwards.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->maintenance = app(Maintenance::class);
    $this->unit = FacilitiesFixture::scratchUnit('MNT-71');
    $this->empty = FacilitiesFixture::scratchUnit('MNT-72');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Recipient household', 'access_restricted' => false]);
});

afterEach(function () {
    FacilitiesFixture::boot();
    Resident::withRemoved()->where('household_id', $this->household->id)->delete();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** Inside the estate as a request is (`tenant()` set), where the notification and the recipients read which estate it is. */
function inTicketEstate(callable $do): mixed
{
    tenancy()->end();
    FacilitiesFixture::boot();
    $result = tenancy()->find(FacilitiesFixture::ESTATE)->run($do);
    FacilitiesFixture::boot();

    return $result;
}

/** A person on the household's lot with an active Resident App account. */
function ticketReader(Household $household, string $name, bool $primary): void
{
    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => $name, 'email' => mb_strtolower(str_replace(' ', '.', $name)).'.'.bin2hex(random_bytes(3)).'@example.com', 'phone' => '+1 876 555 0971', 'relationship' => $primary ? 'owner' : 'spouse', 'is_primary' => $primary, 'status' => Resident::VERIFIED])->save();

    ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => (string) $person->email, 'full_name' => $name,
        'resident_id' => $person->id, 'unit_id' => $household->unit_id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
}

it('names who a message reaches, the person who raised it first, and keeps them on the history line', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    ticketReader($this->household, 'Marlon Recipient', false);
    ticketReader($this->household, 'Andrea Recipient', true);

    $ticket = $this->maintenance->report(title: 'Gate light out', locationLabel: 'Lot MNT-71', unit: $this->unit, reportedByName: 'Andrea Recipient', reportedAt: SiteTime::now()->subHour());

    $board = inTicketEstate(fn (): array => $this->maintenance->ticketBoard($ticket->refresh()));
    expect($board['ticket']['message_to']['names'] ?? null)->toBe(['Andrea Recipient', 'Marlon Recipient'])
        ->and($board['ticket']['message_to']['line'] ?? null)->toBe('To Andrea Recipient (who raised it) and Marlon Recipient, on this request in the Resident App, with a notification.');

    inTicketEstate(fn () => $this->maintenance->messageResident($ticket->refresh(), 'The electrician comes on Monday morning.', $manager));

    $line = collect(inTicketEstate(fn (): array => $this->maintenance->ticketBoard($ticket->refresh()))['history'])->firstWhere('event', 'note');
    expect($line['what'])->toBe('Message to Andrea Recipient and Marlon Recipient')
        ->and($line['words'])->toBe('The electrician comes on Monday morning.');
});

it('says a message would reach nobody where nobody on the lot reads requests in the app', function () {
    $ticket = $this->maintenance->report(title: 'Drain blocked', locationLabel: 'Lot MNT-72', unit: $this->empty, reportedByName: 'Nobody Here', reportedAt: SiteTime::now()->subHour());

    $to = inTicketEstate(fn (): array => $this->maintenance->ticketBoard($ticket->refresh()))['ticket']['message_to'] ?? null;
    expect($to['names'] ?? null)->toBe([])
        ->and($to['line'] ?? null)->toBe('Nobody at MNT-72 reads requests in the Resident App, so a message would reach nobody.');
});
