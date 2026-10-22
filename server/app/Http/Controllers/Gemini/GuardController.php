<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Exports\Exporter;
use App\Services\Gemini\GuardWorkforce;
use App\Services\Gemini\Roster;
use App\Services\Gemini\SupervisorRegister;
use App\Services\Platform\OperatorEstates;
use App\Services\Ring\RingReport;
use App\Services\Tracking\TrackingGaps;
use App\Support\PlatformMoney;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Guard workforce — Super Admin screens 18 to 23.
 *
 * Every read is scoped by the service, not by the view, so a role restricted
 * to assigned sites cannot reach an unassigned estate's guards by searching,
 * sorting, paginating or deep-linking past the list.
 *
 * The directory's whole state — search, client, status, employment, licence,
 * sort, direction, page — lives in the query string. That is what makes the
 * list linkable, back-button correct and reproducible in a bug report, and it
 * is why nothing on screen 18 is filtered in the browser.
 */
class GuardController extends Controller
{
    public function index(Request $request, GuardWorkforce $workforce): Response
    {
        $filters = $workforce->normaliseFilters([
            'q' => $request->query('q'),
            'client' => $request->query('client'),
            'status' => $request->query('status'),
            'employment' => $request->query('employment'),
            'licence' => $request->query('licence'),
        ]);

        // M32 F5: the order, its way, the page and the page size, by the one rule every table reads.
        $roster = $workforce->roster($request->user(), $filters, GuardWorkforce::rosterTable($request));

        return inertia('Gemini/Guards/Index', [
            'guards' => $roster['rows'],

            /*
             * WHETHER EACH OFFICER'S PHONE CAN RING LIKE A CALL (the guard ring §9.2), by guard
             * id — the phone's own last report, read by the server, in a word. The officer's
             * record says when it was reported and what is off.
             */
            'callStyle' => app(RingReport::class)->forGuards(array_values(array_map(static fn (array $row): int => (int) $row['id'], $roster['rows']))),
            'table' => $roster['table'],
            'summary' => $workforce->summary($request->user()),
            // Board 22 is built, so "Add guard" is a link for whoever the form's
            // own route admits — the same gate, asked once, here.
            'canCreate' => $request->user()->can('gemini.guard_workforce.create'),
            'clients' => $workforce->clients($request->user()),
            'filters' => $filters,
            'scoped' => $request->user()->widestScope() === AccessScope::AssignedSites,
        ]);
    }

    /**
     * The Add Guard form — board screen super-admin-22.
     *
     * Everything this hands the page is a real choice the operator can make:
     * the estates they are allowed to post a guard to, that estate's own posts,
     * and the two employment types the directory can filter by. Nothing is a
     * list of labels invented for a picker.
     */
    public function create(Request $request, GuardWorkforce $workforce): Response
    {
        return inertia('Gemini/Guards/Create', [
            'clients' => $workforce->postings($request->user()),
            'employment_types' => $workforce->employmentTypes(),
        ]);
    }

    /**
     * Create the employee record.
     *
     * The whole of what may be entered, what it must satisfy and what gets
     * written to the audit log lives in the service. This method does the two
     * things a service cannot: hand it the viewer whose scope decides which
     * clients are on offer, and turn the outcome back into a redirect.
     *
     * Back to the form rather than on to the new record, and deliberately. The
     * profile screen draws no confirmation, so a redirect there would state the
     * outcome nowhere; coming back with the allocated employee number in hand
     * both confirms the write with a fact the operator could not have known and
     * leaves them where the next hire is entered.
     */
    public function store(Request $request, GuardWorkforce $workforce): RedirectResponse
    {
        $guard = $workforce->add($request->user(), $request->all());

        return back()->with('success', sprintf(
            '%s is on the books as %s. They will not appear on a roster until a device is bound to them.',
            $guard->full_name,
            $guard->employee_number,
        ));
    }

    /**
     * The PSRA licence register.
     *
     * No search here, because the board draws no search field on this topbar
     * and the register is read down rather than looked up: it is short, it is
     * ordered by urgency, and the directory next door is where a named guard is
     * found. A field the design does not have would be one more control to
     * explain rather than one fewer.
     */
    public function compliance(Request $request, GuardWorkforce $workforce): Response
    {
        // M32 F5: soonest expiry first unless the headings ask otherwise, a page at a time. The export is the whole register.
        $register = $workforce->compliancePage($request->user(), GuardWorkforce::complianceTable($request));

        return inertia('Gemini/Guards/Compliance', [
            'guards' => $register['rows'],
            'table' => $register['table'],
            'canExport' => $request->user()->can('gemini.guard_workforce.export'),
            'exportBlockedReason' => 'A licence register leaves the platform as a file naming officers and their PSRA numbers, so it needs Guard workforce export access. You are able to read this register.',
        ]);
    }

