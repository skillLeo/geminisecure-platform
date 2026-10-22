<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The platform's tracking defaults keep what was promised about them (Hassam, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| The consent words 2026-10-12 tell an officer "every 45 to 90 seconds while you move, less often while you stand still
| or your battery is low", and a setting never moves outside what the people it affects were told. No signal is never
| raised for a healthy phone: the tolerance is never shorter than the slowest reading interval plus two minutes
| (Payroll's rule, approved by Hassam). Governance's settings page refuses a per-estate value outside these; this holds
| the platform's own defaults in config/tracking.php, which every estate without its own row uses.
|
*/

it('samples a moving officer every 45 to 90 seconds, and more slowly when still or low on battery', function () {
    $sampling = config('tracking.sampling');

    expect($sampling['interval_s'] >= 45 && $sampling['interval_s'] <= 90)->toBeTrue()
        ->and($sampling['stationary_interval_s'] > $sampling['interval_s'])->toBeTrue()
        ->and($sampling['low_battery_interval_s'] > $sampling['interval_s'])->toBeTrue();
});

it('waits at least the slowest reading interval plus two minutes before saying No signal', function () {
    $sampling = config('tracking.sampling');
    $slowest = max($sampling['stationary_interval_s'], $sampling['low_battery_interval_s']);

    expect(config('tracking.no_signal_after_seconds') >= $slowest + 120)->toBeTrue();
});
