<?php

declare(strict_types=1);

use App\Http\Controllers\Dev\QuickLoginController;
use Illuminate\Support\Facades\Route;

/**
 * Quick login is an authentication bypass. These tests exist to make sure it
 * can never leave a developer's machine.
 *
 * The suite runs with APP_ENV=testing, which is NOT local — so these assert the
 * real production-shaped behaviour rather than a simulation of it.
 */
it('does not register the quick-login route outside local', function () {
    expect(app()->isLocal())->toBeFalse('the suite must not run as local, or this test proves nothing');

    expect(Route::has('dev.login'))->toBeFalse();
});

it('returns 404 for the quick-login URL outside local', function () {
    $this->get('/dev/login/gemini.director')->assertNotFound();
});

it('throws rather than signing anyone in if the controller is reached outside local', function () {
    /*
     * The second guard. Route registration is one line in a route file, and a
     * single line is exactly what gets moved during a refactor — so the
     * controller refuses independently of whether the route exists.
     *
     * It throws rather than 404ing on purpose: reaching this line off a
     * developer machine is a deployment fault worth a stack trace, not a quiet
     * not-found that nobody investigates.
     */
    $controller = new QuickLoginController;

    expect(fn () => $controller('gemini.director'))
        ->toThrow(RuntimeException::class, 'must never run outside APP_ENV=local');

    $this->assertGuest();
});
