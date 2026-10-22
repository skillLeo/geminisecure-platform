<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Http\Controllers\Auth\ContactVerificationController;
use App\Http\Controllers\Auth\FrontDoorRedeemController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Console\AccountController;
use App\Http\Controllers\Console\ConsolePhotoController;
use App\Http\Controllers\Estate\AccountingController;
use App\Http\Controllers\Estate\AuditLogController;
use App\Http\Controllers\Estate\CollectionsController;
use App\Http\Controllers\Estate\CommitteeMessagesController;
use App\Http\Controllers\Estate\DashboardController as EstateDashboardController;
use App\Http\Controllers\Estate\DeletedAccountsController;
use App\Http\Controllers\Estate\DocumentController;
use App\Http\Controllers\Estate\DuesController;
use App\Http\Controllers\Estate\EstateStructureController;
use App\Http\Controllers\Estate\FacilitiesController;
use App\Http\Controllers\Estate\GovernanceController;
use App\Http\Controllers\Estate\ManualJournalController;
use App\Http\Controllers\Estate\NoticesController;
use App\Http\Controllers\Estate\PayablesController;
use App\Http\Controllers\Estate\PayrollController;
use App\Http\Controllers\Estate\RecordController;
use App\Http\Controllers\Estate\ReportsController;
use App\Http\Controllers\Estate\ResidentInviteTrustController;
use App\Http\Controllers\Estate\ResidentsController;
use App\Http\Controllers\Estate\SecurityController;
use App\Http\Controllers\Estate\SettingsController;
use App\Http\Middleware\EnsureContactVerified;
use App\Http\Middleware\EnsureEstateAccess;
use App\Http\Middleware\EnsureEstatePlanIncludes;
use App\Http\Middleware\ForgetTenantRouteParameter;
use App\Services\Auth\FrontDoor;
use App\Services\Rbac\RoleEditor;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Estate Console routes
|--------------------------------------------------------------------------
|
| The SAME routes, reachable two ways.
|
|   Production:  phoenixpark.geminisecure.com/...     (subdomain)
|   Local:       localhost:8000/estate/phoenixpark/...  (path)
|
| Subdomains are right for production — they give each community its own
| hostname and let the session cookie be scoped per estate. They are wrong for
| local development: *.localhost does not resolve on Windows, a hosts entry
| needs administrator rights, and a second registrable domain means the session
| cookie does not travel, which is a login loop rather than a login screen.
|
| So the path form exists ONLY in local, and both forms register the same
| controllers. Nothing about the application knows which one it is serving.
|
| Tenant identity comes from the resolved subdomain or path segment and from
| nowhere else. It is never read from a request parameter, hidden field or
| query string.
|
*/

