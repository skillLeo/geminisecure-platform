<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Account;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Bill;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Unit;
use App\Models\Estate\Vendor;
use App\Rules\PersonName;
use App\Services\Estate\Amenities;
use App\Services\Estate\Ledger;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Payables;
use App\Support\SiteTime;
use App\Support\SourceBadge;
use Brick\Money\Money;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;
use Throwable;

/**
 * Maintenance and amenities — board screens community-admin-17, 18, 19 and 20,
 * and the booking detail that no board draws (D-086).
 *
 * NOT ONE FIGURE ON THESE FOUR SCREENS IS A RESIDENT'S FINANCIAL POSITION, and
 * that is a platform invariant rather than a design choice. The persona on every
 * one of these boards is the Property Manager, who holds `facilities` in full
 * and holds `-` on `dues_ledger`, `payments` and `accounting_posting` — D-010
 * split those modules precisely so the person who commissions the work could see
 * what a job costs without ever reaching what a household owes. So no payload
 * below carries a balance, a bucket, an arrears age or a days-overdue figure;
 * the arrears rule on board 19 reaches this controller as a boolean and as
 * nothing else, and `EstateFacilitiesMaintenanceTest` and
 * `EstateFacilitiesAmenitiesTest` walk these payloads to prove it.
 *
 * OVERDUE IS NEVER READ FROM A COLUMN. Board 17's red chip, its Overdue tile and
 * every Overdue badge in its table come from the same arithmetic — the time
 * reported plus the service target for the priority — computed at draw time.
 * A ticket that sat unassigned for a week is overdue, and there is no stored
 * flag that could have been left saying otherwise.
 *
 * CHARGING A BOOKING FEE NEEDS TWO GATES AND THE SECOND IS NOT `facilities`.
 * Recording the fee posts a charge against a unit — Dr 1200, Cr 4100 — which
 * adds to what a household owes, and that is `estate.dues_ledger.create` wherever
 * it happens. The route carries both; the Property Manager holds the first and
 * not the second, so board 19's "record a fee" is inert for them with the reason
 * on it. The board names it among their actions and the invariant outranks the
 * board.
 *
 * THE BOOKING DETAIL IS THE ONE SCREEN HERE NO BOARD DRAWS (D-086). The client
 * ruled that a service moving money with no route is a worse gap than the
 * caution it replaced, and put the deposit door on it: Facilities `update`
 * records a deposit received or refunded, Facilities `approve` forfeits one —
 * keeping a resident's money is the higher verb and carries a reason. No
 * accounting gate sits beside them, deliberately: the ruling made this the
 * Property Manager's screen, and D-010 locks that role out of Accounting.
 */
class FacilitiesController extends Controller
{
    /**
     * Why the controls on these screens that do nothing, do nothing.
     *
     * Each is a real act with a consequence outside the screen it sits on, and
     * each needs a form, a thread or a table this phase has not built.
     */
    private const NO_VENDORS_TAB_YET = 'The supplier register is the Accounting module\'s screen and needs Accounting view access, which this role does not hold. It is the same register these tickets are assigned from.';

