<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Notifications\InvitationSent;
use App\Services\Audit\AuditLogger;
use App\Services\Gemini\ClientDirectory;
use App\Services\Gemini\ClientGuardAssignment;
use App\Services\Gemini\ClientMessaging;
use App\Services\Gemini\ClientOnboarding;
use App\Services\Gemini\ClientPlan;
use App\Services\InvitationNotSent;
use App\Services\Invitations;
use App\Support\PlatformMoney;
use App\Support\SiteTime;
use DateTimeZone;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Response;
use RuntimeException;

/**
 * Clients (estates) — board screens super-admin-04, 05, 09 and 10.
 *
 * A "client" here is an estate that subscribes to GeminiSecure. Every screen in
 * this module reads gs_platform only; none of them opens an estate database.
 *
 * Search, filter, sort and page are read from the query string and answered in
 * SQL, so every one of them is a real, shareable, bookmarkable URL rather than
 * a piece of state that lives only in the page.
 *
 * The rules live in the services. This class resolves the estate, checks the
 * viewer may reach it, and hands over — so that the answer to "may this officer
 * be put on this gate" is the same one whether it is the picker asking or a
 * request made by hand.
 */
class ClientController extends Controller
{
    public function index(Request $request, ClientDirectory $directory): Response
    {
        $user = $request->user();

        /*
         * M10 §5. The Tier and MRR columns, their sort keys and the "At risk" chip are Billing &
         * subscriptions' — asked once, of the permission, and handed to both the narrowing below
         * and the directory. See `PlatformMoney`.
         */
        $maySeeMoney = PlatformMoney::maySeeRevenue($user);
        $createBlockedReason = $this->createBlockedReason($request);

        return inertia('Gemini/Clients/Index', [
            // M32 F5: the search and the chips are the directory's; the order, its way, the page and the page size the
            // shared table's, by the one rule — and without the money sorts for a reader without billing.
            ...$directory->directory($user, $this->criteria($request, $maySeeMoney), $maySeeMoney, ClientDirectory::table($request, $maySeeMoney)),

            /*
             * "ONBOARD NEW CLIENT", LIVE ONLY WHEN ITS ROUTE WILL LET THIS VIEWER THROUGH (bug hunt
             * C01). The topbar drew it as a link for everybody, and the Dispatcher, the Admin
             * Assistant and the Accountant — who hold Clients to read — followed it onto a 403.
             * Asked of exactly what `clients/new` asks, and when the answer is no the page draws
             * the inert twin with the reason instead.
             */
            'canCreate' => $createBlockedReason === null,
            'createBlockedReason' => $createBlockedReason,
        ]);
    }

