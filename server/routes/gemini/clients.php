<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\ClientController;
use App\Services\Gemini\ClientPlan;
use Illuminate\Support\Facades\Route;

/*
| Clients — board screens super-admin-04 to 11.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
*/

Route::middleware('can:gemini.clients.view')->group(function () {
    Route::get('clients', [ClientController::class, 'index'])->name('gemini.clients');
});

/*
 * Taking on a client is a `create`, not an `update`. A role that may edit the
 * clients it already has is not thereby a role that may add one to the
 * platform's book.
 *
 * Registered BEFORE clients/{tenant}: "new" would otherwise be read as a
 * subdomain and 404 on a tenant nobody has created.
 *
 * AND BILLING & SUBSCRIPTIONS' PRICE CLIENTS ACT AS WELL (bug hunt B004 and
 * B069, D-139; its own permission since M11 B3, D-150). The form is a price list
 * — every tier's per-unit price, the per-guard rate and a live projection — and
 * the submission puts the new client on a tier, which is choosing what it will
 * be charged. The Head of Security holds Clients in full and no Billing cell at
 * all, so this screen handed them every price the platform charges. The first
 * fix asked for Billing's READ verb, and the Operations Manager, who holds
 * Billing to read only, could still put a client on a tier: choosing a price is
 * one of Billing's writes, as raising, resending and crediting an invoice are
 * in routes/gemini/billing.php. See `ClientPlan::PRICING_PERMISSION`.
 */
Route::middleware(['can:gemini.clients.create', 'can:'.ClientPlan::PRICING_PERMISSION])->group(function () {
    Route::get('clients/new', [ClientController::class, 'create'])->name('gemini.clients.create');

    Route::post('clients/new', [ClientController::class, 'store'])->name('gemini.clients.store');
});

Route::middleware('can:gemini.clients.view')->group(function () {
    Route::get('clients/{tenant}', [ClientController::class, 'show'])->name('gemini.clients.show');
});

/*
 * The management screens, each on `update` rather than `view`.
 *
 * Both the screen and the write it launches, deliberately. These boards are not
 * reports with an action bolted on: every panel on them is an editing control,
 * and a role that may only read a client has the client record next door which
 * shows the same facts. Opening a roster editor to someone who cannot save it
 * would be a screenful of controls that all end in a 403.
 */
Route::middleware('can:gemini.clients.update')->group(function () {
    Route::get('clients/{tenant}/guards', [ClientController::class, 'guards'])
        ->name('gemini.clients.guards');

    Route::post('clients/{tenant}/guards', [ClientController::class, 'saveGuards'])
        ->name('gemini.clients.guards.save');

    /*
     * Pricing a client. Both halves on `update`, because every panel on the
     * screen is an editing control and the client record next door already
     * shows the same facts to a reader who may not change them.
     *
     * AND BOTH HALVES ON BILLING & SUBSCRIPTIONS' PRICE CLIENTS TOO (bug hunt
     * B004 and B069, D-139; M11 B3). The screen is drawn from Billing's figures — every
     * tier's price, the per-guard rate, the platform's MRR — and the write sets
     * what the client is invoiced from the next cycle. On Clients `update`
     * alone, the Head of Security, who holds Clients Scoped and no Billing cell,
     * read the whole price list and the platform's earnings and could re-price
     * the client. The client record the note above points at leaves all of that
     * out for them, so it cannot be the screen next door that hands it back.
     *
     * A WRITE, not the read verb. Re-pricing a client changes what it owes, as
     * every write in routes/gemini/billing.php does; on the read verb, the
     * Operations Manager — Billing View — re-priced clients
     * from a module it may only read. The screen goes with the save, for the
     * reason the note above gives: every panel on it is an editing control, and
     * that reader has the prices on the client record and on billing/plans.
     */
    Route::middleware('can:'.ClientPlan::PRICING_PERMISSION)->group(function () {
        Route::get('clients/{tenant}/plan', [ClientController::class, 'plan'])
            ->name('gemini.clients.plan');

        Route::post('clients/{tenant}/plan', [ClientController::class, 'savePlan'])
            ->name('gemini.clients.plan.save');
    });

    /*
     * Messaging a committee. On `update` because it reaches into the client's
     * own console and speaks on Gemini's behalf — a role that may only read a
     * client should not be able to tell them their guard has been suspended.
     */
    Route::get('clients/{tenant}/message', [ClientController::class, 'message'])
        ->name('gemini.clients.message');

    Route::post('clients/{tenant}/message', [ClientController::class, 'sendMessage'])
        ->name('gemini.clients.message.send');

    /*
     * PUTTING A CLIENT LIVE — the act that starts billing.
     *
     * THIS ROUTE DID NOT EXIST, and the button for it did. `Gemini/Clients/Show.vue` has drawn
     * "Mark onboarding complete" since the screen was built and `ClientOnboarding::complete()`
     * has been there to serve it, with `blockedReason()` written specifically so the refusal
     * could be rendered on the button that cannot be pressed. Nothing connected the two: the
     * press posted to a path registered nowhere and the operator got the 404 page. Found by
     * creating a client through the console and pressing the one button that finishes the job.
     *
     * `update` and not `create`: the client already exists. This changes what is true about an
     * existing one — its subscription starts charging and its estate goes live — which is the
     * same verb the plan and roster screens beside it carry.
     *
     * NOT BEHIND BILLING, unlike the plan screen above (bug hunt B004/B136, decided). Nothing
     * here shows or chooses a price: the plan was set on the plan screen, by someone who holds
     * Billing's Price clients act, and this is the operational act of saying the site is ready. The
     * onboarding checklist keeps "Subscription plan prepared" for a reader without Billing
     * precisely so that reader can see what go-live waits on (`ClientDirectory::checklist()`),
     * and when the plan is missing the button tells a reader who cannot set it who can
     * (`ClientPlan::unplannedReason()`), rather than to set it.
     */
    Route::post('clients/{tenant}/onboarding/complete', [ClientController::class, 'completeOnboarding'])
        ->name('gemini.clients.onboarding.complete');

    /*
     * THE CONTACT'S INVITATION, SENT OR SENT AGAIN (DESIGN §11 row 5). Onboarding made the contact an
     * account and sent nothing; the client page now says whether their invitation went and offers this.
     * `update`, as every act on the client record is. Six a minute: the service's own minute between
     * sends is the rule, and this stops a script.
     */
    Route::post('clients/{tenant}/contact-invitation/resend', [ClientController::class, 'resendContactInvitation'])
        ->middleware('throttle:6,1')
        ->name('gemini.clients.contact_invitation.resend');

    /*
     * THE ESTATE'S CLOCK, CHANGED (M19A §9) — the DIRECTOR's alone, asked of the role inside the controller: moving an
     * estate's zone moves every time its residents, guards and office read, and what a wall-clock time typed from now on
     * means. The screen says so before the button, and the change is audited with the zone before and after.
     */
    Route::post('clients/{tenant}/timezone', [ClientController::class, 'saveTimezone'])
        ->name('gemini.clients.timezone.save');
});

/*
| There is no DELETE anywhere in this file, and that is deliberate.
|
| A client that leaves is `cancelled`, not removed: their invoices, their audit
| trail and the messages sent to them all refer to a tenant that has to keep
| existing. A subdomain is never reused either, so the row is the record that
| it was taken.
*/