    /** The maintenance queue — board community-admin-17. */
    public function maintenance(Request $request, Maintenance $maintenance): Response
    {
        // M32 F5: the chip, and a page of its tickets in the order the headings ask for, with the queue's search.
        $queue = $maintenance->queueBoard($request->string('filter')->toString(), null, Maintenance::queueTable($request));

        return inertia('Estate/Facilities/Maintenance', [
            'estate' => ['name' => (string) tenant()->name],
            ...$queue,

            // Category B (13 C3): residents report tickets from the Resident App (13 D3).
            'sourceBadge' => SourceBadge::resident(SourceBadge::anySimulatedIn('tenant', 'maintenance_tickets', array_column($queue['rows'], 'id'))),

            /*
             * The ladder the queue can be triaged against. Board 17's own
             * `blockedReason` names triage, and triage on a list screen is the
             * priority — the row's one act. Sent as {key: label} so the page
             * never spells the enum out itself and a fourth rung would appear
             * on both screens at once.
             */
            'priorities' => MaintenanceTicket::PRIORITY_LABELS,
            // This estate's service targets, hours by priority (M98), and whether this reader may set them.
            'targets' => $maintenance->targets(),
            'longestTarget' => Maintenance::LONGEST_TARGET_HOURS,
            'canConfigure' => $request->user()->can('estate.facilities.configure'),
            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'canCreate' => $request->user()->can('estate.facilities.create'),
            // The Vendors tab is the supplier register, which is Accounting's
            // screen behind Accounting's gate. A link for whoever holds it; the
            // inert twin and `reasons.vendors` for whoever does not (D-010).
            'canViewVendors' => $request->user()->can('estate.accounting_posting.view'),
            // One line, the fact, nothing about permissions (M53 Laws 1 and 2; COPY_REWRITES.md §1).
            'blockedReason' => 'Changing a ticket is not part of your role.',
            'createReason' => 'Raising a work order is not part of your role.',
            'reasons' => [
                'vendors' => self::NO_VENDORS_TAB_YET,
            ],

            /*
             * What a work order is filed against: the estate's units, by reference.
             * Names only — a work order names a place, never a balance.
             *
             * THE ONES THE OFFICE SEARCHED FOR, NOT ALL OF THEM (M11 A2). This used
             * to send every lot on the estate — 2,000 at Phoenix Park, 97 KB of the
             * page — to fill one dropdown. The form now asks for the lot it wants,
             * `?unit_q=47`, and is sent at most `UNIT_MATCHES` of them; the queue
             * page itself carries none.
             */
            'units' => $this->unitMatches($request->string('unit_q')->toString()),
            'unitQuery' => $request->string('unit_q')->toString(),
            'unitMatchLimit' => self::UNIT_MATCHES,
            // How far back "Reported at" may go, for the field's own bound (FAC-12).
            'reportedWithinDays' => self::REPORTED_WITHIN_DAYS,
            // The one list, keys and their words (FAC-6), the console's form and the phones alike.
            'categories' => collect(MaintenanceTicket::CATEGORIES)
                ->map(static fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    /** How many lots a work order's unit search offers at once (M11 A2). */
    public const UNIT_MATCHES = 20;

    /**
     * The lots a typed search names — by reference or phase — best match first.
     *
     * "47" is typed as often as "Lot 47", and both mean Lot 47 before Lot 147 or
     * Lot 1047: an exact reference first, then the shortest reference, then in
     * order. Nothing is read for an empty search.
     *
     * "047" MEANS LOT 47 TOO (FAC-13). A lot is written with leading zeros on a
     * gate pass and a cheque; read as text, "047" found Lot 1047 alone, which the
     * form then chose by itself. The zeros go before the search.
     *
     * @return list<array{id: int, label: string}>
     */
    private function unitMatches(string $search): array
    {
        $search = (string) preg_replace('/^(lot\s*)?0+(?=\d)/i', '$1', trim($search));

        if ($search === '') {
            return [];
        }

        $like = '%'.$search.'%';

        return Unit::query()
            ->where(static fn ($where) => $where->where('reference', 'like', $like)->orWhere('block', 'like', $like))
            ->orderByRaw('(reference = ? OR reference = ?) DESC', [$search, 'Lot '.$search])
            ->orderByRaw('CHAR_LENGTH(reference)')
            ->orderBy('reference')
            ->limit(self::UNIT_MATCHES)
            ->get(['id', 'reference', 'block'])
            ->map(static fn (Unit $unit): array => ['id' => $unit->id, 'label' => $unit->reference.($unit->block === null ? '' : ' · '.$unit->block)])
            ->all();
    }

    /**
     * Raise a work order from the office — board 17's "New work order" (12 §2, Wave 2).
     *
     * THE CLOCK STARTS AT THE REPORT, which defaults to now and may be earlier:
     * a manager keying in a call taken on Saturday is recording a ticket that
     * is already two days old, and the SLA runs from then. Location, category
     * and priority are chosen here, deliberately, because each of them decides
     * something — where a vendor goes, what kind, and by when.
     */
    public function raiseWorkOrder(Request $request, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'location' => ['required', 'string', 'max:160'],
            'unit_id' => ['nullable', 'integer'],
            // One of the one list (FAC-6), in any case: never "security", which is not a maintenance job (FAC-4).
            'category' => ['required', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! array_key_exists(mb_strtolower(trim((string) $value)), MaintenanceTicket::CATEGORIES)) {
                    $fail('Choose a category from the list.');
                }
            }],
            'priority' => ['required', 'string', 'in:high,medium,low'],
            'description' => ['nullable', 'string', 'max:2000'],

            // On the ESTATE'S clock, not UTC's (B050) — see `notLaterThanNow()`; and within the month (FAC-12).
            'reported_at' => ['nullable', 'date', self::notLaterThanNow(), self::notLongerAgoThan(self::REPORTED_WITHIN_DAYS)],
        ]);

        $unit = isset($data['unit_id']) ? Unit::query()->find($data['unit_id']) : null;

        if (isset($data['unit_id']) && $unit === null) {
            return back()->withErrors(['unit_id' => 'That unit is not in this estate.'])->withInput();
        }

        try {
            $ticket = $maintenance->report(
                title: $data['title'],
                locationLabel: $data['location'],
                priority: $data['priority'],
                unit: $unit,
                category: $data['category'],
                description: $data['description'] ?? null,
                reportedAt: $data['reported_at'] ?? null,
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['title' => $refused->getMessage()])->withInput();
        }

        // Printed on the estate's clock: the stored moment is UTC, and its own
        // digits read five hours late in Kingston.
        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', 'Work order #'.$ticket->number.' raised. The service clock is running from '.SiteTime::fmt(SiteTime::local($ticket->reported_at), 'M j, g:i A').'.');
    }

