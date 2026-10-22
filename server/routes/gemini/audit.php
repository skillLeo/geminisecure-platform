<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
| Access and audit log — board screen super-admin-41.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| READ ONLY, permanently. The audit log is append-only, so this module has no
| update or delete route and must never grow one — not even a soft delete.
*/

Route::get('audit', AuditLogController::class)
    ->middleware('can:gemini.access_audit_log.view')
    ->name('gemini.access_audit_log');

// Every email sent and what became of it (M18G §7): the Director and the Operations Manager both read this module.
Route::get('audit/emails', [AuditLogController::class, 'emails'])
    ->middleware('can:gemini.access_audit_log.view')
    ->name('gemini.access_audit_log.emails');

/*
 * The export (12 §1). Its own verb, because taking a copy of who did what is a
 * different act from reading it — and it writes an audit entry of its own, which
 * is the one act on this screen that would otherwise leave no trace.
 */
Route::get('audit/export', [AuditLogController::class, 'export'])
    ->middleware('can:gemini.access_audit_log.export')
    ->name('gemini.access_audit_log.export');
