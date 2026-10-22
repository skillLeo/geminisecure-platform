<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\PlatformSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Platform settings — board screens super-admin-42 to 45.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| `gemini.platform_settings` is the module's LANDING screen (42, Pricing &
| rates), not the role matrix. It served /settings/roles for a while, which put
| the sidebar's Platform settings link on a sub-screen and left the module with
| no front door — and, because the fidelity harness resolves each board by route
| name, made every measurement of board 42 a measurement of board 45.
|
| THE THREE WRITES ARE `configure` AND NOT `update` (12 §2, Wave 4). Each one
| changes what is true for clients other than the one in front of you — a tier
| price re-prices every estate on that tier, a package row changes what every
| current and future client on it receives — so they are not data entry, and the
| verb says so. Each carries an effective date and none is retroactive: an
| invoice already raised was raised at the price in force.
|
| `configure` IS THREE PERMISSIONS HERE, NOT ONE (M11 B3, D-150). It gated what
| every client pays AND where every alert and sign-in code is delivered — the
| mail relay and the SMS provider — AND the event simulator. A role given the
| prices had the relay too: somebody who could reprice a tier could repoint
| where the platform's panic mail goes. So: `set_prices` for prices, rates,
| packages and line items; `configure_messaging` for the relay and the SMS
| provider; `run_simulator` for routes/gemini/simulator.php. A cell holding
| Full here holds all three, so nobody who had configure has lost anything.
|
| THE ROLE MATRIX IS EDITED UNDER `approve`, AND ONLY THERE (M10 §4). It was a
| read until the client asked for the Director to create, rename, re-grant and
| delete the console's roles. Changing what a role may do is an approve-level
| act (D-013), and `gemini.platform_settings.approve` is held by the Director
| and by no other seeded role — so it is the one gate that opens the editor
| without admitting anybody who could already read the screen. The routes are
| the block at the foot of this file. `App\Services\Rbac\RoleEditor` is where
| every rule lives: the fourteen seeded roles keep their names and are never
| renamed or deleted, nobody grants what they do not hold, and the Director
| keeps Full · Approver here whatever else is changed. The screen is still the
| permission tables printed; it now writes them through the same class that
| compiles them into the table `can:` reads.
*/

Route::middleware('can:gemini.platform_settings.view')->group(function () {
    Route::get('settings', [PlatformSettingsController::class, 'index'])
        ->name('gemini.platform_settings');

    Route::get('settings/packages', [PlatformSettingsController::class, 'packages'])
        ->name('gemini.platform_settings.packages');

    Route::get('settings/line-items', [PlatformSettingsController::class, 'lineItems'])
        ->name('gemini.platform_settings.line_items');

    Route::get('settings/roles', [PlatformSettingsController::class, 'roleMatrix'])
        ->name('gemini.platform_settings.roles');

    Route::get('settings/admins', [PlatformSettingsController::class, 'admins'])
        ->name('gemini.platform_settings.admins');

    /*
     * WHERE THE PLATFORM SENDS MAIL FROM (23 September 2026).
     *
     * `view` to read it, `configure_messaging` to change it — the same split the three writes
     * above carry, and for the same reason: repointing the relay changes what is true for
     * every client on the platform at once, so it is not data entry. It is held by the
     * Director alone, which is what "no community role, ever" means here — an estate account
     * cannot reach any Gemini route regardless, since `RedirectToOwnConsole` carries it home.
     *
     * The password is never returned by this route or any other. See `MailSettings::shape()`.
     */
    Route::get('settings/email', [PlatformSettingsController::class, 'mail'])
        ->name('gemini.platform_settings.mail');

    /*
     * WHICH PROVIDER THE PLATFORM TEXTS THROUGH (M10 §1). The Email screen's split exactly —
     * `view` to read it, `configure_messaging` to change it or send a test — because it is the
     * same kind of setting: one credential, presented on behalf of every client at once. The
     * token is never returned by this route or any other. See `SmsSettings::shape()`.
     */
    Route::get('settings/sms', [PlatformSettingsController::class, 'sms'])
        ->name('gemini.platform_settings.sms');
});

/*
 * PLATFORM ACCOUNTS (12 §2, item 43). Issuing one is `create`, changing who
 * holds which role — or their sites, or their standing — is `update`. Neither
 * changes what a role may do; that is the `approve` block at the foot of this
 * file, and so is taking somebody off the console altogether.
 */
Route::middleware('can:gemini.platform_settings.create')->group(function () {
    Route::post('settings/admins/invitations', [PlatformSettingsController::class, 'inviteAdmin'])
        ->name('gemini.platform_settings.admins.invite');

    // Six a minute: the service's own minute between sends is the rule, and this stops a script (DESIGN §11).
    Route::post('settings/admins/invitations/{invitation}/resend', [PlatformSettingsController::class, 'resendAdminInvitation'])
        ->whereNumber('invitation')
        ->middleware('throttle:6,1')
        ->name('gemini.platform_settings.admins.invitation.resend');

    Route::post('settings/admins/invitations/{invitation}/revoke', [PlatformSettingsController::class, 'revokeAdminInvitation'])
        ->whereNumber('invitation')
        ->name('gemini.platform_settings.admins.invitation.revoke');
});

Route::post('settings/admins/{user}', [PlatformSettingsController::class, 'manageAdmin'])
    ->whereNumber('user')
    ->middleware('can:gemini.platform_settings.update')
    ->name('gemini.platform_settings.admins.manage');

