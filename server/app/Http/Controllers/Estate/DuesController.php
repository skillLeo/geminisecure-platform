<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Account;
use App\Models\Estate\Charge;
use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\Document;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitCollectionFlag;
use App\Services\Documents\Documents;
use App\Services\Estate\ChargeSchedules;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\Estate\Receipts;
use App\Services\Exports\Exporter;
use App\Support\MoneyFormatter;
use App\Support\SiteTime;
use Brick\Money\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dues & ledger — board screens community-admin-05, 06 and 35.
 *
 * EVERY FIGURE ON THESE SCREENS COMES FROM POSTED JOURNAL LINES. Not one is
 * read from `charges` or `payments`: those tables hold the billing — a due
 * date, a receipt number, a method — and the ledger holds the money. A balance
 * summed from the billing records would agree with the accounts by coincidence,
 * and coincidence is what fails at an audit. `EstateArrearsTest` re-sums every
 * total below in raw SQL to prove it.
 *
 * POSTING A CHARGE NEEDS `create`, NOT `view`. A committee member may read the
 * arrears all day and may not add to what a household owes; the Property
 * Manager holds neither, by platform invariant rather than estate preference.
 */
class DuesController extends Controller
{
    /**
     * Why the collection controls that are still inert are inert.
     *
     * Boards 7 and 8 are built, and so is the plan register (12 §2, item 18),
     * and the arrears list reads each household's last reminder from the dunning
     * log. What stays is restriction from a list screen, which is deliberate.
     */
    private const NO_RESTRICT_YET = 'Not built yet: restriction stops guest passes at a gate. It is never applied from a list screen without the household in front of you.';

    /**
     * How many of a bulk charge's lots the preview names one by one (M11 A2).
     *
     * A whole-estate charge reaches 2,000 lots, and the page used to receive and
     * draw every one of them. It names the first sixty — enough to see that the
     * scope is the one intended — and says how many more, and the whole list is
     * still what posts: it is held on the server, not re-derived.
     */
    public const BULK_PREVIEW_SHOWN = 60;

    /**
     * Every amount these forms take: dollars and cents, and no more than any one
     * figure on this estate can be (bug hunt B022, B081).
     *
     * `numeric` alone let "6200.005" through, and `Money::of` refuses a third
     * decimal with a RoundingNecessaryException; "100000000000000000" reached
     * `getMinorAmount()->toInt()` and overflowed. Neither is the DomainException
     * the forms catch, so a typo at the counter was a 500 page and the treasurer
     * lost what they typed. Refused here, it is a sentence under the Amount field.
     *
     * THE CEILING IS J$99,999,999.99, the one `FacilitiesController` already
     * keeps for an amenity fee. A hundred million dollars is more than any single
     * charge, payment or monthly rate here, and times the largest register it
     * stays far inside the integer the ledger's minor units are held in.
     */
    private const AMOUNT_RULES = ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'];

    /**
     * What an amount refusal says, in the forms' own voice: one line saying what would go through, no em dash, money
     * written "$" as everywhere else in the console (DR-028, M72 §1.4; Laws 3 and 5).
     *
     * @var array<string, string>
     */
    private const AMOUNT_MESSAGES = [
        'amount.required' => 'Enter an amount.',
        'amount.min' => 'Enter an amount.',
        'amount.decimal' => 'Enter dollars and cents, two decimal places at most, as in 6200.00.',
        'amount.max' => 'No single amount here can be more than $99,999,999.99.',
        'account.exists' => Dues::NOT_AN_INCOME_ACCOUNT,
    ];

    /**
     * A charge's other refusals, in place of the framework's "The due on field is required." (DR-028, step 5).
     *
     * @var array<string, string>
     */
    private const CHARGE_MESSAGES = [
        ...self::AMOUNT_MESSAGES,
        'due_on.required' => 'Choose a due date.',
        'description.required' => 'Say what the charge is for.',
        'account.required' => 'Choose the income account the charge goes to.',
    ];

