<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| One status model for the queue: the cards are the chips (M68 §2.3, FAC-2, FAC-3)
|--------------------------------------------------------------------------
|
| The card said "In progress: 1" and its chip listed 18; the card said "Open:
| 27" and its chip listed 16; seven tickets sat under both chips, unassigned ones
| nobody had started among them, because overdue was a status. Now a ticket is in
| one group: Open (submitted, acknowledged: nobody has the job), In progress
| (assigned, in progress: a vendor has it) or Completed (completed, verified);
| overdue is a flag beside its state. Each card is counted by the rule its chip
| filters by, and the Status column names the real state.
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

/** How many tickets a chip lists, optionally searched. */
function chipTotal(Maintenance $maintenance, string $filter, Carbon $now, string $search = ''): int
{
    $table = Maintenance::queueTable(new Request($search === '' ? [] : ['q' => $search]), $now);

    return (int) $maintenance->queueBoard($filter, $now, $table)['table']['total'];
}

it('counts each card by the rule its chip filters by, and no ticket sits under two chips', function () {
    $now = Carbon::now();
    $tiles = collect($this->maintenance->queueBoard('all', $now)['kpis'])->keyBy('key');

    $open = chipTotal($this->maintenance, 'open', $now);
    $progress = chipTotal($this->maintenance, 'in_progress', $now);
    $completed = chipTotal($this->maintenance, 'completed', $now);
    $cancelled = MaintenanceTicket::query()->where('status', MaintenanceTicket::CANCELLED)->count();

    expect($tiles['open']['value'])->toBe($open)
        ->and($tiles['in_progress']['value'])->toBe($progress)

        // Every ticket in exactly one group: the chips add up to the whole queue.
        ->and($open + $progress + $completed + $cancelled)->toBe(chipTotal($this->maintenance, 'all', $now));
});

it('filters by the overdue badge: every ticket it lists is overdue, as many as its tile counts (FAC-1)', function () {
    $now = Carbon::now();
    $board = $this->maintenance->queueBoard('overdue', $now, Maintenance::queueTable(new Request(['per' => 200]), $now));

    expect($board['filter'])->toBe('overdue')
        ->and($board['table']['total'])->toBe($board['overdue'])
        ->and(collect($board['rows'])->every(fn (array $row): bool => $row['is_overdue'] === true))->toBeTrue();
});

it('shows an unassigned ticket nobody has started as Submitted and overdue, never In progress', function () {
    $now = Carbon::now();
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    // High priority is a day; reported thirty hours ago, so past its target with nobody on it.
    $late = $this->maintenance->report(
        title: 'Drain blocked',
        locationLabel: 'Lot MNT-70',
        priority: MaintenanceTicket::HIGH,
        unit: FacilitiesFixture::scratchUnit('MNT-70'),
        reportedByName: 'Late Household',
        reportedAt: SiteTime::now()->subHours(30),
    );

    try {
        $number = '#'.$late->number;
        $row = fn (): array => collect($this->maintenance->queueBoard('all', $now, Maintenance::queueTable(new Request(['q' => $number]), $now))['rows'])
            ->firstWhere('number', $late->number);

        expect($row()['status_label'])->toBe('Submitted')
            ->and($row()['is_overdue'])->toBeTrue()
            ->and(chipTotal($this->maintenance, 'open', $now, $number))->toBe(1)
            ->and(chipTotal($this->maintenance, 'in_progress', $now, $number))->toBe(0);

        // Seen in the office, still nobody on it: Open, and the state says so, in the approved words (M96, M98).
        $this->maintenance->acknowledge($late, $manager);

        expect($row()['status_label'])->toBe('Under review')
            ->and(chipTotal($this->maintenance, 'open', $now, $number))->toBe(1)
            ->and(chipTotal($this->maintenance, 'in_progress', $now, $number))->toBe(0);

        // A vendor has it: In progress, and still overdue beside it.
        $this->maintenance->assign($late->refresh(), FacilitiesFixture::vendor('Island Electric Services'));

        expect($row()['status_label'])->toBe('Assigned')
            ->and($row()['is_overdue'])->toBeTrue()
            ->and(chipTotal($this->maintenance, 'open', $now, $number))->toBe(0)
            ->and(chipTotal($this->maintenance, 'in_progress', $now, $number))->toBe(1);
    } finally {
        FacilitiesFixture::boot();
    }
});
