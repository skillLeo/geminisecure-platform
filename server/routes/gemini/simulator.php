<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\SimulatorController;
use Illuminate\Support\Facades\Route;

/*
| The event simulator — /simulator. Part C of the web deliverable.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| REGISTERED ONLY WHILE THE FLAG IS ON. Not gated, not hidden: absent. A host
| without GS_SIMULATOR_ENABLED answers 404 for every one of these, exactly as
| it does for a URL that was never built — so a production console cannot be
| talked into firing a panic alert at a client by anybody, whatever they hold.
|
| With the flag on, it is the Director's. `platform_settings.configure` was the
| verb the matrix gives to exactly one role, and putting events into a client's
| live dispatch queue is a platform act rather than a dispatch one. Since M11 B3
| that verb is three permissions (D-150), and this is its own one,
| `run_simulator`: a role trusted with prices or with the mail relay is not
| thereby trusted to put alerts in front of a client's dispatcher.
*/

if (config('simulator.enabled')) {
    Route::middleware('can:gemini.platform_settings.run_simulator')->group(function () {
        Route::get('simulator', [SimulatorController::class, 'index'])->name('gemini.simulator');

        Route::post('simulator/alert', [SimulatorController::class, 'alert'])->name('gemini.simulator.alert');

        Route::post('simulator/gate-event', [SimulatorController::class, 'gateEvent'])->name('gemini.simulator.gate_event');

        Route::post('simulator/shifts', [SimulatorController::class, 'shifts'])->name('gemini.simulator.shifts');

        Route::post('simulator/ambient', [SimulatorController::class, 'ambient'])->name('gemini.simulator.ambient');
    });
}