    /**
     * The account a charge credits: an income account in use (bug hunt B024).
     *
     * The form only ever offered those, and the route took whatever code the
     * request carried. Posted against 1200, a charge is Dr 1200 (the unit) / Cr
     * 1200 (no unit) — the control account does not move, the household is
     * chased for a debt it does not carry, gate:ledger's tie fails and the
     * append-only ledger cannot take it back. `Dues::charge()` refuses the same
     * thing for every caller; this puts the refusal on the field.
     *
     * @return list<string|Exists>
     */
    private static function incomeAccountRules(): array
    {
        return [
            'required',
            'string',
            Rule::exists('tenant.accounts', 'code')->where('type', Account::INCOME)->where('is_active', true),
        ];
    }

    /**
     * Arrears command centre — board community-admin-05.
     *
     * THE CONTROLLER ASKS THE GATE; THE SERVICE IS HANDED THE ANSWER (M10 §5).
     * The route admits every role holding the ledger, and
     * `Dues::maySeeEstatePosition()` decides whether the register has anything
     * in it for them: for the Admin Assistant it has no rows, no strip and no
     * filters, and says why — a list of who is behind is about what they pay.
     * Same division as `ResidentsController::maySeeMoney()`.
     */
    public function arrears(Request $request, Dues $dues): Response
    {
        $maySeePosition = Dues::maySeeEstatePosition($request->user());

        return inertia('Estate/Dues/Arrears', [
            'estate' => ['name' => (string) tenant()->name],

            /*
             * A PAGE OF THE REGISTER, NOT THE REGISTER (M11 A2). Phase, "90+ days",
             * search, sort, direction and page all live in the query string and
             * are applied in SQL — see `Dues::arrearsPage()` — so the list is
             * linkable, correct under the back button, and never the whole estate
             * in one payload. The export below is still the whole register.
             */
            ...$dues->arrearsPage(
                phase: $request->string('phase')->toString(),
                overdueOnly: $request->boolean('overdue'),
                maySeePosition: $maySeePosition,
                // M32 F5: the search, the sort, its way, the page and the page size, by the one rule.
                table: Dues::arrearsTable($request),
            ),
            'canCharge' => $request->user()->can('estate.dues_ledger.create'),

            /*
             * EXPORTING IS ITS OWN VERB (12 §1). A file naming who owes what
             * leaves the estate and this system cannot recall it — what it can
             * do, and does, is record that it left, who took it and what was in
             * it. That entry is the trail, so the control is live for whoever
             * holds `export` and says so to everybody else.
             */
            // Both locks `exportArrears()` applies, so the control is never
            // live over a file the route would refuse.
            'canExport' => $maySeePosition && $request->user()->can('estate.dues_ledger.export'),
            'exportBlockedReason' => 'An export leaves this estate as a file naming who owes what, so it needs Dues & ledger export access and Reports access together.',
            'reasons' => [
                'restrict' => self::NO_RESTRICT_YET,
            ],
        ]);
    }

    /**
     * The arrears register as a file — board 5's Export (12 §1).
     *
     * The filters travel with it, because a file that says "arrears" and holds
     * one phase is a file somebody will read as the estate's whole position.
     * The scope recorded in the audit entry says which.
     */
    public function exportArrears(Request $request, Dues $dues, Exporter $exporter): StreamedResponse
    {
        /*
         * THE SECOND LOCK (M10 §5). The route already carries
         * `can:estate.dues_ledger.export`, which no seeded role without the
         * estate's position holds. §4 lets a Community Super Admin build one
         * that does — and this file IS the estate's position, every household's
         * balance in a column. Refused before a row is read, so no audit entry
         * records a file that never left.
         */
        abort_unless(
            Dues::maySeeEstatePosition($request->user()),
            403,
            'The arrears register as a file is what every household owes, side by side, and taking it needs Reports access as well as Dues & ledger export. Your role does not hold both.',
        );

        $phase = $request->string('phase')->toString();
        $overdue = $request->boolean('overdue');
        $board = $dues->arrearsBoard($phase, $overdue, true);

        $rows = array_map(static fn (array $row): array => [
            $row['unit'],
            $row['household'],
            $row['resident'],
            $row['bucket_label'],
            number_format($row['balance_minor'] / 100, 2, '.', ''),
            $row['last_payment'] ?? '',
        ], $board['rows']);

        $scope = 'Arrears register, '.($phase === '' ? 'all phases' : $phase).($overdue ? ', 90+ days only' : '');

        return $exporter->csv(
            scope: $scope,
            headers: ['Unit', 'Household', 'Primary resident', 'Ageing', 'Balance JMD', 'Last payment'],
            rows: $rows,
            filename: 'arrears-'.Dues::siteDate()->format('Y-m-d').'.csv',
        );
    }

