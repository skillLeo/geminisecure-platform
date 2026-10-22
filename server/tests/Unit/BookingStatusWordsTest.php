<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;

/*
|--------------------------------------------------------------------------
| A booking's status in the one vocabulary (M96 §2; M97 pass)
|--------------------------------------------------------------------------
|
| The diary and the booking page read a declined booking as "Declined", the stored word. The vocabulary's word is
| Rejected. Confirmed, Cancelled and Expired have no word in it; they stay until Hassam decides (docs/qa/M97_PASS_QA.md),
| because a state is never invented.
|
*/

it('reads a declined booking as Rejected, the one vocabulary\'s word', function () {
    expect(AmenityBooking::STATUS_LABELS[AmenityBooking::DECLINED])->toBe('Rejected')
        ->and(in_array('Declined', AmenityBooking::STATUS_LABELS, true))->toBeFalse();
});
