<?php

declare(strict_types=1);

use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Every amenity amount on the diary and the rate card is the stored amount, to the cent (the hub's money proof, 12 Oct)
|--------------------------------------------------------------------------
|
| The diary's Deposit cell, the rate card's fee and deposit, and the booking page all print through
| `AmenityBooking::amount()`, which was `intdiv($minor, 100)`: a $5,000.50 deposit read "$5,000", fifty cents the estate
| is holding that no page said, and the rate card accepts cents ("3,000.50"). The board's whole dollars stay for a whole
| amount; an amount with cents says them. Read back, every label is the minor units it was printed from: every cent from
| $0.00 to $100.00, and the amounts the boards draw.
|
*/

uses(TestCase::class);

/** "$5,000.50" back to 500050; null when the text is not an amount. */
function amenityLabelCents(string $label): ?int
{
    if (preg_match('/^(-?)\$(\d{1,3}(?:,\d{3})*)(?:\.(\d{2}))?$/', $label, $m) !== 1) {
        return null;
    }

    $minor = (int) str_replace(',', '', $m[2]) * 100 + (int) ($m[3] ?? 0);

    return $m[1] === '-' ? -$minor : $minor;
}

it('prints every amount from $0.00 to $100.00, and the boards\' own, as the cents it was given', function (): void {
    $amounts = [...range(0, 10_000), 300_000, 450_050, 500_000, 500_050, 123_456_789, 99_999_999_999];

    $wrong = array_values(array_filter(
        $amounts,
        static fn (int $minor): bool => amenityLabelCents(AmenityBooking::amount($minor)) !== $minor,
    ));

    expect(array_slice($wrong, 0, 5))->toBe([], 'amounts whose label is not the amount it was printed from')
        ->and(AmenityBooking::amount(500_000))->toBe('$5,000')
        ->and(AmenityBooking::amount(500_050))->toBe('$5,000.50')
        ->and(AmenityBooking::amount(5))->toBe('$0.05');
});

it('says a deposit and a fee on the diary and the rate card to the cent', function (): void {
    $held = (new AmenityBooking)->forceFill(['deposit_minor' => 500_050, 'deposit_state' => AmenityBooking::DEPOSIT_HELD]);
    $awaiting = (new AmenityBooking)->forceFill(['deposit_minor' => 450_025, 'deposit_state' => AmenityBooking::DEPOSIT_AWAITING]);
    $card = (new Amenity)->forceFill(['booking_fee_minor' => 300_050, 'deposit_minor' => 500_001]);

    expect($held->depositLabel())->toBe('$5,000.50 held')
        ->and($awaiting->depositLabel())->toBe('$4,500.25 · awaiting payment')
        ->and($card->feeLabel())->toBe('$3,000.50')
        ->and($card->depositLabel())->toBe('$5,000.01');
});
