<?php

declare(strict_types=1);

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\Unit;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\CollectionsFixture;

/*
|--------------------------------------------------------------------------
| Plans, reminders and ageing on the estate's calendar and clock
|--------------------------------------------------------------------------
|
| Bug hunt B023 and B082 on the collections side, and B028. From 7 PM in
| Kingston the UTC date is already tomorrow, and the collections code read the
| UTC one: a charge due on the 1st aged a day early and the morning ladder chose
| the next step a day early; a plan drafted on the evening of the 27th started a
| month late; its reference carried next month. And the dunning log — the record
| a dispute is settled from — printed the 09:00 run as "Today, 2:00 PM".
|
| No HTTP here, for the reason `EstateCollectionsTest` gives: every test is one
| rolled-back transaction on the fixture estate.
|
*/

beforeEach(function () {
    CollectionsFixture::boot();

    DB::connection('tenant')->beginTransaction();

    $this->collections = app(Collections::class);
});

afterEach(function () {
    DB::connection('tenant')->rollBack();
});

/** A lot of its own, owing one charge that fell due on September 1st. */
function siteCalendarUnit(string $reference): Unit
{
    $unit = Unit::create([
        'reference' => $reference,
        'block' => 'Phase 1',
        'street' => 'Phase 1 Drive',
        'type' => 'residential',
        'status' => 'occupied',
    ]);

    app(Dues::class)->charge(
        unit: $unit,
        amount: Money::of('6200.00', 'JMD'),
        description: 'Maintenance fee — September',
        dueOn: '2026-09-01',
    );

    return $unit;
}

it('ages a charge and chooses the ladder\'s step by the estate\'s date, not the UTC one', function () {
    $unit = siteCalendarUnit('Lot 961');

    // 8:30 PM on September 30 in Kingston — October 1 in UTC. Twenty-nine days, not thirty.
    $this->travelTo(Carbon::parse('2026-09-30 20:30', 'America/Jamaica'));

    expect(app(Dues::class)->unitBuckets()[$unit->id])->toBe('current')
        ->and($this->collections->mergeFields($unit)['{days_overdue}'])->toBe('29');

    $sent = collect($this->collections->runAutomated(dryRun: true)['sent'])->firstWhere('unit', 'Lot 961');
    $step = DunningTemplate::query()->where('is_active', true)->where('days_overdue', '<=', 29)->orderByDesc('days_overdue')->orderByDesc('stage')->firstOrFail();

    expect($sent['step'] ?? null)->toBe($step->label);
});

it('draws a plan up from the estate\'s own day, and numbers it in the estate\'s month', function () {
    $unit = siteCalendarUnit('Lot 962');

    // 8:00 PM on September 27 in Kingston — the 28th in UTC. The 28th has not passed here.
    $this->travelTo(Carbon::parse('2026-09-27 20:00', 'America/Jamaica'));

    expect($this->collections->schedule($unit)['starts_on'])->toBe('2026-09-28');

    // 8:00 PM on September 30 — October in UTC.
    $this->travelTo(Carbon::parse('2026-09-30 20:00', 'America/Jamaica'));

    expect($this->collections->draft($unit)->reference)->toStartWith('PLAN-2026-09-');
});

it('prints a notice\'s time on the estate\'s clock, in the log and on the arrears list — bug hunt B028', function () {
    $unit = siteCalendarUnit('Lot 963');
    $template = DunningTemplate::query()->where('key', 'reminder1')->firstOrFail();

    // The 09:00 Kingston run is 14:00 UTC.
    $this->travelTo(Carbon::parse('2026-09-24 09:00', 'America/Jamaica'));
    $morning = $this->collections->send($unit, $template);

    $this->travelTo(Carbon::parse('2026-09-24 15:00', 'America/Jamaica'));

    // Each names its clock (M19A §4.2).
    expect(collect($this->collections->noticesFor($unit))->firstWhere('id', $morning->id)['sent'])->toBe('Today, 9:00 AM JA')
        ->and(collect($this->collections->dunningBoard()['sends'])->firstWhere('id', $morning->id)['sent'])->toBe('Today, 9:00 AM JA')
        ->and(collect(app(Dues::class)->arrearsBoard()['rows'])->firstWhere('id', $unit->id)['last_reminder'])->toBe('Today, 9:00 AM JA');

    // 6:30 PM here is 23:30 UTC, and at 7:30 PM the UTC date has turned: still today in Kingston.
    DunningNotice::query()->whereKey($morning->id)->delete();
    $this->travelTo(Carbon::parse('2026-09-24 18:30', 'America/Jamaica'));
    $evening = $this->collections->send($unit, $template);
    $this->travelTo(Carbon::parse('2026-09-24 19:30', 'America/Jamaica'));

    expect(collect($this->collections->noticesFor($unit))->firstWhere('id', $evening->id)['sent'])->toBe('Today, 6:30 PM JA')
        ->and(collect(app(Dues::class)->arrearsBoard()['rows'])->firstWhere('id', $unit->id)['last_reminder'])->toBe('Today, 6:30 PM JA');

    // And the next morning in Kingston, it is yesterday's.
    $this->travelTo(Carbon::parse('2026-09-25 08:00', 'America/Jamaica'));

    expect(collect($this->collections->noticesFor($unit))->firstWhere('id', $evening->id)['sent'])->toBe('Yesterday');
});