/** The routes themselves, registered identically under both resolvers. */
$estateRoutes = function (): void {
    Route::get('/', EstateDashboardController::class)->name('estate.home');

    /*
     * M12 §4.8: the signed-in person's own profile picture, on this estate's door (the session cookie is host-only).
     * No {user}: always the caller's own. The picture itself is central — the same one on every console.
     */
    Route::get('account/photo', [ConsolePhotoController::class, 'show'])->name('estate.account.photo');
    Route::post('account/photo', [ConsolePhotoController::class, 'store'])->middleware('throttle:20,1')->name('estate.account.photo.store');
    Route::delete('account/photo', [ConsolePhotoController::class, 'destroy'])->name('estate.account.photo.destroy');

    /*
     * M32 F9: My account, on this estate's door — the same page and routes as the Gemini console's, always the caller's
     * own. Throttled like sign-in where a password or a code is asked.
     */
    Route::get('account', [AccountController::class, 'show'])->name('estate.account');
    Route::post('account/details', [AccountController::class, 'details'])->middleware('throttle:20,1')->name('estate.account.details');
    // M34 §1: the sign-in address — the password and the new address, then the code emailed to the new address.
    Route::post('account/sign-in-email/code', [AccountController::class, 'signInEmailCode'])->middleware('throttle:login')->name('estate.account.sign_in_email.code');
    Route::post('account/sign-in-email', [AccountController::class, 'signInEmail'])->middleware('throttle:20,1')->name('estate.account.sign_in_email');
    Route::post('account/sign-in-email/cancel', [AccountController::class, 'signInEmailCancel'])->name('estate.account.sign_in_email.cancel');
    Route::post('account/password/code', [AccountController::class, 'passwordCode'])->middleware('throttle:10,1')->name('estate.account.password.code');
    Route::post('account/password', [AccountController::class, 'password'])->middleware('throttle:login')->name('estate.account.password');
    Route::post('account/two-factor/start', [AccountController::class, 'twoFactorStart'])->middleware('throttle:login')->name('estate.account.two_factor.start');
    Route::post('account/two-factor/confirm', [AccountController::class, 'twoFactorConfirm'])->middleware('throttle:20,1')->name('estate.account.two_factor.confirm');
    Route::post('account/two-factor/replace', [AccountController::class, 'twoFactorReplace'])->middleware('throttle:login')->name('estate.account.two_factor.replace');
    Route::post('account/two-factor/off', [AccountController::class, 'twoFactorOff'])->middleware('throttle:login')->name('estate.account.two_factor.off');
    Route::post('account/two-factor/codes', [AccountController::class, 'twoFactorCodes'])->middleware('throttle:login')->name('estate.account.two_factor.codes');
    Route::post('account/two-factor/cancel', [AccountController::class, 'twoFactorCancel'])->name('estate.account.two_factor.cancel');
    Route::post('account/sessions/{session}/sign-out', [AccountController::class, 'signOutSession'])->whereNumber('session')->name('estate.account.sessions.sign_out');
    Route::post('account/sessions/sign-out-others', [AccountController::class, 'signOutOthers'])->name('estate.account.sessions.sign_out_others');

    /*
     * The full activity log and the notification centre (12 §2, Wave 2).
     *
     * NO EXTRA GATE. Both compose facts this viewer's own module permissions
     * already decide — `ActivityLog::attention()` asks the gate per item, so a
     * Property Manager reaching the bell is shown the tickets they hold and
     * never a claim or a balance. Marking something read is a note about the
     * reader, not a change to the estate, so it needs nothing further.
     */
    /*
     * DOCUMENTS (12 §1). Server-rendered, queued, seven-year retention.
     *
     * EVERY KIND IS GATED ON ITS OWN MODULE and never on a documents
     * permission: a statement is a household's financial position, a receipt is
     * Payments', minutes and a certificate are Governance's. One "documents"
     * gate would be a way of reading any of them through one hole.
     *
     * The download route is deliberately NOT gated further: a document already
     * exists because somebody who held the module asked for it, and it lives
     * under this estate's own private prefix behind this estate's auth group.
     */
    Route::post('finance/units/{unit}/statement', [DocumentController::class, 'statement'])
        ->whereNumber('unit')
        ->middleware('can:estate.dues_ledger.view')
        ->name('estate.document.statement');

    Route::post('finance/payments/{payment}/receipt', [DocumentController::class, 'receipt'])
        ->whereNumber('payment')
        ->middleware('can:estate.payments.view')
        ->name('estate.document.receipt');

    Route::post('accounting/bill-payments/{payment}/remittance', [DocumentController::class, 'remittance'])
        ->whereNumber('payment')
        ->middleware([EnsureEstatePlanIncludes::class.':accounting_core', 'can:estate.accounting_posting.view'])
        ->name('estate.document.remittance');

    Route::post('governance/meetings/{meeting}/{kind}', [DocumentController::class, 'meetingPaper'])
        ->whereNumber('meeting')
        ->where('kind', 'agenda|minutes')
        ->middleware('can:estate.governance.view')
        ->name('estate.document.meeting');

    Route::post('governance/elections/{year}/certificate', [DocumentController::class, 'certificate'])
        ->whereNumber('year')
        ->middleware('can:estate.governance.view')
        ->name('estate.document.certificate');

    Route::get('documents/{document}', [DocumentController::class, 'download'])
        ->whereNumber('document')
        ->name('estate.document.download');

    // Where a document is while a worker writes it: what the shell polls to hand it over (M56 §5).
    Route::get('documents/{document}/status', [DocumentController::class, 'status'])
        ->whereNumber('document')
        ->name('estate.document.status');

    Route::get('activity', [EstateDashboardController::class, 'activity'])->name('estate.activity');
    Route::get('notifications', [EstateDashboardController::class, 'notifications'])->name('estate.notifications');
    Route::post('notifications/read', [EstateDashboardController::class, 'markNotificationsRead'])->name('estate.notifications.read');

    /*
     * Messages from Gemini Security to the committee member they were addressed
     * to (Q-M40, D-M128). NO `can:` HERE, AND THAT IS DELIBERATE.
     *
     * Every other estate route in this file carries its module's permission. This
     * one is gated on the ADDRESS on the message instead: the query is keyed on
     * this estate and on the signed-in user's own id, in SQL, and no id is taken
     * from the URL. A module permission would be the wrong gate — under
     * Governance a Treasurer could not read a letter written to the Treasurer,
     * and under Settings nobody but an administrator could read one at all.
     *
     * It sits beside the notification centre because that is the surface it rides:
     * the bell says a message is waiting and links here. The Estate Console sidebar
     * is ten items in four groups and the boards are the contract, so this adds no
     * eleventh (`App\Support\EstateNavigation`).
     */
    Route::get('messages', CommitteeMessagesController::class)->name('estate.messages');

    /*
     * Estate structure — board 3.
     *
     * READING THE LAYOUT IS `view`; CHANGING IT IS NOT. A phase brings addresses
     * into existence, and an address is what a household is filed at, what dues
     * are billed to and what a guard admits somebody to — so it is `create`,
     * which the President and the Vice President do not hold. Board 24 gives
     * this module Full to the Community Super Admin and the Property Manager,
     * View to the three officers, and an em dash to the Treasurer and the Admin
     * Assistant: a Treasurer opening this console finds no Estate structure at
     * all, whatever every estate board draws in its sidebar (D-044).
     */
    Route::get('estate', [EstateStructureController::class, 'index'])
        ->middleware('can:estate.estate_structure.view')
        ->name('estate.structure');

    // Both writes are `create` (12 §2, Wave 1) and both are all-or-nothing:
    // a phase with its lots in one transaction, an import committed whole.
    Route::middleware('can:estate.estate_structure.create')->group(function (): void {
        Route::post('estate/phases', [EstateStructureController::class, 'addPhase'])->name('estate.structure.phase.add');

        Route::post('estate/import/preview', [EstateStructureController::class, 'previewImport'])->name('estate.structure.import.preview');

        Route::post('estate/import/commit', [EstateStructureController::class, 'commitImport'])->name('estate.structure.import.commit');
    });

    /*
     * Residents — boards 4, 31, 34 and 38.
     *
     * READING THE REGISTER IS `view` AND EVERY ESTATE ROLE HOLDS IT. What they
     * do not all hold is what a register READS: a household's balance is
     * `estate.dues_ledger.view`, a different module that the Property Manager is
     * locked out of by platform invariant (D-010). The controller decides that
     * once and passes it down; there is no residents permission that grants a
     * resident's financial position.
     *
     * ADDING A RESIDENT IS `create`. It puts a person on the estate's register,
     * which is where authorisation at a gate ultimately comes from.
     *
     * APPROVING A CLAIM IS `approve`, ON ITS OWN, AND `update` DOES NOT REACH
     * IT. Approving binds a person to a household — whose guest passes they may
     * issue, whose gate they may be admitted at — and no edit afterwards unbinds
     * the night somebody was let through. Refusing a claim and asking for a
     * document are `update`, because each is answered by making the opposite
     * decision and neither authorises anybody. That split is D-013's whole
     * purpose, and it is why the Secretary — who holds Full on Residents and
     * therefore `update` — is refused the approval and not the refusal.
     */
    Route::middleware('can:estate.residents.view')
        ->prefix('residents')
        ->name('estate.residents.')
        ->group(function (): void {
            Route::get('/', [ResidentsController::class, 'index'])->name('index');

            /*
             * BOTH LITERALS BEFORE `{unit}`, and it is not a preference. The
             * detail route matches any lowercase slug, so "claims" and "new"
             * would both resolve to a unit lookup and answer 404 if they were
             * registered after it.
             */
            Route::get('claims', [ResidentsController::class, 'claims'])->name('claims');

            // The chase list (M18C §5): households whose members or contacts are missing a phone or an email.
            Route::get('missing-contacts', [ResidentsController::class, 'missingContacts'])->name('missing_contacts');

            /*
             * DELETED ACCOUNTS (M18H §6): the list is `view`; a restore is `update`, the permission that ends and gives
             * back app access (Q-M44); deleting one permanently is the Community Super Admin's alone, and the controller
             * asks for that role by name — no permission a role editor could hand out reaches it.
             */
            Route::get('deleted-accounts', [DeletedAccountsController::class, 'index'])->name('deleted_accounts');

            Route::post('deleted-accounts/{account}/restore', [DeletedAccountsController::class, 'restore'])
                ->whereNumber('account')
                ->middleware('can:estate.residents.update')
                ->name('deleted_accounts.restore');

            Route::post('deleted-accounts/{account}/delete-permanently', [DeletedAccountsController::class, 'destroyPermanently'])
                ->whereNumber('account')
                ->middleware('can:estate.residents.update')
                ->name('deleted_accounts.permanent');

            // A removed household, back on its lot (M21 §3.1) — `update`, as restoring an account is.
            Route::post('removed-households/{household}/restore', [DeletedAccountsController::class, 'restoreHousehold'])
                ->whereNumber('household')
                ->middleware('can:estate.residents.update')
                ->name('removed_households.restore');

            Route::get('new', [ResidentsController::class, 'create'])->name('new');

            Route::post('/', [ResidentsController::class, 'store'])
                ->middleware('can:estate.residents.create')
                ->name('store');

            // The bulk import (M98 census): preview, then the previewed list written whole. `create`, as Add resident is.
            Route::post('import/preview', [ResidentsController::class, 'previewImport'])
                ->middleware('can:estate.residents.create')
                ->name('import.preview');

            Route::post('import/commit', [ResidentsController::class, 'commitImport'])
                ->middleware('can:estate.residents.create')
                ->name('import.commit');

            Route::post('claims/{claim}/approve', [ResidentsController::class, 'approveClaim'])
                ->whereNumber('claim')
                ->middleware('can:estate.residents.approve')
                ->name('claim.approve');

            Route::post('claims/{claim}/reject', [ResidentsController::class, 'rejectClaim'])
                ->whereNumber('claim')
                ->middleware('can:estate.residents.update')
                ->name('claim.reject');

            Route::post('claims/{claim}/document', [ResidentsController::class, 'requestDocument'])
                ->whereNumber('claim')
                ->middleware('can:estate.residents.update')
                ->name('claim.document');

            /*
             * Board 38's own URL keys on the LOT and not on the person — the
             * unit outlives every household in it, so a bookmark still resolves
             * after the family at Lot 47 has moved out.
             *
             * The segment is `Unit::slugFor()`'s alphabet, which is every
             * character a reference can be spelled in: "lot-47", "ov_1a" for
             * "OV-1A", "a~2f12" for "A/12" (B040).
             */
            Route::get('{unit}', [ResidentsController::class, 'show'])
                ->where('unit', '[a-z0-9_~-]+')
                ->name('show');

            /*
             * Correcting a person's record is `update` (12 §2, Wave 2). It does
             * NOT change whether they are authorised — that stays with the
             * claims queue, where the decision has a name and a date on it.
             */
            Route::post('{unit}/people/{resident}', [ResidentsController::class, 'editResident'])
                ->where('unit', '[a-z0-9_~-]+')
                ->whereNumber('resident')
                ->middleware('can:estate.residents.update')
                ->name('person.edit');

            /*
             * SEND — OR RESEND — AND CANCEL A RESIDENT'S INVITE TO THE APP. Behind `create`, the permission
             * Add resident sends the first one under: whoever may put a person on the register may send them
             * the invite again when it did not arrive. Resend waits 1, then 2, then 5 minutes (ResidentInvites).
             */
            Route::post('{unit}/people/{resident}/invite', [ResidentsController::class, 'sendInvite'])
                ->where('unit', '[a-z0-9_~-]+')
                ->whereNumber('resident')
                ->middleware('can:estate.residents.create')
                ->name('person.invite');

            Route::post('{unit}/people/{resident}/invite/cancel', [ResidentsController::class, 'cancelInvite'])
                ->where('unit', '[a-z0-9_~-]+')
                ->whereNumber('resident')
                ->middleware('can:estate.residents.create')
                ->name('person.invite.cancel');

            /*
             * WHAT A PERSON MAY DO IN THE RESIDENT APP — Member, Dependent, Helper. `update`, the permission that
             * corrects their record: it changes what the estate lets them do, never who they are or whether they
             * are verified, and it is undone the same way it is done.
             */
            Route::post('{unit}/people/{resident}/access-level', [ResidentsController::class, 'setAccessLevel'])
                ->where('unit', '[a-z0-9_~-]+')
                ->whereNumber('resident')
                ->middleware('can:estate.residents.update')
                ->name('person.access_level');

            /*
             * ENDING AND GIVING BACK A RESIDENT APP ACCOUNT — Q-M44, board 38.
             *
             * `update` AND ITS OWN URL, NOT A FIELD ON THE EDIT FORM. Ending somebody's
             * app access destroys every sign-in on every phone, every address a
             * notification could reach them at, and the e-pass a guard admits them on —
             * and it makes them a VISITOR at their own former gate. That is an explicit
             * act with a reason on it, never something that happens because a clerk
             * corrected a telephone number and saved the form.
             *
             * `update` and not `approve`, deliberately: `approve` is the irreversible
             * side of D-013 — binding a person to a household — and this is the opposite
             * act and a reversible one. The office that may correct the register is the
             * office that may end an app login, and `restore` sits behind the same
             * permission, because a feature that cannot be undone by the person who used
             * it is a feature nobody uses.
             */
            Route::post('{unit}/people/{resident}/app-access/revoke', [ResidentsController::class, 'revokeAppAccess'])
                ->where('unit', '[a-z0-9_~-]+')
                ->whereNumber('resident')
                ->middleware('can:estate.residents.update')
                ->name('person.app_access.revoke');

            Route::post('{unit}/people/{resident}/app-access/restore', [ResidentsController::class, 'restoreAppAccess'])
                ->where('unit', '[a-z0-9_~-]+')
                ->whereNumber('resident')
                ->middleware('can:estate.residents.update')
                ->name('person.app_access.restore');

            /*
             * REMOVING THE HOUSEHOLD (M21 §3.1): every member's app access ended and every member off the register at
             * once, recoverable for the office window — `update`, the permission that ends one person's app access.
             */
            Route::post('{unit}/household/remove', [ResidentsController::class, 'removeHousehold'])
                ->where('unit', '[a-z0-9_~-]+')
                ->middleware('can:estate.residents.update')
                ->name('household.remove');
        });

    /*
     * Dues & ledger — boards 5, 6 and 35.
     *
     * Reading the arrears is `view`. POSTING A CHARGE IS NOT: it adds to what a
     * household owes, so it needs `create`, and the Property Manager holds
     * neither by platform invariant rather than estate preference (D-010).
     */
    Route::middleware('can:estate.dues_ledger.view')
        ->prefix('finance')
        ->name('estate.dues.')
        ->group(function (): void {
            Route::get('arrears', [DuesController::class, 'arrears'])->name('arrears');

            /*
             * EXPORTING IS ITS OWN VERB (12 §1). A file naming who owes what
             * leaves the estate and this system cannot recall it; what it can
             * do is record that it left, who took it and what was in it, and
             * every route here goes through `Exporter` so that entry is written
             * before a byte is streamed.
             */
            Route::get('arrears/export', [DuesController::class, 'exportArrears'])
                ->middleware('can:estate.dues_ledger.export')
                ->name('arrears.export');

            Route::get('units/{unit}', [DuesController::class, 'unit'])
                ->whereNumber('unit')
                ->name('unit');

            /*
             * THE TEST CARD CHECKOUT — a preview of paying by card, and ONLY a GET. There is no
             * route that takes what is typed on it: "Pay" on that page makes no request, so a
             * card number cannot reach this server. `TestCardCheckoutTest` holds it to that.
             */
            Route::get('units/{unit}/test-checkout', [DuesController::class, 'testCheckout'])
                ->whereNumber('unit')
                ->name('unit.test_checkout');

            /*
             * HARDSHIP AND DISPUTE (12 §1). A flag records a committee decision
             * to stop chasing a household, carries the minute that agreed it,
             * and moves no money — so it is an approval, not the office's
             * `create`. Lifting one is the same decision in reverse.
             *
             * EVERY APPROVAL IN THIS GROUP IS ITS OWN PERMISSION (M11 B3, D-150).
             * `estate.dues_ledger.approve` gated five powers at once — rates,
             * reversals, plans, flags and reminder wording — so granting one
             * granted all five. Each route below names the one it is:
             * `App\Support\NamedActs` lists them.
             */
            Route::post('units/{unit}/flag', [DuesController::class, 'flagUnit'])
                ->whereNumber('unit')
                ->middleware('can:estate.dues_ledger.flag_units')
                ->name('unit.flag');

            Route::post('units/{unit}/flag/{flag}/lift', [DuesController::class, 'liftFlag'])
                ->whereNumber('unit')
                ->whereNumber('flag')
                ->middleware('can:estate.dues_ledger.flag_units')
                ->name('unit.flag.lift');

            /*
             * RECORDING A PAYMENT IS THE PAYMENTS MODULE'S VERB, not this one's.
             * D-010 split `payments` from `dues_ledger` so a role may read what a
             * household owes without being able to credit it; the Treasurer and
             * the Admin Assistant hold both, and board 6 draws the control for
             * the Treasurer. Dr 1000 Bank, Cr 1200 against the unit.
             */
            Route::post('units/{unit}/payments', [DuesController::class, 'recordPayment'])
                ->whereNumber('unit')
                ->middleware('can:estate.payments.create')
                ->name('unit.payment');

            // The receipt register — board 5's Receipts tab, with the sequence
            // the ruling gave it and every gap in it drawn.
            Route::get('receipts', [DuesController::class, 'receipts'])->name('receipts');

            Route::get('receipts/export', [DuesController::class, 'exportReceipts'])
                ->middleware('can:estate.payments.export')
                ->name('receipts.export');

            Route::get('charges/new', [DuesController::class, 'newCharge'])->name('charge.new');

            Route::post('charges', [DuesController::class, 'postCharge'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('charge.store');

            /*
             * A WHOLE-PHASE OR WHOLE-ESTATE CHARGE IS TWO PRESSES (12 §2).
             * The first draws the list and posts nothing; the second bills
             * every unit on that list in one transaction, all of them or none.
             * Same `create` gate as a single charge — it is the same act, at
             * the scale that makes the preview necessary.
             */
            Route::post('charges/preview', [DuesController::class, 'previewBulk'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('charge.preview');

            Route::post('charges/bulk', [DuesController::class, 'postBulk'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('charge.bulk');

            /*
             * Collections — boards 7 and 8.
             *
             * Same module, same `view` gate on the reads, and TWO different
             * write gates on the writes. Drafting a plan, recording that a
             * household agreed and sending a notice are `create`. ACTIVATING a
             * plan is an approval, and its own one (`activate_plans`), because it
             * lifts the arrears restriction on a household's guest passes — a
             * decision about who gets through the gate tonight, not data entry,
             * and approval is what this system reserves for acts that editing
             * the row afterwards cannot walk back (D-013).
             */
            // Every plan in the estate — board 5's register tab (12 §2, item 18).
            Route::get('payment-plans', [CollectionsController::class, 'plans'])->name('plans');

            /*
             * THE CHARGE SCHEDULE (12 §2, item 17). Posting a month is the
             * office's `create`, the same as any charge — and it is posted
             * against the figures its preview showed.
             *
             * TWO APPROVALS, NOT ONE (M11 B3). Defining or stopping a schedule
             * and changing its rate from a month not yet billed (M10 §3) are
             * `set_rates`: what every household in it is billed from now on.
             * Reversing a posted month is `reverse_runs`: it rewrites a month
             * already on every household's ledger, and a role that sets rates
             * must not carry it — which is the whole of PRICE-02.
             */
            Route::get('charge-schedule', [DuesController::class, 'chargeSchedule'])->name('schedule');

            Route::post('charge-schedule', [DuesController::class, 'createSchedule'])
                ->middleware('can:estate.dues_ledger.set_rates')
                ->name('schedule.create');

            Route::post('charge-schedule/{schedule}/stop', [DuesController::class, 'stopSchedule'])
                ->whereNumber('schedule')
                ->middleware('can:estate.dues_ledger.set_rates')
                ->name('schedule.stop');

            // The same permission as defining the schedule, because it is the
            // same act at a later date: what every household in scope is billed.
            Route::post('charge-schedule/{schedule}/rate', [DuesController::class, 'amendScheduleRate'])
                ->whereNumber('schedule')
                ->middleware('can:estate.dues_ledger.set_rates')
                ->name('schedule.rate');

            Route::post('charge-schedule/{schedule}/runs', [DuesController::class, 'postRun'])
                ->whereNumber('schedule')
                ->middleware('can:estate.dues_ledger.create')
                ->name('schedule.run');

            Route::post('charge-runs/{run}/reverse', [DuesController::class, 'reverseRun'])
                ->whereNumber('run')
                ->middleware('can:estate.dues_ledger.reverse_runs')
                ->name('schedule.run.reverse');

            // One charge, with a reason (M53 §2): the same approval as a month, on one household's line.
            Route::post('charges/{charge}/reverse', [DuesController::class, 'reverseCharge'])
                ->whereNumber('charge')
                ->middleware('can:estate.dues_ledger.reverse_runs')
                ->name('charge.reverse');

            Route::get('units/{unit}/payment-plan', [CollectionsController::class, 'plan'])
                ->whereNumber('unit')
                ->name('plan');

            Route::post('units/{unit}/payment-plan', [CollectionsController::class, 'generate'])
                ->whereNumber('unit')
                ->middleware('can:estate.dues_ledger.create')
                ->name('plan.generate');

            Route::post('payment-plans/{plan}/agree', [CollectionsController::class, 'agree'])
                ->whereNumber('plan')
                ->middleware('can:estate.dues_ledger.create')
                ->name('plan.agree');

            Route::post('payment-plans/{plan}/activate', [CollectionsController::class, 'activate'])
                ->whereNumber('plan')
                ->middleware('can:estate.dues_ledger.activate_plans')
                ->name('plan.activate');

            /*
             * HOW A PLAN ENDS (bug hunt B019). Withdrawing a draft is `create`:
             * it lifted nothing. Ruling an instalment met or missed is an
             * approval, like activating: missed defaults the plan and puts the
             * restriction back at the gate, met can complete it and lift the
             * restriction for good. Nothing else ends a plan — no timer, no
             * command.
             *
             * AND THE SAME APPROVAL AS ACTIVATING, `activate_plans` (M11 B3,
             * D-150). Both decide whether this household's plan shields it at
             * the gate; the role that may put a plan in force is the role that
             * says whether it is being kept, and neither is the role that sets
             * a rate.
             */
            Route::post('payment-plans/{plan}/withdraw', [CollectionsController::class, 'withdraw'])
                ->whereNumber('plan')
                ->middleware('can:estate.dues_ledger.create')
                ->name('plan.withdraw');

            Route::post('payment-plan-instalments/{instalment}/met', [CollectionsController::class, 'instalmentMet'])
                ->whereNumber('instalment')
                ->middleware('can:estate.dues_ledger.activate_plans')
                ->name('plan.instalment.met');

            Route::post('payment-plan-instalments/{instalment}/missed', [CollectionsController::class, 'instalmentMissed'])
                ->whereNumber('instalment')
                ->middleware('can:estate.dues_ledger.activate_plans')
                ->name('plan.instalment.missed');

            Route::get('dunning', [CollectionsController::class, 'dunning'])->name('dunning');

            Route::post('dunning', [CollectionsController::class, 'send'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('dunning.send');

            /*
             * The template editor (12 §1). Wording a step is `create` — the
             * office proposes, and a draft sends nothing. Putting one in force
             * is an approval, `adopt_dunning`, against a committee resolution
             * reference, because what a household is told about its debt is the
             * committee's decision and not the office's.
             */
            Route::post('dunning/drafts', [CollectionsController::class, 'saveDraft'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('dunning.draft');

            Route::post('dunning/drafts/{draft}/adopt', [CollectionsController::class, 'adoptDraft'])
                ->whereNumber('draft')
                ->middleware('can:estate.dues_ledger.adopt_dunning')
                ->name('dunning.adopt');
        });

    /*
     * Accounting — boards 25 to 28, and 39 behind them.
     *
     * A separate permission module from Dues & ledger, and deliberately: D-010
     * split them so a Property Manager could be refused the estate's books
     * while still seeing the vendor costs they commission.
     *
     * READING IS `view`; the writes below are not. Paying a bill moves money out
     * of the estate's bank account, so it needs `create`; approving a bill and
     * completing a reconciliation are both approvals, because each is the
     * moment a figure stops being a proposal — an approved bill is money owed,
     * and a signed-off period is one nobody re-opens. D-013 separated `approve`
     * from `update` for exactly that.
     *
     * AND THEY ARE TWO APPROVALS, NOT ONE (M11 B3, D-150): `approve_bills` and
     * `complete_reconciliations`. Whoever signs a bank reconciliation off is the
     * check on whoever committed the estate to pay, and one permission for both
     * meant a reviewer could not be given the sign-off without the power it
     * reviews.
     */
    // A plan that leaves Accounting out answers every address here with a page that says so (PlanGate).
    Route::middleware([EnsureEstatePlanIncludes::class.':accounting_core', 'can:estate.accounting_posting.view'])
        ->prefix('accounting')
        ->name('estate.accounting.')
        ->group(function (): void {
            Route::get('chart-of-accounts', [AccountingController::class, 'chart'])->name('chart');

            // M84 §3.1: an account opens to its entries, and an entry to its journal. Read only, behind
            // FEATURE_ACCOUNT_DRILLDOWN (config/features.php); off, both answer 404.
            Route::get('chart-of-accounts/{account}', [AccountingController::class, 'account'])
                ->whereNumber('account')
                ->name('account');

            Route::get('journals/{reference}', [AccountingController::class, 'journal'])
                ->where('reference', '[A-Z]{1,8}-[0-9]{4}-[0-9]{2}-[0-9]{1,8}')
                ->name('journal');

            // M84 §3.3: a month's budget per account. Behind FEATURE_BUDGETS; off, both answer 404. Setting one posts
            // nothing, and is `create`, like recording a bill.
            Route::get('budgets', [AccountingController::class, 'budgets'])->name('budgets');

            Route::post('budgets', [AccountingController::class, 'saveBudgets'])
                ->middleware('can:estate.accounting_posting.create')
                ->name('budgets.save');

            // M84 §3.4: manual journal entry, behind FEATURE_MANUAL_JOURNALS; off, every address answers 404. A draft is
            // `create`; posting is the Treasurer's or the Community Super Admin's alone (ManualJournals::mayPost).
            Route::get('manual-journals', [ManualJournalController::class, 'index'])->name('manual_journals');
            Route::get('manual-journals/new', [ManualJournalController::class, 'create'])->name('manual_journals.new');

            Route::post('manual-journals', [ManualJournalController::class, 'store'])
                ->middleware('can:estate.accounting_posting.create')
                ->name('manual_journals.store');

            Route::get('manual-journals/{draft}', [ManualJournalController::class, 'show'])
                ->whereNumber('draft')
                ->name('manual_journals.show');

            Route::put('manual-journals/{draft}', [ManualJournalController::class, 'update'])
                ->whereNumber('draft')
                ->middleware('can:estate.accounting_posting.create')
                ->name('manual_journals.update');

            Route::post('manual-journals/{draft}/post', [ManualJournalController::class, 'post'])
                ->whereNumber('draft')
                ->middleware('can:estate.accounting_posting.create')
                ->name('manual_journals.post');

            /*
             * CHANGING THE CHART IS `configure` (12 §2, Wave 1). Adding an
             * account changes what every future entry may post against, and
             * archiving one closes it to every future entry; neither is data
             * entry, so the Admin Assistant's Entry cell does not reach them.
             * There is no delete route and never will be — an account with
             * history is archived, and the database refuses the alternative.
             */
            Route::post('chart-of-accounts', [AccountingController::class, 'addAccount'])
                ->middleware('can:estate.accounting_posting.configure')
                ->name('chart.add');

            Route::post('chart-of-accounts/{account}/archive', [AccountingController::class, 'archiveAccount'])
                ->whereNumber('account')
                ->middleware('can:estate.accounting_posting.configure')
                ->name('chart.archive');

            Route::get('vendors', [PayablesController::class, 'vendors'])->name('vendors');

            // Adding a supplier and recording a bill are `create`: they bring a
            // row into existence and post nothing. Approval posts, and is `approve`.
            Route::post('vendors', [PayablesController::class, 'addVendor'])
                ->middleware('can:estate.accounting_posting.create')
                ->name('vendor.add');

            Route::post('bills', [PayablesController::class, 'recordBill'])
                ->middleware('can:estate.accounting_posting.create')
                ->name('bill.record');

            Route::get('vendors/{vendor}', [PayablesController::class, 'vendor'])
                ->whereNumber('vendor')
                ->name('vendor');

            // Editing changes who the estate may pay — `update`, not `create`
            // (12 §2, Wave 2). Deactivating is an edit; nothing deletes.
            Route::post('vendors/{vendor}', [PayablesController::class, 'editVendor'])
                ->whereNumber('vendor')
                ->middleware('can:estate.accounting_posting.update')
                ->name('vendor.edit');

            Route::get('bills', [PayablesController::class, 'bills'])->name('bills');

            Route::get('reconciliation', [PayablesController::class, 'reconciliation'])->name('reconciliation');

            Route::post('bills/{bill}/approve', [PayablesController::class, 'approveBill'])
                ->whereNumber('bill')
                ->middleware('can:estate.accounting_posting.approve_bills')
                ->name('bill.approve');

            Route::post('bills/{bill}/pay', [PayablesController::class, 'payBill'])
                ->whereNumber('bill')
                ->middleware('can:estate.accounting_posting.create')
                ->name('bill.pay');

            // Voiding a draft decides the estate does not owe it — the mirror of
            // approving, so the same approval, `approve_bills` (bug hunt B036;
            // M11 B3). Nothing posts, and it is not the reconciliation sign-off.
            Route::post('bills/{bill}/void', [PayablesController::class, 'voidBill'])
                ->whereNumber('bill')
                ->middleware('can:estate.accounting_posting.approve_bills')
                ->name('bill.void');

            Route::post('reconciliation/lines/{line}/match', [PayablesController::class, 'matchLine'])
                ->whereNumber('line')
                ->middleware('can:estate.accounting_posting.create')
                ->name('reconciliation.match');

            Route::post('reconciliation/lines/{line}/unmatch', [PayablesController::class, 'unmatchLine'])
                ->whereNumber('line')
                ->middleware('can:estate.accounting_posting.create')
                ->name('reconciliation.unmatch');

            Route::post('reconciliation/{reconciliation}/complete', [PayablesController::class, 'completeReconciliation'])
                ->whereNumber('reconciliation')
                ->middleware('can:estate.accounting_posting.complete_reconciliations')
                ->name('reconciliation.complete');
        });

    /*
     * Payroll & HR — boards 13, 14, 15, 16 and 37.
     *
     * THE PROPERTY MANAGER IS ON THIS PAYROLL AND CANNOT OPEN IT. Patricia
     * Morgan is board 15's first line and board 37's first row, and D-010 locks
     * her role out of `payroll` entirely. Being ON a payroll is not permission
     * to SEE one — least of all three colleagues' gross pay, their bank details
     * and their NIS numbers.
     *
     * CALCULATING IS `update`; APPROVING IS `approve`, AND THE SPLIT IS THE
     * WHOLE CONTROL. Board 15's banner says it in the estate's own words:
     * "Prepared by Tracey Reid — awaiting your approval. As a second approver,
     * this run cannot be disbursed until you review and approve it." The
     * permission is only half of that. The other half is that the preparer and
     * the approver must be two different people, which no middleware can
     * express and `Payroll::approvalRefusal()` therefore checks itself.
     *
     * FILING A RETURN IS AN APPROVAL TOO, AND SINCE M11 B3 ITS OWN ONE. It moves
     * money to the revenue authority and clears a liability, so it is not data
     * entry. But it is not the same power as releasing a pay run: a run's figures
     * are somebody's judgement, checked by a second person; a return remits
     * exactly what approved runs withheld. `approve_runs` and `file_returns` are
     * two permissions (D-150), so the officer who files TAJ returns every month
     * need not be one who can release pay.
     */
    // A plan that leaves Payroll & HR out answers every address here with a page that says so (PlanGate).
    Route::middleware([EnsureEstatePlanIncludes::class.':estate_payroll', 'can:estate.payroll.view'])
        ->prefix('payroll')
        ->name('estate.payroll.')
        ->group(function (): void {
            Route::get('/', [PayrollController::class, 'runs'])->name('runs');

            // Starting the next run on the calendar brings a draft into
            // existence and posts nothing: `create`.
            Route::post('runs', [PayrollController::class, 'startRun'])
                ->middleware('can:estate.payroll.create')
                ->name('run.start');

            Route::get('employees', [PayrollController::class, 'employees'])->name('employees');

            // Behind the consent checkbox (12 §1): a bank account number and an
            // NIS number are personal data the estate will hold for seven years.
            Route::post('employees', [PayrollController::class, 'addEmployee'])
                ->middleware('can:estate.payroll.create')
                ->name('employee.add');

            Route::get('filings', [PayrollController::class, 'filings'])->name('filings');

            // Board 16's compliance calendar (12 §2, Wave 4). A read.
            Route::get('filings/calendar', [PayrollController::class, 'calendar'])->name('filings.calendar');

            /*
             * Bound on the period slug, and registered AFTER the two literals
             * above so "employees" and "filings" cannot be swallowed as run
             * periods. The pattern excludes a leading digit for the same reason
             * it is belt and braces: a slug is "aug-2026", never a number.
             */
            Route::get('runs/{slug}', [PayrollController::class, 'show'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->name('run');

            Route::get('runs/{slug}/exceptions', [PayrollController::class, 'exceptions'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->name('run.exceptions');

            /*
             * The two files a run leaves in (12 §1), behind the format adapter.
             * `export` because a payroll file carries what every member of
             * staff is paid — and only an APPROVED run leaves, because a file
             * built from a draft is an instruction to pay figures nobody has
             * agreed.
             */
            Route::get('runs/{slug}/export', [PayrollController::class, 'exportRun'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->middleware('can:estate.payroll.export')
                ->name('run.export');

            Route::post('runs/{slug}/calculate', [PayrollController::class, 'calculate'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->middleware('can:estate.payroll.update')
                ->name('run.calculate');

            Route::post('runs/{slug}/changes', [PayrollController::class, 'requestChanges'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->middleware('can:estate.payroll.update')
                ->name('run.changes');

            // The irreversible one: money leaves the bank.
            Route::post('runs/{slug}/approve', [PayrollController::class, 'approve'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->middleware('can:estate.payroll.approve_runs')
                ->name('run.approve');

            Route::post('exceptions/{exception}', [PayrollController::class, 'resolveException'])
                ->whereNumber('exception')
                ->middleware('can:estate.payroll.update')
                ->name('exception.resolve');

            Route::post('filings/{filing}/file', [PayrollController::class, 'file'])
                ->whereNumber('filing')
                ->middleware('can:estate.payroll.file_returns')
                ->name('filing.file');
        });

    /*
     * Facilities — boards 17, 18, 19 and 20.
     *
     * READING IS `view`; triaging is not. Assigning a vendor, changing a
     * priority, resolving a ticket and deciding a booking all change what
     * somebody is asked to do outside this screen, so they need `update`;
     * raising a work order and blocking a slot bring something into existence,
     * so they need `create`.
     *
     * RECORDING A BOOKING FEE CARRIES A SECOND GATE, AND IT IS NOT A FACILITIES
     * ONE. It posts a charge against a unit — Dr 1200, Cr 4100 — which adds to
     * what a household owes, and that is `estate.dues_ledger.create` wherever it
     * happens. Board 19 names "record a fee" among the Property Manager's
     * actions and D-010 locks that role out of Dues & ledger entirely; the
     * platform invariant outranks the board, so the control is drawn inert for
     * them with the reason on it rather than quietly working.
     */
    Route::middleware('can:estate.facilities.view')
        ->prefix('facilities')
        ->name('estate.facilities.')
        ->group(function (): void {
            Route::get('maintenance', [FacilitiesController::class, 'maintenance'])->name('maintenance');

            // Raising a work order brings a job into existence and starts its
            // clock: `create` (12 §2, Wave 2).
            Route::post('maintenance', [FacilitiesController::class, 'raiseWorkOrder'])
                ->middleware('can:estate.facilities.create')
                ->name('ticket.raise');

            // The estate's service targets, hours per priority (M98): `configure`, as an estate's other settings are.
            // A literal segment, so it never reads as a ticket number (`{ticket}` below is numbers only).
            Route::post('maintenance/targets', [FacilitiesController::class, 'saveTargets'])
                ->middleware('can:estate.facilities.configure')
                ->name('maintenance.targets');

            // Bound on the ticket NUMBER, which is what board 18's own URL uses
            // and what a bill already stores against the job.
            Route::get('maintenance/{ticket}', [FacilitiesController::class, 'ticket'])
                ->whereNumber('ticket')
                ->name('ticket');

            // A photo the resident attached from the Resident App: private, read like the ticket page (7 Oct).
            Route::get('maintenance/{ticket}/photos/{photo}', [FacilitiesController::class, 'ticketPhoto'])
                ->whereNumber('ticket')
                ->whereNumber('photo')
                ->name('ticket.photo');

            Route::post('maintenance/{ticket}/assign', [FacilitiesController::class, 'assignVendor'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.assign');

            Route::post('maintenance/{ticket}/priority', [FacilitiesController::class, 'changePriority'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.priority');

            // The spine's second and fourth stages, each stamped from the ticket (FAC-10).
            Route::post('maintenance/{ticket}/acknowledge', [FacilitiesController::class, 'acknowledgeTicket'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.acknowledge');

            Route::post('maintenance/{ticket}/start', [FacilitiesController::class, 'startTicket'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.start');

            Route::post('maintenance/{ticket}/resolve', [FacilitiesController::class, 'resolveTicket'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.resolve');

            Route::post('maintenance/{ticket}/reopen', [FacilitiesController::class, 'reopenTicket'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.reopen');

            // "Message resident" (M53 §4.10): a line on the ticket's thread the resident reads in the app.
            Route::post('maintenance/{ticket}/message', [FacilitiesController::class, 'messageResident'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.message');

            Route::get('amenities/bookings', [FacilitiesController::class, 'bookings'])->name('bookings');

            // The parent address goes to its first tab rather than a raw 405 (FAC-17).
            Route::get('amenities', [FacilitiesController::class, 'amenitiesIndex'])->name('amenities.index');

            Route::get('amenities/settings', [FacilitiesController::class, 'amenitySettings'])->name('amenities');

            // The rate card (12 §2, Wave 2): adding brings an amenity into
            // existence, `create`; editing changes what the NEXT booking copies
            // and never a confirmed one, `update`. Retiring is an edit.
            Route::post('amenities', [FacilitiesController::class, 'addAmenity'])
                ->middleware('can:estate.facilities.create')
                ->name('amenity.add');

            Route::post('amenities/{amenity}', [FacilitiesController::class, 'editAmenity'])
                ->whereNumber('amenity')
                ->middleware('can:estate.facilities.update')
                ->name('amenity.edit');

            Route::post('amenities/bookings/{booking}/approve', [FacilitiesController::class, 'approveBooking'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.update')
                ->name('booking.approve');

            Route::post('amenities/bookings/{booking}/decline', [FacilitiesController::class, 'declineBooking'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.update')
                ->name('booking.decline');

            Route::post('amenities/{amenity}/block', [FacilitiesController::class, 'blockSlot'])
                ->whereNumber('amenity')
                ->middleware('can:estate.facilities.create')
                ->name('amenity.block');

            Route::post('amenities/bookings/{booking}/fee', [FacilitiesController::class, 'recordBookingFee'])
                ->whereNumber('booking')
                ->middleware(['can:estate.facilities.update', 'can:estate.dues_ledger.create'])
                ->name('booking.fee');

            /*
             * THE BOOKING DETAIL AND THE DEPOSIT DOOR (D-086) — the one screen in
             * this module no approved board draws, added on the client's ruling:
             * "A service that moves money with no route is a worse gap than the
             * caution it replaced."
             *
             * Two gates, and the higher one keeps money. Taking a deposit and
             * refunding it are `update` — the estate's books catching up with
             * cash that has already moved. Forfeiting keeps a resident's money as
             * income, so it is `approve`, and it carries a required reason.
             * Deliberately NOT `accounting_posting`: the ruling made this the
             * Property Manager's screen, and D-010 locks that role out of
             * Accounting — a door carrying both would be one its own persona
             * could never open. `EstateFacilitiesAmenitiesTest` pins all three.
             */
            Route::get('amenities/bookings/{booking}', [FacilitiesController::class, 'booking'])
                ->whereNumber('booking')
                ->name('booking');

            Route::post('amenities/bookings/{booking}/deposit/hold', [FacilitiesController::class, 'holdDeposit'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.update')
                ->name('booking.deposit.hold');

            Route::post('amenities/bookings/{booking}/deposit/refund', [FacilitiesController::class, 'refundDeposit'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.update')
                ->name('booking.deposit.refund');

            Route::post('amenities/bookings/{booking}/deposit/forfeit', [FacilitiesController::class, 'forfeitDeposit'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.approve')
                ->name('booking.deposit.forfeit');
        });

    /*
     * Governance — boards 9, 10, 11, 12 and 36.
     *
     * Reading is `view`. Running the election — opening nominations, closing
     * them, opening and extending the poll, vetting a candidate — is `update`,
     * which the Secretary holds.
     *
     * CERTIFYING AND PUBLISHING ARE APPROVALS, AND THE SECRETARY HOLDS NEITHER.
     * The estate matrix gives Governance `Full · Approver` to the President
     * and Vice President and plain `Full` to the Secretary, so the officer who
     * RUNS an election is deliberately not the officer who declares the result
     * final. Both acts are irreversible — a certified ballot cannot be reopened,
     * and a published meeting has already told 450 households a date — which is
     * exactly what D-013 separated `approve` from `update` for.
     *
     * TWO APPROVALS, NOT ONE (M11 B3, D-150): `certify_elections` decides who
     * governs the estate, `publish_meetings` when its members meet. A returning
     * officer who certifies has no business calling meetings, and an officer
     * who sends meeting notices has none declaring an election's result.
     *
     * THERE IS NO ROUTE THAT CASTS A VOTE. Voting is a resident act in the
     * resident app; this console runs the election and never marks a paper.
     */
    Route::middleware('can:estate.governance.view')
        ->prefix('governance')
        ->name('estate.governance.')
        ->group(function (): void {
            /*
             * Notices — board 32.
             *
             * POSTING IS `create`, NOT `approve`. Publishing a meeting commits
             * every household to a date they will arrange a Saturday around;
             * a notice tells them something and asks nothing of them. Gating a
             * gate closure behind the President would mean the Property Manager
             * who closed the gate could not say so.
             */
            Route::get('notices', [NoticesController::class, 'index'])->name('notices');

            Route::post('notices', [NoticesController::class, 'store'])
                ->middleware('can:estate.governance.create')
                ->name('notice.store');

            // The parent address goes to the election the Elections tab opens, rather than a raw 405 (FAC-17).
            Route::get('elections', [GovernanceController::class, 'electionsIndex'])->name('elections.index');

            Route::get('elections/{year}', [GovernanceController::class, 'controlRoom'])
                ->whereNumber('year')
                ->name('election');

            Route::get('elections/{year}/nominations', [GovernanceController::class, 'nominations'])
                ->whereNumber('year')
                ->name('nominations');

            // One nomination and the record behind its decision (12 §2, Wave 4).
            Route::get('nominations/{nomination}', [GovernanceController::class, 'nomination'])
                ->whereNumber('nomination')
                ->name('nomination');

            Route::get('elections/{year}/results', [GovernanceController::class, 'results'])
                ->whereNumber('year')
                ->name('results');

            /*
             * CONFIGURING AN ELECTION — M10 §7.
             *
             * `ballot_positions.name` has always been free text: nothing here
             * ever hardcoded "Chairman". What was missing was any route to write
             * one, so the only positions an estate could hold an election for
             * were a seeder's. An estate whose committee is not the seeded one
             * would have run an election for somebody else's offices.
             *
             * Creating an election and adding a position are `create`; renaming,
             * reseating, reordering and removing are `update`. That is the same
             * split the rest of this module uses, and it keeps a role that may
             * tidy an existing draft apart from one that may start an election.
             *
             * Every one of them refuses a ballot past DRAFT in the service, not
             * here: once nominations open somebody has stood for a named seat,
             * and the refusal has to hold whichever door the write arrives at.
             *
             * ADDRESSED TO THE BALLOT, NOT THE YEAR (B043). These three were
             * `elections/{year}/…`, which resolved to the year's oldest ballot
             * while board 9 configured its least advanced — so a draft in a year
             * with an older paper had every save land on the other one. The
             * screen now posts to the ballot it is showing.
             */
            Route::post('elections', [GovernanceController::class, 'storeElection'])
                ->middleware('can:estate.governance.create')
                ->name('election.store');

            Route::post('ballots/{ballot}/positions', [GovernanceController::class, 'storePosition'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.create')
                ->name('election.position.store');

            Route::post('ballots/{ballot}/rename', [GovernanceController::class, 'renameElection'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('election.rename');

            Route::post('positions/{position}', [GovernanceController::class, 'updatePosition'])
                ->whereNumber('position')
                ->middleware('can:estate.governance.update')
                ->name('election.position.update');

            Route::post('positions/{position}/remove', [GovernanceController::class, 'removePosition'])
                ->whereNumber('position')
                ->middleware('can:estate.governance.update')
                ->name('election.position.remove');

            Route::post('ballots/{ballot}/positions/order', [GovernanceController::class, 'reorderPositions'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('election.position.order');

            /*
             * Ahead of `meetings/{meeting}`: registering the literal first means
             * the id route can never swallow "new", and the id is constrained
             * to a number besides.
             */
            Route::get('meetings/new', [GovernanceController::class, 'newMeeting'])->name('meeting.new');

            Route::get('meetings', [GovernanceController::class, 'meetings'])->name('meetings');

            // One meeting, whole (12 §2, Wave 2 item 21). A read.
            Route::get('meetings/{meeting}', [GovernanceController::class, 'meeting'])
                ->whereNumber('meeting')
                ->name('meeting');

            /*
             * The two steps of the lifecycle that had a service method and no
             * door (B042): a draft could never be opened for nominations, and a
             * ballot whose nominations were closed could never reach vetting —
             * and so never a poll. `update`, as every other step here is.
             */
            Route::post('ballots/{ballot}/open-nominations', [GovernanceController::class, 'openNominations'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.open_nominations');

            Route::post('ballots/{ballot}/vetting', [GovernanceController::class, 'startVetting'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.vetting');

            Route::post('ballots/{ballot}/close-nominations', [GovernanceController::class, 'closeNominations'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.close_nominations');

            Route::post('ballots/{ballot}/open', [GovernanceController::class, 'openVoting'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.open');

            Route::post('ballots/{ballot}/close', [GovernanceController::class, 'closeVoting'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.close');

            Route::post('ballots/{ballot}/extend', [GovernanceController::class, 'extendVoting'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.extend');

            // The irreversible one.
            Route::post('ballots/{ballot}/certify', [GovernanceController::class, 'certify'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.certify_elections')
                ->name('ballot.certify');

            /*
             * AN ELECTION'S LIFE (M56 §3). Deleting a draft is `delete`; extending or reopening nominations, archiving
             * and recording a challenge are `update`; cancelling an election and reopening a closed poll are the grave
             * ones, and take the certifying officers' approval.
             */
            Route::post('ballots/{ballot}/delete', [GovernanceController::class, 'deleteElection'])
                ->whereNumber('ballot')->middleware('can:estate.governance.delete')->name('ballot.delete');

            Route::post('ballots/{ballot}/extend-nominations', [GovernanceController::class, 'extendNominations'])
                ->whereNumber('ballot')->middleware('can:estate.governance.update')->name('ballot.extend_nominations');

            Route::post('ballots/{ballot}/reopen-nominations', [GovernanceController::class, 'reopenNominations'])
                ->whereNumber('ballot')->middleware('can:estate.governance.update')->name('ballot.reopen_nominations');

            Route::post('ballots/{ballot}/cancel', [GovernanceController::class, 'cancelElection'])
                ->whereNumber('ballot')->middleware('can:estate.governance.certify_elections')->name('ballot.cancel');

            Route::post('ballots/{ballot}/reopen-voting', [GovernanceController::class, 'reopenVoting'])
                ->whereNumber('ballot')->middleware('can:estate.governance.certify_elections')->name('ballot.reopen_voting');

            Route::post('ballots/{ballot}/archive', [GovernanceController::class, 'archiveElection'])
                ->whereNumber('ballot')->middleware('can:estate.governance.update')->name('ballot.archive');

            Route::post('ballots/{ballot}/challenge', [GovernanceController::class, 'challengeElection'])
                ->whereNumber('ballot')->middleware('can:estate.governance.update')->name('ballot.challenge');

            /*
             * ADD A CANDIDATE (M56 §3.3). `create`, as adding a position is: it puts a name forward and decides nothing.
             * The search finds residents by name or lot for the form's three pickers.
             */
            Route::post('ballots/{ballot}/nominations', [GovernanceController::class, 'storeNomination'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.create')
                ->name('nomination.store');

            Route::get('residents/search', [GovernanceController::class, 'residentSearch'])
                ->middleware('can:estate.governance.create')
                ->name('residents.search');

            Route::post('nominations/{nomination}/accept', [GovernanceController::class, 'acceptNomination'])
                ->whereNumber('nomination')
                ->middleware('can:estate.governance.update')
                ->name('nomination.accept');

            Route::post('nominations/{nomination}/reject', [GovernanceController::class, 'rejectNomination'])
                ->whereNumber('nomination')
                ->middleware('can:estate.governance.update')
                ->name('nomination.reject');

            Route::post('nominations/{nomination}/query', [GovernanceController::class, 'queryNomination'])
                ->whereNumber('nomination')
                ->middleware('can:estate.governance.update')
                ->name('nomination.query');

            Route::post('meetings', [GovernanceController::class, 'storeMeeting'])
                ->middleware('can:estate.governance.create')
                ->name('meeting.store');

            // Refused inside the statutory notice period. See Governance.
            Route::post('meetings/{meeting}/publish', [GovernanceController::class, 'publishMeeting'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.publish_meetings')
                ->name('meeting.publish');

            /*
             * THE MINUTES (M21 §1.4) — written by whoever may update Governance, once the meeting has been held;
             * published by whoever may publish a meeting, because it puts them in front of every household.
             * `minutes/record`, not `minutes`: POST governance/meetings/{meeting}/minutes issues the minutes as a PDF.
             */
            Route::post('meetings/{meeting}/minutes/record', [GovernanceController::class, 'saveMinutes'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.update')
                ->name('meeting.minutes');

            Route::post('meetings/{meeting}/minutes/publish', [GovernanceController::class, 'publishMinutes'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.publish_meetings')
                ->name('meeting.minutes.publish');

            /*
             * A MEETING'S LIFE (M56 §2). Each act refuses whatever the meeting's stage does not allow (`Meeting::stage()`),
             * whoever presses it. Editing a draft and keeping the register are `update`; deleting a draft is `delete`; a new
             * date, a postponement and a cancellation tell the households, so they are the act of publishing a meeting.
             */
            Route::post('meetings/{meeting}/edit', [GovernanceController::class, 'updateMeeting'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.update')
                ->name('meeting.update');

            Route::post('meetings/{meeting}/move-to-earliest', [GovernanceController::class, 'moveMeetingToEarliest'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.update')
                ->name('meeting.earliest');

            Route::post('meetings/{meeting}/delete', [GovernanceController::class, 'deleteMeeting'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.delete')
                ->name('meeting.delete');

            Route::post('meetings/{meeting}/date', [GovernanceController::class, 'rescheduleMeeting'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.publish_meetings')
                ->name('meeting.reschedule');

            Route::post('meetings/{meeting}/postpone', [GovernanceController::class, 'postponeMeeting'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.publish_meetings')
                ->name('meeting.postpone');

            Route::post('meetings/{meeting}/cancel', [GovernanceController::class, 'cancelMeeting'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.publish_meetings')
                ->name('meeting.cancel');

            Route::post('meetings/{meeting}/archive', [GovernanceController::class, 'archiveMeeting'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.update')
                ->name('meeting.archive');

            Route::post('meetings/{meeting}/attendance', [GovernanceController::class, 'recordAttendance'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.update')
                ->name('meeting.attendance');

            Route::post('meetings/{meeting}/attendance/{attendance}/remove', [GovernanceController::class, 'removeAttendance'])
                ->whereNumber(['meeting', 'attendance'])
                ->middleware('can:estate.governance.update')
                ->name('meeting.attendance.remove');
        });

    /*
     * Reports — board 29.
     *
     * ONE ROUTE, `view`, AND NOTHING TO POST TO. The board draws a catalogue of
     * seven reports and seven "Generate" controls, and not one of them generates
     * yet — three of the seven have no data on this platform to generate from
     * (there is no budget, no estate-side incident record and no document
     * store). The controller says which is which, on each card, in the estate's
     * own terms.
     *
     * SO THERE IS DELIBERATELY NO `POST` HERE. A route that answered a Generate
     * with nothing would be worse than a control that says what it is waiting
     * for, and registering one now would fix the verb before the first report
     * exists to argue about it. When one lands it is `estate.reports.export` —
     * the payload already carries that gate for the page to draw with.
     *
     * THE SIDEBAR ITEM FLIPS IN THIS SAME CHANGE. `EstateNavigation` draws a
     * module with no screens as greyed and inert, and its own docblock requires
     * the href to be set in the commit that lands the module's first screen;
     * leaving it null here would tell a President the module is missing while
     * its page sits one click away.
     */
    Route::get('reports', [ReportsController::class, 'index'])
        ->middleware('can:estate.reports.view')
        ->name('estate.reports');

    /*
     * Running one, and taking it away as a file — board 30 (12 §1).
     *
     * BOTH ARE `export`, and the catalogue's own comment says why: a report
     * brings nothing into existence, it takes what the estate already holds and
     * puts it in somebody's hands. The matrix withholds export from Entry, so
     * an Admin Assistant who could be given rows to type could never be given
     * the whole estate's books — `create` would have granted it to them.
     *
     * AND THE MODULE THE REPORT READS, which differs by `{key}` and so is the
     * controller's to ask (B137): `Reports::refusalFor()` — the ledger's and
     * Accounting's estate-position rule for the two money reports, Facilities,
     * Security and Governance for the rest. `export` alone let a role holding
     * Reports and nothing else read every household's balance.
     */
    Route::get('reports/{key}', [ReportsController::class, 'show'])
        ->where('key', '[a-z_]+')
        ->middleware('can:estate.reports.export')
        ->name('estate.report');

    Route::get('reports/{key}/export', [ReportsController::class, 'export'])
        ->where('key', '[a-z_]+')
        ->middleware('can:estate.reports.export')
        ->name('estate.report.export');

    /*
     * Settings — boards 21, 22, 23 and 24.
     *
     * READING IS `view`, and the matrix gives that to three shared roles only:
     * Full to the Community Super Admin, View to the President and the Vice
     * President, and nothing to the Secretary, Property Manager, Treasurer,
     * Admin Assistant or the estate's Head of Security. Those five get 403 on
     * every route below, which is what the Settings row of board 24 means when
     * it draws them all as em dashes.
     *
     * SAVING THE PROFILE IS `update`; CHANGING A FEATURE IS `configure`. The
     * second is deliberately the stronger of the two: an estate's contact email
     * is a line on a notice, while switching the accounting module off changes
     * what several hundred households can do tomorrow. `configure` is one of
     * the verbs `AccessLevel::Full` grants and `Entry` does not, which is
     * exactly the distinction "data entry, no approval" is meant to draw.
     *
     * `update` IS TWO PERMISSIONS HERE SINCE M11 B3 (D-150): `edit_profile` for
     * the profile, the logo and the notification defaults, and `manage_people`
     * for changing, suspending and removing the people on the committee. A cell
     * that grants `update` grants both.
     *
     * FIVE ROUTES HERE WRITE A ROLE, AND ALL FIVE ARE BEHIND
     * `estate.settings.approve` (M10 §4). Until M10 there were none: changing
     * what a role may do is an `approve`-level act (D-013), and the seeded
     * matrix gave that permission to nobody, so there was nothing to post to.
     * The client asked for the Community Super Admin to create, copy, rename,
     * re-grant and delete roles, and the seeder now gives exactly that one
     * cell the Approver tag — so these routes admit exactly that one role, and
     * the other seven shared estate roles get 403 from the gate before a
     * controller runs. `EstateSettingsTest` walks all eight to prove who holds
     * it; `EstateRolesEditorTest` proves the 403s route by route.
     *
     * THE GATE IS ONLY THE FIRST LOCK. Behind it, `RoleEditor` refuses a role
     * that is not this estate's own (the eight shared roles are Gemini's to
     * set, and another estate's role is refused in the same words as one that
     * does not exist), any permission the actor does not hold themselves, any
     * module of the other console, and Ruling 1 on a copy of the Property
     * Manager. The ids in these paths are resolved by `RoleEditor::find()`
     * rather than by route-model binding, which would 404 one of those cases
     * and resolve the other. `gate:isolation` STEP 10 proves the tenant fence
     * against real rows.
     *
     * REMOVING A PERSON IS `manage_people`, like managing one: it withdraws
     * access, it never changes what a role may do, and the account and the
     * assignment rows stay.
     */
    /*
     * THE ESTATE'S OWN SECURITY SCREEN — M10 §2.
     *
     * Panic alerts, incidents, gate events and guard presence are central
     * records, and every surface that drew them was a Gemini console screen — so
     * the community whose gate it is could not see its own security at all. This
     * is the estate's side of the same facts, scoped to one tenant by the service.
     *
     * A READ AND ONLY A READ, on `view`. Acknowledging an alert, closing an
     * incident and admitting somebody at a gate each happen where they already
     * happen: the dispatcher's console and the Guard App. A second door onto
     * those records would be a second place for the rules to drift.
     */
    Route::get('security', [SecurityController::class, 'index'])
        ->middleware('can:estate.security.view')
        ->name('estate.security');

    /*
     * The estate's panic pop-up asking whether an alert is still outstanding (B039, B103) —
     * every fifteen seconds while one is, or while the live channel is down. The same read,
     * on the same gate; throttled for a poll per open tab with room for several tabs and
     * none for a loop, as the Gemini console's own is.
     */
    Route::get('security/panic-watch', [SecurityController::class, 'panicWatch'])
        ->middleware(['can:estate.security.view', 'throttle:60,1'])
        ->name('estate.security.panic-watch');

    /*
     * The Security map's one feed (guard live tracking, 12 Oct 2026; GUARD_TRACKING.md 5.4): the guards on duty here,
     * the open panics, who is going and who is nearest, as `EstateSecurityMap` copies them, field by field. The same
     * read and gate as the page; throttled for a refresh per live event from a few open tabs.
     */
    Route::get('security/map', [SecurityController::class, 'map'])
        ->middleware(['can:estate.security.view', 'throttle:120,1'])
        ->name('estate.security.map');

    // One alert, end to end — still a read (26 Sep, E4).
    Route::get('security/alerts/{alert}', [SecurityController::class, 'alert'])
        ->whereNumber('alert')
        ->middleware('can:estate.security.view')
        ->name('estate.security.alert');

    // One incident, read-only, the same reason the alert got one (Batch 1 item 10, 12 Oct).
    Route::get('security/incidents/{incident}', [SecurityController::class, 'incident'])
        ->whereNumber('incident')
        ->middleware('can:estate.security.view')
        ->name('estate.security.incident');

    // `/audit` used to land on the dashboard: the audit page while its switch is on, absent while it is off (M84 §3.2).
    Route::get('audit', [AuditLogController::class, 'redirect'])
        ->middleware('can:estate.settings.view')
        ->name('estate.audit');

    Route::middleware('can:estate.settings.view')
        ->prefix('settings')
        ->name('estate.settings.')
        ->group(function (): void {
            Route::get('profile', [SettingsController::class, 'profile'])->name('profile');

            // The estate's audit log, read only, behind its switch (M84 §3.2, §0.1): GET and nothing else.
            Route::get('audit', [AuditLogController::class, 'index'])->name('audit');
            Route::get('audit/export', [AuditLogController::class, 'export'])->name('audit.export');

            Route::get('users', [SettingsController::class, 'users'])->name('users');

            /*
             * Managing a user is `update` (12 §2): it changes what somebody may
             * do to this estate's money and records, or withdraws their access
             * entirely. Inviting one is `create`, below — a different act.
             *
             * AND SINCE M11 B3 IT IS ITS OWN PERMISSION, `manage_people` (D-150).
             * Settings · update also carried the profile, the logo and the
             * notification defaults, so a role given it to keep the letterhead
             * current could also seat somebody as Treasurer. The two halves are
             * `manage_people` and `edit_profile`; a cell at Entry or Full holds
             * both, so nobody who had update has lost anything.
             */
            Route::post('users/{assignment}', [SettingsController::class, 'manageUser'])
                ->whereNumber('assignment')
                ->middleware('can:estate.settings.manage_people')
                ->name('user.manage');

            // Taking somebody off the committee: deactivated and suspended,
            // never deleted (M10 §4).
            Route::post('users/{assignment}/remove', [SettingsController::class, 'removeUser'])
                ->whereNumber('assignment')
                ->middleware('can:estate.settings.manage_people')
                ->name('user.remove');

            // And bringing them back: the removal's inverse, behind its gate,
            // `manage_people` (M11 B3), which seats a person and is not the
            // power to keep the letterhead.
            Route::post('users/{assignment}/reinstate', [SettingsController::class, 'reinstateUser'])
                ->whereNumber('assignment')
                ->middleware('can:estate.settings.manage_people')
                ->name('user.reinstate');

            /*
             * INVITING IS `create` (12 §2, Wave 1): it brings an account into
             * existence. The matrix gives Settings create to the Community
             * Super Admin alone; the two officers read this screen and may not
             * issue a credential from it. Resend and revoke are the same act's
             * afterlife and carry the same gate.
             */
            Route::post('users/invitations', [SettingsController::class, 'invite'])
                ->middleware('can:estate.settings.create')
                ->name('users.invite');

            // Six a minute: the service's own minute between sends is the rule, and this stops a script (DESIGN §11).
            Route::post('users/invitations/{invitation}/resend', [SettingsController::class, 'resendInvitation'])
                ->whereNumber('invitation')
                ->middleware(['can:estate.settings.create', 'throttle:6,1'])
                ->name('users.invitation.resend');

            Route::post('users/invitations/{invitation}/revoke', [SettingsController::class, 'revokeInvitation'])
                ->whereNumber('invitation')
                ->middleware('can:estate.settings.create')
                ->name('users.invitation.revoke');

            Route::get('features', [SettingsController::class, 'features'])->name('features');

            /*
             * "Trust the main resident's invites" (household invites, stage 2). Configure, as every feature
             * switch; the controller also asks `estate.residents.approve`, because turning it on hands a
             * claim's approval to the primary resident. A fixed hyphenated path `features/{feature}` cannot
             * capture.
             */
            Route::post('household-invites/trust', ResidentInviteTrustController::class)
                ->middleware('can:estate.settings.configure')
                ->name('invite_trust.update');

            Route::get('roles', [SettingsController::class, 'roles'])->name('roles');

            /*
             * Board 24's editor (M10 §4) — see the note above the group. Every
             * one behind the power to change roles, read from `RoleEditor` so
             * the gate and the editor can never name two different permissions.
             */
            Route::middleware('can:'.RoleEditor::editPermission(Console::Estate))->group(function (): void {
                Route::post('roles', [SettingsController::class, 'createRole'])->name('roles.create');

                Route::post('roles/{role}/copy', [SettingsController::class, 'copyRole'])
                    ->whereNumber('role')
                    ->name('role.copy');

                Route::post('roles/{role}', [SettingsController::class, 'renameRole'])
                    ->whereNumber('role')
                    ->name('role.rename');

                // One role's permissions as a page of its own (Hassam, 12 Oct: a long table read across is never a modal).
                Route::get('roles/{role}/permissions', [SettingsController::class, 'rolePermissions'])
                    ->whereNumber('role')
                    ->name('role.permissions.edit');

                Route::post('roles/{role}/permissions', [SettingsController::class, 'setRolePermissions'])
                    ->whereNumber('role')
                    ->name('role.permissions');

                Route::post('roles/{role}/delete', [SettingsController::class, 'deleteRole'])
                    ->whereNumber('role')
                    ->name('role.delete');

                // "Reset to GeminiSecure default" on this community's own version of a shared role (M11 B4).
                Route::post('roles/{role}/reset', [SettingsController::class, 'resetRole'])
                    ->whereNumber('role')
                    ->name('role.reset');
            });

            Route::get('notifications', [SettingsController::class, 'notifications'])->name('notifications');

            /*
             * READ-ONLY, BOTH OF THEM, AND FOR DIFFERENT REASONS.
             *
             * Data & privacy states policy the estate has been told rather than
             * policy it sets here — a retention period, who may export a
             * resident list, what Gemini receives under the security grant. None
             * of it has an owner on this platform yet, and inventing an edit
             * path for a rule nobody has been asked to set is worse than a
             * screen that says what the rule is.
             *
             * Billing reads a CENTRAL subscription. It is Gemini's commercial
             * record of this client, and an estate editing its own plan from its
             * own console is not a settings screen, it is a discount button.
             */
            Route::get('privacy', [SettingsController::class, 'privacy'])->name('privacy');

            Route::get('billing', [SettingsController::class, 'billing'])->name('billing');

            /*
             * One of this estate's own invoices, and its PDF (12 §2, Wave 4).
             * A central record read from the estate's hostname, so the
             * controller proves the estate owns it — another client's invoice
             * id is a 404, the same as an id that does not exist.
             */
            Route::get('billing/invoices/{invoice}', [SettingsController::class, 'billingInvoice'])
                ->whereNumber('invoice')
                ->name('billing.invoice');

            Route::get('billing/invoices/{invoice}/pdf', [SettingsController::class, 'billingInvoicePdf'])
                ->whereNumber('invoice')
                ->name('billing.invoice.pdf');

            /*
             * The profile, its logo and the notification defaults: `edit_profile`,
             * the other half of what Settings · update was (M11 B3, D-150). What
             * the estate's paperwork and messages say, and never who may do what.
             */
            Route::post('notifications', [SettingsController::class, 'saveNotifications'])
                ->middleware('can:estate.settings.edit_profile')
                ->name('notifications.save');

            Route::post('profile', [SettingsController::class, 'saveProfile'])
                ->middleware('can:estate.settings.edit_profile')
                ->name('profile.save');

            /*
             * The estate's mark (12 §1 — it is printed on every statement,
             * receipt and certificate). `edit_profile`, the same as the rest of
             * the profile; served from this estate's own private prefix so one
             * estate's logo cannot be fetched from another's URL.
             */
            Route::post('profile/logo', [SettingsController::class, 'uploadLogo'])
                ->middleware('can:estate.settings.edit_profile')
                ->name('profile.logo');

            Route::get('profile/logo', [SettingsController::class, 'logo'])->name('profile.logo.show');

            /*
             * Bound on the catalogue's own feature KEY — "accounting_core",
             * "estate_payroll" — because that is what the central
             * `package_features` row is identified by and what an estate's
             * override stores. Constrained to the shape a key actually takes so
             * a path segment cannot arrive as anything else.
             */
            Route::post('features/{feature}', [SettingsController::class, 'updateFeature'])
                ->where('feature', '[a-z][a-z0-9_]{2,63}')
                ->middleware('can:estate.settings.configure')
                ->name('feature.update');

            /*
             * WHAT DEPENDENTS AND HELPERS MAY DO IN THE RESIDENT APP (the user, 8 Oct 2026) — the estate's own rules, in
             * one place, changed without a release (App\Api\AccessRules). Reading is Settings · view; changing them is
             * `configure`, as the role access matrix is: both decide who may do what.
             */
            Route::get('resident-access', [SettingsController::class, 'residentAccess'])->name('resident_access');

            Route::post('resident-access', [SettingsController::class, 'saveResidentAccess'])
                ->middleware('can:estate.settings.configure')
                ->name('resident_access.save');

            /*
             * HOW OFTEN THIS ESTATE'S ON-DUTY OFFICERS' PHONES SEND, AND ITS NO SIGNAL TOLERANCE (Hassam, 12 Oct 2026).
             * Reading is Settings · view; changing them is `configure`, as resident access is: both decide how people
             * are followed. App\Services\Tracking\SiteTrackingSettings holds the rule and the bounds.
             */
            Route::get('guard-tracking', [SettingsController::class, 'guardTracking'])->name('guard_tracking');

            Route::post('guard-tracking', [SettingsController::class, 'saveGuardTracking'])
                ->middleware('can:estate.settings.configure')
                ->name('guard_tracking.save');
        });

    Route::prefix('records')
        ->name('estate.records.')
        ->whereNumber('id')
        ->group(function () {
            /*
             * THE REGISTER'S OWN GATE, ON THE REGISTER'S OWN ROWS (bug hunt
             * B072). A resident, a household and a lot are what the Residents
             * register is made of, read one at a time, and these three carried
             * no gate at all: invisible to a committee in the seeded roles,
             * every one of which holds `estate.residents.view`, and not
             * harmless for a Gemini account with platform-wide scope, which
             * passes `EnsureEstateAccess` at every estate, holds no estate
             * permission, and read every resident's name, email and phone by
             * walking the ids while the register answered it 403 — nor for a
             * role a community made without Residents access (M10 §4), which
             * the register refuses as well. Refused before the lookup, so an
             * id that exists and one that does not answer alike.
             */
            Route::get('residents/{id}', [RecordController::class, 'resident'])
                ->middleware('can:estate.residents.view')
                ->name('resident');
            Route::get('households/{id}', [RecordController::class, 'household'])
                ->middleware('can:estate.residents.view')
                ->name('household');
            Route::get('units/{id}', [RecordController::class, 'unit'])
                ->middleware('can:estate.residents.view')
                ->name('unit');

            /*
             * THE TWO MONEY RECORDS CARRY THEIR MODULE'S GATE (M10 §5). "A field
             * hidden in the interface but returned by the API is not hidden":
             * a charge is `amount_minor` and a journal is the money itself, and
             * both answered anybody with a session on this estate. The module
             * gate is here; the estate's-position gate is in the controller,
             * because it is two permissions and a sentence, not one.
             *
             * THE LOOKUPS STAY NAIVE, and the isolation gate is untouched by
             * this. Isolation is the connection's, not a WHERE clause's, and a
             * committee member of another estate is refused by
             * `EnsureEstateAccess` with a 404 before any `can:` is read —
             * `gate:isolation` probes these ids expecting exactly that, and its
             * own positive control reads `residents/{id}` as a committee
             * member it has first checked holds the register's gate above.
             */
            Route::get('charges/{id}', [RecordController::class, 'charge'])
                ->middleware('can:estate.dues_ledger.view')
                ->name('charge');

            Route::get('journals/{id}', [RecordController::class, 'journal'])
                ->middleware('can:estate.accounting_posting.view')
                ->name('journal');
        });
};

/**
 * The estate's own sign-in door — board 1.
 *
 * OUTSIDE `$estateRoutes` BECAUSE A GUEST IS EXACTLY WHO NEEDS IT. Every route
 * in that group carries `auth` and `EnsureEstateAccess`; a login screen behind
 * `auth` redirects to itself, which is a loop rather than a page.
 *
 * IT STILL RESOLVES TENANCY, and that is the whole point of a separate estate
 * door. The card names the community — "Phoenix Park Village 1 · Powered by
 * Gemini Security Limited" — and a resident arriving at their own estate's
 * address should not be shown a console they have never heard of. Resolving the
 * tenant here reads the subdomain or the path segment and nothing else: the
 * identity is never taken from a query string or a hidden field, which is the
 * same rule the authenticated routes follow.
 *
 * NAMED `estate.login`, NOT `login`. Two registrations sharing the name would
 * make `route('login')` ambiguous — Laravel keeps one entry per name — and the
 * central door is the one the framework's own `auth` middleware redirects to.
 */
$estateLoginRoute = function (): void {
    Route::get('login', [LoginController::class, 'create'])
        ->middleware('guest')
        ->name('estate.login');

    /*
     * THE FRONT DOOR'S OTHER END (M11 §5.2): the single-use token the central door issued, redeemed ONCE on this
     * estate's own host, which makes its own session. Not `guest`: somebody signed in here as someone else is
     * signed out and replaced, since the token names who is to be signed in. Throttled like sign-in.
     */
    Route::get('front-door/{token}', FrontDoorRedeemController::class)
        ->where('token', FrontDoor::TOKEN_PATTERN)
        ->middleware('throttle:login')
        ->name('estate.front-door');

    /*
     * Forgot password on the estate's own door (12 §2, Wave 1) — the same
     * controller as the central door, resolving the tenant the same way the
     * sign-in card does, so a committee member resets on the card that names
     * their community. Throttled like sign-in; one sentence whatever the
     * address.
     */
    Route::middleware('guest')->group(function (): void {
        Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('estate.password.request');

        Route::post('forgot-password', [PasswordResetController::class, 'send'])
            ->middleware('throttle:login')
            ->name('estate.password.email');

        Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])->name('estate.password.reset');

        Route::post('reset-password', [PasswordResetController::class, 'update'])
            ->middleware('throttle:login')
            ->name('estate.password.update');

        // Accepting an invitation, on the door of the estate it was sent for.
        Route::get('invitations/{token}', [InvitationController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->name('estate.invitation.show');

        Route::post('invitations/{token}', [InvitationController::class, 'accept'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->middleware('throttle:login')
            ->name('estate.invitation.accept');
    });
};

/**
 * Proving the address an alert is sent to, on the estate's own door — M10 §1.4.
 *
 * OUTSIDE `$estateRoutes` FOR THE SAME REASON THE SIGN-IN DOOR IS: this is where
 * `EnsureContactVerified` sends an unverified committee member, so behind that
 * gate it would redirect to itself. It keeps `auth` and `EnsureEstateAccess` —
 * the page is only for somebody signed in to THIS estate — and drops the gate.
 *
 * ON THE ESTATE'S HOSTNAME AND NOT THE CENTRAL ONE because the session cookie is
 * host-only: a committee member sent to the central host to verify would arrive
 * there as a stranger, which is the login loop `RedirectToOwnConsole` records.
 *
 * Named `estate.contact.verify*` so `route('contact.verify')` stays the central
 * page; the estate's pages build their paths from the door they were served on.
 */
$estateContactRoutes = function (): void {
    Route::get('verify-contact', [ContactVerificationController::class, 'show'])->name('estate.contact.verify');

    Route::post('verify-contact/code', [ContactVerificationController::class, 'send'])
        ->middleware('throttle:10,1')
        ->name('estate.contact.verify.send');

    Route::post('verify-contact', [ContactVerificationController::class, 'verify'])
        ->middleware('throttle:20,1')
        ->name('estate.contact.verify.check');

    Route::post('verify-contact/phone', [ContactVerificationController::class, 'phone'])
        ->middleware('throttle:10,1')
        ->name('estate.contact.verify.phone');
};

/*
 * PRODUCTION SHAPE: one hostname per estate.
 *
 * The group carries an explicit domain constraint. Without one these routes
 * match by URI on every host, so a bare "/" here would shadow the central
 * Gemini Console route — Laravel matches the URI first and only then runs the
 * middleware that rejects the host.
 *
 * `EnsureContactVerified` LAST, after `EnsureEstateAccess` (M10 §1.4): an account
 * asking for another estate is answered 404 exactly as before, and only somebody
 * who belongs here is then asked to prove their alert address. Every estate
 * screen is in this group, so none of them is reachable unverified by any URL.
 */
Route::domain('{tenant}.'.config('app.estate_domain'))
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        ForgetTenantRouteParameter::class,
        'auth',
        EnsureEstateAccess::class,
    ])
    ->group($estateContactRoutes);

Route::domain('{tenant}.'.config('app.estate_domain'))
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        ForgetTenantRouteParameter::class,
        'auth',
        EnsureEstateAccess::class,
        EnsureContactVerified::class,
    ])
    ->group($estateRoutes);

Route::domain('{tenant}.'.config('app.estate_domain'))
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        ForgetTenantRouteParameter::class,
    ])
    ->group($estateLoginRoute);

/*
 * LOCAL SHAPE: same host, estate in the path.
 *
 * Registered only in local. In every other environment an estate is reachable
 * by its own hostname and nothing else, so this cannot become a way around the
 * subdomain boundary in production.
 *
 * InitializeTenancyByPath requires {tenant} to be the FIRST route parameter,
 * which is why the prefix carries it directly.
 */
if (app()->isLocal()) {
    Route::prefix('estate/{tenant}')
        ->middleware([
            'web',
            InitializeTenancyByPath::class,
            'auth',
            EnsureEstateAccess::class,
        ])
        ->group($estateContactRoutes);

    Route::prefix('estate/{tenant}')
        ->middleware([
            'web',
            InitializeTenancyByPath::class,
            'auth',
            EnsureEstateAccess::class,
            EnsureContactVerified::class,
        ])
        ->group($estateRoutes);

    Route::prefix('estate/{tenant}')
        ->middleware([
            'web',
            InitializeTenancyByPath::class,
        ])
        ->group($estateLoginRoute);
}
