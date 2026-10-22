<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A resident's security report is kept apart from maintenance and its deadlines (FAC-4, register DR-078)
|--------------------------------------------------------------------------
|
| The Resident App files a security report (a theft, somebody suspicious at the back wall) as a ticket with the
| category "security". Eight of them sat in the maintenance queue untagged, two given to a plumber and counted
| overdue as plumbing. They now have a chip of their own, "Security reports", and are out of the maintenance queue's
| other chips, its four tiles and its overdue count; a security report has no maintenance deadline, so it is never
| overdue. Nothing is deleted or moved: the report is the same row, read apart.
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

it('keeps a security report out of the maintenance queue, its tiles and its overdue count, under a chip of its own', function () {
    $unit = FacilitiesFixture::scratchUnit('MNT-78', 'Phase 9');
    $before = $this->maintenance->tiles();

    $report = $this->maintenance->report(
        title: 'Somebody climbed the back wall: Back wall, Phase 3',
        locationLabel: 'Back wall, Phase 3',
        priority: MaintenanceTicket::HIGH,
        unit: $unit,
        category: MaintenanceTicket::SECURITY_CATEGORY,
        reportedByName: 'Watchful Household',
        reportedAt: SiteTime::now()->subDays(3),
    );

    try {
        $after = $this->maintenance->tiles();
        $kpi = fn (array $tiles, string $key): int|float => collect($tiles['kpis'])->firstWhere('key', $key)['value'];

        expect($kpi($after, 'open'))->toBe($kpi($before, 'open'))
            ->and($after['overdue'])->toBe($before['overdue'])
            ->and($report->refresh()->isOverdue())->toBeFalse('a security report has no maintenance deadline');

        $numbers = fn (string $filter): array => collect($this->maintenance->queueBoard($filter)['rows'])->pluck('number')->all();

        expect($numbers('all'))->not->toContain($report->number)
            ->and($numbers('open'))->not->toContain($report->number)
            ->and($numbers(Maintenance::OVERDUE))->not->toContain($report->number)
            ->and($numbers('security'))->toContain($report->number)
            ->and(collect($this->maintenance->queueBoard('security')['rows'])->pluck('number')->every(
                fn (int $number): bool => mb_strtolower((string) MaintenanceTicket::query()->where('number', $number)->value('category')) === 'security'
            ))->toBeTrue('the chip holds security reports and nothing else');

        expect(collect($this->maintenance->queueBoard('all')['filters'])->firstWhere('key', 'security'))
            ->toBe(['key' => 'security', 'label' => 'Security reports']);

        expect($this->maintenance->ticketBoard($report)['ticket']['is_overdue'])->toBeFalse();
    } finally {
        FacilitiesFixture::boot();
    }
});

it('leaves a maintenance job in the queue, its tiles and its deadline exactly as before', function () {
    $unit = FacilitiesFixture::scratchUnit('MNT-78', 'Phase 9');
    $before = $this->maintenance->tiles();

    $job = $this->maintenance->report(
        title: 'Pipe burst under the sink',
        locationLabel: 'Lot MNT-78',
        priority: MaintenanceTicket::HIGH,
        unit: $unit,
        category: 'plumbing',
        reportedByName: 'Wet Household',
        reportedAt: SiteTime::now()->subDays(3),
    );

    try {
        $after = $this->maintenance->tiles();

        expect($after['overdue'])->toBe($before['overdue'] + 1)
            ->and($job->refresh()->isOverdue())->toBeTrue()
            ->and(collect($this->maintenance->queueBoard('all')['rows'])->pluck('number')->all())->toContain($job->number)
            ->and(collect($this->maintenance->queueBoard('security')['rows'])->pluck('number')->all())->not->toContain($job->number);
    } finally {
        FacilitiesFixture::boot();
    }
});
