<?php

declare(strict_types=1);

use App\Services\Estate\Residents;

/*
|--------------------------------------------------------------------------
| A household's balance says its cents (Hassam's money proof, 12 Oct test campaign)
|--------------------------------------------------------------------------
|
| "Every amount with cents shows its cents." The register's Balance column and a household's standing printed whole
| dollars by `intdiv`, so a household owing $3,400.50 read "$3,400": fifty cents of a debt missing from the screen the
| office reads before it calls. Written as the amenity diary writes an amount since tonight (`AmenityBooking::amount`):
| whole dollars for a whole amount, as the boards draw it, and the cents whenever there are any. Integer arithmetic only.
| A unit test: the formatter is called on a Residents built without its services, so no database is touched.
|
*/

function householdMoney(int $minor): string
{
    $residents = (new ReflectionClass(Residents::class))->newInstanceWithoutConstructor();

    return (string) (new ReflectionMethod(Residents::class, 'money'))->invoke($residents, $minor);
}

it('writes a balance with cents to the cent, and a whole one as the boards draw it', function (): void {
    expect(householdMoney(340050))->toBe('$3,400.50')
        ->and(householdMoney(340005))->toBe('$3,400.05')
        ->and(householdMoney(99))->toBe('$0.99')
        ->and(householdMoney(1240000))->toBe('$12,400')
        ->and(householdMoney(100))->toBe('$1');
});