    /** The receipt register as a file — board 6's receipts tab (12 §1). */
    public function exportReceipts(Request $request, Receipts $receipts, Exporter $exporter): StreamedResponse
    {
        // The second lock, for the reason `exportArrears()` gives: this file is
        // every payment the estate has taken, with its amount (M10 §5).
        abort_unless(
            Dues::maySeeEstatePosition($request->user(), 'payments'),
            403,
            'The receipt register as a file is every payment the estate has taken, with its amount, and taking it needs Reports access as well as Payments export. Your role does not hold both.',
        );

        // The whole sequence, not the register screen's recent page: a receipt
        // export that stopped at 200 would be a file the office reconciles
        // against and finds short, with nothing on it saying it was cut.
        $register = $receipts->register(limit: PHP_INT_MAX);

        $rows = array_map(static fn (array $row): array => [
            $row['receipt_no'],
            $row['unit'],
            $row['received_on'],
            $row['method_label'],
            number_format($row['amount_minor'] / 100, 2, '.', ''),
            $row['reference'] ?? '',
        ], $register['rows']);

        return $exporter->csv(
            scope: 'Receipt register, '.$receipts->prefix().' sequence, every receipt issued',
            headers: ['Receipt', 'Unit', 'Received', 'Method', 'Amount JMD', 'Reference'],
            rows: $rows,
            filename: 'receipts-'.Dues::siteDate()->format('Y-m-d').'.csv',
        );
    }

    /**
     * One unit's ledger — board community-admin-06.
     *
     * Its plan and dunning controls are links to boards 7 and 8 now, behind the
     * same `dues_ledger.view` gate as this screen, so they need no reason here.
     */
    public function unit(Request $request, Unit $unit, Dues $dues, Collections $collections, Documents $documents): Response
    {
        $flag = $collections->flagFor($unit);

        return inertia('Estate/Dues/UnitLedger', [
            'estate' => ['name' => (string) tenant()->name],
            // The whole statement, a page at a time, in the order its headings ask for (M32 F5).
            ...$dues->unitBoard($unit, Dues::statementTable($request)),
            'canRecord' => $request->user()->can('estate.payments.create'),

            // Reversing one charge is the approval reversing a month is (M53 §2): absent for a role without it (Law 4).
            'canReverse' => $request->user()->can('estate.dues_ledger.reverse_runs'),

            /*
             * THE ESTATE'S TODAY, for "Received on" to start from and stop at (bug
             * hunt B106). The browser's `toISOString()` is the UTC date, which from
             * 7 PM in Kingston is tomorrow — and at month end, next month.
             */
            'today' => Dues::siteDate()->toDateString(),

            /*
             * FLAGGING IS THE COMMITTEE'S ACT (12 §1). It carries a minute
             * reference, so it is an approval on Dues & ledger and not the
             * office's `create` — the office prepares the case, the committee
             * agrees to stop chasing. Its own approval since M11 B3,
             * `flag_units`, so the role that may flag is not thereby the role
             * that may reverse a month or change a rate.
             */
            'canFlag' => $request->user()->can('estate.dues_ledger.flag_units'),
            'flagBlockedReason' => 'A hardship or dispute flag records a committee decision to stop chasing a household, so it needs the Hardship and dispute flags permission on Dues & ledger. You are able to read this ledger.',
            'flagKinds' => UnitCollectionFlag::KINDS,

            /*
             * The statement, and any already issued for this unit (12 §1).
             * Server-rendered and queued, so what the screen shows is the row
             * and its status rather than a file that may not exist yet.
             */
            'documents' => $documents->forSubject('unit', (string) $unit->id),
            'flag' => $flag === null ? null : [
                'id' => $flag->id,
                'kind' => $flag->kind,
                'kind_label' => $flag->kindLabel(),
                'headline' => $flag->headline(),
                'reason' => $flag->reason,
                'minute_reference' => $flag->minute_reference,
                'raised_by' => $flag->raised_by_name,

                // An instant, printed as the day it was at the estate (bug hunt B023).
                'raised_at' => SiteTime::local($flag->raised_at)->format('M j, Y'),
            ],
        ]);
    }

