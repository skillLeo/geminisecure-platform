<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;
use App\Services\Estate\Amenities;
use App\Support\SiteTime;
use Illuminate\Http\Request;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Approve's question names the fee it charges (DR-057)
|--------------------------------------------------------------------------
|
| Approving a booking confirms it and charges its fee to the household in one
| transaction (`Amenities::approveRequest()`), and the household is told. The
| console now asks first, and the question names that fee: the server's own
| figure, written as money is written everywhere ("$7,500.00"), because the diary
| never divides an amount in the browser.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the booking here stands on a
| scratch lot of this file's own, on a day nobody else books.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $this->amenities = app(Amenities::class);
});

it('sends each waiting booking with the fee approving it will charge, in the house format', function () {
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Community Centre'),
        unit: FacilitiesFixture::scratchUnit('AMN-57'),
        residentName: 'Waiting Household',
        startsAt: SiteTime::today()->addDays(163)->addHours(10),
        endsAt: SiteTime::today()->addDays(163)->addHours(12),
    );

    try {
        expect($booking->status)->toBe(AmenityBooking::PENDING)
            ->and($booking->fee_minor)->toBeGreaterThan(0);

        // Farthest first, so this file's own booking is on the first page of the shared diary.
        $board = $this->amenities->bookingsBoard('Community Centre', null, Amenities::bookingsTable(new Request(['sort' => 'when', 'dir' => 'desc'])));
        $row = collect($board['rows'])->firstWhere('reference', $booking->reference);

        expect($row)->not->toBeNull()
            ->and($row['fee_label'])->toMatch('/^\$\d{1,3}(,\d{3})*\.\d{2}$/')
            ->and(str_replace(['$', ',', '.'], '', $row['fee_label']))->toBe((string) $booking->fee_minor);

        // Charged already, approving would charge nothing more, and the question must not say it does.
        $this->amenities->approve($booking);
        $this->amenities->recordFee($booking->refresh());

        $board = $this->amenities->bookingsBoard('Community Centre', null, Amenities::bookingsTable(new Request(['sort' => 'when', 'dir' => 'desc'])));

        expect(collect($board['rows'])->firstWhere('reference', $booking->reference)['fee_label'])->toBeNull();
    } finally {
        FacilitiesFixture::boot();
    }
});
