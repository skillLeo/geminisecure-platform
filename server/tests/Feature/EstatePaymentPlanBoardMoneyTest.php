<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\PaymentPlanInstalment;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Board 7's money, proved against the ledger (the closing order's money rule, 12 Oct)
|--------------------------------------------------------------------------
|
| Every figure on "Place on a payment plan" is one a household is asked to pay on a date: the balance being
| scheduled, the first instalment, which carries the odd cents, and the one that repeats. Each is read a second way
| here: the unit's 1200 lines summed longhand, and the instalments added back up. A household owing J$24,800.03 over
| four pays J$6,200.03 first and then J$6,200.00 three times. One lot of this file's own (the fixture estate is shared).
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->travelTo(Carbon::parse('2026-09-24 10:00', 'America/Jamaica'));
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** Lot 7591, owing J$24,800.03 since April: an uneven split, so the odd cents have somewhere to go. */
function planMoneyUnit(): Unit
{
    $unit = FacilitiesFixture::scratchUnit('Lot 7591');

    if ($unit->household === null) {
        $household = Household::create(['unit_id' => $unit->id, 'name' => 'Lot 7591 household', 'access_restricted' => true]);

        Resident::create([
            'household_id' => $household->id,
            'full_name' => 'Andrea Fletcher',
            'email' => 'lot7591@planmoney.test',
            'relationship' => 'owner',
            'is_primary' => true,
        ]);

        app(Dues::class)->charge(
            unit: $unit,
            amount: Money::of('24800.03', 'JMD'),
            description: 'Maintenance arrears brought forward',
            dueOn: Carbon::parse('2026-04-01'),
        );
    }

    return $unit->refresh();
}

/** What the household owes the ledger: the unit's 1200 lines, summed longhand. */
function planMoneyOwed(Unit $unit): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unit->id])->owed;
}

/** Board 7's props, as the page receives them. */
function planMoneyBoard(object $test, User $viewer, Unit $unit): array
{
    $props = [];

    $test->actingAs($viewer)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return $props;
}

it('draws the balance and the schedule to the cent from the ledger, the odd cents on the first instalment', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = planMoneyUnit();
    $owed = planMoneyOwed($unit);

    $board = planMoneyBoard($this, $treasurer, $unit);
    $preview = $board['preview'];
    $count = (int) $preview['instalments'];

    expect($owed)->toBe(24_800_03)
        ->and($board['balance_minor'])->toBe($owed)
        ->and($preview['total_minor'])->toBe($owed)
        ->and($preview['instalment_minor'])->toBe(intdiv($owed, $count))
        ->and($preview['first_instalment_minor'])->toBe(intdiv($owed, $count) + $owed % $count)
        ->and($preview['first_instalment_minor'] + $preview['instalment_minor'] * ($count - 1))->toBe($owed)
        ->and($preview['is_even'])->toBeFalse();
});

it('draws a live plan from its stored instalments, which add back up to what was owed', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = planMoneyUnit();
    $owed = planMoneyOwed($unit);

    // Drawn up, agreed and activated over board 7's own routes.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'), ['instalments' => 4, 'starts_on' => '2026-10-28'])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $plan = PaymentPlan::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();

    foreach (['agree', 'activate'] as $act) {
        $this->actingAs($treasurer)
            ->post(FacilitiesFixture::url('/finance/payment-plans/'.$plan->id.'/'.$act), ['agreed_by_name' => 'Andrea Fletcher'])
            ->assertSessionHasNoErrors();
    }

    FacilitiesFixture::boot();

    $stored = (int) DB::connection('tenant')
        ->table((new PaymentPlanInstalment)->getTable())
        ->where('payment_plan_id', $plan->id)
        ->sum('amount_minor');

    $board = planMoneyBoard($this, $treasurer, $unit);
    $rows = collect($board['plan']['rows']);

    expect($board['plan']['status'])->toBe('active')
        ->and($board['plan']['total_minor'])->toBe($owed)
        ->and($stored)->toBe($owed)
        ->and((int) $rows->sum('amount_minor'))->toBe($owed)
        ->and($rows->first()['amount_minor'])->toBe(intdiv($owed, 4) + $owed % 4)
        ->and($rows->last()['amount_minor'])->toBe(intdiv($owed, 4));
});
