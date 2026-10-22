<?php

declare(strict_types=1);

use App\Services\Estate\PhasesFromLots;
use App\Support\Geo;

/*
| A phase worked out from a lot number, in one place (M57.5 §4.2), and how far a position lies outside an estate's
| boundary (§4.5). Pure: no database.
*/

$phoenix = [
    ['name' => 'Phase 1', 'first' => 1, 'last' => 356],
    ['name' => 'Phase 2', 'first' => 357, 'last' => 598],
    ['name' => 'Phase 3', 'first' => 599, 'last' => 1218],
    ['name' => 'Phase 4', 'first' => 1219, 'last' => 1577],
    ['name' => 'Phase 5', 'first' => 1578, 'last' => 1999],
];

it('puts the four lots M57.5 found mislabelled in the plan\'s phases, and no lot 2000 anywhere', function () use ($phoenix) {
    expect(PhasesFromLots::phaseOf(47, $phoenix))->toBe('Phase 1')
        ->and(PhasesFromLots::phaseOf(55, $phoenix))->toBe('Phase 1')
        ->and(PhasesFromLots::phaseOf(1202, $phoenix))->toBe('Phase 3')
        ->and(PhasesFromLots::phaseOf(1577, $phoenix))->toBe('Phase 4')
        ->and(PhasesFromLots::phaseOf(1578, $phoenix))->toBe('Phase 5')
        ->and(PhasesFromLots::phaseOf(2000, $phoenix))->toBeNull()
        ->and(PhasesFromLots::phaseOf(null, $phoenix))->toBeNull();
});

it('reads a lot number only from "Lot" and a whole number', function () {
    expect(PhasesFromLots::lotNumberOf('Lot 47'))->toBe(47)
        ->and(PhasesFromLots::lotNumberOf('lot 12'))->toBe(12)
        ->and(PhasesFromLots::lotNumberOf('Lot 1999A'))->toBeNull()
        ->and(PhasesFromLots::lotNumberOf('OV-1A'))->toBeNull();
});

it('refuses runs that overlap, run backwards or share a name', function () use ($phoenix) {
    PhasesFromLots::check($phoenix);

    expect(fn () => PhasesFromLots::check([['name' => 'A', 'first' => 1, 'last' => 10], ['name' => 'B', 'first' => 10, 'last' => 20]]))->toThrow(DomainException::class, 'A and B both hold Lot 10.')
        ->and(fn () => PhasesFromLots::check([['name' => 'A', 'first' => 10, 'last' => 1]]))->toThrow(DomainException::class)
        ->and(fn () => PhasesFromLots::check([['name' => 'A', 'first' => 1, 'last' => 5], ['name' => 'a', 'first' => 6, 'last' => 9]]))->toThrow(DomainException::class, 'a is named twice.');
});

it('writes the column\'s CASE from the runs', function () use ($phoenix) {
    $sql = PhasesFromLots::expression($phoenix, static fn (string $s): string => "'".$s."'");

    expect($sql)->toBe("case when `lot_number` between 1 and 356 then 'Phase 1' when `lot_number` between 357 and 598 then 'Phase 2' when `lot_number` between 599 and 1218 then 'Phase 3' when `lot_number` between 1219 and 1577 then 'Phase 4' when `lot_number` between 1578 and 1999 then 'Phase 5' end");
});

it('says how far outside a boundary a position is, and nothing inside it', function () {
    // About 2.2 km north to south and 2.1 km east to west.
    $square = [[17.95, -76.94], [17.97, -76.94], [17.97, -76.92], [17.95, -76.92]];

    expect(Geo::metresOutside(17.96, -76.93, $square))->toBe(0.0)
        ->and(Geo::metresOutside(17.98, -76.93, $square))->toBeGreaterThan(1050.0)->toBeLessThan(1170.0)
        ->and(Geo::metresOutside(31.5204, 74.3587, $square))->toBeGreaterThan(5_000_000.0);
});
