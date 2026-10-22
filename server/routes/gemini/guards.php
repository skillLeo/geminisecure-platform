<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\GuardController;
use App\Http\Controllers\Gemini\GuardTrackingController;
use Illuminate\Support\Facades\Route;

/*
| Guard workforce — board screens super-admin-18 to 27.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
*/

Route::middleware('can:gemini.guard_workforce.view')->group(function () {
    Route::get('guards', [GuardController::class, 'index'])->name('gemini.guard_workforce');

    // Before the {guard} route: "compliance" is not a number, but keeping the
    // literal first means it can never be read as one.
    Route::get('guards/compliance', [GuardController::class, 'compliance'])
        ->name('gemini.guard_workforce.compliance');

    // The licence register as a file (12 §1). Cross-tenant, so the audit entry
    // it writes names the estates whose officers are in it.
    Route::get('guards/compliance/export', [GuardController::class, 'exportCompliance'])
        ->middleware('can:gemini.guard_workforce.export')
        ->name('gemini.guard_workforce.compliance.export');

    /*
     * Who currently holds supervisor-override authority (Q-M28, D-M129).
     *
     * A literal before the {guard} route, for the same reason "compliance" is: the
     * numeric constraint already keeps them apart, and keeping the literals first
     * means neither can ever be read as an id.
     *
     * On `view`. It is a register to read and carries no write at all — a PIN is
     * still set only by its owner, from their own account page, with no user id in
     * the route. Behind `update` it would only be visible to the supervisors
     * themselves, which is the opposite of a reviewable permission.
     */
    Route::get('guards/supervisors', [GuardController::class, 'supervisors'])
        ->name('gemini.guard_workforce.supervisors');

    Route::get('guards/{guard}', [GuardController::class, 'show'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.show');

    // A read, on `view`, even though the screen exists to launch a write. The
    // register offers "Take action" to every role that may read it, and a link
    // that 403s the reader who followed it is a dead end the screen invited
    // them into. The write below carries the stronger verb instead.
    Route::get('guards/{guard}/compliance', [GuardController::class, 'complianceAction'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.compliance_action');

    /*
     * THE TWO TRACKING SCREENS (Hassam, 12 Oct), both reads. The officer's answers to the location policy, on the guard
     * page's own permission; the positions held about them, on the live map's as well (`gemini.dispatch.view`), the only
     * view today of where a guard is. Neither changes who may see anything (GuardTrackingController says why).
     */
    Route::get('guards/{guard}/location-sharing', [GuardTrackingController::class, 'consent'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.location_sharing');

    Route::get('guards/{guard}/track', [GuardTrackingController::class, 'track'])
        ->whereNumber('guard')
        ->middleware('can:gemini.dispatch.view')
        ->name('gemini.guard_workforce.track');
});

/*
 * The two writes, each a verb stronger than the screen that launches it.
 *
 * Suspending an officer changes an employment record and appends to the audit
 * log; adding one creates an employee. Neither belongs to a role that may only
 * read the roster, and neither is reachable by GET.
 */
Route::middleware('can:gemini.guard_workforce.update')->group(function () {
    Route::post('guards/{guard}/suspend', [GuardController::class, 'suspend'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.suspend');

    /*
     * The three compliance and roster writes (12 §2, Wave 5). Each changes
     * what an officer may do or where they stand, so each is `update` and each
     * is audited. A renewal carries the new expiry date read off the
     * certificate, never a flag; releasing shifts touches the future only,
     * because a shift already worked is evidence a post was covered.
     */
    Route::post('guards/{guard}/licence', [GuardController::class, 'renewLicence'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.licence');

    Route::post('guards/{guard}/release-shifts', [GuardController::class, 'releaseShifts'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.release_shifts');

    Route::post('guards/{guard}/reassign', [GuardController::class, 'reassign'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.reassign');

    // A supervisor's decision on a handset rebind (13 D1).
    Route::post('guards/{guard}/device-rebinds/{rebind}/{decision}', [GuardController::class, 'decideRebind'])
        ->whereNumber('guard')
        ->whereNumber('rebind')
        ->where('decision', 'approve|deny')
        ->name('gemini.guard_workforce.device_rebind');

    /*
     * THE ONE-TIME CODE THAT PUTS AN OFFICER'S PHONE ON THE GUARD APP.
     *
     * `DeviceEnrolment::issueCode()` has existed since the guard app was built and NOTHING in
     * this console called it. The workforce screens could create an officer, suspend one, renew
     * a licence, reassign and decide a handset rebind — but the code the officer types into the
     * app on their first day could only be produced by somebody with a shell on the server. A
     * security company could not onboard its own guards from its own console.
     *
     * `update`, beside the rebind decision above: it is the same authority over the same
     * officer's handset, and a role that may only read the roster does not issue credentials.
     *
     * Throttled, because a code is a credential and this is the endpoint that mints them.
     */
    Route::post('guards/{guard}/enrolment-code', [GuardController::class, 'issueEnrolmentCode'])
        ->whereNumber('guard')
        ->middleware('throttle:10,1')
        ->name('gemini.guard_workforce.enrolment_code');
});

/*
 * Adding an employee — board screen super-admin-22.
 *
 * Behind `create` rather than `view`, on BOTH halves. The form is not a read
 * that happens to carry a button: it is the first step of a write, and a role
 * that may only read the roster has no business being shown a picker of the
 * clients it could post a new officer to. Unlike the compliance action screen
 * next door, nothing links here from a screen a read-only role can reach, so
 * gating the form costs no reader a dead end.
 *
 * `guards/new` cannot collide with `guards/{guard}`: that route is constrained
 * to a number, and "new" is not one.
 */
Route::middleware('can:gemini.guard_workforce.create')->group(function () {
    Route::get('guards/new', [GuardController::class, 'create'])
        ->name('gemini.guard_workforce.create');

    Route::post('guards', [GuardController::class, 'store'])
        ->name('gemini.guard_workforce.store');
});
