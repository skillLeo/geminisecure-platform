<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Platform\OperatorEstates;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorization
|--------------------------------------------------------------------------
|
| A private channel is only private because of what is in this file. Without
| an entry here every subscription is refused, which presents as "the socket
| connects but nothing ever arrives" — the exact symptom that kept the alert
| screens on a 3-second poll.
|
| Alerts are broadcast per estate, never on one global channel. A single
| channel would push every estate's alerts to every connected console and
| rely on the client to filter, which is not a boundary at all: the data has
| already crossed the wire by the time the filter runs.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (int) $user->id === (int) $id;
});

/**
 * estate.{tenantId}.alerts — the live duress and panic feed for one estate.
 *
 * Two kinds of listener are legitimate, and they are authorised differently:
 *
 *   Gemini staff who hold the dispatch module, FOR THE ESTATES THEIR ROLE
 *   REACHES. The permission says whether somebody watches alerts at all — a
 *   Gemini accountant, who holds no dispatch permission, is refused everywhere.
 *   The role's scope says WHICH estates: a Director, a Dispatcher and an
 *   Operations Manager are scoped to all of them, and a Head of Security to the
 *   sites they are assigned (M10 §1.2 item 4, "the Gemini Head of Security for
 *   that site").
 *
 *   Estate users assigned to THAT estate, and only that estate, who may see its
 *   Security screen. canAccessEstate already encodes the assignment and
 *   active-status rules; asking it again here keeps one answer to the question
 *   rather than two.
 *
 * Returning false rather than throwing: a refused subscription is a normal
 * outcome for a user who simply is not on that estate, not an error.
 */
Broadcast::channel('estate.{tenantId}.alerts', function (User $user, string $tenantId): bool {
    /*
     * NEVER AS A PRESENCE CHANNEL (M11 Part C, DESIGN §3.8). The broadcaster strips
     * `private-` and `presence-` alike before it finds this callback, so without this
     * line `presence-estate.{t}.alerts` was admitted to everybody admitted here — and a
     * presence channel broadcasts its member list, telling every console who else is
     * watching the estate's panics. Nothing subscribes to the presence form, so it is
     * refused outright.
     */
    $requested = request()->input('channel_name');

    if (is_string($requested) && str_starts_with($requested, 'presence-')) {
        return false;
    }

    if ($user->isGeminiStaff()) {
        /*
         * THE DEMONSTRATION ESTATE IS NOT IN A DISPATCHER'S SCOPE (M5 §3), and
         * this is the line that makes that a boundary rather than a filter.
         *
         * The dispatch screens already leave it out of the `estateIds` they
         * subscribe to, which is what stops a panic raised by an app-store
         * reviewer appearing in a live control room. But that is the client
         * deciding what to ask for, and the whole reason this file exists is
         * that a boundary the client enforces is not a boundary: a screen built
         * next year that assembles its own list, or a browser left open across
         * a deploy, would be admitted by a callback that only checked the
         * permission.
         *
         * Checked HERE rather than by narrowing `canAccessEstate()`, because
         * that would also close the client record, the alert's own response
         * screen and everything else somebody at Gemini needs in order to
         * notice the demonstration estate is broken before a reviewer does.
         * What is refused is the live feed — the one thing whose whole purpose
         * is to interrupt a person.
         */
        if (OperatorEstates::isDemonstration($tenantId)) {
            return false;
        }

        /*
         * THE ESTATE SCOPE, ON THE SOCKET AND NOT ONLY ON THE SCREEN (M10 §1.2).
         *
         * This returned `can('gemini.dispatch.view')` and nothing more, so a Head
         * of Security — who holds dispatch, scoped to assigned sites — was
         * admitted to EVERY estate's channel. The dispatch screens only asked for
         * the estates in their scope, and that is the client choosing what to
         * ask for: a hand-typed subscription, or the Gemini console's pop-up
         * (`useConsolePanic`), which listens on every page, would have been sent
         * another client's lot, resident's name and panic. The file header says
         * why that is not a boundary; this line is what makes it one.
         *
         * `canAccessEstate()` rather than a second copy of the scope rule: it
         * reads the role's scope, the assignment rows and the account's status,
         * which is exactly the answer every other tenant-scoped read is given —
         * and it now refuses a SUSPENDED Gemini account the live feed, which the
         * permission check alone never did. Permission-based rather than
         * role-named, so a role the Director builds (M10 §4) inherits it from its
         * cells and its scope.
         */
        return $user->can('gemini.dispatch.view') && $user->canAccessEstate($tenantId);
    }

    /*
     * THE ESTATE'S OWN PEOPLE WHO MAY SEE ITS SECURITY SCREEN, AND NO OTHERS (bug
     * hunt B044).
     *
     * This returned `canAccessEstate()` alone, which is true for EVERY active account
     * assigned to the estate. The feed carries each panic's lot, the resident's name
     * and the kind, and the estate console only subscribes for the people its
     * `panicWatch` prop names — those holding `estate.security.view` — so a Treasurer,
     * a Secretary or an Admin Assistant (Security "-"), or a role the estate built
     * with no Security cell, could subscribe by hand and read every panic the Security
     * screen refuses them. The same client-chosen boundary the Gemini branch above was
     * fixed for. The socket now asks the permission `GET {estate}/security` and the
     * `panicWatch` prop ask, from the role's cells rather than its name, so the five
     * roles M10 §1.2 names (and any copy that keeps Security) are admitted and nobody
     * else is.
     */
    return $user->canAccessEstate($tenantId) && $user->can('estate.security.view');
});
