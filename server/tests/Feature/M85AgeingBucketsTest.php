<?php

declare(strict_types=1);

use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M85 A3, EST-S-02: each charge ages in its own bucket (11 October 2026)
|--------------------------------------------------------------------------
|
| The whole balance went into the oldest open charge's bucket, so a nine-day-old October charge was reported as 90+
| days overdue. M81 §3.3: "Each charge ages in its own bucket. Lot 9 on 10 Oct: Jun remainder + Jul → 90+ = 6,700 ·
| Aug → 60 = 6,200 · Sep → 30 = 6,200." A unit's single badge (how long it has had anything outstanding) is unchanged.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file charges a scratch lot of its own, once per run.
|
*/

const M85_AGEING_LOT = 'Lot 98433';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
});

/** Lot 9's shape: 6,200.00 due on the 1st of June to October, and 5,700.00 paid in July (once per run). */
function m85AgeingLot(): Unit
{
    $unit = FacilitiesFixture::scratchUnit(M85_AGEING_LOT);
    $dues = app(Dues::class);

    if (! DB::connection('tenant')->table('charges')->where('unit_id', $unit->id)->exists()) {
        foreach (['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'] as $due) {
            $dues->charge($unit, Money::of('6200.00', 'JMD'), 'EST-S-02 dues due '.$due, Carbon::parse($due));
        }

        $dues->receive($unit, Money::of('5700.00', 'JMD'), 'cash', '2026-07-15');
    }

    return $unit;
}

it('ages each charge in its own bucket: Lot 9 on 10 October', function () {
    $unit = m85AgeingLot();
    $dues = app(Dues::class);
    $on = Carbon::parse('2026-10-10');

    expect($dues->balanceOf($unit, $on)->getMinorAmount()->toInt())->toBe(25_300_00)
        // October 9 days, September 39, August 70: their own buckets. July 101 and June's remainder of 500.00: 90+.
        ->and($dues->ageingOf($unit, $on))->toBe(['current' => 6_200_00, 'd30' => 6_200_00, 'd60' => 6_200_00, 'd90' => 6_700_00])
        // The unit's one badge still says how long it has had anything outstanding: since June.
        ->and($dues->bucketOf($unit, $on))->toBe('d90');
});

it('puts each charge\'s share in the estate\'s totals, never the whole balance in the oldest bucket', function () {
    $unit = m85AgeingLot();
    $dues = app(Dues::class);
    $on = Carbon::parse('2026-10-10');

    $totals = $dues->ageing($on);

    // The estate's four totals still add up to every owing unit's balance, and this lot's 6,200.00 of October sits in
    // Current with the rest of the estate's current debt, not in 90+.
    $owed = array_sum(array_filter($dues->unitBalances($on), static fn (int $b): bool => $b > 0));

    expect(array_sum($totals))->toBe($owed)
        ->and($totals['current'])->toBeGreaterThanOrEqual(6_200_00)
        ->and($dues->ageingOf($unit, $on)['current'])->toBe(6_200_00);
});