    /**
     * Who currently holds supervisor-override authority — Q-M28, D-M129. No board
     * draws this screen.
     *
     * THE RULE DID NOT CHANGE; IT BECAME READABLE. A supervisor is whoever holds
     * `gemini.guard_workforce.update`, which is what the client confirmed. The gap
     * was that nobody could list them: answering "who can start a shift at our gate
     * without the guard's fingerprint" meant reading the role matrix, working out
     * which roles carry that verb, listing each role's users and crossing off the
     * ones whose scope does not reach the estate. A permission nobody can enumerate
     * is one nobody reviews.
     *
     * ON `view`, LIKE THE COMPLIANCE REGISTER NEXT DOOR. This is a register to read,
     * not a control that changes anything — there is no write on this screen at all,
     * and a PIN is still set only by its own owner, from their own account page, with
     * no user id in the route (`OverridePinController`). Putting the register behind
     * `update` would mean only the supervisors themselves could see who the
     * supervisors are.
     */
    public function supervisors(Request $request, SupervisorRegister $register): Response
    {
        $holders = $register->holders($request->user());

        // M32 F5: the whole register ordered by the server and cut to the page; the count to act on is all of it.
        $table = SupervisorRegister::table($request);
        $page = $table->pageOf($holders);

        return inertia('Gemini/Guards/Supervisors', [
            'supervisors' => $page->items(),
            'table' => $table->meta($page),
            'attention' => count(array_filter($holders, static fn (array $row): bool => in_array($row['standing'], ['locked', 'attempts'], true))),
            'windowDays' => SupervisorRegister::WINDOW_DAYS,
            'scoped' => $request->user()->widestScope() === AccessScope::AssignedSites,

            // Their own PIN page, if they are one of the people on this list.
            'ownPinHref' => $request->user()->can(SupervisorOverride::PERMISSION) ? '/account/override-pin' : null,
        ]);
    }

    /**
     * The licence register as a file — board 20's Export (12 §1).
     *
     * CROSS-TENANT: a Director's register spans every estate on the platform,
     * so the audit entry names the estates in the file, per the ruling. A
     * site-scoped role exports its own — the scope is in `compliance()`'s query
     * and never applied to its result.
     */
    public function exportCompliance(Request $request, GuardWorkforce $workforce, Exporter $exporter): StreamedResponse
    {
        $guards = $workforce->compliance($request->user());

        $rows = array_map(static fn (array $row): array => [
            $row['name'],
            $row['psra_number'],
            $row['estate'],
            $row['expires_on'],
            $row['licence_label'],
        ], $guards);

        $tenants = array_values(array_unique(array_column($guards, 'estate')));
        sort($tenants);

        return $exporter->csv(
            scope: 'PSRA licence register, every officer this role can see',
            headers: ['Officer', 'PSRA number', 'Estate', 'Licence expires', 'Standing'],
            rows: $rows,
            filename: 'licence-register-'.now()->format('Y-m-d').'.csv',
            tenants: $tenants,
        );
    }

