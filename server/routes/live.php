<?php

declare(strict_types=1);

use App\Http\Controllers\Live\LiveAuthController;
use App\Http\Controllers\Live\LiveCatchUpController;
use App\Http\Controllers\Live\LiveTilesController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| /live/* — the console session's door to live updates (M11 Part C, DESIGN §3.4)
|--------------------------------------------------------------------------
|
| Loaded by App\Providers\LiveServiceProvider, on every host: the Gemini console's and
| each estate console's own, where the session cookie lives. Which channels a host may
| be granted is the registry's rule (host binding), not the route's.
|
| THE WEB GROUP, SO THE SESSION IS THE PAGES' SESSION: EndSessionOfInactiveAccount signs
| a suspended account out before `auth` answers it 401 (B068), and CSRF is checked. WITHOUT
| THE TWO MEMBERS THAT BUILD A PAGE: HandleInertiaRequests would compute every shared
| prop for a JSON answer nobody renders, and AddLinkHeadersForPreloadedAssets would
| attach a page's asset hints to it.
|
| Each route names its own limiter, so the catch-up and tile routes that join this file
| (f-web-catchup) keep theirs.
|
*/

Route::middleware(['web', 'auth'])
    ->withoutMiddleware([HandleInertiaRequests::class, AddLinkHeadersForPreloadedAssets::class])
    ->group(function (): void {
        Route::post('live/auth', LiveAuthController::class)
            ->middleware('throttle:live-auth')
            ->name('live.auth');

        // What a screen missed, from the log (§5.1); while the socket is down, its poll (§5.6).
        Route::post('live/catch-up', LiveCatchUpController::class)
            ->middleware('throttle:live-catchup')
            ->name('live.catch_up');

        // Counts and tiles, each behind its own gate, cached per audience (§4.4).
        Route::get('live/tiles', LiveTilesController::class)
            ->middleware('throttle:live-rows')
            ->name('live.tiles');
    });
