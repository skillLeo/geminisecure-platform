<?php

declare(strict_types=1);

use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\ChargeSchedules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A reversed month can be posted again — bug hunt B021 and B078
|--------------------------------------------------------------------------
|
| September and October were both posted (a run may go a month ahead), and
| September was reversed. `nextPeriod()` was the month after the latest run
| standing — November — so September could never be billed from the schedule
| again, while the flash had just told the Treasurer "the period can be posted
| again". Reversing the only run after its month had passed skipped it the same
| way: with nothing standing, the next period fell back to this month.
|
| THE RULE NOW: months are reversed LATEST FIRST, so a schedule never has a
| month missing from the middle of what it billed; and the next period is the
| earliest month, from the schedule's first posted month, with no run standing
| — so a reversed month is always the next one offered, and "never skipped"
| holds with every run reversed too.
|
*/

const RUN_ORDER_SCHEDULE = 'Run Order Test Maintenance Fee';

const RUN_ORDER_PHASE = 'Run Order Phase';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // A site-local morning, when the UTC and Kingston calendars agree (D-143).
    $this->travelTo(Carbon::parse('2026-09-24 10:00', 'America/Jamaica'));
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    $ids = ChargeSchedule::query()->where('description', RUN_ORDER_SCHEDULE)->pluck('id');
    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->delete();
    ChargeSchedule::query()->whereIn('id', $ids)->delete();
});

/** A schedule over one lot of this file's own, so every run it posts lands on nobody else. */
function runOrderSchedule(User $by): ChargeSchedule
{
    FacilitiesFixture::scratchUnit('Lot 7431', RUN_ORDER_PHASE);

    return app(ChargeSchedules::class)->create([
        'description' => RUN_ORDER_SCHEDULE,
        'amount' => '6200.00',
        'scope' => 'phase',
        'phase' => RUN_ORDER_PHASE,
        'due_day' => 1,
        'account_code' => '4000',
    ], $by);
}

/** Post the schedule's next month against its own preview, as the Post button does. */
function runOrderPost(ChargeSchedule $schedule, User $by): ChargeRun
{
    $schedules = app(ChargeSchedules::class);
    $preview = $schedules->preview($schedule, $schedules->nextPeriod($schedule));

    return $schedules->post($schedule, $preview['period'], $preview['count'], $preview['total_minor'], $by);
}

it('reverses months latest first, and offers each reversed month to be posted again', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $schedules = app(ChargeSchedules::class);
    $schedule = runOrderSchedule($treasurer);

    $september = runOrderPost($schedule, $treasurer);
    $october = runOrderPost($schedule, $treasurer);

    expect($september->period)->toBe('2026-09')
        ->and($october->period)->toBe('2026-10');

    // September is billed at the wrong rate — but October stands after it.
    expect(fn () => $schedules->reverse($september->fresh(), 'Billed at last year\'s rate.', $treasurer))
        ->toThrow(DomainException::class, 'October 2026');

    expect($september->fresh()->isReversed())->toBeFalse()
        ->and($schedules->nextPeriod($schedule)->format('Y-m'))->toBe('2026-11');

    // Latest first: October, then September. Each is then the next month offered.
    $schedules->reverse($october->fresh(), 'Billed at last year\'s rate.', $treasurer);

    expect($schedules->nextPeriod($schedule)->format('Y-m'))->toBe('2026-10');

    $schedules->reverse($september->fresh(), 'Billed at last year\'s rate.', $treasurer);

    expect($schedules->nextPeriod($schedule)->format('Y-m'))->toBe('2026-09');

    // And it genuinely can be posted again, in order.
    expect(runOrderPost($schedule, $treasurer)->period)->toBe('2026-09')
        ->and(runOrderPost($schedule, $treasurer)->period)->toBe('2026-10');
});

it('does not skip a month whose only run was reversed after the month had passed', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $schedules = app(ChargeSchedules::class);

    $this->travelTo(Carbon::parse('2026-08-20 10:00', 'America/Jamaica'));

    $schedule = runOrderSchedule($treasurer);
    $august = runOrderPost($schedule, $treasurer);

    expect($august->period)->toBe('2026-08');

    $this->travelTo(Carbon::parse('2026-09-24 10:00', 'America/Jamaica'));

    $schedules->reverse($august->fresh(), 'Billed on the wrong register.', $treasurer);

    // Nothing stands, and August — billed, then taken back — is still owed its run.
    expect($schedules->nextPeriod($schedule->fresh())->format('Y-m'))->toBe('2026-08');
});

it('offers a month missing from the middle before any later one, where one already exists', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $schedules = app(ChargeSchedules::class);
    $schedule = runOrderSchedule($treasurer);

    $september = runOrderPost($schedule, $treasurer);
    runOrderPost($schedule, $treasurer);

    /*
     * A schedule reversed in the middle before the latest-first rule existed. The
     * rule stops a new one being made; a month already missing is still found and
     * offered, rather than passed over for November.
     */
    DB::connection('tenant')->table('charge_runs')->where('id', $september->id)->update(['reversed_at' => now()]);

    expect($schedules->nextPeriod($schedule)->format('Y-m'))->toBe('2026-09');

    // October stands after the gap and keeps the rate it was billed at: a change for it is refused, and never offered.
    expect(fn () => $schedules->amendRate($schedule->fresh(), '7000.00', '2026-10', 'The AGM voted the new fee in.', $treasurer))
        ->toThrow(DomainException::class, 'already been billed');

    $card = collect($schedules->board()['schedules'])->firstWhere('id', $schedule->id);

    expect(collect($card['rate_options'])->pluck('period')->all())
        ->toContain('2026-09')
        ->not->toContain('2026-10');
});

it('draws Reverse only on the latest month standing, says why on the others, and tells the truth in the flash', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $schedule = runOrderSchedule($treasurer);

    $september = runOrderPost($schedule, $treasurer);
    $october = runOrderPost($schedule, $treasurer);

    $runs = [];

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/charge-schedule'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$runs): void {
            $runs = collect($page->toArray()['props']['runs'])->keyBy('id')->all();
        });

    expect($runs[$october->id]['reversible'])->toBeTrue()
        ->and($runs[$october->id]['reverse_blocked'])->toBeNull()
        ->and($runs[$september->id]['reversible'])->toBeFalse()
        ->and($runs[$september->id]['reverse_blocked'])->toContain('October 2026');

    // The route refuses what the page does not offer.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charge-runs/'.$september->id.'/reverse'), ['reason' => 'Billed at last year\'s rate.'])
        ->assertSessionHasErrors('reversal');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charge-runs/'.$october->id.'/reverse'), ['reason' => 'Billed at last year\'s rate.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'October 2026 is the next month this schedule bills'));
});
