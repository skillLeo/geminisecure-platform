<?php

declare(strict_types=1);

/*
| M11 A1 — how big the measured estate is right now, counted. Run by path (not part of the suite):
|     .\t.ps1 tests/Performance/SizeTest.php      (writes PERF_OUT/size-now.json)
| The API and panic walks add a handful of rows of their own; this records exactly how many were
| there when a figure was taken, rather than assuming the 12,015 the build started from.
*/

use Tests\Performance\Support\ScaleEstate;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('counts the measured estate as it stands', function (): void {
    ScaleEstate::assertSlot();

    $size = ScaleEstate::size();
    file_put_contents(ScaleEstate::out().DIRECTORY_SEPARATOR.'size-now.json', json_encode($size, JSON_PRETTY_PRINT));
    fwrite(STDOUT, PHP_EOL.json_encode($size, JSON_PRETTY_PRINT).PHP_EOL);

    expect($size['units'])->toBe(2000)->and($size['households'])->toBe(441);
});
