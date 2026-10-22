<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Household;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\PaymentPlanInstalment;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\Restriction\RestrictionPolicy;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An instalment is ruled met or missed — bug hunt B019
|--------------------------------------------------------------------------
|
| A household owing J$24,800 agreed four instalments and the Treasurer
| activated the plan. The household never paid. Every morning `dunning:run`
| counted the unit "on plan" and sent nothing, the gate went on admitting its
| guests and the amenity diary its bookings — for ever, because no screen and
| no route could rule an instalment missed, and nothing else ends a plan. The
| other way round, a household that paid it all off was never COMPLETED. And
| board 7 promised "dunning resumes automatically".
|
| THE RULING IS A PERSON'S, as the model says it must be: an approver rules each
| instalment met or — once its date has passed — missed, from board 7. Missed
| defaults the plan, which takes the shield off at the gate and puts the
| household back on the morning ladder. The register and the morning run both
| name every instalment past its date that nobody has ruled on yet.
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

/**
 * A lot of this file's own, restricted for arrears, owing J$24,800 since the spring.
 */
function rulingsUnit(string $reference): Unit
{
    $unit = FacilitiesFixture::scratchUnit($reference);

    if ($unit->household === null) {
        $household = Household::create(['unit_id' => $unit->id, 'name' => $reference.' household', 'access_restricted' => true]);

        Resident::create([
            'household_id' => $household->id,
            'full_name' => 'Andrea Fletcher',
            'email' => str_replace(' ', '', strtolower($reference)).'@rulings.test',
            'relationship' => 'owner',
            'is_primary' => true,
        ]);

        app(Dues::class)->charge(
            unit: $unit,
            amount: Money::of('24800.00', 'JMD'),
            description: 'Maintenance arrears brought forward',
            dueOn: Carbon::parse('2026-04-01'),
        );
    }

    return $unit->refresh();
}

/** Draft, agree and activate a plan over the HTTP routes board 7 posts to. */
function rulingsActivePlan(object $test, Unit $unit, User $treasurer, string $startsOn = '2026-05-28'): PaymentPlan
{
    $test->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'), ['instalments' => 4, 'starts_on' => $startsOn])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $plan = PaymentPlan::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();

    $test->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/payment-plans/'.$plan->id.'/agree'), ['agreed_by_name' => 'Andrea Fletcher'])
        ->assertSessionHasNoErrors();

    $test->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/payment-plans/'.$plan->id.'/activate'), ['agreed_by_name' => 'Andrea Fletcher'])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    return $plan->fresh(['schedule']);
}

it('lets an approver rule a past-due instalment missed, which ends the shield at the gate and on the ladder', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $assistant = FacilitiesFixture::bookkeeper();
    $unit = rulingsUnit('Lot 7451');
    $plan = rulingsActivePlan($this, $unit, $treasurer);
    $collections = app(Collections::class);
    $policy = new RestrictionPolicy;

    // Shielded: the morning run counts it on a plan, and the gate admits its guests.
    $before = $collections->runAutomated(dryRun: true);

    expect($collections->isProtected($unit))->toBeTrue()
        ->and($policy->decide($unit->household, 'guest')['admitted'])->toBeTrue()
        ->and($before['awaiting_ruling'])->toContain($plan->reference);

    $first = $plan->schedule->first();

    // A ruling that puts a restriction back is an approval, not data entry.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/payment-plan-instalments/'.$first->id.'/missed'))
        ->assertForbidden();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/payment-plan-instalments/'.$first->id.'/missed'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'))
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'defaulted')
            && str_contains($flash, 'restricted again'));

    FacilitiesFixture::boot();

    $after = $collections->runAutomated(dryRun: true);

    expect($plan->fresh()->status)->toBe(PaymentPlan::DEFAULTED)
        ->and($first->fresh()->status)->toBe(PaymentPlanInstalment::MISSED)
        ->and($collections->isProtected($unit))->toBeFalse()
        ->and($policy->decide($unit->fresh()->household, 'guest')['admitted'])->toBeFalse()
        ->and($after['on_plan'])->toBe($before['on_plan'] - 1)
        ->and($after['awaiting_ruling'])->not->toContain($plan->reference);
});