    public function show(Request $request, string $tenant, ClientDirectory $directory): Response
    {
        $estate = $this->reachable($request, $tenant);

        return inertia('Gemini/Clients/Show', [
            /*
             * The tier pill, the MRR, the billing status and its "Overdue" badge, the per-unit
             * price and the invoice list are all Billing & subscriptions' records (M10 §5), and
             * the service leaves every one of them out for a reader who does not hold it.
             *
             * And whether this reader may open the plan screen, so an unplanned client's go-live
             * button tells a reader who cannot set the plan who can, not to set it (B004).
             */
            'estate' => $directory->detail(
                (string) $estate->getTenantKey(),
                PlatformMoney::maySeeRevenue($request->user()),
                ClientPlan::mayPrice($request->user()),
            ),

            /*
             * The board's primary action opens this client's billing history.
             * Billing is its own gated module, and an Operations Manager who
             * cannot open it should be told so on the button rather than sent
             * to a 403 by a link that looked live.
             */
            'canViewBilling' => PlatformMoney::maySeeRevenue($request->user()),

            /*
             * Whether this role may CHANGE the client rather than only read it,
             * which is what the whole action stack turns on.
             *
             * The page has always declared this prop and it was never passed,
             * so it arrived undefined and every management action on the screen
             * read as refused — including "Mark onboarding complete", the one
             * act board 09 exists for. Undefined is falsy, so nothing looked
             * broken; the button simply said the work was not the reader's,
             * to a Director who holds every permission there is.
             */
            'canManage' => $request->user()->can('gemini.clients.update'),

            /*
             * AND THE TWO LINKS IN THE SAME STACK, each with its own reason (bug hunt B015, C02).
             * "Manage guard assignment", "Message estate admin" and — while onboarding — "Message
             * primary contact" were drawn live for everybody, and the three roles that hold Clients
             * to read followed them onto a 403, on the very screen whose billing button already
             * said why instead. Asked of what `clients/{tenant}/guards` and `…/message` are behind;
             * null when the link leads somewhere.
             */
            'guardsBlockedReason' => $request->user()->can('gemini.clients.update')
                ? null
                : 'Assigning guards changes who stands this client\'s gates, so it needs Clients update access. You are able to read this record.',
            'messageBlockedReason' => $request->user()->can('gemini.clients.update')
                ? null
                : 'A message reaches this client\'s own console and speaks for Gemini, so it needs Clients update access. You are able to read this record.',

            /*
             * The contact's invitation (DESIGN §11 row 5): whether its email went, and Send or Resend. Null once
             * nobody is waiting for one — the contact has signed in, or none was named.
             */
            'contactInvitation' => Invitations::onboardingContactShape((string) $estate->getTenantKey()),
            'invitationBlockedReason' => $request->user()->can('gemini.clients.update')
                ? null
                : 'An invitation issues a way into this client\'s console, so it needs Clients update access. You are able to read this record.',

            // The estate's clock (M19A §9): shown to every reader, changed by the Director alone.
            'timezone' => [
                'zone' => SiteTime::valid($estate->getAttribute('timezone')),
                'name' => SiteTime::clockName((string) $estate->getAttribute('timezone')),
                'zones' => DateTimeZone::listIdentifiers(),
                'canChange' => $request->user()->hasRole(Role::DIRECTOR) && $request->user()->can('gemini.clients.update'),
                'blockedReason' => 'Changing an estate\'s time zone moves every time its residents, guards and office read, so only the Gemini Director can do it.',
            ],
        ]);
    }

    /**
     * Manage guard assignment — board screen super-admin-10.
     */
    public function guards(Request $request, string $tenant, ClientGuardAssignment $assignment): Response
    {
        $estate = $this->reachable($request, $tenant);

        return inertia('Gemini/Clients/Guards', [
            /*
             * The subtitle names the client's tier, and a tier is a price list (M10 §5, bug hunt
             * B004): a Head of Security reaches this screen from the client record's own button
             * and holds no Billing cell, so it is left out for them exactly as the record's pill is.
             */
            'roster' => $assignment->roster($estate, PlatformMoney::maySeeRevenue($request->user())),

            /*
             * The pencil on each row opens that officer's own record, which is
             * where an employment fact is changed. It is a different module, so
             * a role holding Clients and not Guard workforce is told on the
             * control rather than sent to a 403 by it.
             */
            'canOpenGuardRecords' => $request->user()->can('gemini.guard_workforce.view'),
        ]);
    }

    /**
     * Commit a roster.
     *
     * The whole roster, in one request, because that is what the screen edits.
     * See ClientGuardAssignment for why it is not saved a row at a time.
     */
    public function saveGuards(Request $request, string $tenant, ClientGuardAssignment $assignment): RedirectResponse
    {
        $estate = $this->reachable($request, $tenant);

        /** @var array{roster: list<array{guard_id: int, post_id: int|null}>} $validated */
        $validated = $request->validate([
            'roster' => ['present', 'array'],
            'roster.*.guard_id' => ['required', 'integer', 'exists:mysql.guards,id'],
            'roster.*.post_id' => ['nullable', 'integer', 'exists:mysql.posts,id'],
        ]);

        try {
            // The officers taken off it give up their future shifts here (bug hunt B006), on the rota as open.
            $opened = $assignment->save($estate, $validated['roster'], $request->user());
        } catch (RuntimeException $refused) {
            /*
             * Back to the screen with the reason on the roster field, not a 500.
             * Every one of these is a rule a reader can act on — a lapsed
             * licence, a post belonging to another client — and each names what
             * to do next.
             */
            return back()->withErrors(['roster' => $refused->getMessage()]);
        }

        return back()->with('success', "The roster for {$estate->name} has been saved.".($opened === 0 ? '' : sprintf(
            ' %d future %s held by the officers taken off it %s now open on the rota, for somebody posted here to take.',
            $opened,
            $opened === 1 ? 'shift' : 'shifts',
            $opened === 1 ? 'is' : 'are',
        )));
    }

