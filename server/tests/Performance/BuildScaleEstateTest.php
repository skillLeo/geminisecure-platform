<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M11 A1 — build Phoenix Park Village 1 at production size in this slot
|--------------------------------------------------------------------------
|
| NOT PART OF THE SUITE. tests/Performance is outside both testsuites in phpunit.xml, so the full
| run never builds two thousand lots. It is run by path, through the slot's own runner:
|
|     $env:PERF_OUT = '<a directory outside the repository>'
|     .\t.ps1 tests/Performance/BuildScaleEstateTest.php
|
| and then the baseline is measured against it (tools/perf, BASELINE.md).
|
*/

use Tests\Performance\Support\ScaleEstate;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('builds Phoenix Park Village 1 at its production size — 2,000 lots, 441 households — and says how big it is', function (): void {
    $size = ScaleEstate::ensure();

    file_put_contents(ScaleEstate::out().DIRECTORY_SEPARATOR.'size.json', json_encode($size, JSON_PRETTY_PRINT));
    fwrite(STDOUT, PHP_EOL.json_encode($size, JSON_PRETTY_PRINT).PHP_EOL);

    // The two figures production states and PhoenixDemoSeed::PHASES encodes.
    expect($size['units'])->toBe(2000)
        ->and($size['households'])->toBe(441)
        ->and($size['charges'])->toBeGreaterThan(10_000)
        ->and($size['central']['tenants'])->toBe(4);
});
