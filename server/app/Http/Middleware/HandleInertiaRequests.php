<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Console;
use App\Live\LivePage;
use App\Models\User;
use App\Services\Console\ConsolePhotos;
use App\Services\Dispatch\PanicWatch;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Navigation\ConsoleNavigation;
use App\Support\EstateNavigation;
use App\Support\EstatePhone;
use App\Support\ScreenState;
use App\Support\SiteTime;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page.
     *
     * Navigation is generated here rather than per controller, so no screen
     * can accidentally ship without it or, worse, ship with a hand-written
     * list that has drifted from the role matrix.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        /*
         * SITE TIME OR MY TIME (M19A §7), decided before the page is read: a Gemini console user who switched to their
         * own clock has every stamp on it read in their zone. Everybody else, and every estate console, reads site time.
         * Set on every request, so a worker that served a "my time" reader never carries it to the next.
         */
        $mine = $user instanceof User && $user->console === Console::Gemini && ! tenancy()->initialized
            && $user->getAttribute('console_time_view') === 'mine' && is_string($user->getAttribute('console_zone'));
        SiteTime::viewAs($mine ? (string) $user->getAttribute('console_zone') : null);

        return [
            ...parent::share($request),

            // The header's switch: which clock this console is read on, and the viewer's own zone when it is theirs.
            'timeView' => $user instanceof User && $user->console === Console::Gemini
                ? ['view' => $mine ? 'mine' : 'site', 'zone' => $mine ? SiteTime::viewerZone() : null]
                : null,

            'auth' => [
                'user' => $user ? $this->presentUser($user) : null,
            ],

            /*
             * THE EXAMPLE A PHONE BOX SHOWS (M32 F7): the estate's own country's, from its record; in the Gemini console,
             * the platform's home. Never the viewer's browser or locale. A Gemini page about one estate (a post's
             * supervisor number) sends that estate's own.
             *
             * NOT ON THE CLASSIC ROSTER AND SITES PAGES, which keep their own example and are frozen for the client's
             * comparison (M32 §17), their responses held to the M30 recording (`ClassicWorkforcePagesAreUnchangedTest`).
             */
            ...($request->routeIs('gemini.guard_workforce.roster', 'gemini.guard_workforce.sites')
                ? []
                : ['phoneExample' => fn (): array => EstatePhone::example()]),

            'nav' => fn () => $user
                ? app(ConsoleNavigation::class)->for($user, $user->console)
                : [],

            /*
             * The Estate Console's own sidebar, and only inside an estate.
             *
             * Shared rather than passed per controller for the same reason the
             * Gemini nav is: forgetting it on one screen is how a console ends
             * up with a sidebar that is right on nine screens and empty on the
             * tenth. Which item is CURRENT is the screen's business and arrives
             * as a prop on the layout, so this carries only the items and the
             * permissions that filter them.
             */
            'estateNav' => fn (): array => $user !== null && tenancy()->initialized
                ? app(EstateNavigation::class)->forViewer($user, tenantKey: (string) tenant()?->getTenantKey())
                : [],

            /*
             * WHETHER THIS READER IS TOLD ABOUT A PANIC, WHEREVER THEY ARE — M10 §1.
             *
             * The client: "It must go to that community's main dashboard where
             * the president and all the people can see it: a pop-up, and
             * something blinking on it."
             *
             * Shared rather than passed per controller for the same reason the
             * navigation is: it has to reach all fifty-two estate screens, and a
             * screen that forgot it would be a screen where an emergency is
             * silent. `EstateConsole.vue` is the one shell they all render, so
             * this is what it gates the subscription on.
             *
             * GATED ON `estate.security.view`, WHICH IS ALREADY EXACTLY THE RIGHT
             * FIVE. The matrix gives that module to the Community Super Admin and
             * the estate Head of Security in full, and to the President, the Vice
             * President and the Property Manager as view — which is precisely the
             * list §1.2 names. So there is no second rule to keep in step: the
             * people who may open the Security screen are the people the pop-up
             * reaches, and changing one changes the other.
             *
             * The estate key travels with it because the channel is per estate
             * and the browser must not be trusted to name its own tenant.
             *
             * AND WHAT IS STILL OUTSTANDING HERE (B039, B103). The shell is
             * built afresh by every page, so an indicator that knew only what
             * the socket said since the page loaded went out on the first link
             * a reader followed, and never appeared for anybody who arrived
             * after the panic. The alerts nobody in the control room has
             * acknowledged travel with every page, with the path to ask again
             * — `PanicWatch::forEstate()`, the Director's own rule for one estate.
             */
            'panicWatch' => fn (): ?array => $user !== null
                && tenancy()->initialized
                && $user->can('estate.security.view')
                    ? app(PanicWatch::class)->forEstate((string) tenant()?->getTenantKey())
                    : null,

            /*
             * THE SAME, FOR THE GEMINI CONSOLE — M10 §1.2 items 3 and 4.
             *
             * "The Gemini Director — dashboard alert." "The Gemini Head of Security
             * for that site — dashboard alert plus email." The Gemini console had no
             * live subscription outside the dispatch screens, so a Director learnt of
             * a panic from the bell at their next page load. `GeminiConsole.vue` is
             * the shell every Gemini page renders, so this is what it mounts the
             * pop-up on — for exactly the people `PanicWatch::watches()` names
             * (whoever may act on an alert), with exactly the estates the channel
             * will admit them to. Null everywhere else, including inside an estate.
             */
            'geminiPanicWatch' => fn (): ?array => $user !== null && ! tenancy()->initialized
                ? app(PanicWatch::class)->forViewer($user)
                : null,

            /*
             * LIVE UPDATES (M11 Part C, DESIGN.md §6.1, §6.4). The channels every page of this console holds —
             * the person's own, the panic feed, the feeds behind the bell — each decided for this viewer by the
             * registry the socket's grants use, and the heads this page's data was read at. The shells start
             * the live client with it and mount the line that says whether the screen is live. A screen whose
             * data moves by itself returns its own `live` (LivePage::for, which carries all of this too); every
             * other page has no bindings, and says "This screen does not update by itself". No user, no socket.
             */
            'live' => fn (): ?array => $user instanceof User ? LivePage::shellOnly($user) : null,

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),

                // What a door said on the way to another: "a reset link has
                // been sent", "your password has been changed".
                'status' => fn () => $request->session()->get('status'),

                /*
                 * A ONE-TIME CREDENTIAL ON ITS WAY TO A SCREEN, ONCE.
                 *
                 * A guard's enrolment code is generated, hashed, and the plaintext never stored —
                 * so the only moment it can be shown is the redirect that follows issuing it.
                 * `success` cannot carry it: that is a sentence, and this needs the officer's
                 * name and the expiry beside the code so the operator reading it down a
                 * telephone knows what they are reading and for how long it lasts.
                 *
                 * It lives in the flash bag and nowhere else — not a prop, not a query string,
                 * not a model attribute — so it exists for exactly one render and is gone.
                 */
                'enrolment_code' => fn () => $request->session()->get('enrolment_code'),

                /*
                 * WHAT BECAME OF AN INVITATION'S EMAIL, FOR ITS OWN ROW (DESIGN §11 rows 1, 2 and 5).
                 * `{id, state, message}` — `sent` only when the mail server took it; `not_sent`,
                 * `not_set_up` or `refused` otherwise. The screen draws it in the invitation's row,
                 * scrolled into view, rather than only in a banner above a list the reader is not
                 * looking at.
                 */
                'invitation_outcome' => fn () => $request->session()->get('invitation_outcome'),

                /*
                 * The same for a RESIDENT's invite to the app, after Add resident (household invites, stage 2):
                 * `{resident_id, state, message}`, drawn in that person's row on the household page.
                 */
                'invite_outcome' => fn () => $request->session()->get('invite_outcome'),

                /*
                 * THE DOCUMENT JUST ASKED FOR, so the screen can wait for it and hand it over (M56 §5: "View agenda shows
                 * a green banner and no document ever appears"; the same on Bills' "View receipt"). `{id, kind}`. The
                 * console's shell polls its status and starts the download the moment the worker has written it, on
                 * every screen at once.
                 */
                'document' => fn () => $request->session()->get('document'),
            ],

            /*
             * Which of the six states to force, in local development only.
             *
             * Shared rather than passed per controller because it applies to
             * every screen and because forgetting it on one screen is exactly
             * how the other five states go unreviewed. Null in every other
             * environment — ScreenState asserts that itself, and this is the
             * second place it is checked.
             */
            'screenState' => fn (): ?string => ScreenState::forced($request),
        ];
    }

    /**
     * Only what the interface actually renders.
     *
     * Deliberately not the whole model: a User carries mfa_secret, a password
     * hash and a status, none of which belong in a JSON payload that ships to
     * the browser on every page.
     *
     * @return array{id: int, name: string, email: string, console: string, role_label: string, is_gemini_staff: bool, is_supervisor: bool, receives_alerts: bool, photo_version: string|null}
     */
    private function presentUser(User $user): array
    {
        $role = $user->roles->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'console' => $user->console->value,

            // `??` covers both halves — a user with no role at all, and a role
            // row whose label was never set. `?->` on the left of it is
            // redundant: null-coalescing already suppresses the null access.
            'role_label' => $role->label ?? 'No role assigned',
            'is_gemini_staff' => $user->console === Console::Gemini,

            // Whether the sidebar's own-account menu offers "Clock-in override PIN" (M4 §3.4).
            // The route is gated on the same permission; this only decides whether to draw the link.
            'is_supervisor' => app(SupervisorOverride::class)->isSupervisor($user),

            // Whether the own-account menu offers "Alert contact" (M10 §1.4) — the way back
            // to the verification page to change a proven address. Only for accounts the
            // contact gate applies to; for anybody else there is nothing an alert would use.
            'receives_alerts' => $user->isSubjectToContactGate(),

            // M12 §4.8: the profile picture's version, or null (the shells draw initials). Each shell builds the
            // address on its own door: `/account/photo?v=…`.
            'photo_version' => ConsolePhotos::version($user),
        ];
    }
}
