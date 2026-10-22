<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M11 A1 — what `panic:walk` recorded, read back leg by leg
|--------------------------------------------------------------------------
|
| NOT PART OF THE SUITE. tools/perf/sequence.ps1 runs `panic:walk phoenixscale --lot="Lot 47"
| --force` in the production-shaped environment (tools/perf/artisan.ps1) and then this, by path:
|
|     $env:PERF_OUT = '<dir>'; $env:PERF_SINCE = '<ISO time the walks started>'; $env:PERF_WALKS = '3'
|     .\t.ps1 tests/Performance/PanicWalkReadbackTest.php
|
| The command prints its own table; this reads the same rows from `duress_alert_deliveries` for
| every drill it raised since PERF_SINCE and writes them to PERF_OUT/panic-walks.json in the shape
| PanicBaselineTest writes, so the report puts the two side by side. Nothing is timed here.
|
*/

use App\Console\Commands\PanicWalk;
use App\Models\DuressAlert;
use Illuminate\Support\Carbon;
use Tests\Performance\Support\ApiProbe;
use Tests\Performance\Support\PanicLegs;
use Tests\Performance\Support\ScaleEstate;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('reads back every leg of the drills panic:walk raised, and how long each took to hand off', function (): void {
    ScaleEstate::assertSlot();

    $since = (string) getenv('PERF_SINCE');
    $expected = (int) (getenv('PERF_WALKS') ?: 3);
    expect($since)->not->toBe('', 'Set PERF_SINCE to the moment the walks started.');

    $ids = DuressAlert::query()
        ->where('tenant_id', ScaleEstate::ESTATE)
        ->where('raised_by_name', PanicWalk::RAISED_BY)
        ->where('is_drill', true)
        // Stored in the application's zone; PERF_SINCE carries its own offset, so it is moved there first.
        ->where('created_at', '>=', Carbon::parse($since)->setTimezone((string) config('app.timezone')))
        ->orderBy('id')
        ->pluck('id')->map(static fn ($id): int => (int) $id)->all();

    $walks = PanicLegs::walks($ids);
    $rows = PanicLegs::summarise($walks);
    $fanout = ApiProbe::median(array_values(array_filter(array_column($walks, 'fanout_ms'), static fn ($v): bool => $v !== null)));

    $out = ScaleEstate::out().DIRECTORY_SEPARATOR.'panic-walks.json';
    file_put_contents($out, json_encode([
        'measured_at' => now()->toIso8601String(),
        'since' => $since,
        'walks_found' => count($walks),
        'fanout_ms' => array_column($walks, 'fanout_ms'),
        'fanout_ms_median' => $fanout,
        'legs' => $rows,
        'walks' => $walks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    fwrite(STDOUT, PHP_EOL.sprintf('panic:walk drills read back: %d; fan-out median %s ms', count($walks), (string) $fanout).PHP_EOL);
    foreach ($rows as $row) {
        fwrite(STDOUT, sprintf('%-44s %-9s %-8s rcpt %-4s n %d  median %-7s max %-7s', mb_substr($row['label'], 0, 44), $row['channel'], $row['outcome'], (string) $row['recipients'], $row['n'], (string) $row['elapsed_ms_median'], (string) $row['elapsed_ms_max']).PHP_EOL);
    }
    fwrite(STDOUT, 'written '.$out.PHP_EOL);

    expect($walks)->toHaveCount($expected)
        ->and(PanicLegs::everyDestinationOnEveryPress($walks))->toBeTrue();
});