    /**
     * THE ESTATE'S SERVICE TARGETS (M98): how many hours a ticket of each priority has before it is overdue.
     *
     * `configure`, as every other setting an estate decides is; the Community Super Admin and the Property Manager hold
     * it on Facilities. Each refusal is said under the field it is about; a ticket already raised keeps its own target.
     */
    public function saveTargets(Request $request, Maintenance $maintenance): RedirectResponse
    {
        $longest = Maintenance::LONGEST_TARGET_HOURS;
        $messages = [];

        foreach (['high', 'medium', 'low'] as $field) {
            $messages[$field.'.required'] = 'Enter the hours.';
            $messages[$field.'.integer'] = 'Enter whole hours.';
            $messages[$field.'.min'] = 'At least 1 hour.';
            $messages[$field.'.max'] = "At most {$longest} hours (30 days).";
        }

        $data = $request->validate([
            'high' => ['required', 'integer', 'min:1', 'max:'.$longest],
            'medium' => ['required', 'integer', 'min:1', 'max:'.$longest],
            'low' => ['required', 'integer', 'min:1', 'max:'.$longest],
        ], $messages);

        $hours = array_map('intval', $data);

        // The ladder, under the rung that breaks it.
        if ($hours['high'] > $hours['medium']) {
            return back()->withErrors(['high' => 'High must be no longer than Medium.'])->withInput();
        }

        if ($hours['medium'] > $hours['low']) {
            return back()->withErrors(['medium' => 'Medium must be no longer than Low.'])->withInput();
        }

        try {
            $maintenance->setTargets($hours, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['high' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', 'Service targets saved. New tickets take them; tickets already raised keep their own.');
    }

    /**
     * "Not later than now" for a time the office typed, judged on the estate's clock (B050).
     *
     * A datetime-local field sends digits with no offset, and those digits are
     * the ESTATE'S wall clock (M4 §3.1): `SiteTime::parse()` is how the service
     * reads them. `before_or_equal:now` read the same digits as UTC, and Kingston
     * is five hours behind — so at 10:00 there a manager could type 14:30, the
     * rule saw a time half an hour gone, and the ticket was filed four and a half
     * hours in the future. This reads the value exactly as the service will and
     * compares moments. A value that is not a date at all is `date`'s to report.
     */
    private static function notLaterThanNow(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $moment = self::typedMoment($value);

            if ($moment !== null && $moment->greaterThan(Carbon::now())) {
                $fail(sprintf(
                    '%s has not happened yet: it is %s on the estate\'s clock. A report is dated when it was made.',
                    SiteTime::fmt(SiteTime::local($moment), 'M j, g:i A'),
                    SiteTime::fmt(SiteTime::now(), 'M j, g:i A'),
                ));
            }
        };
    }

    /** How far back the office may date a report it keys in (FAC-12): a month's paper backlog, not a year's. */
    public const REPORTED_WITHIN_DAYS = 30;

    /**
     * "Not further back than this" for a report the office typed, on the estate's clock (FAC-12).
     *
     * The call taken on Saturday and keyed on Monday is the point of the field. 1 January 2020 was taken, and the
     * ticket printed it with no year, as if it were this January, five years overdue on arrival. Counted from the
     * start of the day that many days back on the estate's calendar, as the page's field counts it.
     */
    private static function notLongerAgoThan(int $days): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($days): void {
            $moment = self::typedMoment($value);

            if ($moment !== null && $moment->lessThan(SiteTime::now()->subDays($days)->startOfDay())) {
                $fail(sprintf(
                    'A report is keyed within %d days of being made. %s is further back than that.',
                    $days,
                    SiteTime::local($moment)->format('j M Y, g:i A'),
                ));
            }
        };
    }

    /**
     * "Not after today" for a date the office typed, judged on the estate's calendar (B050).
     *
     * `before_or_equal:today` is UTC's today, which from 7 PM in Kingston is
     * already tomorrow — so a deposit could be recorded as received or returned
     * on a day that had not begun on the estate. The day is read the way the
     * service reads it, and compared with the estate's own today.
     */
    private static function notAfterToday(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $moment = self::typedMoment($value);

            if ($moment === null) {
                return;
            }

            $day = SiteTime::local($moment)->toDateString();
            $today = SiteTime::today()->toDateString();

            if ($day > $today) {
                $fail(sprintf(
                    '%s has not begun yet: today on the estate\'s calendar is %s.',
                    SiteTime::local($moment)->format('M j, Y'),
                    SiteTime::today()->format('M j, Y'),
                ));
            }
        };
    }

    /** What the office typed, as the UTC moment the service will store — or null when it is not a time at all. */
    private static function typedMoment(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return SiteTime::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** One ticket end to end — board community-admin-18. */
    public function ticket(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): Response
    {
        /*
         * The register this ticket can be assigned from: the suppliers that take maintenance work, never a utility
         * (DR-035, `Vendor::takesMaintenanceWork()`). Names only — a vendor's TRN and what it is owed are the Accounting
         * module's, and this screen has no business carrying either. Read once, for Assign and for Record a bill.
         */
        $vendors = Vendor::query()
            ->takesMaintenanceWork()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Vendor $vendor): array => ['id' => $vendor->id, 'name' => $vendor->name])
            ->all();

