<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Account;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Vendor;
use App\Rules\PhoneNumber;
use App\Rules\ReceivesMail;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Payables;
use App\Support\EstatePhone;
use App\Support\JamaicanNumber;
use Brick\Money\Money;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Vendors, bills and the bank statement — board screens 26, 27, 28 and 39.
 *
 * EVERY FIGURE ON THESE SCREENS COMES FROM POSTED JOURNAL LINES. Total payable
 * is the balance of 2000 Accounts Payable, a vendor's "currently owed" is that
 * vendor's own lines on the same account, and what one bill still owes is the
 * balance of the entries raised for it. Nothing is summed from the `bills` table
 * — a total taken from the billing records would agree with the accounts by
 * coincidence, and `EstatePayablesTest` re-sums each of these in raw SQL to
 * prove they are the same rows read two ways.
 *
 * APPROVING A BILL AND SIGNING OFF A RECONCILIATION NEED `approve`, NOT `create`.
 * Both are the moment a figure stops being a proposal: an approved bill is money
 * the estate owes, and a completed reconciliation is a period nobody re-opens.
 * D-013 separated the verb from `update` for exactly this — a role may prepare an
 * irreversible act without being able to commit it. The route file carries the
 * gates; a controller that checked them again would be a second place to keep in
 * step with the matrix.
 *
 * TWO APPROVALS SINCE M11 B3 (D-150): `approve_bills` and
 * `complete_reconciliations`. The reconciliation is the check on the payments,
 * so a reviewer can be given the sign-off without the power it reviews.
 */
class PayablesController extends Controller
{
    /**
     * Why the controls on these screens that do nothing, do nothing.
     *
     * Each is a real act with a consequence outside the screen it is drawn on,
     * and each needs a form, a document or a table this phase has not built.
     */
    public const NO_CREATE_ACCESS = 'Adding a supplier or a bill needs Accounting create access.';

    // M98: one plain line, drawn beside the greyed button (level 2); it named file parsing and carried an em dash.
    private const NO_IMPORT_STATEMENT_YET = 'Not available yet: statement lines are entered by hand.';

    /*
     * The work order behind a bill. Boards 17 and 18 are built, so on Bills &
     * payments it is a link for anybody holding Facilities view — which every
     * role that reads Accounting does today — and this sentence is only ever
     * the refusal for a role that does not, on either screen.
     */
    public const NO_TICKET_ACCESS = 'Opening the work order needs view access on Facilities.';

    /**
     * An amount a bill or a payment can carry (bug hunt B022, B081).
     *
     * `numeric` alone let "6200.005" through, and `Money::of` then refused it
     * with an exception nobody caught: a 500 for one key too many. Two decimals
     * is what JMD holds, and the ceiling is far above any bill an estate pays and
     * far below the point where minor units stop fitting an integer.
     */
    private const AMOUNT_RULES = ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999.99'];

    /**
     * What a missing supplier detail says, beside its field (M18C §6), with this estate's own example number (M32 F7).
     *
     * @return array<string, string>
     */
    private static function vendorMessages(): array
    {
        return [
            'contact_phone.required' => 'Enter the supplier\'s phone number: a supplier nobody can call cannot be sent to a resident\'s house. Example: '.EstatePhone::forEstate()['local'].'.',
            'contact_email.required' => 'Enter the supplier\'s email.',
            'contact_name.required' => 'Enter who to ask for at the supplier.',
            'category.required' => 'Choose what the supplier does.',
        ];
    }