    /**
     * THE TEST CARD CHECKOUT — how paying this unit's dues by card will look once the estate chooses a card
     * gateway (the client's request, 25 September 2026: "a fake page … like Stripe … just for testing").
     *
     * A PREVIEW, NEVER A PAYMENT. Dues are paid by hand (Q-012) and this changes nothing about that:
     *   - this is the page's ONLY route, and it is a GET. Nothing on the server takes what is typed on it;
     *     "Pay" makes no request at all, so no card number, expiry or code can reach this server or its logs;
     *   - the page takes only Stripe's published test numbers and wipes a real one the moment it sees one;
     *   - it posts nothing to the ledger and allocates no receipt: the result says no money moved and the
     *     balance has not changed, and this unit's balance is shown only as the amount to pretend to pay.
     */
    public function testCheckout(Unit $unit, Dues $dues): Response
    {
        $today = Dues::siteDate();

        return inertia('Estate/Dues/TestCheckout', [
            'estate' => ['name' => (string) tenant()->name],
            'unit' => $dues->unitHeader($unit),
            'balance_minor' => $dues->balanceOf($unit)->getMinorAmount()->toInt(),

            // This month at the estate, for "expired" — never the browser's own calendar (bug hunt B106).
            'today' => ['year' => (int) $today->year, 'month' => (int) $today->month],

            // The zone the test receipt's "When" is printed in: the estate's, not the browser's.
            'timezone' => SiteTime::zone(),
        ]);
    }

