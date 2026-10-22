<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ContactVerificationController;
use App\Http\Controllers\Auth\FrontDoorController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\ResidentDoorController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Dev\QuickLoginController;
use App\Http\Controllers\PublicPages\ClientSiteController;
use App\Http\Middleware\EnsureContactVerified;
use App\Http\Middleware\OnlyOnTheRootHost;
use App\Http\Middleware\RedirectToOwnConsole;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central routes
|--------------------------------------------------------------------------
|
| Served from the central domain. Estate routes live in routes/tenant.php,
| behind an explicit subdomain constraint.
|
| The Gemini Console's own routes are split ONE FILE PER MODULE under
| routes/gemini/. That is not tidiness: the console is 45 screens, they are
| built module by module, and a single 400-line route file is a file every
| piece of that work has to touch at once. Each module's routes now live
| beside nothing but themselves.
|
| Every module route is gated by a permission from the role access matrix,
| named <console>.<module>.<verb>. The gate and the sidebar therefore read the
| same source: a module absent from the navigation is also unreachable by URL,
| rather than merely hidden.
|
| There is no registration route anywhere in this application. Accounts are
| issued by invitation and never self-created.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');

    /*
     * Throttled, and on two keys at once.
     *
     * Per address stops one machine working through a password list; per
     * account stops a distributed attempt at one known address. Either alone
     * leaves the other attack open, and this console holds data for every
     * client estate on the platform.
     *
     * Five a minute is room for somebody mistyping and none for a program. The
     * form already refuses to say WHICH half was wrong — distinguishing a bad
     * password from an unknown address turns sign-in into an oracle for which
     * addresses hold accounts — so a limit is what stops the same question
     * being asked ten thousand times instead.
     */
    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    /*
     * Forgot password (12 §2, Wave 1). The request is throttled on the same
     * two keys as sign-in, because it is the same oracle if it leaks — and it
     * does not leak: one sentence whatever the address. `password.reset` is
     * the name the framework's broker expects for the link.
     */
    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');

    Route::post('forgot-password', [PasswordResetController::class, 'send'])
        ->middleware('throttle:login')
        ->name('password.email');

    Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');

    Route::post('reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:login')
        ->name('password.update');

    /*
     * Accepting an invitation — the only way an account is created, and a
     * guest's act by definition. Throttled like sign-in: the token is the
     * credential, and this is where one could be guessed at.
     */
    Route::get('invitations/{token}', [InvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('invitation.show');

    Route::post('invitations/{token}', [InvitationController::class, 'accept'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->middleware('throttle:login')
        ->name('invitation.accept');
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
 * THE FRONT DOOR (M11 §5.2): "Log in" on the landing page, for everybody. The email, then the password, then
 * the right console: a Gemini account is signed in here, where its console is; an estate account is handed to
 * its estate's own host with a single-use 60-second token that host redeems (routes/tenant.php,
 * `estate.front-door`) and is never signed in on this one. No cookie crosses a hostname.
 *
 * The password and the choice are on the same `login` limiter as the consoles' doors — five a minute per address
 * and per IP — because each is an answer somebody could otherwise ask ten thousand times. The email step answers
 * nothing (every address goes on to the password), so it has a looser limit of its own: on `login` it spent two
 * of a person's five on one ordinary sign-in.
 */
Route::get('sign-in', [FrontDoorController::class, 'show'])->name('front-door.show');
// M24 §4 (D-223): the page offers two choices first. "As staff" is the email and password below.
Route::get('sign-in/staff', [FrontDoorController::class, 'staff'])->name('front-door.staff');
Route::get('sign-in/staff/forgot', [FrontDoorController::class, 'forgot'])->name('front-door.forgot');
Route::post('sign-in/email', [FrontDoorController::class, 'email'])->middleware('throttle:30,1')->name('front-door.email');
Route::post('sign-in', [FrontDoorController::class, 'password'])->middleware('throttle:login')->name('front-door.password');
Route::post('sign-in/restart', [FrontDoorController::class, 'restart'])->name('front-door.restart');
Route::get('sign-in/choose', [FrontDoorController::class, 'choose'])->name('front-door.choose');
Route::post('sign-in/choose', [FrontDoorController::class, 'chosen'])->middleware('throttle:login')->name('front-door.chosen');

/*
 * "As a resident" (M24 §4.4, D-223): the email, the six-digit code the app uses, and then the resident's community
 * platform page. Each POST has a limit of its own on top of ResidentSignIn's hourly limits for the address.
 */
Route::get('sign-in/resident', [ResidentDoorController::class, 'show'])->name('front-door.resident');
Route::post('sign-in/resident', [ResidentDoorController::class, 'email'])->middleware('throttle:10,1')->name('front-door.resident.email');
Route::post('sign-in/resident/code', [ResidentDoorController::class, 'code'])->middleware('throttle:20,1')->name('front-door.resident.code');
Route::post('sign-in/resident/again', [ResidentDoorController::class, 'again'])->middleware('throttle:10,1')->name('front-door.resident.again');
Route::post('sign-in/resident/restart', [ResidentDoorController::class, 'restart'])->name('front-door.resident.restart');
Route::get('community', [ResidentDoorController::class, 'community'])->name('community');
Route::post('community/log-out', [ResidentDoorController::class, 'logout'])->name('community.logout');

/*
 * THE GEMINI CONSOLE'S SECOND FACTOR (M11 §0.3), after either door has proven the password: the code from the
 * authenticator app, or setting it up the first time. Nobody is signed in until a code is accepted
 * (App\Services\Auth\TwoFactor). The controller limits every ACCOUNT to five codes a minute, whatever address they
 * come from — that is the limit protecting the six digits. Each POST also has a looser per-address limit of its own:
 * on the doors' `login` limiter, code entry spent the password step's five a minute, and a person who mistyped a
 * password twice and a code once was locked out.
 */
Route::get('two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
Route::post('two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:20,1')->name('two-factor.verify');
Route::post('two-factor/recovery', [TwoFactorController::class, 'recovery'])->middleware('throttle:20,1')->name('two-factor.recovery');
Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:20,1')->name('two-factor.confirm');
Route::post('two-factor/cancel', [TwoFactorController::class, 'cancel'])->name('two-factor.cancel');
Route::get('two-factor/recovery-codes', [TwoFactorController::class, 'codes'])->middleware('auth')->name('two-factor.codes');

/*
 * Quick login - LOCAL ONLY.
 *
 * An authentication bypass for comparing the 14 system roles without maintaining
 * 14 sets of credentials. Registered only when the application is local; the
 * controller asserts the same thing again, because this single line is exactly
 * what gets moved during a refactor.
 */
if (app()->isLocal()) {
    Route::get('dev/login/{role}', QuickLoginController::class)->name('dev.login');
}

/*
 * The pages an app store requires at a public address: the resident privacy policy and terms, and the
 * account-deletion request page. No sign-in, and linked from nowhere on the website: see routes/public.php.
 */
require __DIR__.'/public.php';

/*
 * THE LANDING PAGE: THE CLIENT'S OWN (M14, D-194), built from its own code (landing/ → public/landing). A guest sees
 * it; a signed-in Gemini account goes to its console; an estate account that somehow holds a session here is
 * handed to its own estate by `RedirectToOwnConsole`, as everywhere else on this host. Until the client's page is
 * built, `/` is a plain page with the company's name and Log in, and nothing about the product (M24, D-223).
 *
 * Its Apply page and its Privacy Policy, and the three calls its forms make, are the root host's only
 * (`OnlyOnTheRootHost`): an estate's host keeps its own paths. Each form is five a minute per address.
 */
Route::middleware(RedirectToOwnConsole::class)
    ->get('/', [ClientSiteController::class, 'home'])
    ->name('landing');

Route::middleware(OnlyOnTheRootHost::class)->group(function () {
    Route::get('contact', [ClientSiteController::class, 'subpage'])->name('site.contact');
    Route::get('privacy-policy', [ClientSiteController::class, 'subpage'])->name('site.privacy');
    // Under /site/, not /landing/: public/landing is the page's static folder, and a path inside a real folder is
    // one a web server may answer for itself.
    Route::get('site/community-types', [ClientSiteController::class, 'communityTypes'])->name('site.community-types');
    Route::post('site/contact-support', [ClientSiteController::class, 'contact'])->middleware('throttle:5,1')->name('site.contact-support');
    Route::post('site/registration', [ClientSiteController::class, 'application'])->middleware('throttle:5,1')->name('site.registration');
});

/*
 * Proving the address an alert is sent to — M10 §1.4.
 *
 * OUTSIDE THE CONSOLE GROUP BELOW, ON `auth` AND NOT ON THE GATE, because this is
 * where the gate sends people: a verification page behind `EnsureContactVerified`
 * would redirect to itself. `RedirectToOwnConsole` IS here, so an estate account
 * that reached the central host is carried to its own estate's door and verifies
 * there, on the hostname its session actually lives on.
 *
 * Throttled on top of the service's own rules (one code a minute, five tries a
 * code), so a script cannot use either POST as a way to hammer the mail relay or
 * the database.
 */
Route::middleware(['auth', RedirectToOwnConsole::class])->group(function () {
    Route::get('verify-contact', [ContactVerificationController::class, 'show'])->name('contact.verify');

    Route::post('verify-contact/code', [ContactVerificationController::class, 'send'])
        ->middleware('throttle:10,1')
        ->name('contact.verify.send');

    Route::post('verify-contact', [ContactVerificationController::class, 'verify'])
        ->middleware('throttle:20,1')
        ->name('contact.verify.check');

    Route::post('verify-contact/phone', [ContactVerificationController::class, 'phone'])
        ->middleware('throttle:10,1')
        ->name('contact.verify.phone');
});

/*
 * The Gemini Console.
 *
 * Every module file is loaded inside this one auth group, so no module file
 * can forget the guard. A file that omitted `auth` on its own group would
 * publish its whole module, and that is not a mistake worth leaving available.
 *
 * `RedirectToOwnConsole` SITS BESIDE `auth`, NOT INSIDE A MODULE. A committee account has no
 * business on any of these routes, and the permission gate's answer to one — 403, "Not
 * available to your role" — is true and useless: it reads as the account being broken rather
 * than as the reader being at the wrong address. One middleware here carries them to the
 * console they do hold, for every Gemini module at once, so no module file can be the one that
 * forgets. `logout` is registered ABOVE this group and stays reachable, or an estate account
 * that landed here would have no way out.
 *
 * `EnsureContactVerified` SITS BESIDE BOTH, FOR THE SAME REASON (M10 §1.4): one line gates
 * every Gemini module at once, so a Director, Head of Security, Dispatcher or Operations
 * Manager with no proven alert address reaches no console page by any URL. It runs after
 * `RedirectToOwnConsole`, so an estate account is carried home first and meets the gate on its
 * own door rather than this one.
 */
Route::middleware(['auth', RedirectToOwnConsole::class, EnsureContactVerified::class])->group(function () {
    foreach (glob(__DIR__.'/gemini/*.php') ?: [] as $module) {
        require $module;
    }
});
