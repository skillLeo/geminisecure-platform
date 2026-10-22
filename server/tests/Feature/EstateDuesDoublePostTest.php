<?php

declare(strict_types=1);

use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\Journal;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\UnitCollectionFlag;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\ChargeSchedules;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A money state change is decided under the lock that makes it — bug hunt B020
|--------------------------------------------------------------------------
|
| Reversing a 450-unit run takes over a second, and "Confirm reversal" did not
| disable while it ran. A second press — or a second officer in another tab —
| checked `reversed_at` on the model it was handed, found it empty because the
| first press had not committed yet, and posted a second mirror entry: every
| household credited twice. Posting a month had the same gap between reading
| `nextPeriod()` and writing the run.
|
| HOW THESE TESTS RACE WITHOUT A SECOND PROCESS. The first request runs to the
| end of its work on the estate connection and holds its transaction open — the
| instant before its COMMIT. The second gets a connection of its own, as a
| second PHP worker would, and the first commits at the moment the second first
| asks the database for a lock or makes a write. Code that decides OUTSIDE the
| lock has already decided by then, on a read that could not see the first; code
| that decides under the lock reads the committed truth. That is the whole
| difference between the two, reproduced deterministically in one process.
|
*/

const DOUBLE_POST_SCHEDULE = 'Double Post Test Maintenance Fee';

const DOUBLE_POST_PHASE = 'Double Post Phase';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->travelTo(Carbon::parse('2026-09-24 10:00', 'America/Jamaica'));
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    $ids = ChargeSchedule::query()->where('description', DOUBLE_POST_SCHEDULE)->pluck('id');
    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->delete();
    ChargeSchedule::query()->whereIn('id', $ids)->delete();
});

/**
 * Run `$first` and hold its transaction open; then run `$second` on a connection of
 * its own, committing the first the instant the second first locks or writes.
 */
function doublePostRace(callable $first, callable $second): mixed
{
    FacilitiesFixture::boot();

    $a = DB::connection('tenant');
    $a->beginTransaction();

    try {
        $first();
    } catch (Throwable $failed) {
        $a->rollBack();

        throw $failed;
    }

    // The first request's session, kept alive by this reference after its
    // connection object is let go, with its transaction still open.
    $session = $a->getPdo();

    DB::purge('tenant');
    FacilitiesFixture::boot();

    $b = DB::connection('tenant');
    $b->statement('SET SESSION innodb_lock_wait_timeout = 5');

    $committed = false;

    $b->beforeExecuting(function (string $query) use (&$committed, $session): void {
        if (! $committed && preg_match('/\bfor\s+update\b|^\s*(insert|update|delete)\b/i', $query) === 1) {
            $session->commit();
            $committed = true;
        }
    });

    try {
        return $second();
    } finally {
        if (! $committed) {
            $session->commit();
        }

        DB::purge('tenant');
        FacilitiesFixture::boot();
    }
}

/** A schedule over one lot of this file's own. */
function doublePostSchedule(User $by): ChargeSchedule
{
    FacilitiesFixture::scratchUnit('Lot 7441', DOUBLE_POST_PHASE);

    return app(ChargeSchedules::class)->create([
        'description' => DOUBLE_POST_SCHEDULE,
        'amount' => '6200.00',
        'scope' => 'phase',
        'phase' => DOUBLE_POST_PHASE,
        'due_day' => 1,
        'account_code' => '4000',
    ], $by);
}

it('reverses a run once when a second press arrives while the first is still posting', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $schedules = app(ChargeSchedules::class);
    $schedule = doublePostSchedule($treasurer);

    $preview = $schedules->preview($schedule, $schedules->nextPeriod($schedule));
    $run = $schedules->post($schedule, $preview['period'], $preview['count'], $preview['total_minor'], $treasurer);
    $journal = Journal::query()->where('reference', $run->journal_ref)->firstOrFail();

    // Both presses were made from the same screen, so both hold the run as it was drawn.
    $firstPress = ChargeRun::query()->findOrFail($run->id);
    $secondPress = ChargeRun::query()->findOrFail($run->id);

    $refused = null;

    doublePostRace(
        fn () => app(ChargeSchedules::class)->reverse($firstPress, 'Billed at last year\'s rate.', $treasurer),
        function () use ($secondPress, $treasurer, &$refused): void {
            try {
                app(ChargeSchedules::class)->reverse($secondPress, 'Billed at last year\'s rate.', $treasurer);
            } catch (DomainException $refusal) {
                $refused = $refusal;
            }
        },
    );

    // One mirror entry, and the second press told why rather than posting another.
    expect(Journal::query()->where('reverses_journal_id', $journal->id)->count())->toBe(1)
        ->and($refused)->toBeInstanceOf(DomainException::class)
        ->and($refused?->getMessage())->toContain('reversed')
        ->and(ChargeRun::query()->findOrFail($run->id)->reversal_journal_ref)
        ->toBe(Journal::query()->where('reverses_journal_id', $journal->id)->value('reference'));
});

