<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;
use App\Services\Estate\Amenities;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A booking's deposit says its state as a badge, with when (M94 §1.2, M96 §1)
|--------------------------------------------------------------------------
|
| Hassam, 11 Oct: none of the three deposit buttons on a finished booking worked, and they said nothing on hover. The
| booking now carries its deposit's state as a badge, "Deposit refunded · 6 Oct", and whether it is settled, so the
| page shows the badge and no buttons when nothing is left to do: refunded, forfeited, or a deposit a declined
| booking will never need. Held says since when, so "Record deposit received" can go.
|
| EVERY BOOKING HERE IS THIS FILE'S OWN, on the Club House and a lot no other file stands on (Lot 967).
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->amenities = app(Amenities::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function badgedBooking(Amenities $amenities, int $days): AmenityBooking
{
    $day = Carbon::today()->addDays($days)->format('Y-m-d');

    return $amenities->book(
        amenity: FacilitiesFixture::amenity('Club House'),
        unit: FacilitiesFixture::scratchUnit('Lot 967'),
        residentName: 'Badge Walker',
        startsAt: $day.' 15:00',
        endsAt: $day.' 17:00',
        guests: 10,
    );
}

it('says pending, then held since when, then refunded on when and settled', function () {
    // Month first, as every other date on the booking page is written (12 October: it read "11 Oct" beside "Oct 11").
    $today = SiteTime::today()->format('M j');
    $booking = $this->amenities->approve(badgedBooking($this->amenities, 30));

    $deposit = fn (): array => $this->amenities->bookingBoard(AmenityBooking::findOrFail($booking->id))['deposit'];

    expect($deposit()['badge'])->toBe(['label' => 'Deposit pending', 'tone' => 'pending'])
        ->and($deposit()['settled'])->toBeFalse();

    $this->amenities->holdDeposit(AmenityBooking::findOrFail($booking->id));

    expect($deposit()['badge'])->toBe(['label' => 'Deposit held · '.$today, 'tone' => 'in_progress'])
        ->and($deposit()['settled'])->toBeFalse();

    $this->amenities->refundDeposit(AmenityBooking::findOrFail($booking->id));

    expect($deposit()['badge'])->toBe(['label' => 'Deposit refunded · '.$today, 'tone' => 'completed'])
        ->and($deposit()['settled'])->toBeTrue();
});

it('says forfeited on when and settled', function () {
    $booking = $this->amenities->approve(badgedBooking($this->amenities, 31));
    $this->amenities->holdDeposit($booking);
    $this->amenities->forfeitDeposit(AmenityBooking::findOrFail($booking->id), 'Glass door cracked.', keepMinor: 1_000_00);

    $deposit = $this->amenities->bookingBoard(AmenityBooking::findOrFail($booking->id))['deposit'];

    expect($deposit['badge'])->toBe(['label' => 'Deposit forfeited · '.SiteTime::today()->format('M j'), 'tone' => 'closed'])
        ->and($deposit['settled'])->toBeTrue();
});

it('says no deposit is due on a request that expired before its deposit moved on, and is settled', function () {
    // The morning run expires an undecided request and moves its deposit on as its next step; between the two, there is
    // nothing to press.
    $booking = $this->amenities->expire(badgedBooking($this->amenities, 32));

    $deposit = $this->amenities->bookingBoard(AmenityBooking::findOrFail($booking->id))['deposit'];

    expect($deposit['badge'])->toBe(['label' => 'No deposit due', 'tone' => 'closed'])
        ->and($deposit['settled'])->toBeTrue();
});

it('carries no deposit card at all once a declined booking has moved its deposit on', function () {
    $booking = $this->amenities->decline(badgedBooking($this->amenities, 33), 'The Club House is closed for repairs that week.');

    $deposit = $this->amenities->bookingBoard(AmenityBooking::findOrFail($booking->id))['deposit'];

    expect($deposit['amount'])->toBeNull()
        ->and($deposit['badge'])->toBeNull();
});
