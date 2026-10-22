<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\OperationsController;
use App\Http\Controllers\Gemini\PlacesController;
use App\Http\Controllers\Gemini\ScheduleController;
use App\Http\Controllers\Gemini\ShiftPanelController;
use Illuminate\Support\Facades\Route;

/*
| Security operations — board screens super-admin-24 to 27.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| These four screens sit in the Guard workforce module and share its permission,
| but they are not records about one guard: they are the operational picture
| ACROSS clients — who is standing which post this week, which standing orders
| are in force, what the posts are reporting, and what has gone wrong. That is
| why they have a controller of their own rather than living beside the guard
| directory and the compliance queue.
|
| THE ROSTER HAS TWO WRITES (12 §2, Wave 5) and they are `update`, because
| putting a client's gate on the rota is not reading it. An OPEN shift is a real
| row with no officer on it: a post needing somebody is a fact this console has
| to hold, and a placeholder guard would show an empty gate as manned.
|
| THE INCIDENT LOG HAS TWO WRITES (12 §2, item 27): a structured intake, and a
| resolution that closes an incident for good.
*/

Route::middleware('can:gemini.guard_workforce.view')->group(function () {
    /*
     * Literal segments, all of them. `guards/{guard}` in routes/gemini/guards.php
     * is constrained to a number, so none of these can be read as a guard id —
     * but they are registered here as their own paths rather than relying on
     * that constraint alone.
     */
    Route::get('guards/roster', [OperationsController::class, 'roster'])
        ->name('gemini.guard_workforce.roster');

    Route::get('guards/standing-orders', [OperationsController::class, 'standingOrders'])
        ->name('gemini.guard_workforce.standing_orders');

    Route::get('guards/activity', [OperationsController::class, 'gateActivity'])
        ->name('gemini.guard_workforce.gate_activity');

    Route::get('guards/incidents', [OperationsController::class, 'incidents'])
        ->name('gemini.guard_workforce.incidents');

    // One order set, every version, every acknowledgement (12 §2, item 28).
    Route::get('guards/standing-orders/{set}', [OperationsController::class, 'standingOrder'])
        ->whereNumber('set')
        ->name('gemini.guard_workforce.standing_order');

    // One incident (12 §2, item 27). 404 outside the viewer's scope.
    Route::get('guards/incidents/{incident}', [OperationsController::class, 'incident'])
        ->whereNumber('incident')
        ->name('gemini.guard_workforce.incident');

    // Sites & geofences (M11 §6): where the posts and checkpoints are, on a map.
    Route::get('guards/sites', [OperationsController::class, 'sites'])
        ->name('gemini.guard_workforce.sites');

    // Who is on duty, who is next, who is away (26 Sep, C4) — for every role that reads the workforce.
    Route::get('guards/on-duty', [OperationsController::class, 'onDuty'])
        ->name('gemini.guard_workforce.on_duty');

    // M30 §4: the Schedule, one client's week of shifts, beside the classic roster (which is not touched).
    Route::get('guards/schedule', [ScheduleController::class, 'show'])
        ->name('gemini.guard_workforce.schedule');

    // M30 §5.2: one shift as the panel opens on it — read by every role that reads the schedule; changed only by update.
    Route::get('guards/schedule/shifts/{shift}', [ShiftPanelController::class, 'show'])
        ->whereNumber('shift')
        ->name('gemini.guard_workforce.schedule.shift');

    // M30 §7: Places, the estate, its gates and its patrol in four steps, beside Sites & geofences (not touched).
    Route::get('guards/places', [PlacesController::class, 'show'])
        ->name('gemini.guard_workforce.places');
});

/*
 * Placing a post or a checkpoint on the map is `update`: moving a geofence changes who can clock in there. Audited
 * with the position before and after (SiteGeography).
 */
