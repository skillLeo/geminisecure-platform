<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\PayrollController;
use Illuminate\Support\Facades\Route;

/*
| Payroll and accounting — board screens super-admin-28 to 31.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| Q-002 is ruled (D-082): the TAJ cards carry their published periodic figures
| and are verified, and the client unblocked payroll. So there is now exactly
| one write here — approving a calculated guard run — behind its own `approve`
| permission (D-084), with the first-live-run acknowledgement the ruling asks for.
*/

Route::middleware('can:gemini.payroll_accounting.view')->group(function () {
    Route::get('payroll', [PayrollController::class, 'index'])->name('gemini.payroll_accounting');

    /*
     * Before the {run} route. "filings" is not a number, and whereNumber
     * already says so, but keeping the literal first means the ordering can
     * never become load-bearing by accident.
     */
    Route::get('payroll/filings', [PayrollController::class, 'filings'])
        ->name('gemini.payroll_accounting.filings');

    Route::get('payroll/rates', [PayrollController::class, 'rates'])
        ->name('gemini.payroll_accounting.rates');

    /*
     * Prepare the next return from an approved run (12 §2, Wave 4). It brings
     * a remittance into existence, so `create`; filing it with TAJ is not done
     * here at all.
     */
    Route::post('payroll/filings', [PayrollController::class, 'prepareFiling'])
        ->middleware('can:gemini.payroll_accounting.create')
        ->name('gemini.payroll_accounting.filings.prepare');

    Route::get('payroll/{run}', [PayrollController::class, 'show'])
        ->whereNumber('run')
        ->name('gemini.payroll_accounting.show');

    /*
     * The one write, and it is `approve`, not `update` (D-013). Approving a
     * guard run releases every guard's pay; editing a figure afterwards cannot
     * walk that back.
     */
    Route::post('payroll/{run}/approve', [PayrollController::class, 'approve'])
        ->whereNumber('run')
        ->middleware('can:gemini.payroll_accounting.approve')
        ->name('gemini.payroll_accounting.approve');
});

/*
| No PATCH and no DELETE in this file, and each absence is a decision.
|
| A filed statutory return is a posted record: no edit route, no delete route,
| not even a disabled one, because a control that could be enabled implies the
| act is possible. A correction is an amended return.
|
| There is no route that verifies or edits a RATE VERSION either. The cards are
| seeded from TAJ's published tables under the client's ruling, and a card is
| changed by recording a new dated version, never by editing one a run used.
*/
