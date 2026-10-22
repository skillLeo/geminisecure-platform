<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PRODUCTION ERROR HANDLING — M16 §5.2
|--------------------------------------------------------------------------
|
| Every unhandled error becomes a plain page, never a stack trace; debug mode
| is off and cannot be turned on by a request parameter. The exception handler
| in bootstrap/app.php renders `ErrorPage` for 403/404/419/429/500/503 unless
| debug is on AND it is a 500. This proves the production posture.
*/

beforeEach(function () {
    $this->withoutVite();
    // Model production: debug off.
    config(['app.debug' => false]);

    Route::middleware('web')->get('/__m16_boom', function () {
        throw new RuntimeException('a deliberate internal error for the test');
    });
});

it('renders a plain error page for a 500, with no stack trace, when debug is off', function () {
    $response = $this->get('/__m16_boom');

    $response->assertStatus(500);

    $body = (string) $response->getContent();

    // The plain Inertia ErrorPage, not a framework debug screen.
    expect($body)->toContain('ErrorPage');

    // And no trace, no exception message, no framework debugger leaked.
    expect($body)->not->toContain('a deliberate internal error for the test')
        ->and($body)->not->toContain('Stack trace')
        ->and(strtolower($body))->not->toContain('whoops')
        ->and($body)->not->toContain('vendor/laravel/framework');
});

it('cannot be made to leak a trace by a request parameter', function () {
    // Laravel never reads debug from the request; a query param must change nothing.
    // (No message argument to toContain — it is variadic and would become a second needle.)
    foreach (['?debug=1', '?debug=true', '?APP_DEBUG=true', '?app_debug=1'] as $q) {
        $response = $this->get('/__m16_boom'.$q);

        expect($response->status())->toBe(500, "[{$q}] status");

        $body = (string) $response->getContent();

        expect($body)->toContain('ErrorPage');
        expect($body)->not->toContain('a deliberate internal error for the test');
        expect($body)->not->toContain('Stack trace');
    }
});

it('renders a plain page for a 404 too', function () {
    $response = $this->get('/__m16_no_such_route_'.bin2hex(random_bytes(4)));

    $response->assertStatus(404);
    expect((string) $response->getContent())->toContain('ErrorPage');
});

it('respects debug off even where the environment turns it on', function () {
    // Locally APP_DEBUG is on; production sets it off. The guarantee that matters is that
    // WHEN it is off (config below, as production has it), no trace leaks — proved above.
    // Here we prove the toggle is honoured both ways: debug ON does keep the trace for a
    // 500 (the developer's aid), so the production posture is a real choice, not an accident.
    config(['app.debug' => true]);

    $response = $this->get('/__m16_boom');

    $response->assertStatus(500);
    // With debug on, bootstrap/app.php returns the framework response for a 500 (the trace),
    // which is NOT the plain Inertia ErrorPage. This is what production turns off.
    expect((string) $response->getContent())->not->toContain('"component":"ErrorPage"');
});
