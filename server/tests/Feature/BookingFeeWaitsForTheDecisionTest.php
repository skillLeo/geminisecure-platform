<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;
use App\Models\Role;
use App\Services\Estate\Amenities;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A booking fee is charged once the estate has said yes (B052)
|--------------------------------------------------------------------------
|
| Recording a booking fee refused a declined or cancelled booking and let a
| PENDING one through. The charge was posted to the unit — Dr 1200, Cr 4100 — and
| a decline afterwards, the only thing a pending booking can become besides
| confirmed, left the household billed for a booking the estate had turned down.
| Board 19 only draws the button on a confirmed or completed row; the server now
| holds the same rule for a request that did not come from that button.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the bookings here stand on
| scratch lots of this file's own, on days nobody else books.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->amenities = app(Amenities::class);
    $this->admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** A unit's own receivable on 1200, summed from the lines. */
function bookingFeeReceivable(int $unitId): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unitId])->net;
}

it('will not charge a booking fee while the booking is still waiting on the estate\'s decision', function () {
    // The role the finding names holds both gates the route asks for.
    expect($this->admin->can('estate.facilities.update'))->toBeTrue()
        ->and($this->admin->can('estate.dues_ledger.create'))->toBeTrue();

    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Community Centre'),
        unit: FacilitiesFixture::scratchUnit('AMN-2'),
        residentName: 'Pending Household',
        startsAt: SiteTime::today()->addDays(158)->addHours(10),
        endsAt: SiteTime::today()->addDays(158)->addHours(12),
    );

    expect($booking->status)->toBe(AmenityBooking::PENDING)
        ->and($booking->fee_minor)->toBeGreaterThan(0);

    $before = bookingFeeReceivable($booking->unit_id);

    $this->actingAs($this->admin)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertSessionHasErrors('fee');

    FacilitiesFixture::boot();

    expect(AmenityBooking::query()->findOrFail($booking->id)->fee_charge_id)->toBeNull()
        ->and(bookingFeeReceivable($booking->unit_id))->toBe($before);

    // The service refuses it too — the route is not the only door.
    expect(fn () => $this->amenities->recordFee($booking->refresh()))
        ->toThrow(DomainException::class, 'waiting');

    // Declined, the household owes nothing for it.
    $this->amenities->decline($booking->refresh(), 'The centre is closed that weekend');

    expect(bookingFeeReceivable($booking->unit_id))->toBe($before);
});

it('charges the fee once the booking is confirmed, which is what the diary\'s button is for', function () {
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Community Centre'),
        unit: FacilitiesFixture::scratchUnit('AMN-3'),
        residentName: 'Confirmed Household',
        startsAt: SiteTime::today()->addDays(159)->addHours(10),
        endsAt: SiteTime::today()->addDays(159)->addHours(12),
    );

    $this->amenities->approve($booking);

    $before = bookingFeeReceivable($booking->unit_id);

    $this->actingAs($this->admin)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $charged = AmenityBooking::query()->findOrFail($booking->id);

    expect($charged->fee_charge_id)->not->toBeNull()
        ->and(bookingFeeReceivable($booking->unit_id) - $before)->toBe($charged->fee_minor);
});
