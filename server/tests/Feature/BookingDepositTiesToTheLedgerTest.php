<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;
use App\Models\Role;
use App\Services\Estate\Amenities;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A booking's deposit, as its page shows it, is the deposit the books hold
|--------------------------------------------------------------------------
|
| THE MONEY RULE (M97): every figure on a page ties to the ledger. The booking page shows one money figure that moves,
| the deposit, and once it is recorded as received the estate holds it in 2200 Resident Deposits Held under the entry
| the booking names. That entry's 2200 lines are summed here longhand, and the page has to show the same figure and the
| state the entry put it in. `EstateAmenityDepositsTest` owns the state machine and the entries themselves.
|
| THIS FILE'S OWN BOOKING, on its own lot: the estate database is shared by every file in the run and is not rolled
| back, so nothing below reads a total.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** What one entry put into 2200 Resident Deposits Held, less what it took out, summed longhand. */
function bookingDepositHeldBy(string $reference): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.credit_minor - l.debit_minor), 0) AS held
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.entry_ref = ?
    ', ['2200', $reference])->held;
}

it('shows the deposit the ledger holds for the booking, once it is recorded as received', function () {
    $amenities = app(Amenities::class);
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $day = Carbon::today()->addDays(331)->format('Y-m-d');

    $booking = $amenities->approve($amenities->book(
        amenity: FacilitiesFixture::amenity('Club House'),
        unit: FacilitiesFixture::scratchUnit('Lot 993'),
        residentName: 'Ledger Tie',
        startsAt: $day.' 15:00',
        endsAt: $day.' 17:00',
        guests: 8,
    ));

    $detail = FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id);

    // Recorded through the page's own door, as the office does it.
    $this->actingAs($manager)
        ->post($detail.'/deposit/hold', ['on' => null])
        ->assertRedirect($detail)
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $held = AmenityBooking::query()->findOrFail($booking->id);
    $inTheBooks = bookingDepositHeldBy((string) $held->deposit_journal_ref);

    $props = [];

    $this->actingAs($manager)
        ->get($detail)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    expect($inTheBooks)->toBeGreaterThan(0)
        ->and($props['deposit']['amount'])->toBe(AmenityBooking::amount($inTheBooks))
        ->and($props['deposit']['state'])->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($props['deposit']['settled'])->toBeFalse();
});