        return inertia('Estate/Facilities/Ticket', [
            'estate' => ['name' => (string) tenant()->name],
            ...$maintenance->ticketBoard($ticket),
            'sourceBadge' => SourceBadge::resident($ticket->is_simulated),

            // The photos the resident attached from the Resident App, oldest first (7 Oct: they reached the server and
            // were shown nowhere). Each is served by `ticketPhoto` below, to this page's own readers and nobody else.
            'photos' => $this->photosOf($ticket),

            // The visit window is typed on the ESTATE's clock (26 Sep): the form says so, and what time it is there now.
            'estateNow' => SiteTime::now()->format('g:i A, D j M').SiteTime::label(SiteTime::now()),

            'vendors' => $vendors,
            'priorities' => MaintenanceTicket::PRIORITY_LABELS,
            // This estate's service targets (M98), for what the priority modal says each priority means.
            'targets' => $maintenance->targets(),

            /*
             * WHAT THE JOB HAS COST (M98, cost capture): the bills recorded against this work order, for whoever holds
             * Vendor costs. D-010 split that module from Accounting so the Property Manager sees what the work they
             * commission costs and never what a household owes; a supplier's bill is the estate's spending, not a
             * resident's position. Null where the reader does not hold it, and the section is not drawn (M96 level 3).
             */
            'costs' => $request->user()->can('estate.vendor_costs.view') ? $this->costsOf($ticket) : null,

            // Recording one is a bill against this job, a draft that posts nothing, behind the gate Bills & payments asks.
            'costForm' => $request->user()->can('estate.accounting_posting.create') ? $this->costForm($ticket, $vendors) : null,

            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'blockedReason' => 'Changing this ticket is not part of your role.',
            // A message is posted on the ticket's own thread (M53 §4.10): the resident reads it in the app, under the
            // status timeline, and is told by a notification. Only a ticket a resident reported has anybody to tell.
            'reasons' => [
                'message' => ! $request->user()->can('estate.facilities.update')
                    ? 'This section is not part of your role.'
                    : ($ticket->unit_id === null ? 'Only a request a resident reported has a resident to message.' : null),
            ],
        ]);
    }

    /**
     * The bills recorded against this work order, newest first, and what they come to (M98). A bill names its job by the
     * ticket's NUMBER (`bills.ticket_id`), as Bills & payments records it. A void bill is listed and not counted.
     *
     * @return array{rows: list<array<string, mixed>>, total_minor: int, currency: string}
     */
    private function costsOf(MaintenanceTicket $ticket): array
    {
        // One read, the supplier's name joined in: a page's reads stay the same at any number of bills (the scale budgets).
        $bills = Bill::query()
            ->join('vendors', 'vendors.id', '=', 'bills.vendor_id')
            ->where('bills.ticket_id', $ticket->number)
            ->orderByDesc('bills.id')
            ->get(['bills.*', 'vendors.name as supplier_name']);

        return [
            'rows' => $bills->map(static function (Bill $bill): array {
                $state = $bill->boardStatus();

                return [
                    'id' => $bill->id,
                    'reference' => $bill->reference,
                    'supplier' => (string) $bill->getAttribute('supplier_name'),
                    'description' => $bill->description,
                    'amount_minor' => (int) $bill->amount_minor,
                    'status' => $state,
                    'status_label' => Payables::STATUS_LABELS[$state],
                    'due_on' => $bill->due_on->format('M j, Y'),
                ];
            })->values()->all(),
            'total_minor' => (int) $bills->where('status', '!=', Bill::VOID)->sum('amount_minor'),
            'currency' => 'JMD',
        ];
    }

    /**
     * What Record a bill needs on a work order (M98): the suppliers that take maintenance work (the same list Assign
     * offers, read once for both), the active expense accounts with Maintenance and Repairs chosen where the chart has
     * it, the job's own label as what the bill is for, and the estate's today for the due date (B106: never the browser's
     * UTC date).
     *
     * @param  list<array{id: int, name: string}>  $suppliers
     * @return array<string, mixed>
     */
    private function costForm(MaintenanceTicket $ticket, array $suppliers): array
    {
        $accounts = Account::query()
            ->where('type', Account::EXPENSE)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name']);

        return [
            'today' => Ledger::today()->toDateString(),
            'suppliers' => $suppliers,
            'accounts' => $accounts
                ->map(static fn (Account $account): array => ['code' => $account->code, 'label' => $account->optionLabel()])
                ->all(),
            'account' => $accounts->firstWhere('code', '5100')->code ?? $accounts->first()?->code,
            'description' => $ticket->label(),
        ];
    }

    /** "Message resident" (M53 §4.10): a line on the ticket's thread, from this person, and a notification to the household. */
    public function messageResident(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:500']], ['body.required' => 'Write the message first.']);

        try {
            $maintenance->messageResident($ticket, (string) $data['body'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['body' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', 'Message sent. The resident sees it on this request in the app.');
    }

    /**
     * ONE PHOTO A RESIDENT ATTACHED TO THIS TICKET, to a reader of the ticket page.
     *
     * The file is on the estate's private disk (`CommunityController::ticketMedia` wrote it there) and has no public
     * address: it is reached through this route, behind `estate.facilities.view` like the page itself, and only as a
     * photo of THIS ticket — another ticket's id, or one from another estate, is the same 404. Shown inline, never
     * guessed at: the type is the one detected when it arrived, and `nosniff` keeps the browser to it.
     */
    public function ticketPhoto(MaintenanceTicket $ticket, int $photo): FileResponse
    {
        $row = DB::connection('tenant')->table('ticket_media')
            ->where('id', $photo)
            ->where('maintenance_ticket_id', $ticket->id)
            ->first();

        abort_if($row === null || ! Storage::disk('local')->exists((string) $row->path), 404);

        return Storage::disk('local')->response((string) $row->path, (string) $row->filename, [
            'Content-Type' => (string) $row->content_type,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * @return list<array{id: int, url: string, filename: string, is_image: bool, is_video: bool, added: string}>
     */
    private function photosOf(MaintenanceTicket $ticket): array
    {
        return DB::connection('tenant')->table('ticket_media')
            ->where('maintenance_ticket_id', $ticket->id)
            ->orderBy('id')
            ->get(['id', 'filename', 'content_type', 'created_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'url' => $this->path('/facilities/maintenance/'.$ticket->number.'/photos/'.(int) $row->id),
                'filename' => (string) $row->filename,
                // HEIC is a photo no desktop browser draws: it is offered as a file to open, not a broken picture.
                'is_image' => in_array((string) $row->content_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true),
                'is_video' => str_starts_with((string) $row->content_type, 'video/'),
                'added' => SiteTime::fmt(SiteTime::local(Carbon::parse((string) $row->created_at)), 'g:i A, D j M'),
            ])
            ->values()
            ->all();
    }

    /**
     * `/facilities/amenities` is a parent address (FAC-17). It held only the POST that adds an amenity, so a GET
     * answered a raw 405; it now goes to the module's first tab, the booking diary.
     */
    public function amenitiesIndex(): RedirectResponse
    {
        return redirect()->to($this->path('/facilities/amenities/bookings'));
    }

    /** The booking diary — board community-admin-19. */
    public function bookings(Request $request, Amenities $amenities): Response
    {
        // The whole diary, a page at a time, in the order its headings ask for (M32 F5).
        $diary = $amenities->bookingsBoard(
            $request->string('amenity')->toString(),
            table: Amenities::bookingsTable($request),
            // The bookings waiting on a decision, from the amber chip (AME-2).
            waiting: $request->boolean('waiting'),
        );

        return inertia('Estate/Facilities/Bookings', [
            'estate' => ['name' => (string) tenant()->name],
            ...$diary,

            // Category B (13 C3): residents book from the Resident App (13 D3).
            'sourceBadge' => SourceBadge::resident(SourceBadge::anySimulatedIn('tenant', 'amenity_bookings', array_column($diary['rows'], 'id'))),
            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'canCreate' => $request->user()->can('estate.facilities.create'),
            'canViewVendors' => $request->user()->can('estate.accounting_posting.view'),

            /*
             * BOTH GATES, because the route carries both.
             *
             * The fee is a charge on a unit, so it needs `dues_ledger.create` —
             * and it is still a facilities act, so it needs `facilities.update`
             * beside it. Testing only the ledger half would draw the control
             * live for a Treasurer, who holds Dues & ledger in full and
             * Facilities as VIEW, and the POST behind that live button would be
             * refused by the route. A screen that offers an act its own route
             * will not accept is worse than one that explains itself: the
             * person clicking it has no way to know which of the two gates they
             * are missing, and the estate's answer arrives as a 403.
             */
            'canCharge' => $request->user()->can('estate.facilities.update')
                && $request->user()->can('estate.dues_ledger.create'),
            'chargeReason' => 'Recording a booking fee posts a charge against the unit, so it needs Dues & ledger create access as well as Facilities update. Whoever commissions the work in this estate holds the second and not the first, by platform rule rather than by estate preference.',
            'reasons' => [
                'vendors' => self::NO_VENDORS_TAB_YET,
            ],
        ]);
    }

    /** The rate card — board community-admin-20. */
    public function amenitySettings(Request $request, Amenities $amenities): Response
    {
        return inertia('Estate/Facilities/AmenitySettings', [
            'estate' => ['name' => (string) tenant()->name],
            ...$amenities->settingsBoard(),
            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'canCreate' => $request->user()->can('estate.facilities.create'),
            'blockedReason' => 'Changing what an amenity costs sets the terms every future booking will copy, so it needs Facilities update access. You are able to read this rate card.',
            'createReason' => 'Adding an amenity puts something new in the diary for every household, so it needs Facilities create access. You are able to read this rate card.',
        ]);
    }

    /** Add an amenity — capacity, hours, fee and deposit together (12 §2, Wave 2). */
    public function addAmenity(Request $request, Amenities $amenities): RedirectResponse
    {
        $data = $this->amenityTerms($request);

        try {
            $amenity = $amenities->addAmenity($data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/amenities/settings'))
            ->with('success', $amenity->name.' added to the rate card. Every booking from now on copies these terms.');
    }

    /**
     * Edit the rate card. Every booking already made keeps the terms it was made under.
     *
     * THE FLASH SAYS WHAT MOVED AND WHAT DID NOT (M10 §3) — "Booking fee $3,000 →
     * $3,500. Applies to bookings made from now on. The 2 upcoming bookings already
     * made keep …" — which is the sentence the edit panel showed before the press,
     * now with the change in it. The card's labels are read before the edit, since
     * the edit writes onto the same model.
     */
    public function editAmenity(Request $request, Amenity $amenity, Amenities $amenities): RedirectResponse
    {
        $data = $this->amenityTerms($request);
        $data['is_active'] = ! $request->boolean('retire');

        $before = $amenities->priceLabels($amenity);

        try {
            $amenities->editAmenity($amenity, $data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/amenities/settings'))
            ->with('success', $data['is_active']
                ? $amenity->name.' updated. '.$amenities->termsEffect($amenity, $before)
                : $amenity->name.' retired. It keeps its rate card and its history, and takes no new bookings.');
    }

    /**
     * The rate card's terms, checked field by field.
     *
     * A FEE AND A DEPOSIT ARE DOLLARS AND CENTS (B045). `numeric` alone let
     * "4500.505" and "1e30" through to a conversion that could not make money of
     * either, and the Property Manager got a 500 page instead of a word under the
     * field. Two decimal places, the same ceiling the service holds, and nothing
     * rounded to fit.
     *
     * @return array<string, mixed>
     */
    private function amenityTerms(Request $request): array
    {
        $money = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.(Amenities::MAX_TERM_MINOR / 100)];

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'icon_key' => ['required', 'string', 'in:'.implode(',', Amenity::ICONS)],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'],
            'fee' => $money,
            'deposit' => $money,
            'opens_at' => ['required', 'string', 'regex:/^(?:[01]\d|2[0-4]):[0-5]\d$/'],
            'closes_at' => ['required', 'string', 'regex:/^(?:[01]\d|2[0-4]):[0-5]\d$/'],
            'booking_window_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'cancellation_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'is_bookable' => ['nullable', 'boolean'],
            // A booking of it waits for the office's approval, its fee charged then (M53 §2). Left out: kept as it was.
            'requires_approval' => ['nullable', 'boolean'],
        ], [
            'fee.decimal' => 'A booking fee is dollars and cents, at most two decimal places, like 4500.50.',
            'fee.max' => 'A booking fee runs to $'.number_format(Amenities::MAX_TERM_MINOR / 100, 2).'.',
            'deposit.decimal' => 'A deposit is dollars and cents, at most two decimal places, like 5000.00.',
            'deposit.max' => 'A deposit runs to $'.number_format(Amenities::MAX_TERM_MINOR / 100, 2).'.',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /** Put a vendor on a ticket, or a different one. */
    public function assignVendor(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer'],
            // A name, not keyboard noise: "djskb" was taken and the household told it (E2E-M1).
            'technician_name' => ['nullable', 'string', 'max:160', new PersonName],
            /*
             * A PHONE NUMBER, NOT ANY TEXT (the client, 26 Sep: "sdacsad" was taken). Digits, with the spaces,
             * dashes, dots and brackets people write numbers with, and a leading + — seven to fifteen digits.
             */
            'technician_phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9\s().\-]+$/', static function (string $attribute, mixed $value, Closure $fail): void {
                $digits = strlen((string) preg_replace('/\D/', '', (string) $value));

                if ($digits < 7 || $digits > 15) {
                    $fail('Enter the technician\'s phone number: seven to fifteen digits.');
                }
            }],
            'eta_starts_at' => ['nullable', 'date'],
            'eta_ends_at' => ['nullable', 'date', 'after:eta_starts_at'],
        ], [
            'technician_phone.regex' => 'Enter the technician\'s phone number: digits, with spaces, dashes or brackets if you like.',
            // In place of the framework's "The eta ends at field must be a date after eta starts at." (DR-028, step 4).
            'eta_ends_at.after' => 'The visit must end after it starts.',
        ]);

        /*
         * A VISIT WINDOW THAT HAS ALREADY ENDED IS NOT A PROMISE (26 Sep). The household would be told to expect a
         * technician at a time that is over. Read on the ESTATE's clock, as the form is typed; a window left as it
         * was on the ticket is not asked about again.
         */
        $end = $data['eta_ends_at'] ?? $data['eta_starts_at'] ?? null;

        if ($end !== null) {
            $endsAt = SiteTime::parse($end);
            $stored = $ticket->eta_ends_at ?? $ticket->eta_starts_at;

            if ($endsAt->lessThanOrEqualTo(Carbon::now()) && ! ($stored !== null && $stored->equalTo($endsAt))) {
                return back()->withErrors([
                    'eta_ends_at' => 'That visit window has already passed at the estate: it is '.SiteTime::now()->format('g:i A, M j').SiteTime::label(SiteTime::now()).' there now. Choose a time still to come.',
                ])->withInput();
            }
        }

        $vendor = Vendor::findOrFail($data['vendor_id']);
        $sameSupplier = (int) $ticket->assigned_vendor_id === (int) $vendor->id;
        $before = [$ticket->technician_name, $ticket->technician_phone, $ticket->eta_starts_at?->getTimestamp(), $ticket->eta_ends_at?->getTimestamp()];

        try {
            $maintenance->assign(
                ticket: $ticket,
                vendor: $vendor,
                technicianName: $data['technician_name'] ?? null,
                technicianPhone: $data['technician_phone'] ?? null,
                etaStartsAt: $data['eta_starts_at'] ?? null,
                etaEndsAt: $data['eta_ends_at'] ?? null,
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['vendor_id' => $refused->getMessage()])->withInput();
        }

        $ticket->refresh();
        $after = [$ticket->technician_name, $ticket->technician_phone, $ticket->eta_starts_at?->getTimestamp(), $ticket->eta_ends_at?->getTimestamp()];

        // The vendor that already has the job, with nothing about the visit changed, is not a reassignment (M68 §2.2).
        if ($sameSupplier && $before === $after) {
            return back()->withErrors([
                'vendor_id' => $vendor->name.' already has this job. Change the technician, the phone or the visit time, or choose another supplier.',
            ])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', ! $sameSupplier
                ? 'Assigned to '.$vendor->name.'.'
                : $vendor->name.'\'s visit is updated. The household sees the new details.');
    }

    /**
     * Raise or lower the priority.
     *
     * The deadline moves with it and the clock does not reset — an escalated
     * ticket is measured against the new target from the moment it was reported.
     */
    public function changePriority(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'priority' => ['required', 'string', 'in:high,medium,low'],
        ]);

        try {
            $maintenance->changePriority($ticket, $data['priority'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['priority' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', 'Priority set to '.MaintenanceTicket::PRIORITY_LABELS[$data['priority']].'.');
    }

    /**
     * The office has seen it: the spine's second stage, stamped under the name of whoever pressed it (FAC-10). Only
     * from Submitted; a ticket already past it is told so, and nothing is written.
     */
    public function acknowledgeTicket(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        if ($ticket->status !== MaintenanceTicket::SUBMITTED) {
            return back()->withErrors(['stage' => 'Ticket #'.$ticket->number.' is already '.strtolower($ticket->stateLabel()).'.']);
        }

        $maintenance->acknowledge($ticket, $request->user());

        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', 'Ticket #'.$ticket->number.' acknowledged.');
    }

    /**
     * The work has started: the spine's fourth stage, stamped (FAC-10). Refused once it has started, and on a closed
     * job, where work starting again is a reopen.
     */
    public function startTicket(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        if ($ticket->status === MaintenanceTicket::IN_PROGRESS) {
            return back()->withErrors(['stage' => 'Work on ticket #'.$ticket->number.' has already started.']);
        }

        if ($ticket->isClosed()) {
            return back()->withErrors(['stage' => 'Ticket #'.$ticket->number.' is '.strtolower($ticket->stateLabel()).'. Reopen it first.']);
        }

        try {
            $maintenance->start($ticket, by: $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['stage' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', 'Work on ticket #'.$ticket->number.' has started.');
    }

    /** Mark it completed — board 18's primary action. */
    public function resolveTicket(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $maintenance->resolve($ticket, $data['resolution'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['resolution' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/maintenance'))
            ->with('success', 'Ticket #'.$ticket->number.' completed.');
    }

    /** It came back. The original closure stays in the log. */
    public function reopenTicket(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
        ]);

        try {
            $maintenance->reopen($ticket, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', 'Ticket #'.$ticket->number.' reopened.');
    }

    /** Approve a pending booking: confirmed, and its fee charged in the same step (M53 §2). */
    public function approveBooking(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        try {
            $approved = $amenities->approveRequest($booking, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['booking' => $refused->getMessage()]);
        }

        $charged = $approved->feeCharge;

        return redirect()
            ->to($this->path('/facilities/amenities/bookings'))
            ->with('success', 'Booking '.$approved->reference.' confirmed.'.($charged === null
                ? ''
                : ' Its fee, '.AmenityBooking::amount((int) $charged->amount_minor).', is charged to '.$approved->unit->reference.' as '.$charged->reference.'.'));
    }

    /** Decline it, with the reason the household will be given. */
    public function declineBooking(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ]);

        try {
            $amenities->decline($booking, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings'))
            ->with('success', 'Booking '.$booking->reference.' declined.');
    }

    /** Take a period out of an amenity's diary. */
    public function blockSlot(Request $request, Amenity $amenity, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $amenities->blockSlot(
                amenity: $amenity,
                startsAt: $data['starts_at'],
                endsAt: $data['ends_at'],
                reason: $data['reason'] ?? null,
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['starts_at' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings'))
            ->with('success', $amenity->name.' blocked for that period.');
    }

    /**
     * Charge the booking fee to the unit.
     *
     * A REAL POSTING, and the route carries `estate.dues_ledger.create` beside
     * the facilities gate for it. See the class docblock.
     */
    public function recordBookingFee(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'due_on' => ['nullable', 'date'],
        ]);

        try {
            $amenities->recordFee($booking, $request->user(), $data['due_on'] ?? null);
        } catch (DomainException $refused) {
            return back()->withErrors(['fee' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings'))
            ->with('success', 'Booking fee charged to '.$booking->unit->reference.'.');
    }

    /**
     * One booking and the deposit behind it — the screen no board draws (D-086).
     *
     * Read on `view`, like every screen in this module. The two gates on the
     * deposit acts are sent as two booleans rather than one, because the screen
     * has to say WHICH one a viewer lacks: an Admin Assistant may record a
     * deposit received and may not keep one, and a single `canAct` would draw
     * them either three live buttons or none.
     */
    public function booking(Request $request, AmenityBooking $booking, Amenities $amenities): Response
    {
        return inertia('Estate/Facilities/Booking', [
            'estate' => ['name' => (string) tenant()->name],
            ...$amenities->bookingBoard($booking),
            'sourceBadge' => SourceBadge::resident($booking->is_simulated),
            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'canApprove' => $request->user()->can('estate.facilities.approve'),
            // One line, the fact, nothing about permissions (M53 Laws 1 and 2), shown on the card, not only on hover (M94 §1.2).
            'updateReason' => 'Recording a deposit is not part of your role.',
            'approveReason' => 'Keeping a deposit is not part of your role.',
        ]);
    }

    /**
     * The deposit has arrived — Dr 1000 Bank, Cr 2200 Deposits Held.
     *
     * `facilities.update` on the route, as ruled. The date is optional and means
     * today when absent; a deposit paid last Friday and recorded on Monday is
     * posted on the Friday, because that is when the bank had it.
     */
    public function holdDeposit(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'on' => ['nullable', 'date', self::notAfterToday()],
        ]);

        try {
            $amenities->holdDeposit($booking, $data['on'] ?? null, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['deposit' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings/'.$booking->id))
            ->with('success', sprintf(
                'Deposit of %s on %s recorded as received and held.',
                AmenityBooking::amount($booking->deposit_minor),
                $booking->reference,
            ));
    }

    /** It has gone back — Dr 2200, Cr 1000. `facilities.update`, as ruled. */
    public function refundDeposit(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'on' => ['nullable', 'date', self::notAfterToday()],
        ]);

        try {
            $amenities->refundDeposit($booking, $data['on'] ?? null, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['deposit' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings/'.$booking->id))
            ->with('success', sprintf(
                'Deposit of %s on %s refunded.',
                AmenityBooking::amount($booking->deposit_minor),
                $booking->reference,
            ));
    }

    /**
     * The estate is keeping it — Dr 2200, Cr 4100.
     *
     * `facilities.approve` on the route: forfeiting keeps a resident's money and
     * the ruling gives it "the higher verb plus its required reason". The reason
     * is required here AND refused blank by the service, so a caller that
     * reaches the service some other way cannot keep money without one either.
     *
     * IN WHOLE OR IN PART (M4 §4, defect 2). `amount` is what the estate keeps,
     * in dollars; blank keeps all of it, as this always did. The rest goes back
     * in the same entry — Cr 1000 Bank — so a part-forfeit leaves nothing held.
     * The reason is capped at what `deposit_forfeit_reason` holds (255): the old
     * cap of 300 let through a reason the column then refused.
     */
    public function forfeitDeposit(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        /*
         * DOLLARS AND CENTS, AND NOT ROUNDED (B045). The amount used to be rounded
         * half-up into cents, so "100.505" kept J$100.51 — a figure nobody typed,
         * on the entry that takes a resident's money. Two decimal places or the
         * field says why, exactly as the rate card's own fee and deposit do.
         */
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999'],
        ], [
            'amount.decimal' => 'The amount kept is dollars and cents, at most two decimal places.',
        ]);

        $keepMinor = ($data['amount'] ?? null) === null
            ? null
            : Money::of((string) $data['amount'], $booking->currency)->getMinorAmount()->toInt();

        try {
            $amenities->forfeitDeposit($booking, $data['reason'], null, $request->user(), $keepMinor);
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        $kept = (int) $booking->depositKeptMinor();

        return redirect()
            ->to($this->path('/facilities/amenities/bookings/'.$booking->id))
            ->with('success', $kept < $booking->deposit_minor
                ? sprintf(
                    '%s of the %s deposit on %s forfeited, and %s returned. The reason is recorded on the booking and in the entry.',
                    AmenityBooking::amount($kept),
                    AmenityBooking::amount($booking->deposit_minor),
                    $booking->reference,
                    AmenityBooking::amount($booking->deposit_minor - $kept),
                )
                : sprintf(
                    'Deposit of %s on %s forfeited. The reason is recorded on the booking and in the entry.',
                    AmenityBooking::amount($booking->deposit_minor),
                    $booking->reference,
                ));
    }

    /**
     * Where a screen lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