Route::middleware('can:gemini.guard_workforce.update')->group(function () {
    Route::post('guards/sites/posts/{post}', [OperationsController::class, 'placePost'])
        ->whereNumber('post')
        ->name('gemini.guard_workforce.sites.post');

    Route::post('guards/sites/checkpoints/{checkpoint}', [OperationsController::class, 'placeCheckpoint'])
        ->whereNumber('checkpoint')
        ->name('gemini.guard_workforce.sites.checkpoint');

    // M21 §1.1: a new checkpoint on a patrol route, placed as it is made, with a tag code of its own.
    Route::post('guards/sites/posts/{post}/checkpoints', [OperationsController::class, 'addCheckpoint'])
        ->whereNumber('post')
        ->name('gemini.guard_workforce.sites.add_checkpoint');

    // M22: a checkpoint taken off its route, with the reason.
    Route::post('guards/sites/checkpoints/{checkpoint}/retire', [OperationsController::class, 'retireCheckpoint'])
        ->whereNumber('checkpoint')
        ->name('gemini.guard_workforce.sites.retire_checkpoint');

    // M21 §1.1: a checkpoint's tag, printed — its QR, its name and its code. Update access, not view: the code on
    // the tag is what proves a guard stood there, so it is handed out by those who place checkpoints.
    Route::get('guards/sites/checkpoints/{checkpoint}/tag', [OperationsController::class, 'checkpointTag'])
        ->whereNumber('checkpoint')
        ->name('gemini.guard_workforce.sites.checkpoint_tag');

    // M26 §3.5, M27 §4.2: every tag on the estate's patrol, one to a page, to print at once (and again for spares).
    Route::get('guards/sites/{estate}/tags', [OperationsController::class, 'routeTags'])
        ->where('estate', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.sites.route_tags');

    // M27 §4.1: a checkpoint on the estate's own patrol route, with its phase; and many at once, one name to a line.
    Route::post('guards/sites/{estate}/checkpoints', [OperationsController::class, 'addRouteCheckpoint'])
        ->where('estate', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.sites.add_route_checkpoint');

    Route::post('guards/sites/{estate}/checkpoints/many', [OperationsController::class, 'addRouteCheckpoints'])
        ->where('estate', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.sites.add_route_checkpoints');

    // M27 §4.3: many checkpoints placed at once, from the installers' messages, as the Director confirmed them.
    Route::post('guards/sites/{estate}/checkpoints/place', [OperationsController::class, 'placeRouteCheckpoints'])
        ->where('estate', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.sites.place_route_checkpoints');

    // M27 §4.4: the route's order, and how long after one checkpoint the next is due.
    Route::post('guards/sites/checkpoints/{checkpoint}/move', [OperationsController::class, 'moveCheckpoint'])
        ->whereNumber('checkpoint')
        ->name('gemini.guard_workforce.sites.move_checkpoint');

    Route::post('guards/sites/{estate}/patrol-interval', [OperationsController::class, 'patrolInterval'])
        ->where('estate', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.sites.patrol_interval');

    // M26: the other names the estate uses for a post ("Gate 5, Phase 5 Gate"), shown wherever the post is named.
    Route::post('guards/sites/posts/{post}/also-called', [OperationsController::class, 'postAlsoCalled'])
        ->whereNumber('post')
        ->name('gemini.guard_workforce.sites.post_also_called');

    // M26: a post that does not exist, taken away — deleted, or retired when something was recorded at it.
    Route::post('guards/sites/posts/{post}/remove', [OperationsController::class, 'removePost'])
        ->whereNumber('post')
        ->name('gemini.guard_workforce.sites.remove_post');

    // TRACK-01: the per-contract setting that lets a client's own console see its guards' pins (default off).
    Route::post('guards/sites/{estate}/guard-positions', [OperationsController::class, 'estateSeesGuards'])
        ->where('estate', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.sites.guard_positions');

    // M12 §1: a new post, placed as it is made — the only way a post (and so a geofence) comes to exist.
    Route::post('guards/sites/{estate}/posts', [OperationsController::class, 'addPost'])
        ->where('estate', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.sites.add_post');

    // M12 §1: the estate's own fence (the fallback for a post with none) and its supervisor's number.
    Route::post('guards/sites/{estate}/estate', [OperationsController::class, 'placeEstate'])
        ->where('estate', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.sites.estate');
});

/*
 * Standing orders (12 §2, item 28). Publishing a set is `create`; publishing
 * its next version, or recording a review, is `update`. A guard acknowledges
 * from the handset, over /api/v1, never from here.
 */
Route::post('guards/standing-orders', [OperationsController::class, 'createOrders'])
    ->middleware('can:gemini.guard_workforce.create')
    ->name('gemini.guard_workforce.standing_orders.create');

Route::post('guards/standing-orders/{set}/revise', [OperationsController::class, 'reviseOrders'])
    ->whereNumber('set')
    ->middleware('can:gemini.guard_workforce.update')
    ->name('gemini.guard_workforce.standing_orders.revise');

Route::post('guards/standing-orders/{set}/reviewed', [OperationsController::class, 'reviewOrders'])
    ->whereNumber('set')
    ->middleware('can:gemini.guard_workforce.update')
    ->name('gemini.guard_workforce.standing_orders.reviewed');

/*
 * Logging an incident brings an evidence record into existence: `create`.
 * Closing one records what was done about it: `update`.
 */
Route::post('guards/incidents', [OperationsController::class, 'logIncident'])
    ->middleware('can:gemini.guard_workforce.create')
    ->name('gemini.guard_workforce.incident.log');

Route::post('guards/incidents/{incident}/resolve', [OperationsController::class, 'resolveIncident'])
    ->whereNumber('incident')
    ->middleware('can:gemini.guard_workforce.update')
    ->name('gemini.guard_workforce.incident.resolve');

/*
 * `guard_workforce.update`, the same module these screens read under — there is
 * no separate operations module in the matrix, and inventing one here would be
 * a permission the role screen does not draw.
 */
Route::middleware('can:gemini.guard_workforce.update')->group(function () {
    Route::post('guards/roster/shifts', [OperationsController::class, 'postShift'])
        ->name('gemini.guard_workforce.roster.post');

    Route::post('guards/roster/shifts/{shift}/assign', [OperationsController::class, 'assignShift'])
        ->whereNumber('shift')
        ->name('gemini.guard_workforce.roster.assign');

    // An officer's claim on an open shift, answered (26 Sep, G3). Approving IS assigning, so the same gate.
    Route::post('guards/roster/claims/{claim}/approve', [OperationsController::class, 'approveClaim'])
        ->whereNumber('claim')
        ->name('gemini.guard_workforce.roster.claim.approve');

    Route::post('guards/roster/claims/{claim}/decline', [OperationsController::class, 'declineClaim'])
        ->whereNumber('claim')
        ->name('gemini.guard_workforce.roster.claim.decline');

    // M30 §5: the Schedule's shift panel — what the server would say, as the form changes; and adding the shift.
    Route::post('guards/schedule/check', [ShiftPanelController::class, 'check'])
        ->middleware('throttle:120,1')
        ->name('gemini.guard_workforce.schedule.check');

    Route::post('guards/schedule/shifts', [ShiftPanelController::class, 'store'])
        ->name('gemini.guard_workforce.schedule.store');

    // M30 §5.2: the panel opened on a shift — change it, take its guard off, or remove it before it starts.
    Route::post('guards/schedule/shifts/{shift}', [ShiftPanelController::class, 'update'])
        ->whereNumber('shift')
        ->name('gemini.guard_workforce.schedule.update');

    Route::post('guards/schedule/shifts/{shift}/take-off', [ShiftPanelController::class, 'takeOff'])
        ->whereNumber('shift')
        ->name('gemini.guard_workforce.schedule.take_off');

    Route::post('guards/schedule/shifts/{shift}/remove', [ShiftPanelController::class, 'remove'])
        ->whereNumber('shift')
        ->name('gemini.guard_workforce.schedule.remove');

    // M30 §4.6: copy last week into the week on screen — said first (the plan), then done exactly as said.
    Route::get('guards/schedule/copy', [ScheduleController::class, 'copyPlan'])
        ->name('gemini.guard_workforce.schedule.copy_plan');

    Route::post('guards/schedule/copy', [ScheduleController::class, 'copy'])
        ->name('gemini.guard_workforce.schedule.copy');

    // M30 §8: a guard's leave decided on the Schedule, with his shifts in those days said first and chosen for.
    Route::post('guards/schedule/leave/{guardRequest}', [ScheduleController::class, 'decideLeave'])
        ->whereNumber('guardRequest')
        ->name('gemini.guard_workforce.schedule.leave');

    /*
     * M30 §7: Places' writes, through SiteGeography (the classic page's own rules), and its place search — asked of
     * Nominatim by the server, one request a second across the platform (PlaceSearch); this throttle is the viewer's.
     */
    Route::get('guards/places/search', [PlacesController::class, 'search'])
        ->middleware('throttle:60,1')
        ->name('gemini.guard_workforce.places.search');

    Route::post('guards/places/{client}/estate', [PlacesController::class, 'placeEstate'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.estate');

    Route::post('guards/places/{client}/gates', [PlacesController::class, 'addGate'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.add_gate');

    Route::post('guards/places/gate/{post}', [PlacesController::class, 'placeGate'])
        ->whereNumber('post')
        ->name('gemini.guard_workforce.places.gate');

    Route::post('guards/places/gate/{post}/name', [PlacesController::class, 'renameGate'])
        ->whereNumber('post')
        ->name('gemini.guard_workforce.places.gate_name');

    // M30 §7.2 step 3: the patrol — where it starts, its interval, its checkpoints and their order.
    Route::post('guards/places/{client}/patrol-start', [PlacesController::class, 'patrolStart'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.patrol_start');

    Route::post('guards/places/{client}/patrol-interval', [PlacesController::class, 'patrolInterval'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.patrol_interval');

    Route::post('guards/places/{client}/checkpoints', [PlacesController::class, 'addCheckpoint'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.add_checkpoint');

    Route::post('guards/places/{client}/checkpoints/many', [PlacesController::class, 'addCheckpoints'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.add_checkpoints');

    Route::post('guards/places/{client}/checkpoints/place', [PlacesController::class, 'placeCheckpoints'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.place_checkpoints');

    Route::post('guards/places/{client}/route', [PlacesController::class, 'reorder'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.route');

    Route::post('guards/places/checkpoint/{checkpoint}', [PlacesController::class, 'placeCheckpoint'])
        ->whereNumber('checkpoint')
        ->name('gemini.guard_workforce.places.checkpoint');

    Route::post('guards/places/checkpoint/{checkpoint}/retire', [PlacesController::class, 'retireCheckpoint'])
        ->whereNumber('checkpoint')
        ->name('gemini.guard_workforce.places.retire_checkpoint');

    // M30 §7.2 step 4: the supervisor's number, and whether the client's own console sees its guards' positions.
    Route::post('guards/places/{client}/supervisor', [PlacesController::class, 'supervisor'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.supervisor');

    Route::post('guards/places/{client}/guard-positions', [PlacesController::class, 'guardPositions'])
        ->where('client', '[a-z0-9][a-z0-9_-]{0,62}')
        ->name('gemini.guard_workforce.places.guard_positions');
});