// What every client pays: `set_prices`.
Route::middleware('can:gemini.platform_settings.set_prices')->group(function () {
    Route::post('settings/prices', [PlatformSettingsController::class, 'changePrice'])
        ->name('gemini.platform_settings.price');

    /*
     * THE PER-GUARD ADD-ON (M10 §3). The same gate as a tier price, because it
     * is the same class of act — it re-prices every client with guards
     * deployed — and the same rules: dated, never retroactive, a reason, and
     * billed from the period whose first day is on or after its date.
     */
    Route::post('settings/rates', [PlatformSettingsController::class, 'changeRate'])
        ->name('gemini.platform_settings.rate');

    Route::post('settings/packages', [PlatformSettingsController::class, 'savePackages'])
        ->name('gemini.platform_settings.packages.save');

    Route::post('settings/line-items', [PlatformSettingsController::class, 'addLineItem'])
        ->name('gemini.platform_settings.line_items.add');

    Route::post('settings/line-items/{item}/end', [PlatformSettingsController::class, 'endLineItem'])
        ->whereNumber('item')
        ->name('gemini.platform_settings.line_items.end');
});

// Where every alert, sign-in code and notice is delivered from: `configure_messaging`.
Route::middleware('can:gemini.platform_settings.configure_messaging')->group(function () {
    Route::post('settings/email', [PlatformSettingsController::class, 'saveMail'])
        ->name('gemini.platform_settings.mail.save');

    /*
     * Sending a test is `configure` and not `view`, deliberately. It is a write in the sense
     * that matters — it makes the platform emit a message to an address somebody typed — and
     * a read-level permission that could post mail to any address is a small open relay.
     */
    Route::post('settings/email/test', [PlatformSettingsController::class, 'testMail'])
        ->middleware('throttle:6,1')
        ->name('gemini.platform_settings.mail.test');

    Route::post('settings/sms', [PlatformSettingsController::class, 'saveSms'])
        ->name('gemini.platform_settings.sms.save');

    // The mail test's reasoning, with a bill attached: every test text costs the client money.
    Route::post('settings/sms/test', [PlatformSettingsController::class, 'testSms'])
        ->middleware('throttle:6,1')
        ->name('gemini.platform_settings.sms.test');

    // Gemini Security's phone line, which every resident's "Call Gemini Security" calls (8 Oct 2026). On the SMS screen.
    Route::post('settings/support-line', [PlatformSettingsController::class, 'saveSupportLine'])
        ->name('gemini.platform_settings.support_line.save');
});

/*
 * CHANGING WHAT A ROLE MAY DO, AND WHO IS AN ADMINISTRATOR AT ALL (M10 §4).
 *
 * `approve`, which the Director holds and no other seeded role does — see the
 * note at the head of this file. Seven writes, each its own route so each is one
 * audited act on one role or one person:
 *
 *   POST settings/roles                         a new role, empty or with a grid
 *   POST settings/roles/{role}/copy             a new role from another's grid
 *   POST settings/roles/{role}                  rename: `label` only, never `name`
 *   POST settings/roles/{role}/permissions      re-grant the modules posted, no others
 *   POST settings/roles/{role}/delete           a custom role nobody holds
 *   POST settings/roles/{role}/reset            a seeded role back to GeminiSecure's default
 *   POST settings/admins/{user}/remove          suspended, holding nothing; never deleted
 *
 * THE RESET IS ITS OWN ROUTE, not a re-grant posted with the default grid. A
 * platform update keeps what the Director changed on a seeded role; this is
 * the one deliberate way back, it clears the mark that makes a re-seed leave
 * the role alone, and it is audited as `role.reset_to_default` so the log
 * says which of the two happened.
 *
 * `{role}` IS AN ID, AND IT IS NOT ROUTE-MODEL BOUND. The controller passes it
 * to `RoleEditor::find()`, which answers an estate's role, the other console's
 * and a role that does not exist with one sentence. A binder would 404 one and
 * resolve the others, telling a guessed id apart from a real one.
 *
 * NONE OF THIS IS RE-CHECKED IN THE CONTROLLER. The gate is here and the rules
 * are in `RoleEditor` and `PlatformAdmins`; a third copy would drift from both.
 */
Route::middleware('can:gemini.platform_settings.approve')->group(function () {
    Route::post('settings/roles', [PlatformSettingsController::class, 'createRole'])
        ->name('gemini.platform_settings.roles.create');

    Route::post('settings/roles/{role}/copy', [PlatformSettingsController::class, 'copyRole'])
        ->whereNumber('role')
        ->name('gemini.platform_settings.roles.copy');

    Route::post('settings/roles/{role}', [PlatformSettingsController::class, 'renameRole'])
        ->whereNumber('role')
        ->name('gemini.platform_settings.roles.rename');

    Route::post('settings/roles/{role}/permissions', [PlatformSettingsController::class, 'updateRolePermissions'])
        ->whereNumber('role')
        ->name('gemini.platform_settings.roles.permissions');

    Route::post('settings/roles/{role}/delete', [PlatformSettingsController::class, 'deleteRole'])
        ->whereNumber('role')
        ->name('gemini.platform_settings.roles.delete');

    Route::post('settings/roles/{role}/reset', [PlatformSettingsController::class, 'resetRole'])
        ->whereNumber('role')
        ->name('gemini.platform_settings.roles.reset');

    Route::post('settings/admins/{user}/remove', [PlatformSettingsController::class, 'removeAdmin'])
        ->whereNumber('user')
        ->name('gemini.platform_settings.admins.remove');
});
