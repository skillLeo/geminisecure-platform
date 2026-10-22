<?php

use App\Http\Controllers\Gemini\NearestGuardsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The nearest guards to an open alert (Hassam, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| Read only, behind the same permission as every dispatch screen. The system never assigns: it shows the three officers
| on duty nearest the alert, each with its distance, and a person sends one with the alert's own Dispatch act.
|
*/

Route::middleware('can:gemini.dispatch.view')->group(function () {
    Route::get('dispatch/alerts/{alert}/nearest', [NearestGuardsController::class, 'show'])
        ->whereNumber('alert')
        ->name('gemini.dispatch.alerts.nearest');
});
