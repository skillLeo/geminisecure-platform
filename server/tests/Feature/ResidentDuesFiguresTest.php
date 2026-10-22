<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Charge;
use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\ChargeSchedules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| What the Resident App is told a household owes — bug hunt B057, B058, B121
|--------------------------------------------------------------------------
|
| B057. `invoices.index` spread the ledger balance over every charge on the lot,
| newest first, and never asked whether a charge had been REVERSED. A reversed
| run's charges stay on the table with status `reversed` — the mirror entry took
| them back out of the balance — so the walk handed the whole balance to the
| reversed charge and left the charge actually owed reading `paid`. `pay-intent`
| read the same walk: nothing due on the real bill, the full amount on the bill
| that was taken back, and its reference to quote.
|
| B058 and B121. "Today" was UTC's. From 7 PM in Kingston UTC is already tomorrow,
| so a charge due tomorrow read `overdue`, a charge due today read one day
| overdue on the balance, and `as_at` named tomorrow's date. A charge was also
| `overdue` for the whole of the day it fell due (midnight has passed), which the
| ageing — `due_on <= as-at`, zero days on the due date — has never said.
|
| THIS FILE'S OWN GROUND. The estate database is committed and shared, and a dues
| run bills real lots, so each test bills one lot of its own in a block of its
| own, and every run it posted is reversed afterwards — the ledger for those lots
| ends where it began.
|
*/

const FX_DUES_SCHEDULE = 'Fx Mobile Dues Figures';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    $this->schedules = app(ChargeSchedules::class);
    $this->office = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    FacilitiesFixture::boot();

    $ids = ChargeSchedule::query()->where('description', 'like', FX_DUES_SCHEDULE.'%')->pluck('id');

    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->whereNull('reversed_at')->get()
        ->each(fn (ChargeRun $run) => $this->schedules->reverse($run, 'Resident dues figures test, taken back out.', $this->office));

    DB::connection('tenant')->table('charge_schedule_rate_changes')->whereIn('charge_schedule_id', $ids)->delete();
    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->delete();
    ChargeSchedule::query()->whereIn('id', $ids)->delete();

    DB::connection('mysql')->rollBack();
});

/** A lot of this file's own, in a block of its own, so a phase run bills it and nothing else. */
function fxDuesLot(string $reference, string $block): Unit
{
    return FacilitiesFixture::scratchUnit($reference, $block);
}

/** A schedule billing that one block, J$10,000 a month, due on the given day. */
function fxDuesSchedule(User $by, string $block, int $dueDay): ChargeSchedule
{
    FacilitiesFixture::boot();

    return app(ChargeSchedules::class)->create([
        'description' => FX_DUES_SCHEDULE.' '.$block,
        'amount' => '10000.00',
        'scope' => ChargeSchedule::PHASE,
        'phase' => $block,
        'due_day' => $dueDay,
        'account_code' => '4000',
    ], $by);
}

/** Post the schedule's next month, against its own preview, as the office would. */
function fxDuesPostNext(ChargeSchedule $schedule, User $by): ChargeRun
{
    FacilitiesFixture::boot();

    $schedules = app(ChargeSchedules::class);
    $preview = $schedules->preview($schedule, $schedules->nextPeriod($schedule));

    return $schedules->post($schedule, $preview['period'], $preview['count'], $preview['total_minor'], $by);
}

/**
 * The lot's household, a resident on it, and a Resident App token — made NOW, because
 * a token idle for thirty minutes is `session_expired`, and these tests travel in time.
 *
 * @return array{0: Household, 1: string}
 */