it('refuses to rule missed an instalment whose date has not come', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = rulingsUnit('Lot 7452');

    // A plan agreed today, its first instalment on the 28th.
    $plan = rulingsActivePlan($this, $unit, $treasurer, '2026-09-28');
    $first = $plan->schedule->first();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/payment-plan-instalments/'.$first->id.'/missed'))
        ->assertSessionHasErrors('instalment');

    FacilitiesFixture::boot();

    expect($plan->fresh()->status)->toBe(PaymentPlan::ACTIVE)
        ->and(app(Collections::class)->isProtected($unit))->toBeTrue();
});

it('completes a plan whose every instalment is ruled met, and lifts the restriction once the ledger is clear', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = rulingsUnit('Lot 7453');
    $plan = rulingsActivePlan($this, $unit, $treasurer);

    // The money moves where every receipt does.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payments'), [
            'method' => 'bank', 'amount' => '24800.00', 'received_on' => '2026-09-24', 'reference' => 'Plan settled',
        ])
        ->assertSessionHasNoErrors();

    foreach ($plan->schedule as $instalment) {
        $this->actingAs($treasurer)
            ->post(FacilitiesFixture::url('/finance/payment-plan-instalments/'.$instalment->id.'/met'))
            ->assertSessionHasNoErrors();
    }

    FacilitiesFixture::boot();

    expect($plan->fresh()->status)->toBe(PaymentPlan::COMPLETED)
        ->and($unit->fresh()->household->access_restricted)->toBeFalse();
});

it('draws every instalment on board 7 with what may be ruled on it, and names the ones past due on the register', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $unit = rulingsUnit('Lot 7454');
    $plan = rulingsActivePlan($this, $unit, $treasurer);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Dues/PaymentPlan')
            ->where('canApprove', true)
            ->where('plan.rows', function ($rows): bool {
                $rows = collect($rows);

                // May, June, July, August are past; nothing else in a four-instalment plan.
                return $rows->count() === 4
                    && $rows->every(fn (array $row): bool => $row['status'] === 'due' && $row['overdue'] === true);
            }));

    // A committee member reads it and rules on nothing.
    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canApprove', false));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/finance/payment-plan-instalments/'.$plan->schedule->first()->id.'/met'))
        ->assertForbidden();

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/payment-plans'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', fn ($rows): bool => str_contains(
            (string) (collect($rows)->firstWhere('id', $plan->id)['progress'] ?? ''),
            '4 past due, not ruled',
        )));
});

it('draws each plan on the register for what the household owed the ledger, split exactly into its instalments', function () {
    /*
     * THE MONEY RULE (M97). A plan's Total on the register is the balance it was drawn up for, so it is read a second
     * way here: the unit's 1200 lines summed longhand before the plan, and the plan's own instalments summed longhand.
     * Its Next instalment is the earliest one still due, amount and date.
     */
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = rulingsUnit('Lot 7459');

    $owed = (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unit->id])->owed;

    $plan = rulingsActivePlan($this, $unit, $treasurer);

    $split = DB::connection('tenant')
        ->table((new PaymentPlanInstalment)->getTable())
        ->where('payment_plan_id', $plan->id)
        ->orderBy('due_on')
        ->get();

    $props = [];

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/payment-plans'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    $row = collect($props['rows'])->firstWhere('id', $plan->id);
    $first = $split->first();

    expect($owed)->toBe(24_800_00)
        ->and((int) $split->sum('amount_minor'))->toBe($owed)
        ->and($row['total'])->toBe(MoneyFormatter::fromMinor($owed))
        ->and($row['next'])->toBe(MoneyFormatter::fromMinor((int) $first->amount_minor).' due '.Carbon::parse($first->due_on)->format('M j, Y'));
});

