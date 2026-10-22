<?php

declare(strict_types=1);

use App\Http\Controllers\Console\AccountController;
use App\Http\Controllers\Console\ConsolePhotoController;
use App\Http\Controllers\Gemini\OverridePinController;
use App\Http\Controllers\Gemini\TimeViewController;
use App\Services\Dispatch\SupervisorOverride;
use Illuminate\Support\Facades\Route;

/*
| The signed-in person's own account — today one thing: a supervisor's clock-in
| override PIN (M4 §3.4). No board draws it.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth` here.
|
| Behind the permission that MAKES somebody a supervisor — the same verb that decides
| a handset rebind, suspends and reassigns (`routes/gemini/guards.php`). A role that
| may only read the roster has no PIN to set and is refused here, not merely shown
| nothing. There is no {user} in either path: the PIN set is always the caller's own.
|
| The write is throttled like sign-in: it asks for the account password again, and a
| password prompt nobody limits is a place to guess one.
*/

/*
| M12 §4.8: the person's own profile picture — every signed-in console user, no permission beyond being signed in.
| No {user} in the path: it is always the caller's own. The same three routes are on every estate's door.
*/
Route::get('account/photo', [ConsolePhotoController::class, 'show'])->name('gemini.account.photo');
Route::post('account/photo', [ConsolePhotoController::class, 'store'])->middleware('throttle:20,1')->name('gemini.account.photo.store');
Route::delete('account/photo', [ConsolePhotoController::class, 'destroy'])->name('gemini.account.photo.destroy');

// Site time or my time (M19A §7): the header's switch, remembered on the user. Always the caller's own.
Route::post('account/time-view', [TimeViewController::class, 'update'])->middleware('throttle:30,1')->name('gemini.account.time_view');

/*
| M32 F9: My account — name, phone and photo; the password; two-step verification; where the account is signed in.
| Every signed-in console user, always their own. The same routes are on every estate's door (routes/tenant.php).
| Throttled like sign-in where a password or a code is asked.
*/
Route::get('account', [AccountController::class, 'show'])->name('gemini.account');
Route::post('account/details', [AccountController::class, 'details'])->middleware('throttle:20,1')->name('gemini.account.details');
// M34 §1: the sign-in address — the password and the new address, then the code emailed to the new address.
Route::post('account/sign-in-email/code', [AccountController::class, 'signInEmailCode'])->middleware('throttle:login')->name('gemini.account.sign_in_email.code');
Route::post('account/sign-in-email', [AccountController::class, 'signInEmail'])->middleware('throttle:20,1')->name('gemini.account.sign_in_email');
Route::post('account/sign-in-email/cancel', [AccountController::class, 'signInEmailCancel'])->name('gemini.account.sign_in_email.cancel');
Route::post('account/password/code', [AccountController::class, 'passwordCode'])->middleware('throttle:10,1')->name('gemini.account.password.code');
Route::post('account/password', [AccountController::class, 'password'])->middleware('throttle:login')->name('gemini.account.password');
Route::post('account/two-factor/start', [AccountController::class, 'twoFactorStart'])->middleware('throttle:login')->name('gemini.account.two_factor.start');
Route::post('account/two-factor/confirm', [AccountController::class, 'twoFactorConfirm'])->middleware('throttle:20,1')->name('gemini.account.two_factor.confirm');
Route::post('account/two-factor/replace', [AccountController::class, 'twoFactorReplace'])->middleware('throttle:login')->name('gemini.account.two_factor.replace');
Route::post('account/two-factor/off', [AccountController::class, 'twoFactorOff'])->middleware('throttle:login')->name('gemini.account.two_factor.off');
Route::post('account/two-factor/codes', [AccountController::class, 'twoFactorCodes'])->middleware('throttle:login')->name('gemini.account.two_factor.codes');
Route::post('account/two-factor/cancel', [AccountController::class, 'twoFactorCancel'])->name('gemini.account.two_factor.cancel');
Route::post('account/sessions/{session}/sign-out', [AccountController::class, 'signOutSession'])->whereNumber('session')->name('gemini.account.sessions.sign_out');
Route::post('account/sessions/sign-out-others', [AccountController::class, 'signOutOthers'])->name('gemini.account.sessions.sign_out_others');

Route::middleware('can:'.SupervisorOverride::PERMISSION)->group(function () {
    Route::get('account/override-pin', [OverridePinController::class, 'show'])
        ->name('gemini.guard_workforce.override_pin');

    Route::post('account/override-pin', [OverridePinController::class, 'update'])
        ->middleware('throttle:login')
        ->name('gemini.guard_workforce.override_pin.update');
});