function fxDuesResident(Unit $unit, string $seed): array
{
    FacilitiesFixture::boot();

    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Fx Dues '.$seed.' household', 'access_restricted' => false]);
    // Possibly a shared fixture lot: this resident takes the household over for this run (one primary, DESIGN §13).
    $resident = Tests\Support\HouseholdHolder::of($household, 'Fx Dues Resident '.$seed);

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'fx.dues.'.strtolower($seed).'.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Fx Dues Resident '.$seed, 'resident_id' => $resident->id, 'unit_id' => $unit->id,
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$household, $account->createToken('resident-app:dues', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

/** One call as the Resident App, held to its catalogue entry. */
function fxDuesCall(TestCase $test, string $token, string $name, array $params = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    app('auth')->forgetGuards();

    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson($uri)
        : $test->withToken($token)->json($entry['method'], $uri);

    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, $name);

    FacilitiesFixture::boot();

    return $response;
}

/** The charges `invoices.index` answers for this lot's charges, keyed by id. */
function fxDuesInvoices(TestCase $test, string $token): array
{
    return collect(fxDuesCall($test, $token, 'invoices.index')->json('items'))->keyBy('id')->all();
}

it('leaves a reversed run out of what is owed, so the charge actually owed carries the balance and the amount to pay (B057)', function () {
    $unit = fxDuesLot('Lot 7801', 'Fx Dues Reversal Close');
    $schedule = fxDuesSchedule($this->office, 'Fx Dues Reversal Close', 1);

    // August's dues, due on the 1st and never paid.
    $this->travelTo(Carbon::parse('2026-08-10 15:00:00'));
    $august = fxDuesPostNext($schedule, $this->office);

    // September's run, posted and then taken back as posted in error.
    $this->travelTo(Carbon::parse('2026-09-24 15:00:00'));
    $september = fxDuesPostNext($schedule, $this->office);
    $this->schedules->reverse($september, 'Posted in error: the estate waived September.', $this->office);

    [$household, $token] = fxDuesResident($unit, 'Reversal');

    $augustCharge = Charge::query()->where('journal_ref', $august->journal_ref)->where('unit_id', $unit->id)->sole();
    $septemberCharge = Charge::query()->where('journal_ref', $september->journal_ref)->where('unit_id', $unit->id)->sole();

    expect($septemberCharge->status)->toBe('reversed');

    $invoices = fxDuesInvoices($this, $token);

    // What the ledger says the lot owes: August, and nothing else.
    $balance = fxDuesCall($this, $token, 'households.balance', ['household' => $household->id]);
    expect($balance->json('balance'))->toBe('10000.00')
        ->and($balance->json('oldest_unpaid_due_on'))->toBe('2026-08-01')
        ->and($balance->json('days_overdue'))->toBe(54)
        // M18D §2.2: what to pay now — no plan, past its date: the whole balance, overdue since August.
        ->and($balance->json('pay_now'))->toBe(['amount' => '10000.00', 'due_on' => '2026-08-01', 'reason' => 'overdue'])
        ->and($balance->json('plan'))->toBeNull();

    // The bill actually owed carries it. The one taken back is not a bill at all.
    expect($invoices)->toHaveKey($augustCharge->id)
        ->and($invoices[$augustCharge->id]['outstanding'])->toBe('10000.00')
        ->and($invoices[$augustCharge->id]['status'])->toBe('overdue')
        ->and($invoices)->not->toHaveKey($septemberCharge->id);

    // And how to pay says the same: the full amount on August, nothing on September.
    $payAugust = fxDuesCall($this, $token, 'invoices.pay_intent', ['invoice' => $augustCharge->id]);
    $paySeptember = fxDuesCall($this, $token, 'invoices.pay_intent', ['invoice' => $septemberCharge->id]);

    expect($payAugust->json('amount_due'))->toBe('10000.00')
        ->and($payAugust->json('quote_reference'))->toContain((string) $augustCharge->reference)
        ->and($paySeptember->json('amount_due'))->toBe('0.00');
});

/*
 * M18D §2.2 and §3 — ON A PLAN, THE NEXT INSTALMENT IS WHAT TO PAY NOW, and the plan says what it is: the instalment,
 * how many, how many made, the next and last dates, when it was agreed.
 */
it('on a payment plan, asks for the next instalment and explains the plan (M18D)', function () {
    $unit = fxDuesLot('Lot 7803', 'Fx Dues Plan Close');
    $schedule = fxDuesSchedule($this->office, 'Fx Dues Plan Close', 1);

    $this->travelTo(Carbon::parse('2026-08-10 15:00:00'));
    fxDuesPostNext($schedule, $this->office);

    $this->travelTo(Carbon::parse('2026-09-24 15:00:00'));
    FacilitiesFixture::boot();
    $collections = app(App\Services\Estate\Collections::class);
    $plan = $collections->draft($unit, 4, '2026-10-02');
    $collections->agree($plan, 'Fx Dues Resident Plan');
    $collections->activate($plan, 'Fx Dues Resident Plan', new User(['name' => 'Treasurer']));

    [$household, $token] = fxDuesResident($unit, 'Plan');
    $balance = fxDuesCall($this, $token, 'households.balance', ['household' => $household->id]);

    expect($balance->json('on_payment_plan'))->toBeTrue()
        ->and($balance->json('pay_now'))->toBe(['amount' => '2500.00', 'due_on' => '2026-10-02', 'reason' => 'instalment'])
        ->and($balance->json('plan.instalment'))->toBe('2500.00')
        ->and($balance->json('plan.instalments'))->toBe(4)
        ->and($balance->json('plan.made'))->toBe(0)
        ->and($balance->json('plan.remaining'))->toBe(4)
        ->and($balance->json('plan.next_due_on'))->toBe('2026-10-02')
        ->and($balance->json('plan.next_is_overdue'))->toBeFalse()
        ->and($balance->json('plan.final_due_on'))->toBe('2027-01-02')
        ->and($balance->json('plan.agreed_on'))->toBe('2026-09-24')
        ->and($balance->json('plan.frequency'))->toBe('Monthly');

    FacilitiesFixture::boot();
    $plan->schedule()->delete();
    $plan->delete();
});

it('dates "overdue", the as-at day and the days overdue by the estate\'s calendar, not UTC\'s (B058, B121)', function () {
    $unit = fxDuesLot('Lot 7802', 'Fx Dues Evening Close');
    $schedule = fxDuesSchedule($this->office, 'Fx Dues Evening Close', 24);

    // September's dues, due on the 24th.
    $this->travelTo(Carbon::parse('2026-09-10 15:00:00'));
    $september = fxDuesPostNext($schedule, $this->office);

    $charge = Charge::query()->where('journal_ref', $september->journal_ref)->where('unit_id', $unit->id)->sole();

    expect(Carbon::parse((string) $charge->getRawOriginal('due_on'))->toDateString())->toBe('2026-09-24');

    // Four days before it falls due: nothing is in arrears, and what to pay now still says BY WHEN (M22 §4 — it used
    // to answer a null date, and the Dues page "Due — $10,000" with no day).
    $this->travelTo(Carbon::parse('2026-09-20 15:00:00', 'America/Jamaica'));
    [$household, $token] = fxDuesResident($unit, 'Early');

    $balance = fxDuesCall($this, $token, 'households.balance', ['household' => $household->id]);

    expect($balance->json('oldest_unpaid_due_on'))->toBeNull()
        ->and($balance->json('days_overdue'))->toBe(0)
        ->and($balance->json('pay_now'))->toBe(['amount' => '10000.00', 'due_on' => '2026-09-24', 'reason' => 'due']);

    // 8 PM in Kingston on the day it falls due: UTC has already reached the 25th.
    $this->travelTo(Carbon::parse('2026-09-24 20:00:00', 'America/Jamaica'));
    [$household, $token] = fxDuesResident($unit, 'Evening');

    expect(fxDuesInvoices($this, $token)[$charge->id]['status'])->toBe('due');

    $balance = fxDuesCall($this, $token, 'households.balance', ['household' => $household->id]);

    expect($balance->json('as_at'))->toBe('2026-09-24')
        ->and($balance->json('oldest_unpaid_due_on'))->toBe('2026-09-24')
        ->and($balance->json('days_overdue'))->toBe(0)
        // Due today is due, not overdue — and what to pay now says so.
        ->and($balance->json('pay_now.reason'))->toBe('due');

    // The evening after, it is overdue by one day, and the as-at day is the 25th.
    $this->travelTo(Carbon::parse('2026-09-25 20:00:00', 'America/Jamaica'));
    [, $token] = fxDuesResident($unit, 'Evening2');

    expect(fxDuesInvoices($this, $token)[$charge->id]['status'])->toBe('overdue');

    $balance = fxDuesCall($this, $token, 'households.balance', ['household' => $household->id]);

    expect($balance->json('as_at'))->toBe('2026-09-25')
        ->and($balance->json('days_overdue'))->toBe(1);
});