    /**
     * A SUPPLIER'S RECORD, NOTHING OPTIONAL (M18C §2): who they are, what they do, who to ask for, a phone and an email.
     * The TRN is the one field that may not be to hand yet — bills are recorded and not paid until it arrives
     * (Payables) — so it is ADDITIONAL (§3.3), asked under its own heading (DECISIONS D-M18C-TRN).
     *
     * @return array<string, list<mixed>>
     */
    private static function vendorRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:64'],
            'trn' => ['nullable', 'string', 'max:24'],
            'contact_name' => ['required', 'string', 'max:160'],
            'contact_phone' => ['required', 'string', 'max:40', new PhoneNumber],
            'contact_email' => ['required', 'email', 'max:190', new ReceivesMail],
        ];
    }

    /** @var array<string, string> */
    private const AMOUNT_MESSAGES = [
        'amount.decimal' => 'An amount is dollars and cents, at most two decimal places, as "45000.00".',
        'amount.max' => 'That is more than any one bill or payment this estate records. Check the digits.',
    ];

    /** The supplier register — board community-admin-26. */
    public function vendors(Request $request, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Vendors', [
            'estate' => ['name' => (string) tenant()->name],
            'optionalTabs' => AccountingController::optionalTabs(),
            // M10 §5: what every supplier was paid is the estate's spending. M32 F5: sorted and paged by the server.
            ...$payables->vendorsBoard($this->maySeePosition($request), Payables::vendorsTable($request, $this->maySeePosition($request))),
            'canCreate' => $request->user()->can('estate.accounting_posting.create'),
            'blockedReason' => self::NO_CREATE_ACCESS,
        ]);
    }

    /** One vendor's sub-ledger — board community-admin-39. */
    public function vendor(Request $request, Vendor $vendor, Payables $payables, Maintenance $maintenance): Response
    {
        return inertia('Estate/Accounting/Vendor', [
            // The jobs this supplier has been given (M98, maintenance and vendors), for a reader who may open a work
            // order; null for one who may not, and the section is not drawn (M96 level 3).
            'workOrders' => $request->user()->can('estate.facilities.view') ? $maintenance->workOrdersFor($vendor) : null,
            'estate' => ['name' => (string) tenant()->name],
            // The bill history a page at a time, in the order its headings ask for (M32 F5).
            ...$payables->vendorBoard($vendor, $this->maySeePosition($request), Payables::vendorBillsTable($request)),
            ...$this->billForm($payables),
            'canCreate' => $request->user()->can('estate.accounting_posting.create'),
            'canEdit' => $request->user()->can('estate.accounting_posting.update'),
            'blockedReason' => self::NO_CREATE_ACCESS,
            'editBlockedReason' => 'Editing a supplier needs Accounting update access.',

            // The same gate Bills & payments asks: the ticket is Facilities'.
            'canOpenTicket' => $request->user()->can('estate.facilities.view'),
            'reasons' => [
                'ticket' => self::NO_TICKET_ACCESS,
            ],
        ]);
    }

    /**
     * Edit a supplier — board 27's second action (12 §2, Wave 2).
     *
     * The TRN rule is stated on the screen, because filling one in is what
     * unblocks payment and clearing one blocks it again. Deactivating is the
     * only way off the register; a supplier with an open bill cannot take it.
     */
    public function editVendor(Request $request, Vendor $vendor, Payables $payables): RedirectResponse
    {
        $fields = $request->validate([...self::vendorRules(), 'status' => ['required', 'string', 'in:active,inactive']], self::vendorMessages());
        $fields['contact_phone'] = JamaicanNumber::normalise($fields['contact_phone']);

        try {
            $payables->editVendor($vendor, $fields);
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/accounting/vendors/'.$vendor->id))
            ->with('success', $vendor->name.' updated.'.($vendor->trn === null
                ? ' No TRN is on file, so bills can be recorded against them and payment will refuse until one is.'
                : ' TRN '.$vendor->trn.' is on file, so bills against them can be paid.'));
    }

    /** Bills & payments — board community-admin-27. */
    public function bills(Request $request, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Bills', [
            'estate' => ['name' => (string) tenant()->name],
            'optionalTabs' => AccountingController::optionalTabs(),
            ...$payables->billsBoard(maySeePosition: $this->maySeePosition($request), table: Payables::billsTable($request)),
            ...$this->billForm($payables),
            'canPay' => $request->user()->can('estate.accounting_posting.create'),
            'canApprove' => $request->user()->can('estate.accounting_posting.approve_bills'),
            // The work order is board 18, behind the Facilities gate rather than
            // this module's — so whether it is a link is asked of that gate.
            'canOpenTicket' => $request->user()->can('estate.facilities.view'),
            'blockedReason' => 'Paying a bill needs Accounting create access.',
            'reasons' => [
                'ticket' => self::NO_TICKET_ACCESS,
            ],
        ]);
    }

    /**
     * Whether this viewer may read the estate's payables as a whole (M10 §5).
     *
     * Asked here and handed down, the way `ResidentsController::maySeeMoney()`
     * is. The bills themselves and the reconciliation stay for every role in
     * Accounting — an office that records a bill, pays it and matches a bank
     * line needs those figures to do it. The totals across them are the
     * estate's own position, and `Dues::maySeeEstatePosition()` decides them.
     */
    private function maySeePosition(Request $request): bool
    {
        return Dues::maySeeEstatePosition($request->user(), 'accounting_posting');
    }

    /**
     * What recording a bill has to choose from: the register, the expense
     * accounts, and the open work orders (12 §2, Wave 1 — "expense account
     * and work order chosen deliberately"). Names and codes only; what a
     * vendor is owed is the board's own figure, not the form's.
     *
     * @return array<string, mixed>
     */
    private function billForm(Payables $payables): array
    {
        return [
            /*
             * THE ESTATE'S TODAY, from the server (bug hunt B106). The pages used
             * to build it from `new Date().toISOString()`, which is the UTC date —
             * a day ahead of Kingston from 7 PM — so an evening's new bill fell due
             * a day late and a payment could be dated tomorrow. The due-date
             * default and the payment date's ceiling both start from this.
             */
            'today' => Ledger::today()->toDateString(),

            'billVendors' => Vendor::query()
                ->where('status', Vendor::ACTIVE)
                ->orderBy('name')
                ->get(['id', 'name', 'trn'])
                ->map(static fn (Vendor $vendor): array => ['id' => $vendor->id, 'name' => $vendor->name, 'has_trn' => $vendor->hasTrn()])
                ->all(),
            'expenseAccounts' => Account::query()
                ->where('type', Account::EXPENSE)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(static fn (Account $account): array => ['code' => $account->code, 'label' => $account->optionLabel()])
                ->all(),
            'openTickets' => MaintenanceTicket::query()
                ->whereNull('closed_at')
                ->orderByDesc('number')
                ->limit(50)
                ->get(['number', 'title'])
                ->map(static fn (MaintenanceTicket $ticket): array => [
                    'number' => $ticket->number,
                    'label' => $ticket->label(),
                ])
                ->all(),
        ];
    }

    /** Bank reconciliation — board community-admin-28. */
    public function reconciliation(Request $request, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Reconciliation', [
            'estate' => ['name' => (string) tenant()->name],
            'optionalTabs' => AccountingController::optionalTabs(),
            ...$payables->reconciliationBoard(),
            'canMatch' => $request->user()->can('estate.accounting_posting.create'),
            'canComplete' => $request->user()->can('estate.accounting_posting.complete_reconciliations'),
            // M84 §3.4: a bank-only line offers a manual journal, only while the switch is on.
            'manualJournals' => (bool) config('features.manual_journals') && $request->user()->can('estate.accounting_posting.create'),
            'blockedReason' => 'Matching a bank line needs Accounting create access.',
            'reasons' => [
                'import' => self::NO_IMPORT_STATEMENT_YET,
            ],

            /*
             * The scope banner's copy, verbatim from board 28. It is a promise
             * about this release rather than a description of the screen, so it
             * lives with the release and not in the page.
             */
            'scope' => [
                'title' => 'Manual matching in this release',
                'body' => 'Match each bank line to the estate entry it is, yourself. Automatic matching is not part of this release.',
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /** Put a supplier on the register — board 26's "Add vendor". */
    public function addVendor(Request $request, Payables $payables): RedirectResponse
    {
        $data = $request->validate(self::vendorRules(), self::vendorMessages());

        try {
            $vendor = $payables->addVendor(
                name: $data['name'],
                category: $data['category'],
                trn: $data['trn'] ?? null,
                contactName: $data['contact_name'],
                contactPhone: JamaicanNumber::normalise($data['contact_phone']),
                contactEmail: $data['contact_email'],
            );
        } catch (DomainException $refused) {
            $field = str_contains($refused->getMessage(), 'TRN') ? 'trn' : 'name';

            return back()->withErrors([$field => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/accounting/vendors/'.$vendor->id))
            ->with('success', $vendor->name.' added to the register'.($vendor->hasTrn() ? '.' : ' — no TRN yet, so bills can be recorded and not paid until it arrives.'));
    }

    /**
     * Record an invoice — boards 27 and 39. A draft: no journal until it is
     * approved. The expense account and the work order are chosen here, as
     * ruled, and the vendor is chosen on board 27 or fixed on board 39.
     */
    public function recordBill(Request $request, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer'],
            'description' => ['required', 'string', 'max:200'],
            'amount' => self::AMOUNT_RULES,
            'due_on' => ['required', 'date'],

            /*
             * AN ACTIVE EXPENSE ACCOUNT (bug hunt B024) — the only kind the form
             * offers, and now the only kind the server takes. A bill against a
             * control account broke its tie to its sub-ledger for good.
             * `Payables::recordBill()` refuses the rest too, for any caller that
             * is not this form.
             */
            'account' => ['bail', 'required', 'string', 'max:16', static function (string $attribute, mixed $value, Closure $fail): void {
                $code = is_string($value) ? $value : '';

                $expense = Account::query()
                    ->where('code', $code)
                    ->where('type', Account::EXPENSE)
                    ->where('is_active', true)
                    ->exists();

                if (! $expense) {
                    $fail('A bill is charged to one of this estate\'s active expense accounts, the cost it is for. '.$code.' is not one of them.');
                }
            }],
            'ticket_number' => ['nullable', 'integer'],
        ], self::AMOUNT_MESSAGES);

        $vendor = Vendor::query()->findOrFail($data['vendor_id']);

        $ticket = isset($data['ticket_number'])
            ? MaintenanceTicket::query()->where('number', (int) $data['ticket_number'])->first()
            : null;

        if (isset($data['ticket_number']) && $ticket === null) {
            return back()->withErrors(['ticket_number' => 'No work order carries that number.'])->withInput();
        }

        try {
            $bill = $payables->recordBill(
                vendor: $vendor,
                amount: Money::of((string) $data['amount'], 'JMD'),
                description: $data['description'],
                dueOn: $data['due_on'],
                account: $data['account'],
                ticketId: $ticket?->number,
                ticketLabel: $ticket?->label(),
            );
        } catch (DomainException $refused) {
            // On the field the refusal is about: the account it is charged to, or the amount.
            $field = str_contains($refused->getMessage(), 'account') ? 'account' : 'amount';

            return back()->withErrors([$field => $refused->getMessage()])->withInput();
        }

        // Back where it was recorded: the work order's own page (M98, cost capture), the supplier's, or Bills & payments.
        $back = match (true) {
            $request->boolean('from_ticket') && $ticket !== null => '/facilities/maintenance/'.$ticket->number,
            $request->boolean('from_vendor') => '/accounting/vendors/'.$vendor->id,
            default => '/accounting/bills',
        };

        return redirect()
            ->to($this->path($back))
            ->with('success', 'Bill '.$bill->reference.' recorded against '.$vendor->name.' as a draft. Approve it to post the liability.');
    }

    /** Agree a bill, and post the liability. */
    public function approveBill(Request $request, Bill $bill, Payables $payables): RedirectResponse
    {
        try {
            $payables->approve($bill, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['bill' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/bills'))
            ->with('success', 'Bill '.$bill->reference.' approved.');
    }

    /**
     * Void a draft the estate does not owe (bug hunt B036). Nothing posts: a
     * draft raised no liability. A reason is required, and the gate is the bill
     * approval, `approve_bills` (M11 B3) — deciding a claim is not owed is the
     * mirror of deciding it is, and neither is the reconciliation sign-off.
     */
    public function voidBill(Request $request, Bill $bill, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ], [
            'reason.required' => 'Say why the estate does not owe this bill — a void with no reason is a claim that disappeared.',
        ]);

        try {
            $payables->void($bill, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['bill' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/bills'))
            ->with('success', 'Bill '.$bill->reference.' voided. It was a draft, so nothing had posted and nothing is reversed; it stays on '.$bill->vendor->name.'’s record, marked Void.');
    }

    /** Pay one, and post the entry that moves the money. */
    public function payBill(Request $request, Bill $bill, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            /*
             * A decimal string, never a float. `Money::of` refuses a value it
             * cannot represent exactly, which is why the amount arrives as a
             * string and is not cast on the way in — and why the rule says two
             * decimals, so the refusal is a sentence on the field and not a 500.
             */
            'amount' => self::AMOUNT_RULES,
            'method' => ['required', 'string', 'in:cheque,bank,card,cash'],

            // Not after the estate's today (bug hunt B033), as a receipt is not.
            'paid_on' => ['required', 'date', 'before_or_equal:'.Ledger::today()->toDateString()],
            'reference' => ['nullable', 'string', 'max:64'],
            'overpayment_reason' => ['nullable', 'string', 'max:190'],
        ], [
            ...self::AMOUNT_MESSAGES,
            'paid_on.before_or_equal' => 'A payment is dated the day the money moved, and that day has not come yet — today is '.
                Ledger::today()->format('j M Y').'. A cheque written for a later date is paid on the day it clears.',
        ]);

        try {
            $payables->pay(
                bill: $bill,
                amount: Money::of((string) $data['amount'], 'JMD'),
                method: $data['method'],
                paidOn: $data['paid_on'],
                by: $request->user(),
                reference: $data['reference'] ?? null,
                overpaymentReason: $data['overpayment_reason'] ?? null,
            );
        } catch (DomainException $refused) {
            /*
             * Against `amount`, because that is the field the person can change
             * — and where they cannot (a missing TRN), the message says so
             * rather than leaving them retrying a number that was never the
             * problem.
             */
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/accounting/bills'))
            ->with('success', 'Payment recorded against '.$bill->reference.'.');
    }

    /** Claim that a bank line and a posted entry are the same event. */
    public function matchLine(Request $request, BankStatementLine $line, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            'entry_ref' => ['required', 'string', 'max:40'],
        ]);

        try {
            $payables->match($line, $data['entry_ref']);
        } catch (DomainException $refused) {
            return back()->withErrors(['entry_ref' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/reconciliation'))
            ->with('success', 'Matched to '.$data['entry_ref'].'.');
    }

    /** Take the claim back. */
    public function unmatchLine(Request $request, BankStatementLine $line, Payables $payables): RedirectResponse
    {
        try {
            $payables->unmatch($line);
        } catch (DomainException $refused) {
            return back()->withErrors(['line' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/reconciliation'))
            ->with('success', 'Match removed.');
    }

    /** Sign the period off — refused while a difference remains. */
    public function completeReconciliation(
        Request $request,
        BankReconciliation $reconciliation,
        Payables $payables,
    ): RedirectResponse {
        try {
            $payables->complete($reconciliation, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reconciliation' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/reconciliation'))
            ->with('success', $reconciliation->periodLabel().' reconciliation completed.');
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