it('activates a plan only on the agreement it names, even when the agreement is re-recorded at that moment', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit('Lot 7442');
    $collections = app(Collections::class);

    if (app(Dues::class)->balanceOf($unit)->isZero()) {
        app(Dues::class)->charge(unit: $unit, amount: Money::of('12400.00', 'JMD'), description: 'Arrears brought forward', dueOn: '2026-06-01');
    }

    PaymentPlan::query()->where('unit_id', $unit->id)->whereIn('status', [PaymentPlan::DRAFT, PaymentPlan::ACTIVE])->update(['status' => PaymentPlan::CANCELLED]);

    $plan = $collections->draft($unit, 4);
    $collections->agree($plan, 'Andrea Fletcher');

    // The approver's screen reads "Andrea Fletcher's agreement".
    $onTheApproversScreen = PaymentPlan::query()->findOrFail($plan->id);

    $refused = null;

    doublePostRace(
        // The office re-records the agreement under another name...
        fn () => app(Collections::class)->agree(PaymentPlan::query()->findOrFail($plan->id), 'John Brown'),

        // ...as the approver activates the one on their screen.
        function () use ($onTheApproversScreen, $treasurer, &$refused): void {
            try {
                app(Collections::class)->activate($onTheApproversScreen, 'Andrea Fletcher', $treasurer);
            } catch (DomainException $refusal) {
                $refused = $refusal;
            }
        },
    );

    $plan->refresh();

    // Nothing was approved on an agreement the approver never saw.
    expect($plan->status)->toBe(PaymentPlan::DRAFT)
        ->and($plan->agreed_by_name)->toBe('John Brown')
        ->and($refused)->toBeInstanceOf(DomainException::class)
        ->and($refused?->getMessage())->toContain('John Brown');
});

it('flags a household once when two approvers flag it at the same moment', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit('Lot 7444');

    UnitCollectionFlag::query()->where('unit_id', $unit->id)->whereNull('lifted_at')->update(['lifted_at' => now(), 'lifted_by_name' => 'Double post fixture']);

    $refused = null;

    doublePostRace(
        fn () => app(Collections::class)->flag($unit, 'hardship', 'Job loss, agreed at committee.', 'Min. 2026-09-03 §4', $treasurer),
        function () use ($unit, $treasurer, &$refused): void {
            try {
                app(Collections::class)->flag($unit, 'dispute', 'Disputes the special assessment.', 'Min. 2026-09-03 §5', $treasurer);
            } catch (DomainException $refusal) {
                $refused = $refusal;
            }
        },
    );

    // One episode at a time, and the second approver told so.
    expect(UnitCollectionFlag::query()->where('unit_id', $unit->id)->whereNull('lifted_at')->count())->toBe(1)
        ->and($refused?->getMessage())->toContain('already flagged');
});

it('lifts a flag once, and keeps who lifted it, when two lifts land together', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $unit = FacilitiesFixture::scratchUnit('Lot 7445');

    UnitCollectionFlag::query()->where('unit_id', $unit->id)->whereNull('lifted_at')->update(['lifted_at' => now(), 'lifted_by_name' => 'Double post fixture']);

    $flag = app(Collections::class)->flag($unit, 'hardship', 'Job loss, agreed at committee.', 'Min. 2026-09-03 §4', $treasurer);

    // Both screens hold the flag as it was drawn: in force.
    $firstScreen = UnitCollectionFlag::query()->findOrFail($flag->id);
    $secondScreen = UnitCollectionFlag::query()->findOrFail($flag->id);

    $refused = null;

    doublePostRace(
        fn () => app(Collections::class)->liftFlag($firstScreen, 'Back in work.', 'Min. 2026-10-01 §3', $treasurer),
        function () use ($secondScreen, $president, &$refused): void {
            try {
                app(Collections::class)->liftFlag($secondScreen, 'Lifted at the AGM.', 'Min. 2026-10-02 §1', $president);
            } catch (DomainException $refusal) {
                $refused = $refusal;
            }
        },
    );

    $lifted = UnitCollectionFlag::query()->findOrFail($flag->id);

    expect($lifted->lifted_by_name)->toBe($treasurer->name)
        ->and($lifted->lifted_minute_reference)->toBe('Min. 2026-10-01 §3')
        ->and($refused?->getMessage())->toContain('already been lifted');
});

it('does not let "Confirm reversal" be pressed again while the reversal is in flight', function () {
    // A Vue behaviour, read off the page as `CategoryBTest` reads its screens.
    $screen = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/ChargeSchedule.vue'));

    expect(preg_match('/:disabled="[^"]*reverseInFlight[^"]*"[^>]*@click="reverse\(run\)"/s', $screen))->toBe(1)
        ->and($screen)->toMatch('/onFinish:\s*\(\)\s*=>\s*\(reverseInFlight\.value = null\)/');
});

it('posts a month once when two officers post it at the same moment', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $assistant = FacilitiesFixture::bookkeeper();
    $schedules = app(ChargeSchedules::class);
    $schedule = doublePostSchedule($treasurer);

    // Both screens drew the same preview.
    $preview = $schedules->preview($schedule, $schedules->nextPeriod($schedule));

    $refused = null;

    doublePostRace(
        fn () => app(ChargeSchedules::class)->post($schedule, $preview['period'], $preview['count'], $preview['total_minor'], $treasurer),
        function () use ($schedule, $preview, $assistant, &$refused): void {
            try {
                app(ChargeSchedules::class)->post($schedule, $preview['period'], $preview['count'], $preview['total_minor'], $assistant);
            } catch (DomainException $refusal) {
                $refused = $refusal;
            }
        },
    );

    expect(ChargeRun::query()->where('charge_schedule_id', $schedule->id)->where('period', $preview['period'])->whereNull('reversed_at')->count())->toBe(1)
        ->and($refused)->toBeInstanceOf(DomainException::class)
        ->and($refused?->getMessage())->toContain('October 2026');
});
