<?php

declare(strict_types=1);

use App\Http\Controllers\PublicPages\PublicPagesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public pages: no sign-in, ever, and LINKED FROM NOWHERE (M5 §4, M24, D-223)
|--------------------------------------------------------------------------
|
| The client's rule (M24): the website tells nobody what the product is. So the pages that described it are gone,
| and what is left here is only what an app store requires at a public address: the resident privacy policy and
| terms, and the account-deletion page Google Play loads itself. They are reached from the Resident App and the
| store listing, never from the website: no menu, footer, sitemap or other page points at them, and every one
| carries `noindex` (the shared layout, resources/views/public/layout.blade.php). The Guard App's own policy lives
| inside the Guard App and has no address here at all.
|
| The one write — a deletion REQUEST — is throttled per address and deletes nothing by itself.
|
*/

Route::get('legal/privacy', [PublicPagesController::class, 'privacy'])->name('public.privacy');
Route::get('legal/terms', [PublicPagesController::class, 'terms'])->name('public.terms');
Route::get('account/delete', [PublicPagesController::class, 'deleteAccount'])->name('public.delete-account');

/*
 * TAKEN DOWN (M24 §2, §7). They described the product and have been live, so they answer 410 Gone, a clean answer
 * that tells a search engine the page is gone for good, and never a redirect to anything that still describes it.
 */
Route::get('apps', fn () => abort(410))->name('public.apps.gone');
Route::get('support', fn () => abort(410))->name('public.support.gone');

// M18G §5: the invite's "Open the app" link. Signed, read from the link alone: it opens no estate and signs nobody in.
Route::get('open/invite', [PublicPagesController::class, 'openInvite'])->middleware('throttle:30,1')->name('public.open-invite');

Route::post('public-requests', [PublicPagesController::class, 'store'])
    ->middleware('throttle:6,10')
    ->name('public.requests.store');

Route::get('public-requests/{reference}', [PublicPagesController::class, 'received'])
    ->where('reference', 'GS-[A-Z2-9]{6}')
    ->name('public.received');