    /**
     * Price a client — board screen super-admin-06.
     *
     * One screen for two acts: activating an onboarding estate's plan, and
     * re-pricing a live one. The service decides which by reading the estate's
     * own status rather than taking it from the URL.
     *
     * Behind Billing & subscriptions' write verb as well as Clients update — see the route and
     * `ClientPlan::PRICING_PERMISSION`. And the platform's MRR only to a reader who may see the
     * whole platform, because it is a sum over every client and a site-scoped role that has
     * been given Billing still may not read the others.
     */
    public function plan(Request $request, string $tenant, ClientPlan $plan): Response
    {
        return inertia('Gemini/Clients/Plan', $plan->forEstate(
            $this->reachable($request, $tenant),
            $request->user()->widestScope() !== AccessScope::AssignedSites,
        ));
    }

    /**
     * Commit the pricing.
     *
     * Back to the screen rather than on to the client record. The projection
     * panel is the confirmation — an account manager pricing a client usually
     * has a second change to make, and bouncing them to the detail screen
     * costs a round trip each time.
     */
    public function savePlan(Request $request, string $tenant, ClientPlan $plan): RedirectResponse
    {
        $estate = $this->reachable($request, $tenant);

        $plan->apply($estate, $request->user(), $request->all());

        return back()->with('success', "{$estate->name}'s plan has been saved. It takes effect on the first full billing cycle.");
    }

    /**
     * Take on a new client — board screen super-admin-08.
     *
     * Behind Billing & subscriptions' write verb as well as Clients create: the form prices the
     * client.
     */
    public function create(Request $request, ClientPlan $plan): Response
    {
        return inertia('Gemini/Clients/Create', $plan->newClientForm($request->user()));
    }

