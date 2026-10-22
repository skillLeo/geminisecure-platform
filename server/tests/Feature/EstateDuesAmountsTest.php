<?php

declare(strict_types=1);

use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\Payment;
use App\Models\Role;
use App\Services\Estate\ChargeSchedules;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An amount the ledger cannot hold is a field error — bug hunt B022 and B081
|--------------------------------------------------------------------------
|
| "6200.005" is one key too many at the counter, and "100000000000000000" is a
| finger resting on a key. Both used to pass `numeric`, reach `Money::of`, and
| come back as a 500 page: Brick refuses a third decimal with a
| RoundingNecessaryException and an amount past PHP_INT_MAX cents with an
| IntegerOverflowException, and neither is the DomainException the forms catch.
|
| THE RULE IS AT THE DOOR NOW: two decimals at most and a ceiling every figure
| on these screens fits under, so the refusal is a sentence under the Amount
| field and the Treasurer keeps what they typed. Nothing is written in either
| case — the refusal comes before any transaction.
|
*/

const AMOUNTS_TEST_SCHEDULE = 'Amounts Test Maintenance Fee';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    ChargeSchedule::query()->where('description', AMOUNTS_TEST_SCHEDULE)->delete();
});

it('refuses an amount the ledger cannot hold as a field error on every dues form, and writes nothing', function (string $amount) {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit('Lot 7411');
    $today = now()->toDateString();

    $payments = Payment::query()->count();
    $charges = DB::connection('tenant')->table('charges')->count();
    $schedules = ChargeSchedule::query()->count();

    // Record payment — board 6, a plain text field with no transform.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payments'), [
            'method' => 'cash',
            'amount' => $amount,
            'received_on' => $today,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('amount');

    // A single-unit charge.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges'), [
            'unit' => $unit->reference,
            'type' => 'fine',
            'amount' => $amount,
            'description' => 'Amounts test fine',
            'due_on' => $today,
            'account' => '4000',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('amount');

    // The first press of a whole-estate charge, which posts nothing even when it works.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'estate',
            'type' => 'fine',
            'amount' => $amount,
            'description' => 'Amounts test assessment',
            'due_on' => $today,
            'account' => '4000',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('amount');

    // Defining a schedule.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charge-schedule'), [
            'description' => AMOUNTS_TEST_SCHEDULE,
            'amount' => $amount,
            'scope' => 'estate',
            'due_day' => 1,
            'account_code' => '4000',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('amount');

    FacilitiesFixture::boot();

    expect(Payment::query()->count())->toBe($payments)
        ->and(DB::connection('tenant')->table('charges')->count())->toBe($charges)
        ->and(ChargeSchedule::query()->count())->toBe($schedules);
})->with([
    'a third decimal' => '6200.005',
    'more cents than an integer holds' => '100000000000000000',
]);

it('still takes an amount written with no cents, or with one or two decimals', function (string $amount) {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    // The preview writes nothing, so the positive control leaves no mark on the estate.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'estate',
            'type' => 'fine',
            'amount' => $amount,
            'description' => 'Amounts test assessment',
            'due_on' => now()->toDateString(),
            'account' => '4000',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirectContains('/finance/charges/new?preview=');
})->with(['6200', '6200.5', '6200.50']);

it('refuses a schedule amount it cannot hold in the service too, as a sentence rather than an exception', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    // The service is the documented guard for every caller, not only the form.
    expect(fn () => app(ChargeSchedules::class)->create([
        'description' => AMOUNTS_TEST_SCHEDULE,
        'amount' => '6200.005',
        'scope' => 'estate',
        'due_day' => 1,
        'account_code' => '4000',
    ], $treasurer))->toThrow(DomainException::class, 'dollars and cents');
});

it('refuses a rate change the ledger cannot hold as a field error', function (string $amount) {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    FacilitiesFixture::boot();

    $schedule = app(ChargeSchedules::class)->create([
        'description' => AMOUNTS_TEST_SCHEDULE,
        'amount' => '6200.00',
        'scope' => 'estate',
        'due_day' => 1,
        'account_code' => '4000',
    ], $treasurer);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charge-schedule/'.$schedule->id.'/rate'), [
            'amount' => $amount,
            'effective_period' => now()->addMonthNoOverflow()->format('Y-m'),
            'reason' => 'The AGM voted the new fee in.',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('amount');
})->with([
    'a third decimal' => '6200.005',
    'more cents than an integer holds' => '100000000000000000',
]);
