<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| EST-B-01: what is owed is what is owed today (M83 §1.2 item 2, M79 §3.1; 10 October 2026)
|--------------------------------------------------------------------------
|
| Dues & ledger said 6.05M outstanding; Accounting's 1200 said 1.89M. The gap was one charge on every lot dated 12
| February 2027 (seeded, M82 answer 3), counted as owed today. A charge posts on the day it falls due, and the chart of
| accounts and the Profit & Loss read the ledger as at today; Dues did not, because `balancesQuery()` dated nothing
| unless a day was named, and no caller named one. One rule now, in one place: a unit's receivable is read as at the
| estate's today unless a day is named, for its balance, its charges and its statement.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file charges a scratch lot of its own, once per run.
|
*/

const ESTB01_LOT = 'Lot 98431';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
});

/**
 * The scratch lot, charged 6,200.00 due today and 2,000.00 due in 45 days (once per run).
 *
 * @return array{unit: Unit, today: int, later: int}
 */
function estB01Lot(): array
{
    $unit = FacilitiesFixture::scratchUnit(ESTB01_LOT);
    $dues = app(Dues::class);

    if (! DB::connection('tenant')->table('charges')->where('unit_id', $unit->id)->exists()) {
        $dues->charge($unit, Money::of('6200.00', 'JMD'), 'EST-B-01 dues due today', Ledger::today());
        $dues->charge($unit, Money::of('2000.00', 'JMD'), 'EST-B-01 dues due in 45 days', Ledger::today()->addDays(45));
    }

    $ids = DB::connection('tenant')->table('charges')->where('unit_id', $unit->id)->orderBy('due_on')->pluck('id')->all();

    return ['unit' => $unit, 'today' => (int) $ids[0], 'later' => (int) $ids[1]];
}

it('owes a charge from its day: the balance and the statement leave a later one out, and the walk keeps it unpaid', function () {
    ['unit' => $unit, 'today' => $today, 'later' => $later] = estB01Lot();
    $dues = app(Dues::class);

    expect($dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe(620000)
        ->and($dues->unitBalances(null, [$unit->id])[$unit->id] ?? null)->toBe(620000)
        ->and(collect($dues->statement($unit, 50))->pluck('balance_minor')->first())->toBe(620000);

    // The walk says what is still UNPAID on each charge, not what is owed today: the later charge is unpaid in full,
    // never paid (the QA session's part 35: a booking fee due in 30 days read 0.00 outstanding), and today's is the same
    // 6,200.00 the today-only balance gives.
    expect($dues->outstandingByCharge($unit))->toBe([$later => 200000, $today => 620000])
        ->and(collect($dues->ledgerOf($unit)['charges'])->pluck('id')->all())->toBe([$later, $today]);

    // Named, a later day still counts what is owed by then.
    expect($dues->unitBalances(Ledger::today()->addDays(45), [$unit->id])[$unit->id] ?? null)->toBe(820000)
        ->and($later)->toBeGreaterThan(0);
});

it('ties the estate\'s dues total to the ledger\'s 1200 as at today (EST-UC-16)', function () {
    estB01Lot();

    $owed = array_sum(app(Dues::class)->unitBalances());
    $receivable = app(Ledger::class)->balanceOf(Account::query()->where('code', '1200')->firstOrFail(), Ledger::today())->getMinorAmount()->toInt();

    expect($owed)->toBe($receivable);
});
