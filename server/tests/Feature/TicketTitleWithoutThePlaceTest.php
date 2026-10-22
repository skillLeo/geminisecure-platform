<?php

declare(strict_types=1);

use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A ticket's title without the place, and the place from the lot (FAC-15, register DR-087)
|--------------------------------------------------------------------------
|
| The Resident App writes a title as "The gate arm sticks open: Common area" and sends "Common area" as the place too,
| so the queue and the ticket printed the place twice. And a place typed as "Lot 47, Phase 2" was printed as typed,
| when the estate's own record puts Lot 47 in Phase 1. The board now draws the title without a place it ends with, and
| a place that names the ticket's own lot from the lot's record. The title and the place as stored are untouched:
| they are what the household wrote.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the tickets here stand on a scratch lot of this file's own.
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

it('draws the title without the place it ends with, the place once', function () {
    $ticket = $this->maintenance->report(
        title: 'The gate arm sticks open: Common area',
        locationLabel: 'Common area',
        reportedByName: 'Titled Household',
        reportedAt: SiteTime::now()->subHour(),
    );

    try {
        expect($ticket->displayTitle())->toBe('The gate arm sticks open')
            ->and($ticket->label())->toBe('#'.$ticket->number.' · The gate arm sticks open')
            ->and($ticket->summaryTitle())->toBe('The gate arm sticks open · Common area')
            ->and($ticket->refresh()->title)->toBe('The gate arm sticks open: Common area');

        $board = $this->maintenance->ticketBoard($ticket);

        expect($board['ticket']['summary_title'])->toBe('The gate arm sticks open · Common area')
            ->and($board['ticket']['location'])->toBe('Common area');
    } finally {
        FacilitiesFixture::boot();
    }
});

it('draws a place that names the ticket\'s own lot from the lot\'s record, whatever phase was typed', function () {
    $unit = FacilitiesFixture::scratchUnit('MNT-87', 'Phase 9');
    $ticket = $this->maintenance->report(
        title: 'Pipe burst under the sink: MNT-87, Phase 2',
        locationLabel: 'MNT-87, Phase 2',
        unit: $unit,
        reportedByName: 'Phased Household',
        reportedAt: SiteTime::now()->subHour(),
    );

    try {
        expect($ticket->placeLabel())->toBe('MNT-87 · Phase 9')
            ->and($ticket->displayTitle())->toBe('Pipe burst under the sink')
            ->and($ticket->summaryTitle())->toBe('Pipe burst under the sink · MNT-87 Phase 9');

        $row = collect($this->maintenance->queueBoard('all')['rows'])->firstWhere('number', $ticket->number);

        expect($row['ticket'])->toBe('#'.$ticket->number.' · Pipe burst under the sink')
            ->and($row['location'])->toBe('MNT-87 · Phase 9');
    } finally {
        FacilitiesFixture::boot();
    }
});

it('leaves a title that does not end with its place, and a place that is not the lot, as they were written', function () {
    $unit = FacilitiesFixture::scratchUnit('MNT-87', 'Phase 9');
    $ticket = $this->maintenance->report(
        title: 'Gate lighting',
        locationLabel: 'Phase 2 visitor parking',
        unit: $unit,
        reportedByName: 'Plain Household',
        reportedAt: SiteTime::now()->subHour(),
    );

    try {
        expect($ticket->displayTitle())->toBe('Gate lighting')
            ->and($ticket->placeLabel())->toBe('Phase 2 visitor parking')
            ->and($ticket->summaryTitle())->toBe('Gate lighting · Phase 2 visitor parking');
    } finally {
        FacilitiesFixture::boot();
    }
});