    /**
     * Flag a household for hardship or dispute — board 6 (12 §1).
     *
     * THE DEBT DOES NOT MOVE. No journal is raised here and none is reversed:
     * the balance, the ageing strip and the arrears board are exactly what they
     * were, because the household still owes what it owes. What stops is the
     * estate's automated chasing.
     */
    public function flagUnit(Request $request, Unit $unit, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(UnitCollectionFlag::KINDS))],
            'reason' => ['required', 'string', 'max:500'],
            'minute_reference' => ['required', 'string', 'max:80'],
        ]);

        try {
            $collections->flag($unit, $data['kind'], $data['reason'], $data['minute_reference'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', $unit->reference.' is flagged under '.$data['minute_reference'].'. Automated reminders stop; the balance and the ageing are unchanged, and a notice can still be sent deliberately.');
    }

    /**
     * Lift a flag. The row stays — a flag is an episode, and it keeps its end.
     * A reason and a minute, as raising it needed (13 A4).
     */
    public function liftFlag(Request $request, Unit $unit, UnitCollectionFlag $flag, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'lifted_reason' => ['required', 'string', 'max:500'],
            'lifted_minute_reference' => ['required', 'string', 'max:80'],
        ]);

        if ($flag->unit_id !== $unit->id) {
            return back()->withErrors(['lifted_reason' => 'That flag belongs to another unit.']);
        }

        try {
            $collections->liftFlag($flag, $data['lifted_reason'], $data['lifted_minute_reference'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['lifted_reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', 'The flag on '.$unit->reference.' is lifted under '.$data['lifted_minute_reference'].'. Automated reminders resume from the next run.');
    }

    /** Post a charge — board community-admin-35. */
    public function newCharge(Request $request, Dues $dues): Response
    {
        $unit = $request->filled('unit')
            ? Unit::where('reference', $request->string('unit')->toString())->first()
            : Unit::query()->orderBy('id')->first();

        return inertia('Estate/Dues/NewCharge', [
            'estate' => ['name' => (string) tenant()->name],
            'unit' => $unit === null ? null : $dues->unitHeader($unit),
            'currentBalanceMinor' => $unit === null ? 0 : $dues->balanceOf($unit)->getMinorAmount()->toInt(),
            'accounts' => Account::query()
                ->where('type', Account::INCOME)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(fn (Account $a): array => ['code' => $a->code, 'label' => $a->optionLabel()])
                ->all(),
            'types' => [
                ['value' => 'dues', 'label' => 'Maintenance fee'],
                ['value' => 'special_assessment', 'label' => 'Special assessment'],
                ['value' => 'fine', 'label' => 'Fine'],
                ['value' => 'amenity', 'label' => 'Amenity fee'],
            ],
            'canPost' => $request->user()->can('estate.dues_ledger.create'),
            // One line, the fact, nothing about permissions (M53 Laws 1 and 2; COPY_REWRITES.md §1).
            'blockedReason' => 'Posting charges is not part of your role.',

            // The phases a whole-phase charge may reach, off the register.
            'phases' => Unit::query()
                ->whereNotNull('block')
                ->distinct()
                ->orderBy('block')
                ->pluck('block')
                ->all(),

            /*
             * The preview a bulk charge was taken against, held in the session
             * rather than re-derived: the list the treasurer agreed to is the
             * list that posts, and a register that changed between the two
             * presses refuses rather than silently billing a different set.
             */
            // Without `unit_ids` (M11 A2): the page names the first lots and the count;
            // the full list of what posts stays on the server.
            'bulkPreview' => $request->filled('preview') && is_array($held = $request->session()->get('charge-bulk.'.$request->string('preview')->toString()))
                ? Arr::except($held, ['unit_ids'])
                : null,
            'previewToken' => $request->string('preview')->toString() ?: null,
        ]);
    }

    /**
     * Who a whole-phase or whole-estate charge would reach. NOTHING IS POSTED.
     *
     * Step one of two (12 §2). A charge against the whole estate is hundreds of
     * journal entries at once, and the list — which units, how many, what it
     * comes to — is what a treasurer needs in front of them before the press.
     */
    public function previewBulk(Request $request, Dues $dues): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'in:phase,estate'],
            'phase' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'string', 'in:dues,special_assessment,fine,amenity'],
            'amount' => self::AMOUNT_RULES,
            'description' => ['required', 'string', 'max:200'],
            'due_on' => ['required', 'date'],
            'account' => self::incomeAccountRules(),
        ], self::CHARGE_MESSAGES);

        try {
            $preview = $dues->bulkPreview($data['scope'], $data['phase'] ?? null, Money::of((string) $data['amount'], 'JMD'));
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        $token = Str::random(20);

        $request->session()->put('charge-bulk.'.$token, [
            'scope' => $preview['scope'],
            'phase' => $preview['phase'],
            'count' => $preview['count'],
            'total_minor' => $preview['total_minor'],

            /*
             * THE LIST THAT POSTS, AS IDS (M11 A2). Every unit the preview reached,
             * in the order it drew them, is still what `postBulk()` bills — so the
             * list the treasurer agreed to is still the list that posts. It is kept
             * as ids because a whole-estate preview is 2,000 lots, and the session
             * is read and written on every request until it is spent.
             */
            'unit_ids' => array_column($preview['units'], 'id'),

            // What the page draws: the first lots by reference, and how many more.
            'units' => array_slice($preview['units'], 0, self::BULK_PREVIEW_SHOWN),
            'more' => max(0, $preview['count'] - self::BULK_PREVIEW_SHOWN),

            'type' => $data['type'],
            'amount' => (string) $data['amount'],
            'amount_label' => MoneyFormatter::fromMinor(Money::of((string) $data['amount'], 'JMD')->getMinorAmount()->toInt()),
            'total_label' => MoneyFormatter::fromMinor($preview['total_minor']),
            'description' => $data['description'],
            'due_on' => $data['due_on'],
            'account' => $data['account'],
        ]);

        return redirect()->to($this->chargePath().'?preview='.$token);
    }

    /** Post the previewed charge to every unit on it — all of them, or none. */
    public function postBulk(Request $request, Dues $dues): RedirectResponse
    {
        $token = $request->string('token')->toString();
        $preview = $request->session()->get('charge-bulk.'.$token);

        if (! is_array($preview)) {
            return redirect()
                ->to($this->chargePath())
                ->withErrors(['amount' => 'That preview has expired or has already been posted. Nothing was raised; take it again.']);
        }

        try {
            $result = $dues->chargeMany(
                // A preview taken before M11 carried every unit whole; one taken since carries the ids.
                unitIds: $preview['unit_ids'] ?? array_column($preview['units'], 'id'),
                amount: Money::of((string) $preview['amount'], 'JMD'),
                description: (string) $preview['description'],
                dueOn: (string) $preview['due_on'],
                type: (string) $preview['type'],
                account: (string) $preview['account'],
                by: $request->user(),

                // THE CLAIM IS THE DATABASE'S (bug hunt B020). Forgetting the
                // token below tidies this session; it cannot stop a second
                // request that read the session before this one wrote it.
                previewToken: $token,
            );
        } catch (DomainException $refused) {
            $request->session()->forget('charge-bulk.'.$token);

            return redirect()->to($this->chargePath())->withErrors(['amount' => $refused->getMessage()]);
        }

        // Spent, so this session does not offer the list again.
        $request->session()->forget('charge-bulk.'.$token);

        return redirect()
            ->to($this->arrearsPath())
            ->with('success', sprintf(
                '%s posted to %d unit(s), %s in total. Each unit has its own charge and its own journal entry.',
                $preview['description'],
                $result['posted'],
                MoneyFormatter::fromMinor($result['total_minor']),
            ));
    }

    private function chargePath(): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().'/finance/charges/new'
            : '/finance/charges/new';
    }

    private function arrearsPath(): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().'/finance/arrears'
            : '/finance/arrears';
    }

    /** Post it. A real state change, and a journal entry. */
    public function postCharge(Request $request, Dues $dues): RedirectResponse
    {
        $data = $request->validate([
            'unit' => ['required', 'string'],
            'type' => ['required', 'string', 'in:dues,special_assessment,fine,amenity'],
            'amount' => self::AMOUNT_RULES,
            'description' => ['required', 'string', 'max:200'],
            'due_on' => ['required', 'date'],
            'account' => self::incomeAccountRules(),
        ], self::CHARGE_MESSAGES);

        $unit = Unit::where('reference', $data['unit'])->firstOrFail();

        try {
            $dues->charge(
                unit: $unit,
                /*
                 * Constructed from a decimal string, never a float. `Money::of`
                 * refuses a value it cannot represent exactly, which is the
                 * whole reason the amount arrives as a string and not a
                 * cast-to-float.
                 */
                amount: Money::of((string) $data['amount'], 'JMD'),
                description: $data['description'],
                dueOn: $data['due_on'],
                type: $data['type'],
                account: $data['account'],
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', 'Charge posted to '.$unit->reference.'.');
    }

    /**
     * Record money taken at the office — board 6's "Record manual payment".
     *
     * The day-one collection path: no card gateway exists (Q-012), so a
     * household's dues arrive as cash, a cheque or a transfer and are keyed
     * here. `estate.payments.create` on the route. The receipt number is not
     * asked for — it is allocated at posting from the estate's sequence.
     */
    public function recordPayment(Request $request, Unit $unit, Dues $dues): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'in:cash,cheque,bank'],
            'amount' => self::AMOUNT_RULES,

            // The estate's today (bug hunt B023): `today` alone is UTC's, which after
            // 7 PM in Kingston is tomorrow, and let tomorrow's date through.
            'received_on' => ['required', 'date', 'before_or_equal:'.Dues::siteDate()->toDateString()],
            'reference' => ['nullable', 'string', 'max:60'],
        ], [
            ...self::AMOUNT_MESSAGES,
            'received_on.before_or_equal' => 'A payment cannot be received in the future. The date on a receipt is the day the money arrived, at the estate.',
        ]);

        try {
            $payment = $dues->receive(
                unit: $unit,
                amount: Money::of((string) $data['amount'], 'JMD'),
                method: $data['method'],
                receivedAt: $data['received_on'],
                by: $request->user(),
                reference: isset($data['reference']) && trim($data['reference']) !== '' ? trim($data['reference']) : null,
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['payment' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', sprintf(
                'Payment of %s recorded against %s, receipt %s.',
                MoneyFormatter::fromMinor($payment->amount_minor),
                $unit->reference,
                $payment->receipt_no,
            ));
    }

    /**
     * The receipt register — board 5's Receipts tab.
     *
     * THE SEQUENCE FOR EVERYBODY IN THE LEDGER; THE TAKINGS ONLY FOR THOSE WHO
     * MAY READ THE ESTATE'S POSITION (M10 §5). The office still checks its
     * numbering, finds a receipt and asks for its PDF here. What every
     * household paid, listed together, and the month's total are the estate's
     * takings — see `Receipts::register()`.
     */
    public function receipts(Request $request, Receipts $receipts): Response
    {
        $maySeePosition = Dues::maySeeEstatePosition($request->user(), 'payments');

        return inertia('Estate/Dues/Receipts', [
            'estate' => ['name' => (string) tenant()->name],

            // A page of the sequence, with its search (M11 A2), in the order its headings ask for (M32 F5) — the
            // export is still the whole of it.
            ...$receipts->registerPage(
                table: Receipts::registerTable($request, $maySeePosition),
                maySeePosition: $maySeePosition,
            ),
            'canRecord' => $request->user()->can('estate.payments.create'),

            // A receipt's PDF opens behind the Payments module's read (`Document::KIND_PERMISSIONS`), not this page's
            // Dues & ledger read, so a role built with one and not the other has no Download receipt (M96 level 3).
            'canReceipt' => $request->user()->can(Document::KIND_PERMISSIONS[Document::RECEIPT]),

            // Both locks `exportReceipts()` applies, so the control is never live
            // over a file the route would refuse (bug hunt B029) — the rule the
            // arrears list already keeps, for a role §4 can build with the export
            // verb and without Reports.
            'canExport' => $maySeePosition && $request->user()->can('estate.payments.export'),
            'exportBlockedReason' => 'An export leaves this estate as a file naming who paid what, so it needs Payments export access and Reports access together.',
        ]);
    }

    /** Board 5's "Charge schedule" tab (12 §2, item 17). See `ChargeSchedules`. */
    public function chargeSchedule(Request $request, ChargeSchedules $schedules): Response
    {
        $user = $request->user();

        return inertia('Estate/Dues/ChargeSchedule', [
            'estate' => ['name' => (string) tenant()->name],
            // The posted months a page at a time, in the order their headings ask for (M32 F5).
            ...$schedules->board(ChargeSchedules::runsTable($request)),

            // Posting a month is the office's `create`, the same as any charge.
            // Defining or stopping a schedule and changing its rate (M10 §3) are
            // `set_rates`; reversing a posted month is `reverse_runs`. Two
            // permissions since M11 B3 (PRICE-02): the power to set a rate must
            // not carry the power to rewrite a month already billed.
            'canPost' => $user->can('estate.dues_ledger.create'),
            'canSetRates' => $user->can('estate.dues_ledger.set_rates'),
            'canReverse' => $user->can('estate.dues_ledger.reverse_runs'),
            'reasons' => [
                'post' => 'Posting a month bills every unit in the schedule, so it needs Dues & ledger create access. You are able to read this screen.',
                'schedule' => 'Defining or stopping a schedule decides what every household in it is billed, so it needs the Set dues rates permission on Dues & ledger.',
                'rate' => 'Changing the rate changes what every household on this schedule is billed from the month you choose, so it needs the Set dues rates permission on Dues & ledger. Months already posted keep the rate they were raised at.',
                'reverse' => 'Reversing a posted month takes it back out of every household it billed, so it needs the Reverse posted charges permission on Dues & ledger, which is separate from setting rates.',
            ],
        ]);
    }

    public function createSchedule(Request $request, ChargeSchedules $schedules): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:120'],
            'amount' => self::AMOUNT_RULES,
            'scope' => ['required', 'string', 'in:estate,phase'],
            'phase' => ['nullable', 'string', 'max:64'],
            'due_day' => ['required', 'integer', 'min:1', 'max:28'],
            'account_code' => ['required', 'string', 'max:8'],
        ], self::AMOUNT_MESSAGES);

        try {
            $schedule = $schedules->create($data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['schedule' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $schedule->description.' is scheduled. Nothing is billed until a month is posted from the preview below.');
    }

    public function stopSchedule(Request $request, ChargeSchedule $schedule, ChargeSchedules $schedules): RedirectResponse
    {
        $schedules->stop($schedule, $request->user());

        return back()->with('success', $schedule->description.' is stopped. Its posted months stand.');
    }

    /**
     * Change a schedule's rate from a month not yet billed (M10 §3).
     *
     * `set_rates`, on the route, like defining the schedule: it changes what every
     * household in scope is billed. The refusals — a month already billed, no
     * reason, the rate already in force — come back from `ChargeSchedules` as
     * sentences on the `rate` key, which the rate panel prints under itself.
     *
     * THE FLASH SAYS WHAT HAPPENED AND FROM WHEN, and what did not: the month it
     * starts, how many units and what a month then bills, and the latest posted
     * month by name as untouched. It is the same promise the panel made before
     * the press, made again after it.
     */
    public function amendScheduleRate(Request $request, ChargeSchedule $schedule, ChargeSchedules $schedules): RedirectResponse
    {
        $data = $request->validate([
            'amount' => self::AMOUNT_RULES,
            'effective_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'reason' => ['required', 'string', 'max:300'],
        ], self::AMOUNT_MESSAGES);

        try {
            $result = $schedules->amendRate(
                $schedule,
                (string) $data['amount'],
                (string) $data['effective_period'],
                (string) $data['reason'],
                $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['rate' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $result['summary'].' '.$result['untouched']);
    }

    public function postRun(Request $request, ChargeSchedule $schedule, ChargeSchedules $schedules): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'expected_count' => ['required', 'integer', 'min:1'],
            'expected_total_minor' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $run = $schedules->post($schedule, $data['period'], (int) $data['expected_count'], (int) $data['expected_total_minor'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['run' => $refused->getMessage()]);
        }

        return back()->with('success', sprintf(
            '%s posted: %d units, %s, entry %s.',
            $schedule->description.' for '.$run->period,
            $run->unit_count,
            MoneyFormatter::fromMinor($run->total_minor),
            $run->journal_ref,
        ));
    }

    /**
     * Reverse one charge, with a reason (M53 §2): the same approval as reversing a month, `reverse_runs`, because it is
     * the same act on one household's line. `Dues::reverseCharge()` decides what may be reversed alone.
     */
    public function reverseCharge(Request $request, Charge $charge, Dues $dues): RedirectResponse
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:10', 'max:300']],
            ['reason.required' => 'Say why the charge is being reversed.', 'reason.min' => 'Say why, in ten characters at least.'],
        );

        try {
            $mirror = $dues->reverseCharge($charge, (string) $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()]);
        }

        $unit = Unit::query()->findOrFail($charge->unit_id);

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', $charge->reference.' is reversed with entry '.$mirror->reference.'. Both stay on '.$unit->reference.'\'s statement, and the charge has left the arrears.');
    }

    public function reverseRun(Request $request, ChargeRun $run, ChargeSchedules $schedules): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        try {
            $run = $schedules->reverse($run, (string) $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reversal' => $refused->getMessage()]);
        }

        /*
         * WHAT HAPPENS TO THE MONTH, SAID AS IT IS (bug hunt B021). This used to
         * promise "the period can be posted again" while the schedule's next
         * period had moved past it. It is now the month `nextPeriod()` names —
         * the reversed one, since months are reversed latest first — or, for a
         * stopped schedule, that nothing further is billed from it.
         */
        $schedule = $run->schedule;

        return back()->with('success', 'Run reversed with entry '.$run->reversal_journal_ref.'. Its charges have left the ageing, and '.(
            $schedule->is_active
                ? $schedules->nextPeriod($schedule)->format('F Y').' is the next month this schedule bills.'
                : 'the schedule is stopped, so nothing further is billed from it.'
        ));
    }

    /**
     * Where a unit's ledger lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function unitPath(Unit $unit): string
    {
        $path = '/finance/units/'.$unit->id;

        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
