<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A ticket's thread is sent whole, each line already worded (M68 §2.1, FAC-7)
|--------------------------------------------------------------------------
|
| The server stored every line of a ticket's history (reported, assigned,
| reassigned, a message, completed, reopened, each with a name and a time) and
| the ticket page drew none of them, so a message to the resident was accepted
| and appeared nowhere. Each line now arrives worded on the server: what
| happened, the words, who and when, so the page prints it and composes nothing.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the ticket here stands on a
| scratch lot of this file's own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->maintenance = app(Maintenance::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('sends every line of the history worded: what happened, the words, who and when', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $ticket = $this->maintenance->report(
        title: 'Gate light out',
        locationLabel: 'Lot MNT-68',
        unit: FacilitiesFixture::scratchUnit('MNT-68'),
        category: 'Electrical',
        reportedByName: 'Thread Household',
        reportedAt: SiteTime::now()->subHours(6),
    );

    try {
        $this->maintenance->acknowledge($ticket, $manager);
        $this->maintenance->assign($ticket->refresh(), FacilitiesFixture::vendor('Island Electric Services'), by: $manager);
        $this->maintenance->assign($ticket->refresh(), FacilitiesFixture::vendor('AquaTech Pool Services'), by: $manager);
        $this->maintenance->messageResident($ticket->refresh(), 'The electrician comes on Monday morning.', $manager);
        $this->maintenance->resolve($ticket->refresh(), 'Replaced the bulb and the photocell.', $manager);
        $this->maintenance->reopen($ticket->refresh(), 'The light went out again the same night.', null, 'Thread Household');

        $history = collect($this->maintenance->ticketBoard($ticket->refresh())['history']);

        expect($history->pluck('what')->all())->toBe([
            'Reported',
            'Acknowledged',
            'Assigned to Island Electric Services',
            'Reassigned from Island Electric Services to AquaTech Pool Services',
            'Message to the household',
            'Marked completed',
            'Reopened',
        ]);

        $message = $history->firstWhere('what', 'Message to the household');
        expect($message['words'])->toBe('The electrician comes on Monday morning.')
            ->and($message['who'])->toBe($manager->name)
            ->and($message['when'])->toMatch('/^[A-Z][a-z]{2} \d{1,2}, \d{1,2}:\d{2} [AP]M/');

        // The reopen reason, and who reopened it: the household, from the app.
        $reopened = $history->firstWhere('what', 'Reopened');
        expect($reopened['words'])->toBe('The light went out again the same night.')
            ->and($reopened['who'])->toBe('Thread Household')
            ->and($reopened['kind'])->toBe('resident');

        // The resolver's name, beside the resolution.
        $resolved = $history->firstWhere('what', 'Marked completed');
        expect($resolved['who'])->toBe($manager->name)
            ->and($resolved['words'])->toBe('Replaced the bulb and the photocell.');

        // A vendor's line names the vendor in what happened, and repeats no name after it.
        expect($history->firstWhere('event', 'reassigned')['who'])->toBeNull()
            ->and($history->firstWhere('what', 'Reported')['who'])->toBe('Thread Household');
    } finally {
        FacilitiesFixture::boot();
    }
});
