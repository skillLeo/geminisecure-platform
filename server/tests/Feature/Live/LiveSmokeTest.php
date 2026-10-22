<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Live\Support\LiveAuthFixture;

/*
|--------------------------------------------------------------------------
| live:smoke fails loudly, and says where, when there is no Reverb (DESIGN.md §10.3)
|--------------------------------------------------------------------------
|
| The passing case needs a real Reverb and a real relay; it is run against both on the server by
| 80-live.sh, and was run against both on the laptop for this unit (an event recorded, relayed and
| received over a real socket in 250 ms). What the suite can hold it to is the other half: with
| nothing listening it neither hangs nor passes, it fails within its budget and names the step.
|
*/

it('fails within its budget, naming the step, when nothing answers', function () {
    LiveAuthFixture::credentials();

    $started = hrtime(true);
    $exit = Artisan::call('live:smoke', ['--url' => 'http://127.0.0.1:9', '--within' => 0.5]);
    $took = (hrtime(true) - $started) / 1e9;

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('FAIL while connecting')
        ->and($took)->toBeLessThan(9.0);
});

it('refuses to run with no reverb credentials at all', function () {
    config(['broadcasting.connections.reverb.secret' => '']);

    expect(Artisan::call('live:smoke'))->toBe(1)
        ->and(Artisan::output())->toContain('not configured');
});
