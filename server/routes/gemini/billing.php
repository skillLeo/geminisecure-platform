<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\BillingController;
use Illuminate\Support\Facades\Route;

/*
| Billing and subscriptions — board screens super-admin-32 to 35.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| Dunning and suspension gate BILLING features only. Nothing in this module
| may withhold entry, a pass or a safety function over money owed.
*/

Route::middleware('can:gemini.billing_subscriptions.view')->group(function () {
    Route::get('billing', [BillingController::class, 'index'])->name('gemini.billing_subscriptions');

    Route::get('billing/plans', [BillingController::class, 'plans'])
        ->name('gemini.billing_subscriptions.plans');

    Route::get('billing/payment-methods', [BillingController::class, 'paymentMethods'])
        ->name('gemini.billing_subscriptions.payment_methods');

    // After the literals. `whereNumber` already keeps them apart, but ordering
    // the literals first means that constraint never becomes load-bearing.
    Route::get('billing/invoices/{invoice}', [BillingController::class, 'invoice'])
        ->whereNumber('invoice')
        ->name('gemini.billing_subscriptions.invoice');

    /*
     * The PDF is a READ of an immutable record, so it sits with the other
     * reads: an invoice's lines, total and reference never change, and a
     * correction is a credit note of its own. It still writes the entry every
     * export writes (12 §1).
     */
    Route::get('billing/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])
        ->whereNumber('invoice')
        ->name('gemini.billing_subscriptions.invoice.pdf');

    /*
     * What a client's next, not-yet-raised invoice would carry (12 §2, item
     * 44). A read of the rates and line items in force — not a document, with
     * no number, and nothing owed on it.
     */
    Route::get('billing/preview/{tenant}', [BillingController::class, 'preview'])
        ->where('tenant', '[a-z0-9][a-z0-9-]{0,62}')
        ->name('gemini.billing_subscriptions.preview');
});

/*
| The three writes (12 §2, Wave 4; 13 B1). Each reaches outside this console and
| each is audited. None edits the invoice: a raised invoice is never edited, and a
| correction is a credit note, which is a new posted record of its own.
|
| ONE PERMISSION EACH (M11 B3, D-150). They were all Billing's `update`, and they
| are three different powers: sending a client an email changes nothing it owes,
| raising an invoice bills it at the prices in force, and a credit note writes
| money off. Pricing a client, the fourth act `update` was split into, is on the
| Clients routes (`ClientPlan::PRICING_PERMISSION`).
*/
Route::post('billing/invoices/{invoice}/resend', [BillingController::class, 'resendInvoice'])
    ->middleware('can:gemini.billing_subscriptions.resend_invoices')
    ->whereNumber('invoice')
    ->name('gemini.billing_subscriptions.invoice.resend');

Route::post('billing/invoices/{invoice}/credit-note', [BillingController::class, 'creditNote'])
    ->middleware('can:gemini.billing_subscriptions.issue_credit_notes')
    ->whereNumber('invoice')
    ->name('gemini.billing_subscriptions.invoice.credit_note');

/*
 * The invoice run (13 B1): raise the period the preview projects, and post
 * it — Dr 1100 Accounts Receivable, Cr 4000 Revenue. A POST that CREATES a
 * record; it still edits and deletes nothing.
 */
Route::post('billing/preview/{tenant}/raise', [BillingController::class, 'raiseInvoice'])
    ->middleware('can:gemini.billing_subscriptions.raise_invoices')
    ->where('tenant', '[a-z0-9][a-z0-9-]{0,62}')
    ->name('gemini.billing_subscriptions.invoice.raise');

/*
| No PATCH or DELETE, and the absence is the enforcement.
|
| A raised invoice is a posted record. There is no edit route and no delete
| route, not even a guarded one — a correction is a credit note, which is a new
| posted record of its own. A route that exists but is gated is one refactor
| away from being reachable.
*/