    /**
     * Record the client.
     *
     * The subdomain is validated hard because it becomes a database name and a
     * hostname: lowercase letters and digits only, and unique across every
     * tenant that has ever existed.
     */
    public function store(Request $request, ClientOnboarding $onboarding, Invitations $invitations): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],

            /*
             * One field, as the board asks it: "Mandeville, Manchester". The
             * street line and the parish are stored separately because clients
             * are listed and reported by parish, and the split happens in the
             * service — asking for two fields would be a storage decision
             * leaking onto a form.
             */
            'address' => ['required', 'string', 'max:200'],

            'units' => ['required', 'integer', 'min:1', 'max:100000'],
            'phases' => ['required', 'integer', 'min:1', 'max:50'],
            'plan_id' => ['required', 'integer', 'exists:mysql.plans,id'],
            'guards' => ['required', 'integer', 'min:0', 'max:500'],

            // PRESENT as well as nullable: null is "Monthly rolling", and the service reads the
            // key. Left out, it passed validation and the request answered 500 (see ClientPlan).
            'term_months' => ['present', 'nullable', 'integer', 'min:1', 'max:120'],
            'contact_name' => ['required', 'string', 'max:160'],

            /*
             * A NEW ADDRESS, NOT ONE AN ACCOUNT ALREADY HOLDS (B001). The contact
             * becomes a new Estate Console account; an address somebody already
             * signs in with is refused here, on the field, and again in the
             * service — which used to overwrite that person's password, console
             * and standing instead. The index's collation is case-insensitive, so
             * this matches `JANE@x.com` to `jane@x.com`.
             */
            'contact_email' => ['required', 'email', 'max:190', Rule::unique('mysql.users', 'email')],
            'contact_phone' => ['required', 'string', 'max:40'],

            // The estate's clock (M19A §9): an IANA zone, chosen, never assumed — every time the estate sees is on it.
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ], [
            'address.required' => 'Where is the estate? Street or district, then the parish.',
            'timezone.required' => 'Choose the estate\'s time zone. Every time the estate, its residents and its guards see is on this clock.',
            'timezone.in' => 'Choose a time zone from the list, such as America/Jamaica or America/New_York.',
            'term_months.present' => ClientPlan::TERM_REQUIRED,
            'contact_email.unique' => ClientOnboarding::CONTACT_HOLDS_AN_ACCOUNT,
        ]);

        try {
            $estate = $onboarding->start($data, $request->user());
        } catch (DomainException $refused) {
            // The service's own refusal, for an address taken between the form's check and the write.
            return back()->withErrors(['contact_email' => $refused->getMessage()])->withInput();
        }

        /*
         * AND THE CONTACT IS INVITED (DESIGN §11 row 5). The onboarding made them an `invited` Estate Console
         * account with a random password and sent nothing, so they could never get in; the checklist still said
         * "Estate admin invited". Now the invitation goes, after the client has been recorded, and the client
         * page says in the contact's panel whether it did — with Resend when it did not.
         */
        $outcome = $this->inviteContact($estate, $request, $invitations);

        // The link opens on the address whose certificate is still being issued: the office is told, as the email tells the contact.
        if ($outcome['state'] === 'sent' && $outcome['id'] !== null && InvitationSent::opensOnNewAddress(Invitation::query()->findOrFail($outcome['id']))) {
            $outcome['message'] .= ' Its link opens on '.$estate->getTenantKey().'.'.config('app.estate_domain', 'geminisl.com')
                .', which browsers trust once its certificate is issued, within five minutes; the email tells them to wait and open it again if their browser warns them first.';
        }

        return redirect()
            ->route('gemini.clients.show', ['tenant' => $estate->getTenantKey()])
            ->with('invitation_outcome', $outcome)
            /*
             * WHAT ACTUALLY HAPPENS NEXT, and it is no longer a command to
             * remember.
             *
             * This sentence used to read "Provision its database with: php
             * artisan estate:provision …", which was wrong twice over: the
             * TenantCreated pipeline has already built the database
             * synchronously by this point, and running that command afterwards
             * would fail on a duplicate tenant. It then read as an instruction
             * to run add-estate-host.sh, which was true but was a manual step
             * nothing enforced — and the one estate created before anybody
             * noticed sat unreachable behind a certificate warning until
             * somebody went looking for why.
             *
             * `gs-estate-hosts` now reconciles the certificate and nginx's
             * server_name list with the tenants table every five minutes, so the
             * honest thing to tell the operator is how long to wait rather than
             * what to type.
             */
            ->with('success', "{$estate->name} has been recorded, its database is built and its console is live at "
                .$estate->getTenantKey().'.'.config('app.estate_domain', 'geminisl.com')
                .'. Its certificate is issued automatically within five minutes — until then a browser will warn about the connection.');
    }

    /**
     * CHANGE AN ESTATE'S TIME ZONE (M19A §9) — the Gemini Director only.
     *
     * Every time is stored in UTC, so nothing stored moves; what moves is what every screen, pass, push and email SAYS
     * the time is at the estate, and what a wall-clock time typed from now on means. The client page says that before
     * the button. Audited with the zone before and after.
     */
    public function saveTimezone(Request $request, string $tenant): RedirectResponse
    {
        abort_unless($request->user()->hasRole(Role::DIRECTOR), 403, 'Only the Gemini Director can change an estate\'s time zone.');

        $estate = Tenant::query()->findOrFail($tenant);
        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ], [
            'timezone.required' => 'Choose the estate\'s time zone.',
            'timezone.in' => 'Choose a time zone from the list, such as America/Jamaica or America/New_York.',
        ]);

        $before = (string) $estate->getAttribute('timezone');
        if ($before === $data['timezone']) {
            return back()->with('success', "{$estate->name} is already on ".SiteTime::clockName($before).'.');
        }

        $estate->forceFill(['timezone' => $data['timezone']])->save();
        SiteTime::flush();

        app(AuditLogger::class)->record(
            action: 'client.timezone_changed',
            entityType: 'tenant',
            entityId: (string) $estate->getTenantKey(),
            before: ['timezone' => $before],
            after: ['timezone' => $data['timezone']],
            tenantId: (string) $estate->getTenantKey(),
        );

        return back()->with('success', "{$estate->name} is now on ".SiteTime::clockName($data['timezone']).' ('.$data['timezone'].'). Every time it shows is on that clock from now on.');
    }

    /**
     * The client page's "Send invitation" / "Resend invitation" for its contact (DESIGN §11 row 5).
     *
     * The first one for a client onboarded before invitations were sent — every one of them — and a resend of
     * the one standing otherwise, under the resend's rules: a fresh link only when the email goes, not twice in a
     * minute. What happened is drawn in the contact's panel. Behind Clients update, like every act on this page.
     */
    public function resendContactInvitation(Request $request, string $tenant, Invitations $invitations): RedirectResponse
    {
        $estate = $this->reachable($request, $tenant);

        return redirect()
            ->route('gemini.clients.show', ['tenant' => $estate->getTenantKey()])
            ->with('invitation_outcome', $this->inviteContact($estate, $request, $invitations));
    }

    /**
     * Send the contact's invitation and say what happened, for the contact's panel.
     *
     * @return array{id: int|null, state: string, message: string}
     */
    private function inviteContact(Tenant $estate, Request $request, Invitations $invitations): array
    {
        try {
            $invitation = $invitations->sendToOnboardingContact($estate, $request->user());
        } catch (InvitationNotSent $unsent) {
            return ['id' => $unsent->invitation->id, 'state' => $unsent->state(), 'message' => $unsent->getMessage()];
        } catch (DomainException $refused) {
            return ['id' => null, 'state' => 'refused', 'message' => $refused->getMessage()];
        }

        return ['id' => $invitation->id, 'state' => 'sent', 'message' => sprintf(
            // Said the moment it went: "just now" belongs to no clock (M19A §4.1); a date always has its weekday (§4.4).
            'Invitation emailed to %s just now. It stands until %s; they choose their own password when they accept it.',
            $invitation->email,
            SiteTime::local($invitation->expires_at)->format('l, F j'),
        )];
    }

    /**
     * Put an onboarding client live — the act that starts billing.
     *
     * THE BUTTON EXISTED AND THE ROUTE DID NOT. `Gemini/Clients/Show.vue` has drawn "Mark
     * onboarding complete" since the screen was built, and `ClientOnboarding::complete()` has been
     * there to serve it, with `blockedReason()` beside it written specifically so the refusal
     * could be rendered on the button. Nothing connected them: pressing it posted to
     * `/clients/{tenant}/onboarding/complete`, which was registered nowhere, and the operator got
     * the 404 page. Found on 23 September 2026 by creating a client through the console and
     * pressing the one button that finishes the job.
     *
     * The refusal is shown rather than thrown at the reader: `complete()` raises a RuntimeException
     * whose message is already a sentence meant for a person — "This client has no prepared
     * subscription plan, so there would be nothing to bill. Set the plan first.", or, to a reader
     * who may not open the plan screen, who can set it (`ClientPlan::unplannedReason()`) — so it
     * goes back to the screen as an error, not a stack trace.
     */
    public function completeOnboarding(Request $request, string $tenant, ClientOnboarding $onboarding): RedirectResponse
    {
        $estate = $this->reachable($request, $tenant);

        try {
            $onboarding->complete($estate, $request->user());
        } catch (RuntimeException $refused) {
            return back()->withErrors(['onboarding' => $refused->getMessage()]);
        }

        return redirect()
            ->route('gemini.clients.show', ['tenant' => $estate->getTenantKey()])
            ->with('success', $estate->name.' is live. Its subscription is active and billing has started.');
    }

    /**
     * Message a client's committee — board screen super-admin-11.
     */
    public function message(Request $request, string $tenant, ClientMessaging $messaging): Response
    {
        return inertia('Gemini/Clients/Message', $messaging->forEstate($this->reachable($request, $tenant)));
    }

    /** Send it. */
    public function sendMessage(Request $request, string $tenant, ClientMessaging $messaging): RedirectResponse
    {
        $estate = $this->reachable($request, $tenant);

        $messaging->send($estate, $request->user(), $request->all());

        /*
         * "DELIVERED TO THEIR CONSOLE" — the whole of what happens, and no more.
         *
         * This line read "Recorded … the platform does not deliver it" for as long as that
         * was true, which was most of the build: `ClientMessaging::send` wrote the row and
         * the audit entry and nothing else, while the screen claimed an inbox, an email and
         * a read receipt. The client ruled for the inbox (Q-M40, D-M128), so the message now
         * reaches the named recipient's own Estate Console and their notification bell.
         *
         * It still does not say "sent", because nothing is emailed and an operator told
         * "sent" stops there. Naming the console says where it went; naming the telephone
         * says what to do instead when it cannot wait.
         */
        return back()->with('success', "Delivered to {$estate->name}'s console, for the person you addressed it to. Nobody is emailed — you will see here when they open it, and if it cannot wait, telephone.");
    }

    /**
     * Why this viewer may not take on a client, or null when they may.
     *
     * The same two permissions `clients/new` is behind, in the same order, so the button and
     * the route cannot disagree. Neither sentence names a figure: it ships to readers who are
     * not shown any.
     *
     * The second asks for Billing's Price clients act (bug hunt B069; its own permission since
     * M11 B3), and names that permission rather than saying "no Billing": the Operations Manager
     * holds Billing to read, and a sentence telling them they hold none of it would be false.
     */
    private function createBlockedReason(Request $request): ?string
    {
        $user = $request->user();

        if (! $user->can('gemini.clients.create')) {
            return 'Taking on a client adds it to the platform\'s book, so it needs Clients create access. You are able to read this directory.';
        }

        if (! $user->can(ClientPlan::PRICING_PERMISSION)) {
            return 'Taking on a client puts it on a tier, which sets what it is charged, so it needs the Price clients permission on Billing & subscriptions as well. You are able to read this directory.';
        }

        return null;
    }

    /**
     * The estate this URL names, or a 404.
     *
     * 404 rather than 403 throughout: a 403 confirms the estate exists, which
     * is a fact a role scoped to its own assigned sites should not be able to
     * harvest by walking subdomains.
     */
    private function reachable(Request $request, string $tenant): Tenant
    {
        $estate = Tenant::findOrFail($tenant);

        abort_unless($request->user()->canAccessEstate($estate->getTenantKey()), 404);

        return $estate;
    }

    /**
     * The directory's query string, narrowed to what it may contain.
     *
     * Narrowed rather than validated, deliberately. These parameters shape a
     * view; they are not a submission. A hand-edited or stale `?sort=` should
     * show the directory sorted the default way, not bounce the reader back
     * where they came from with a validation error over a column name.
     *
     * The narrowing is also the safety: `sort` reaches the query as a column
     * name, so it may only ever be one of a known set, and anything else is
     * replaced rather than passed on.
     *
     * A reader who may not see billing (M10 §5) is narrowed against the shorter lists, so
     * `?sort=mrr` and `?status=at-risk` from them fall back exactly as a column that does not
     * exist would: the directory in its default order, and no filter.
     *
     * @return array{q: string, status: list<string>}
     */
    private function criteria(Request $request, bool $maySeeMoney = true): array
    {
        // `q` is the parameter the console's top-bar search posts.
        $q = $request->query('q');

        $requested = array_values(
            array_filter(Arr::wrap($request->query('status', [])), 'is_string')
        );

        return [
            'q' => is_string($q) ? trim(mb_substr($q, 0, 120)) : '',
            'status' => array_values(array_intersect($requested, ClientDirectory::statusKeys($maySeeMoney))),
        ];
    }
}
