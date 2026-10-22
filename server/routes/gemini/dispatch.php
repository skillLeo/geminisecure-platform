<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\DispatchController;
use App\Http\Controllers\Gemini\LiveChatController;
use App\Http\Controllers\Gemini\PanicAlarmController;
use App\Http\Controllers\Gemini\PanicWatchController;
use Illuminate\Support\Facades\Route;

/*
| Dispatch — board screens super-admin-12 to 17.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
*/

Route::middleware('can:gemini.dispatch.view')->group(function () {
    /*
     * The live map.
     *
     * A read, and only a read. Nothing on it is a position: the pins are posts
     * and the posts do not move, so there is no endpoint here that could be
     * asked where a guard is standing.
     */
    Route::get('dispatch/map', [DispatchController::class, 'map'])->name('gemini.dispatch.map');

    Route::get('dispatch/coverage', [DispatchController::class, 'coverage'])
        ->name('gemini.dispatch.coverage');

    /*
     * Guard alertness and patrol monitoring.
     *
     * A read, and only a read. The screen reports what a guard has already
     * proved — a checkpoint they reached, a challenge they answered — and there
     * is no endpoint here that returns, or could return, where anyone is
     * standing.
     */
    Route::get('dispatch/alertness', [DispatchController::class, 'alertness'])
        ->name('gemini.dispatch.alertness');

    /*
     * The requests inbox.
     *
     * Reading the queue is `view`; emptying it is not. See the update group
     * below — the split is the same one the alert screens make, and for the
     * same reason.
     */
    Route::get('dispatch/requests', [DispatchController::class, 'requests'])
        ->name('gemini.dispatch.requests');

    // What became of the requests the inbox no longer holds (12 §2, Wave 4).
    // A read, on the same gate as the queue itself.
    Route::get('dispatch/requests/history', [DispatchController::class, 'requestHistory'])
        ->name('gemini.dispatch.requests.history');

    Route::get('dispatch/alerts', [DispatchController::class, 'alerts'])->name('gemini.dispatch');

    Route::get('dispatch/alerts/{alert}', [DispatchController::class, 'alert'])
        ->whereNumber('alert')
        ->name('gemini.dispatch.alert');

    /*
     * LISTENING to the resident's live audio (M50 §4.2 #1): the pieces after the last one played, and one piece's
     * bytes from the private disk. A read, on the alert page's own gate — whoever may watch the alert may hear it.
     */
    Route::get('dispatch/alerts/{alert}/audio', [DispatchController::class, 'audioList'])
        ->whereNumber('alert')
        ->name('gemini.dispatch.alert.audio');

    Route::get('dispatch/alerts/{alert}/audio/{seq}', [DispatchController::class, 'audioPiece'])
        ->whereNumber('alert')
        ->whereNumber('seq')
        ->name('gemini.dispatch.alert.audio.piece');

    /*
     * LIVE CHAT (M50 §4.3): residents who wrote on the Resident App's Help & support, waiting first. Reading it is
     * `view`, as every dispatch screen is; answering and resolving are in the update group below.
     */
    Route::get('dispatch/chat', [LiveChatController::class, 'index'])->name('gemini.dispatch.chat');

    /*
     * Acting on an alert needs `update`, not `view`.
     *
     * The Admin Assistant holds View on dispatch and can watch the queue all
     * day; they may not record that a guard is responding. Gating the writes
     * on the same permission as the read would have handed that to them
     * silently, and the console would have had to remember not to draw the
     * button.
     */
    Route::middleware('can:gemini.dispatch.update')->group(function () {
        Route::post('dispatch/alerts/{alert}/acknowledge', [DispatchController::class, 'acknowledge'])
            ->whereNumber('alert')
            ->name('gemini.dispatch.alert.acknowledge');

        Route::post('dispatch/alerts/{alert}/resolve', [DispatchController::class, 'resolve'])
            ->whereNumber('alert')
            ->name('gemini.dispatch.alert.resolve');

        /*
         * Sending an officer, and the two steps a radio call records on their
         * behalf (M10 §1.3).
         *
         * `update`, exactly as acknowledge is: each of these puts a claim on the
         * resident's own panic screen — "an officer has been dispatched", "an
         * officer has arrived" — and a role that may watch the queue is not a role
         * that may tell a frightened person help is coming.
         */
        Route::post('dispatch/alerts/{alert}/dispatch', [DispatchController::class, 'dispatchOfficer'])
            ->whereNumber('alert')
            ->name('gemini.dispatch.alert.dispatch');

        Route::post('dispatch/alerts/{alert}/en-route', [DispatchController::class, 'enRoute'])
            ->whereNumber('alert')
            ->name('gemini.dispatch.alert.en_route');

        Route::post('dispatch/alerts/{alert}/on-scene', [DispatchController::class, 'onScene'])
            ->whereNumber('alert')
            ->name('gemini.dispatch.alert.on_scene');

        /*
         * Telling an officer to go — the console half of a channel the handset
         * has always had.
         *
         * `update` alongside acknowledge and resolve, and for the same reason:
         * this records that somebody was instructed to respond. A role that may
         * watch the queue is not thereby a role that may commit an officer to an
         * incident.
         *
         * Throttled. It reaches a handset and, during a panic, a dispatcher
         * pressing send repeatedly is a person under pressure rather than an
         * attacker — but the officer on the other end should not have their
         * screen filled either way.
         */
        Route::post('dispatch/alerts/{alert}/message', [DispatchController::class, 'messageGuard'])
            ->whereNumber('alert')
            ->middleware('throttle:30,1')
            ->name('gemini.dispatch.alert.message');

        /*
         * Replying to the RESIDENT who raised the panic (M50 §4.2 #2) — the console half of "Message dispatch…"
         * on their panic screen. `update`, and throttled, for `message`'s reasons.
         */
        Route::post('dispatch/alerts/{alert}/reply', [DispatchController::class, 'reply'])
            ->whereNumber('alert')
            ->middleware('throttle:30,1')
            ->name('gemini.dispatch.alert.reply');

        /*
         * Answering a resident on Live chat, and marking the conversation resolved (M50 §4.3). `update`: an answer
         * speaks for Gemini Security on a resident's phone. Throttled for `reply`'s reasons.
         */
        Route::post('dispatch/chat/{chat}/reply', [LiveChatController::class, 'reply'])
            ->whereNumber('chat')
            ->middleware('throttle:30,1')
            ->name('gemini.dispatch.chat.reply');

        Route::post('dispatch/chat/{chat}/resolve', [LiveChatController::class, 'resolve'])
            ->whereNumber('chat')
            ->name('gemini.dispatch.chat.resolve');

        /*
         * Deciding a request takes a guard off post for eight days. That is a
         * roster change, so it needs `update` — an Admin Assistant may watch
         * this queue all day and may not empty it.
         */
        Route::post('dispatch/requests/{guardRequest}', [DispatchController::class, 'decide'])
            ->whereNumber('guardRequest')
            ->name('gemini.dispatch.request.decide');
    });

    /*
     * THE CONSOLE'S PANIC POP-UP (M10 §1.2 items 3 and 4) — a read, but only for
     * somebody who may ACT on an alert. The pop-up interrupts the people who can
     * answer a panic, and the Admin Assistant, who may watch this module and not
     * answer anything in it, is not one of them. See `PanicWatch`.
     *
     * Throttled for a poll that runs every fifteen seconds per open tab, with
     * room for a Director with several tabs and none for a loop.
     */
    Route::get('dispatch/panic-watch', PanicWatchController::class)
        ->middleware(['can:gemini.dispatch.update', 'throttle:60,1'])
        ->name('gemini.dispatch.panic-watch');

    /*
     * WHETHER THE SIREN MAY STOP (B107) — a dispatch screen that is sounding it asks which of
     * its alerts the control room still owes an answer. On `view`, like every screen that
     * sounds it; throttled for one ask every five seconds per sounding tab, with room for
     * several tabs.
     */
    Route::get('dispatch/alarm', PanicAlarmController::class)
        ->middleware('throttle:120,1')
        ->name('gemini.dispatch.alarm');
});
