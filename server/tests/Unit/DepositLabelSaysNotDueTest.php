<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;

/*
|--------------------------------------------------------------------------
| A deposit nobody owes does not read "awaiting payment"
|--------------------------------------------------------------------------
|
| Routed to the hub by Accounting and Residents (12 October 2026): the bookings diary read "$5,000 · awaiting payment" on
| a cancelled booking, telling the office to chase money nobody owes. The booking page's own badge already said "No
| deposit due" for a booking that was cancelled, declined or has expired; the label every list prints (the diary, the
| booking page, a household's page) now says the same.
|
*/

it('says no deposit is due on a booking that will not happen, and awaiting payment on one that will', function (string $status, string $expected) {
    $booking = (new AmenityBooking)->forceFill([
        'status' => $status,
        'deposit_state' => AmenityBooking::DEPOSIT_AWAITING,
        'deposit_minor' => 5_000_00,
    ]);

    expect($booking->depositLabel())->toBe($expected);
})->with([
    'cancelled' => [AmenityBooking::CANCELLED, 'No deposit due'],
    'declined' => [AmenityBooking::DECLINED, 'No deposit due'],
    'expired' => [AmenityBooking::EXPIRED, 'No deposit due'],
    'confirmed' => [AmenityBooking::CONFIRMED, '$5,000 · awaiting payment'],
    'pending' => [AmenityBooking::PENDING, '$5,000 · awaiting payment'],
]);