    public function show(Request $request, Guard $guard, GuardWorkforce $workforce): Response
    {
        /*
         * 404 rather than 403. A 403 confirms this guard exists and is posted
         * at an estate the viewer cannot see, which is more than a
         * site-restricted role should be able to learn.
         */
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $guard->load(['post', 'estate']);

        return inertia('Gemini/Guards/Show', [
            // The payslip gross is Payroll & Accounting's figure, not the roster's (M10 §5).
            'guard' => $workforce->profile($guard, PlatformMoney::maySeePay($request->user())),
            'history' => $workforce->deploymentHistory($guard),

            // Whether this officer's phone can ring like a call, each check ON or OFF, and when it said so (the guard ring §9.2).
            'callStyle' => app(RingReport::class)->forGuard($guard),

            /*
             * THE OFFICER'S SHIFTS (26 Sep, C5: "how would the admin know what the time schedule of that guard is"). The
             * one running now and the next fourteen days, on each estate's clock, with whether they clocked in.
             */
            'shifts' => Shift::query()
                ->with('post')
                ->where('guard_id', $guard->id)
                ->where('rostered_end', '>=', now())
                ->where('rostered_start', '<=', now()->addDays(14))
                ->whereNotIn('status', Shift::SETTLED)
                ->orderBy('rostered_start')
                ->limit(40)
                ->get()
                ->map(static fn (Shift $shift): array => [
                    'id' => $shift->id,
                    'post' => $shift->postLabel(),
                    'day' => $shift->rostered_start->format('D j M'),
                    'window' => $shift->rostered_start->format('g:i A').' – '.$shift->rostered_end->format('g:i A').SiteTime::label($shift->rostered_end),
                    'state' => $shift->actual_start !== null && $shift->actual_end === null ? 'On duty now'
                        : ($shift->rostered_start->isPast() ? 'Running, not clocked in' : 'Rostered'),
                    'on_duty' => $shift->actual_start !== null && $shift->actual_end === null,
                ])
                ->all(),

            // Whether positions were recorded through each recent shift, and when they were not (M22 §1.1).
            'positions' => $this->recentPositions($guard),

            // The positions held about this officer open from here for a role holding the live map's permission (12 Oct).
            'canSeeTrack' => Gate::allows('gemini.dispatch.view'),

            /*
             * Where this officer could be moved (12 §2, Wave 5), narrowed to
             * what this viewer may reach. A site-scoped role offering a client
             * they cannot see would be a picker that leaks the client list.
             */
            'clientOptions' => $this->clientOptions($request),
            'postOptions' => $this->postOptions($request),
            'canAct' => Gate::allows('gemini.guard_workforce.update'),
            'actBlockedReason' => 'Recording a renewal or moving an officer changes where they may stand, so it needs Guard workforce update access. You are able to read this record.',

            // A handset waiting to replace the bound one (13 D1).
            'rebindRequests' => DB::connection('mysql')->table('device_rebind_requests')
                ->where('guard_id', $guard->id)
                ->where('status', 'pending')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $r): array => [
                    'id' => (int) $r->id,
                    'label' => $r->label,
                    'platform' => $r->platform === 'ios' ? 'iPhone' : 'Android handset',

                    // Stored as a true UTC instant; read on the clock of the estate this officer
                    // stands at, because that is the clock the person asking was looking at (M4 §3.1).
                    'requested_at' => SiteTime::stamp(
                        Carbon::parse((string) $r->created_at, 'UTC'),
                        (string) $guard->tenant_id,
                        'M j, g:i A',
                    ),
                ])
                ->all(),
        ]);
    }

    /**
     * THE SHIFT RECORD, FOR POSITIONS (M22 §1.1). For each shift this officer clocked in to over the last week, newest
     * first: whether positions were recorded throughout, and every gap when they were not — a phone off, killed, signed
     * out, or its sharing stopped by itself. Judged on capture times, so a spell out of signal whose positions arrived
     * later is not a gap. Where the officer did not agree to share, the record says that instead. Never where they were.
     *
     * @return list<array{id: int, day: string, window: string, post: string, summary: string, gaps: list<string>, gap: bool}>
     */
    private function recentPositions(Guard $guard): array
    {
        $shifts = Shift::query()->with('post')->where('guard_id', $guard->id)->whereNotNull('actual_start')
            ->where('actual_start', '>=', now()->subDays(7))->orderByDesc('actual_start')->limit(8)->get();
        // Three reads for all of them (the page has a query budget).
        $records = app(TrackingGaps::class)->onShifts($shifts);

        return $shifts
            ->map(static function (Shift $shift) use ($records): array {
                $record = $records[(int) $shift->id];
                $lines = array_map(static fn (array $gap): string => TrackingGaps::line($gap, (string) $shift->tenant_id), $record['gaps']);
                $until = $shift->actual_end === null ? 'on duty now' : $shift->actual_end->format('g:i A').SiteTime::label($shift->actual_end);

                return [
                    'id' => (int) $shift->id,
                    'day' => $shift->actual_start->format('D j M'),
                    'window' => $shift->actual_start->format('g:i A').' – '.$until,
                    'post' => $shift->post?->label() ?? 'Post',
                    'summary' => match (true) {
                        ! $record['shared'] => 'Position not shared: the officer has not agreed to the current location policy',
                        $record['count'] === 0 => 'No position recorded on this shift',
                        $lines === [] => sprintf('Recorded throughout · %d position%s', $record['count'], $record['count'] === 1 ? '' : 's'),
                        default => sprintf('%d gap%s · %d position%s recorded', count($lines), count($lines) === 1 ? '' : 's', $record['count'], $record['count'] === 1 ? '' : 's'),
                    },
                    'gaps' => $lines,
                    'gap' => $record['shared'] && ($lines !== [] || $record['count'] === 0),
                ];
            })->all();
    }

    /**
     * Approve or refuse a handset rebind (13 D1). The decision is recorded against
     * the officer's current or next shift; see `DeviceEnrolment::decideRebind()`.
     */
    public function decideRebind(Request $request, Guard $guard, int $rebind, string $decision, DeviceEnrolment $enrolment): RedirectResponse
    {
        abort_unless($guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id), 404);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:190']]);

        abort_unless(DB::connection('mysql')->table('device_rebind_requests')->where('id', $rebind)->where('guard_id', $guard->id)->exists(), 404);

        if ($decision === 'deny' && trim((string) ($data['note'] ?? '')) === '') {
            return back()->withErrors(['note' => 'Say why the handset is refused. The officer is shown it.']);
        }

        try {
            $enrolment->decideRebind($rebind, $decision === 'approve', $request->user(), $data['note'] ?? null);
        } catch (DomainException $refused) {
            return back()->withErrors(['note' => $refused->getMessage()]);
        }

        return back()->with('success', $decision === 'approve'
            ? 'Handset approved for '.$guard->full_name.'. It is bound when the officer\'s app collects its token, and the old handset stops working then.'
            : 'Handset refused. The officer\'s app is told why.');
    }

    /**
     * Issue the one-time code that puts an officer's phone on the Guard App.
     *
     * THIS HAD NO WAY IN AT ALL. `DeviceEnrolment::issueCode()` has existed since the guard app
     * was built and nothing in the console called it: the workforce screens could create a guard,
     * suspend one, renew a licence, reassign and decide a rebind, but the code an officer types
     * into the app on their first day could only be produced by somebody with a shell on the
     * server. A security company cannot onboard its own guards from its own console.
     *
     * THE CODE IS SHOWN ONCE, ON THE SCREEN, AND NOWHERE ELSE. Only its hash is stored
     * (`DeviceEnrolment::issueCode`), so it cannot be looked up again — the operator reads it to
     * the officer or writes it down, and issues another if it is lost. It is grouped for reading
     * aloud over a telephone ("4F7K-9QXM") because that is how it is actually delivered.
     *
     * `update` and not `create`: the officer already exists, and this is the same authority that
     * decides their handset rebind two methods above.
     */
    public function issueEnrolmentCode(Request $request, Guard $guard, DeviceEnrolment $enrolment): RedirectResponse
    {
        abort_unless($guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id), 404);

        try {
            $code = $enrolment->issueCode($guard, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['enrolment' => $refused->getMessage()]);
        }

        return back()->with('enrolment_code', [
            'code' => $code,
            'guard' => $guard->full_name,
            'hours' => DeviceEnrolment::CODE_HOURS,
        ]);
    }

    /**
     * The clients this viewer may post an officer to.
     *
     * @return list<array{id: string, name: string}>
     */
    private function clientOptions(Request $request): array
    {
        $user = $request->user();

        /*
         * `Tenant` is stancl's model with a string key and a JSON data column,
         * so its collection is not generically typed the way the application's
         * own models are. Read as plain rows: the two fields this picker needs
         * are columns, not virtual attributes.
         */
        return DB::connection('mysql')
            ->table('tenants')
            /*
             * Scope AND the demonstration estate (M5 §3). This picker is how a
             * real officer is posted to a client site, and the demonstration
             * estate is not a client: an officer posted there would be on
             * Gemini's payroll standing a gate nobody owns.
             */
            ->tap(static fn ($query) => OperatorEstates::narrow($query, $user, 'id'))
            ->orderBy('name')
            ->select('id', 'name')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * The posts those clients have, each carrying its client so the screen can
     * narrow one picker by the other without a second request.
     *
     * @return list<array{id: int, name: string, tenant_id: string}>
     */
    private function postOptions(Request $request): array
    {
        $user = $request->user();

        return Post::query()
            ->where('is_active', true)
            ->when(
                $user->widestScope() === AccessScope::AssignedSites,
                static fn ($query) => $query->whereIn('tenant_id', $user->accessibleEstateIds()),
            )
            ->orderBy('name')
            ->get()
            ->map(static fn (Post $post): array => [
                'id' => $post->id,
                'name' => $post->label(),
                'tenant_id' => (string) $post->tenant_id,
            ])
            ->all();
    }

    /**
     * The compliance action screen — board 21, pinned to one open matter.
     *
     * A READ behind `view`, not behind `update`, and deliberately. The register
     * next door offers "Take action" to everyone who may see it, and sending
     * half of them to a 403 for following their own screen's link would be the
     * silent dead end this console is built to avoid. So the page opens, the
     * case is legible, and the ability to ACT arrives as `can_act` — the write
     * itself is gated at the route, twice over.
     */
    public function complianceAction(Request $request, Guard $guard, GuardWorkforce $workforce): Response
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $guard->load(['post', 'estate']);

        return inertia('Gemini/Guards/ComplianceAction', [
            'action' => $workforce->complianceAction($guard),
            'can_act' => Gate::allows('gemini.guard_workforce.update'),

            // How many shifts releasing would actually open (12 §2, Wave 5).
            // A control that says "post open shifts" for an officer holding
            // none would be offering to do nothing.
            'future_shifts' => app(Roster::class)->futureShiftCount($guard),
        ]);
    }

    /**
     * Record a renewed PSRA licence — boards 20 and 21 (12 §2, Wave 5).
     *
     * A RENEWAL IS A NEW EXPIRY DATE READ OFF THE CERTIFICATE, which is what
     * the old reason asked for. Everything that decides whether it may be
     * recorded is in the service; this refuses a guard the viewer cannot see.
     */
    public function renewLicence(Request $request, Guard $guard, GuardWorkforce $workforce): RedirectResponse
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $data = $request->validate([
            'psra_expires_on' => ['required', 'date'],
            'psra_number' => ['required', 'string', 'max:40'],
        ]);

        try {
            $renewed = $workforce->markLicenceRenewed($guard, $data['psra_expires_on'], $data['psra_number'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['psra_expires_on' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', sprintf(
            '%s\'s licence is recorded to %s.%s',
            $renewed->full_name,
            $renewed->psra_expires_on->format('M j, Y'),
            $renewed->status === 'suspended'
                ? ' They remain suspended — that was a decision somebody took, and lifting it is its own.'
                : '',
        ));
    }

    /**
     * Release an officer's future shifts back to open — boards 20 and 21.
     *
     * FUTURE ONLY. A shift already worked is evidence that a post was covered,
     * and clearing its officer would erase who covered it.
     */
    public function releaseShifts(Request $request, Guard $guard, Roster $roster): RedirectResponse
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $data = $request->validate(['reason' => ['required', 'string', 'max:190']]);

        try {
            $count = $roster->releaseFutureShifts($guard, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()]);
        }

        return back()->with('success', $count === 0
            ? $guard->full_name.' holds no future shifts, so there was nothing to open.'
            : $count.' shift(s) are now open on the rota, with the reason on each so a dispatcher knows why.');
    }

    /** Move an officer to another client — board 20's "Reassign client". */
    public function reassign(Request $request, Guard $guard, GuardWorkforce $workforce, Roster $roster): RedirectResponse
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $data = $request->validate([
            'tenant_id' => ['nullable', 'string', 'max:64'],
            'post_id' => ['nullable', 'integer'],
        ]);

        $tenantId = ($data['tenant_id'] ?? '') === '' ? null : $data['tenant_id'];

        if ($tenantId !== null && ! $request->user()->canAccessEstate($tenantId)) {
            return back()->withErrors(['tenant_id' => 'That client is not one this role can post an officer to.']);
        }

        try {
            // What actually happened, in the service's words (bug hunt B009): this said "any
            // future shifts at the previous client are open on the rota" after every move,
            // including one between two posts at one client, where none was opened.
            $done = $workforce->reassign($guard, $tenantId, $data['post_id'] ?? null, $request->user(), $roster);
        } catch (DomainException $refused) {
            return back()->withErrors(['tenant_id' => $refused->getMessage()]);
        }

        return back()->with('success', $done);
    }

    /**
     * Suspend a guard from active duty over an open compliance matter.
     *
     * Everything that decides whether this may happen, what changes and what is
     * written to the audit log lives in the service. The controller's whole job
     * is the one thing a service cannot do: refuse a guard this viewer's role
     * cannot see, and turn the outcome back into a redirect.
     */
    public function suspend(Request $request, Guard $guard, GuardWorkforce $workforce): RedirectResponse
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $guard->load(['post', 'estate']);

        return back()->with('success', $workforce->suspendFromDuty($guard));
    }
}