it('rules only an active plan\'s instalments, keeps who ruled, and lets a second press change nothing', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = rulingsUnit('Lot 7457');

    // A draft owes nothing under it yet, so nothing on it is ruled.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'), ['instalments' => 4, 'starts_on' => '2026-05-28'])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $draft = PaymentPlan::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/payment-plan-instalments/'.$draft->schedule()->firstOrFail()->id.'/met'))
        ->assertSessionHasErrors('instalment');

    expect(session('errors')->first('instalment'))->toContain('has not been activated');

    $this->actingAs($treasurer)->post(FacilitiesFixture::url('/finance/payment-plans/'.$draft->id.'/agree'), ['agreed_by_name' => 'Andrea Fletcher']);
    $this->actingAs($treasurer)->post(FacilitiesFixture::url('/finance/payment-plans/'.$draft->id.'/activate'), ['agreed_by_name' => 'Andrea Fletcher']);

    FacilitiesFixture::boot();
    [$first, $second] = $draft->fresh()->schedule->take(2)->all();

    // Missed, twice — as a double press would send it. The second finds it ruled and changes nothing.
    foreach ([1, 2] as $press) {
        $this->actingAs($treasurer)
            ->post(FacilitiesFixture::url('/finance/payment-plan-instalments/'.$first->id.'/missed'))
            ->assertSessionHasNoErrors();
    }

    FacilitiesFixture::boot();

    /*
     * THIS ESTATE'S ruling of THIS instalment. Instalment ids start at 1 in every
     * estate's database and the audit log is shared, so an id alone can be another
     * estate's instalment — another file rules instalments missed in estates of its
     * own. An audit row is identified by its estate and its entity together.
     */
    $rulings = fn () => AuditEntry::query()
        ->where('tenant_id', FacilitiesFixture::ESTATE)
        ->where('action', 'estate.payment_plan_instalment_missed')
        ->where('entity_type', 'PaymentPlanInstalment')
        ->where('entity_id', (string) $first->id);

    expect($draft->fresh()->status)->toBe(PaymentPlan::DEFAULTED)
        ->and($rulings()->count())->toBe(1)
        ->and($rulings()->value('actor_id'))->toBe($treasurer->id);

    // A defaulted plan is history: its other instalments are not ruled any more.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/payment-plan-instalments/'.$second->id.'/met'))
        ->assertSessionHasErrors('instalment');

    expect(session('errors')->first('instalment'))->toContain('defaulted');

    FacilitiesFixture::boot();

    expect($second->fresh()->status)->toBe(PaymentPlanInstalment::DUE);
});

it('lets the office withdraw a draft, so a stale draft does not block a new plan for ever', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $assistant = FacilitiesFixture::bookkeeper();
    $unit = rulingsUnit('Lot 7455');

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'), ['instalments' => 4, 'starts_on' => '2026-09-28'])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $draft = PaymentPlan::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();

    // Two live plans over one balance is two schedules a household can be held to.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'), ['instalments' => 6, 'starts_on' => '2026-09-28'])
        ->assertSessionHasErrors('instalments');

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/payment-plans/'.$draft->id.'/withdraw'))
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    expect($draft->fresh()->status)->toBe(PaymentPlan::CANCELLED);

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'), ['instalments' => 6, 'starts_on' => '2026-09-28'])
        ->assertSessionHasNoErrors();
});

it('names on the morning run every plan with an instalment past due and not ruled on', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = rulingsUnit('Lot 7456');
    $plan = rulingsActivePlan($this, $unit, $treasurer);

    expect(Artisan::call('dunning:run', ['--estate' => FacilitiesFixture::ESTATE, '--dry-run' => true]))->toBe(0);

    // Read once: `Artisan::output()` empties the buffer it reads from.
    $output = Artisan::output();

    expect($output)->toContain($plan->reference)
        ->and($output)->toContain('past due and not yet ruled');

    FacilitiesFixture::boot();
});

it('no longer promises on board 7 what nothing does', function () {
    $screen = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/PaymentPlan.vue'));
    $template = (string) preg_replace('/<!--.*?-->/s', '', $screen);

    expect($template)->not->toContain('dunning resumes automatically')
        ->and($template)->not->toContain('while it is met')
        ->and($template)->not->toContain('while every instalment is met')
        ->and($template)->not->toContain('Finish or default');

    $controller = (string) file_get_contents(app_path('Http/Controllers/Estate/CollectionsController.php'));

    expect($controller)->not->toContain('while it is being met');
});
