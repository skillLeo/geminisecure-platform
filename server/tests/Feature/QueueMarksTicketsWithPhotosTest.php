<?php

declare(strict_types=1);

use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The queue marks a ticket that carries the resident's photos (FAC-5, register DR-079)
|--------------------------------------------------------------------------
|
| Eight tickets carried a photo the resident attached in the app, and the queue showed nothing of it: the photos were
| found only by opening each ticket. Each row now carries how many there are, counted inside the queue's own read, for
| the row's mark; a ticket with none carries nought.
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

it('carries how many photos each ticket has, for the row\'s mark', function () {
    $unit = FacilitiesFixture::scratchUnit('MNT-79', 'Phase 9');
    $report = fn (string $title) => $this->maintenance->report(
        title: $title,
        locationLabel: 'Lot MNT-79',
        unit: $unit,
        reportedByName: 'Photographing Household',
        reportedAt: SiteTime::now()->subHour(),
    );
    $pictured = $report('Wall cracked by the drive');
    $plain = $report('Hinge loose on the side gate');

    try {
        foreach (['crack-1.jpg', 'crack-2.jpg'] as $file) {
            DB::connection('tenant')->table('ticket_media')->insert([
                'maintenance_ticket_id' => $pictured->id, 'path' => 'tickets/'.$file, 'filename' => $file,
                'content_type' => 'image/jpeg', 'bytes' => 1024, 'sha256' => str_repeat('a', 64),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $rows = collect($this->maintenance->queueBoard('all')['rows'])->keyBy('number');

        expect($rows[$pictured->number]['photos'])->toBe(2)
            ->and($rows[$plain->number]['photos'])->toBe(0);
    } finally {
        DB::connection('tenant')->table('ticket_media')->where('maintenance_ticket_id', $pictured->id)->delete();
        FacilitiesFixture::boot();
    }
});
