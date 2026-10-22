<?php

declare(strict_types=1);

use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\Journal;
use App\Models\Role;
use App\Services\Estate\ChargeSchedules;
use App\Services\Estate\Dues;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The charge schedule — board 5's tab (12 §2, item 17)
|--------------------------------------------------------------------------
|
| "Recurring run with preview and reversal." A month posts against the figures
| its preview showed, once, as one entry; a wrong month is reversed whole and
| can then be posted again. Defining a schedule and reversing a month are
| `approve`; posting a month is the office's `create`.
|
*/

const SCHEDULE_TEST_DESCRIPTION = 'Schedule Test Maintenance Fee';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    $ids = ChargeSchedule::query()->where('description', SCHEDULE_TEST_DESCRIPTION)->pluck('id');
    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->delete();
    ChargeSchedule::query()->whereIn('id', $ids)->delete();
});

it('previews a month, posts it once as one entry, and reverses it whole', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $assistant = FacilitiesFixture::bookkeeper();
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $schedule = [
        'description' => SCHEDULE_TEST_DESCRIPTION,
        'amount' => '6200.00',
        'scope' => 'estate',
        'due_day' => 1,
        'account_code' => '4000',
    ];

    // Defining what every household is billed is approval, not data entry.
    $this->actingAs($assistant)->post(FacilitiesFixture::url('/finance/charge-schedule'), $schedule)->assertForbidden();

    $this->actingAs($treasurer)->post(FacilitiesFixture::url('/finance/charge-schedule'), $schedule)->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $model = ChargeSchedule::query()->where('description', SCHEDULE_TEST_DESCRIPTION)->sole();

    $preview = null;

    $this->actingAs($assistant)
        ->get(FacilitiesFixture::url('/finance/charge-schedule'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$preview, $model) {
            $page->component('Estate/Dues/ChargeSchedule')
                ->where('canPost', true)
                ->where('canSetRates', false)
                ->where('canReverse', false);

            $preview = collect($page->toArray()['props']['schedules'])->firstWhere('id', $model->id)['preview'];
        });

    expect($preview)->not->toBeNull()
        ->and($preview['period'])->toBe(now()->format('Y-m'))
        ->and($preview['total_minor'])->toBe($preview['count'] * 6_200_00);

    $post = fn (array $overrides = []) => [
        'period' => $preview['period'],
        'expected_count' => $preview['count'],
        'expected_total_minor' => $preview['total_minor'],
        ...$overrides,
    ];

    // A committee member reads the schedule and does not bill anybody from it.
    $this->actingAs($president)->post(FacilitiesFixture::url('/finance/charge-schedule/'.$model->id.'/runs'), $post())->assertForbidden();

    // A LIST NOBODY SAW IS NOT BILLED: figures that disagree with the register are refused.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/charge-schedule/'.$model->id.'/runs'), $post(['expected_count' => $preview['count'] + 1]))
        ->assertSessionHasErrors('run');

    FacilitiesFixture::boot();
    $before = app(Dues::class)->unitBalances();

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/charge-schedule/'.$model->id.'/runs'), $post())
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $run = ChargeRun::query()->where('charge_schedule_id', $model->id)->sole();

    expect($run->unit_count)->toBe($preview['count'])
        ->and(Journal::query()->where('reference', $run->journal_ref)->exists())->toBeTrue()
        ->and(DB::connection('tenant')->table('charges')->where('journal_ref', $run->journal_ref)->count())->toBe($preview['count']);

    $after = app(Dues::class)->unitBalances();
    $unitId = (int) array_key_first($after);

    expect(($after[$unitId] ?? 0) - ($before[$unitId] ?? 0))->toBe(6_200_00);

    /*
     * THE MONEY RULE (M97): the posted month's Total in the table is what its entry put on the households' accounts,
     * read a second way: the 1200 lines of that one entry, summed longhand. It has to be the preview's total, and the
     * table has to print that figure.
     */
    $posted = (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS posted
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.entry_ref = ?
    ', ['1200', $run->journal_ref])->posted;

    $shown = null;

    $this->actingAs($assistant)
        ->get(FacilitiesFixture::url('/finance/charge-schedule'))
        ->assertInertia(function (AssertableInertia $page) use (&$shown, $run): void {
            $shown = collect($page->toArray()['props']['runs'])->firstWhere('id', $run->id);
        });

    FacilitiesFixture::boot();

    expect($posted)->toBe($preview['total_minor'])
        ->and($shown['total'] ?? null)->toBe(MoneyFormatter::fromMinor($posted));

    // ONCE: the same month cannot be billed twice.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/charge-schedule/'.$model->id.'/runs'), $post())
        ->assertSessionHasErrors('run');

    /* Reversal. */
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/charge-runs/'.$run->id.'/reverse'), ['reason' => 'Billed at last year\'s rate.'])
        ->assertForbidden();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charge-runs/'.$run->id.'/reverse'), ['reason' => 'wrong'])
        ->assertSessionHasErrors('reversal');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charge-runs/'.$run->id.'/reverse'), ['reason' => 'Billed at last year\'s rate.'])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $run->refresh();

    expect($run->isReversed())->toBeTrue()
        ->and(Journal::query()->where('reference', $run->reversal_journal_ref)->exists())->toBeTrue()
        ->and(DB::connection('tenant')->table('charges')->where('journal_ref', $run->journal_ref)->where('status', '!=', 'reversed')->count())->toBe(0);

    // The mirror took it all back out of the receivable.
    expect(app(Dues::class)->unitBalances()[$unitId] ?? 0)->toBe($before[$unitId] ?? 0);

    // Never twice — and the reversed month can now be posted again.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charge-runs/'.$run->id.'/reverse'), ['reason' => 'Billed at last year\'s rate.'])
        ->assertSessionHasErrors('reversal');

    expect(app(ChargeSchedules::class)->nextPeriod($model->fresh())->format('Y-m'))->toBe(now()->format('Y-m'));
});

it('lists every payment plan on the register tab', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $propertyManager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/payment-plans'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Dues/PaymentPlans')
            ->has('rows')
            ->has('counts.active')
            ->where('filter', ''));

    // A filter the register does not have is ignored, not trusted.
    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/payment-plans?status=whatever'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filter', ''));

    // D-010: the Property Manager never reads what a household owes.
    $this->actingAs($propertyManager)->get(FacilitiesFixture::url('/finance/payment-plans'))->assertForbidden();
});
