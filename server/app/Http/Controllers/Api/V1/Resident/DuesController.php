<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\AutopayArrangement;
use App\Models\Estate\Charge;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Estate\Autopay;
use App\Services\Estate\Collections;
use App\Services\Estate\DemoCardDeclined;
use App\Services\Estate\DemoCardPayments;
use App\Services\Estate\Dues;
use App\Services\Estate\HouseholdCards;
use App\Services\ResidentApp\ResidentAccounts;
use App\Services\Restriction\RestrictionPolicy;
use App\Support\Decimal;
use App\Support\SiteTime;
use Brick\Money\Money;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The resident's OWN household's dues: invoices, balance, statement, payments,
 * receipts, and how to pay (13 D3). Boards resident-app-11 to -14.
 *
 * ONE HOUSEHOLD, THE TOKEN'S. Every query is keyed on the account's unit, and a
 * household id in the path that is not theirs is 404 — the same answer as an id
 * nobody has, so the endpoint cannot be used to learn which households exist.
 *
 * EVERY FIGURE FROM THE LEDGER. The balance is `Dues::balanceOf` (the journal on
 * 1200), and the statement is the journal lines — the same arithmetic board 6
 * draws, so the app and the estate office cannot quote a household two numbers.
 *
 * THE CARD IS A DEMONSTRATION (M12 §2.6 overrides Q-012/D-077 on the client's
 * instruction; D-179). `payments.card` takes a payment with one of Stripe's test
 * cards through to a real receipt and a lower balance, posted to 1090 and never to
 * the bank, and marked a demonstration everywhere. `pay-intent` still answers with
 * how this estate is paid by hand — the bank transfer stays as the other way — and
 * `autopay` is still refused: no card is ever stored.
 */
class DuesController extends Controller
{
    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly Dues $dues,
    ) {}

    public function invoices(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];

        return response()->json([
            'currency' => 'JMD',
            'items' => $this->charges($unit, $this->siteToday($context)),
        ]);
    }

    public function balance(int $household, DeviceContext $context): JsonResponse
    {
        $home = $this->household($household, $context);
        $unit = $home['unit'];

        /*
         * THE ESTATE'S DAY, NOT UTC'S (bug hunt B121). From 7 PM in Kingston UTC is
         * already tomorrow, so `as_at` named tomorrow and a charge due today read
         * "1 day overdue" with the arrears warning. The ageing counts from the due
         * date on the estate's calendar — zero days ON the due date — and so does this.
         */
        $today = $this->siteToday($context);
        $oldest = $this->dues->oldestOpenChargeDate($unit, $today);
        $plan = app(Collections::class)->activePlanFor($unit);
        $balanceMinor = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();
        // EST-B-01's correction: with nothing owed today, what to pay now is what is billed and not yet due, by its day.
        $payableMinor = $balanceMinor > 0 ? $balanceMinor : $this->dues->unpaidOf($unit)->getMinorAmount()->toInt();

        return response()->json([
            'household_id' => $home['household']->id,
            'unit' => $unit->reference,
            'balance' => Decimal::of($balanceMinor),
            'currency' => 'JMD',
            'as_at' => $today->toDateString(),
            'oldest_unpaid_due_on' => $oldest?->toDateString(),
            'days_overdue' => $oldest === null || $oldest->greaterThanOrEqualTo($today) ? 0 : (int) $oldest->diffInDays($today),
            'on_payment_plan' => $plan !== null,
            // M18D §3: the plan itself, so "On a payment plan" says what the plan IS — and the next instalment, which is
            // what the household must pay now while the plan holds.
            'plan' => $plan === null ? null : $this->planShape($plan, $today),
            // M18D §2.2: WHAT MUST BE PAID NOW, AND BY WHEN — the one action on the dues page, with the amount in its
            // button. The plan's next instalment while a plan holds; else the whole balance. Null when nothing is owed.
            'pay_now' => $this->payNow($plan, $payableMinor, $oldest ?? $this->nextOpenDueOn($unit), $today),
            // Whether a visitor for this household would be held at the gate RIGHT NOW — the gate's own decision
            // (RestrictionPolicy, payment plan included), so this screen and the gate can never disagree. It is
            // read, never worked out here: a resident must be told in words, not first learn of a restriction
            // when a guest is turned away.
            'access_restricted' => ! app(RestrictionPolicy::class)->decide($home['household'], 'visitor')['admitted'],
        ]);
    }

    /**
     * An active plan in a resident's terms: the instalment, how many, how many met, the next and the last dates, and
     * when it was agreed (M18D §3).
     *
     * @return array<string, mixed>
     */
    private function planShape(\App\Models\Estate\PaymentPlan $plan, Carbon $today): array
    {
        $schedule = $plan->schedule()->get();
        $next = $schedule->first(fn ($i) => $i->status === \App\Models\Estate\PaymentPlanInstalment::DUE);
        $last = $schedule->last();

        return [
            'reference' => $plan->reference,
            'instalment' => Decimal::of((int) ($next->amount_minor ?? $schedule->first()->amount_minor ?? 0)),
            'instalments' => (int) $plan->instalments,
            'made' => $schedule->where('status', \App\Models\Estate\PaymentPlanInstalment::MET)->count(),
            'remaining' => $schedule->where('status', \App\Models\Estate\PaymentPlanInstalment::DUE)->count(),
            'next_due_on' => $next?->due_on?->toDateString(),
            'next_is_overdue' => $next !== null && $next->due_on->lessThan($today),
            'final_due_on' => $last?->due_on?->toDateString(),
            'agreed_on' => $plan->agreed_at === null ? null : SiteTime::local($plan->agreed_at)->toDateString(),
            'frequency' => \App\Models\Estate\PaymentPlan::FREQUENCY,
        ];
    }

    /**
     * What to pay now, and by when (M18D §2.2). `reason`: `instalment` (the plan's next), `overdue` (the balance, past its
     * date) or `due` (the balance, not yet due).
     *
     * @return array{amount: string, due_on: ?string, reason: string}|null
     */
    private function payNow(?\App\Models\Estate\PaymentPlan $plan, int $balanceMinor, ?Carbon $oldest, Carbon $today): ?array
    {
        if ($balanceMinor <= 0) {
            return null;
        }

        $next = $plan?->nextDue();
        if ($next !== null) {
            return ['amount' => Decimal::of(min((int) $next->amount_minor, $balanceMinor)), 'due_on' => $next->due_on->toDateString(), 'reason' => 'instalment'];
        }

        return ['amount' => Decimal::of($balanceMinor), 'due_on' => $oldest?->toDateString(), 'reason' => $oldest !== null && $oldest->lessThan($today) ? 'overdue' : 'due'];
    }

    /**
     * The due date of the first charge still open when none has fallen due yet (M22 §4, VERIFY line 37).
     *
     * `oldestOpenChargeDate` counts only charges due by today — it is the ageing's, and a charge not yet due is not in
     * arrears — so a household billed on the 1st for the 12th was told "Due — $4,500" with no date: the one question of
     * the four ("how much, by when") the page left unanswered on the most ordinary day of the month. The same walk
     * (payments clear the oldest charge first) says which charges are open; the earliest of their dates is by when.
     */
    private function nextOpenDueOn(Unit $unit): ?Carbon
    {
        $open = array_keys(array_filter($this->dues->outstandingByCharge($unit), static fn (int $minor): bool => $minor > 0));
        $due = $open === [] ? null : Charge::query()->whereKey($open)->min('due_on');

        return $due === null ? null : Carbon::parse((string) $due);
    }

    public function statement(Request $request, int $household, DeviceContext $context): JsonResponse
    {
        $unit = $this->household($household, $context)['unit'];
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:200']]);

        $lines = DB::connection('tenant')->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
            ->where('accounts.code', '1200')
            ->where('journal_lines.unit_id', $unit->id)
            ->orderBy('journals.posted_on')
            ->orderBy('journal_lines.id')
            ->get(['journals.posted_on', 'journals.memo as entry_memo', 'journals.reference', 'journals.source', 'journal_lines.memo as line_memo', 'journal_lines.debit_minor', 'journal_lines.credit_minor']);

        $running = 0;
        $rows = [];

        foreach ($lines as $line) {
            $running += (int) $line->debit_minor - (int) $line->credit_minor;

            $rows[] = [
                'date' => Carbon::parse((string) $line->posted_on)->toDateString(),
                // A reversal says so, and why (EST-B-04): the same words as the console's statement.
                'description' => Dues::statementDescription($line->line_memo, $line->entry_memo, (string) $line->source),
                'reference' => (string) $line->reference,
                'charge' => (int) $line->debit_minor > 0 ? Decimal::of((int) $line->debit_minor) : null,
                'payment' => (int) $line->credit_minor > 0 ? Decimal::of((int) $line->credit_minor) : null,
                'balance' => Decimal::of($running),
            ];
        }

        return response()->json([
            'unit' => $unit->reference,
            'currency' => 'JMD',
            'closing_balance' => Decimal::of($running),
            'items' => array_slice(array_reverse($rows), 0, (int) ($data['limit'] ?? 50)),
        ]);
    }

    public function payIntent(int $invoice, DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $charge = Charge::query()->whereKey($invoice)->where('unit_id', $unit->id)->first();

        if ($charge === null) {
            throw ApiError::notFound('not_found', 'No invoice of your household\'s with that id.');
        }

        // A reversed charge is not in the walk (B057), so nothing is due on it.
        $open = collect($this->charges($unit, $this->siteToday($context)))->firstWhere('id', $charge->id);
        $instructions = EstateSetting::current()->getAttribute('dues_payment_instructions');

        $card = DemoCardPayments::enabled();

        return response()->json([
            'invoice_id' => $charge->id,
            // The test-card checkout (D-179): a card, with a demonstration badge, and the bank transfer beside it.
            'online_payment_available' => $card,
            'card_mode' => $card ? 'demonstration' : null,
            'reason' => $card
                ? 'Pay by card in the app — a demonstration payment with a test card: you get a receipt and your balance goes down, and no money moves. Or pay the estate by bank transfer, cash or cheque.'
                : 'This estate takes dues by bank transfer, cash or cheque. Card payment is not offered in the app.',
            'amount_due' => $open['outstanding'] ?? Decimal::of(0),
            'currency' => 'JMD',
            'quote_reference' => $unit->reference.' · '.$charge->getAttribute('reference'),
            'instructions' => $instructions ?? 'Pay at the estate office, or by bank transfer to the estate\'s account shown on your statement. Quote the reference above so the payment reaches your household.',
            'estate' => (string) Tenant::query()->whereKey($context->tenantId)->value('name'),
        ]);
    }

    public function payments(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];

        return response()->json([
            'currency' => 'JMD',
            'items' => Payment::query()->where('unit_id', $unit->id)->orderByDesc('received_at')->limit(100)->get()
                ->map(fn (Payment $p): array => $this->paymentShape($p))->all(),
        ]);
    }

    public function receipt(int $payment, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = Payment::query()->whereKey($payment)->where('unit_id', $home['unit']->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No payment of your household\'s with that id.');
        }

        return response()->json([
            ...$this->paymentShape($record),
            'estate' => (string) Tenant::query()->whereKey($context->tenantId)->value('name'),
            'unit' => $home['unit']->reference,
            'household' => $home['household']->name,
            'received_by_name' => $record->received_by_name,
            'entered_at' => $record->entered_at->toIso8601String(),
        ]);
    }

    /**
     * Pay with a TEST card (M12 §2.6; D-179) — `payments.card`. The phone checked the form and sends only which of
     * Stripe's test cards it was; the answer is the real receipt, posted to 1090 and marked a demonstration.
     */
    public function cardPayment(Request $request, DeviceContext $context, DemoCardPayments $cards): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'brand' => ['required', 'string', 'in:visa,mastercard,amex,discover'],
            'last4' => ['required', 'string', 'regex:/^\d{4}$/'],
        ], [
            'amount.regex' => 'Enter an amount in dollars and cents, like 12400.00.',
            'last4.regex' => 'The card is identified by its last four digits.',
        ]);

        try {
            $payment = $cards->payDues(
                $home['unit'],
                Money::of((string) $data['amount'], 'JMD'),
                (string) $data['brand'],
                (string) $data['last4'],
                (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident'),
            );
        } catch (DemoCardDeclined $declined) {
            throw ApiError::unprocessable('card_declined', $declined->getMessage());
        } catch (DomainException $refused) {
            throw ApiError::conflict('payment_refused', $refused->getMessage());
        }

        return response()->json([
            ...$this->paymentShape($payment),
            'estate' => (string) Tenant::query()->whereKey($context->tenantId)->value('name'),
            'unit' => $home['unit']->reference,
            'household' => $home['household']->name,
            'received_by_name' => $payment->received_by_name,
            'entered_at' => $payment->entered_at->toIso8601String(),
            // What is owed now, from the ledger — the phone shows the lower balance at once.
            'balance' => Decimal::of($this->dues->balanceOf($home['unit'])->getMinorAmount()->toInt()),
        ], 201);
    }

    /** The household's AutoPay as it stands (M50 §4.2 #3) — `autopay.show`. See `Autopay`. */
    public function autopayShow(DeviceContext $context, Autopay $autopay): JsonResponse
    {
        $home = $this->accounts->home($context);

        return response()->json(Autopay::shape($autopay->current($home['unit']), $this->siteToday($context)));
    }

    /**
     * AutoPay turned on, or its terms changed (M50 §4.2 #3, §6.6) — `autopay.store`. It used to refuse always ("this
     * estate takes dues by hand"); with the demonstration checkout on it pays, on the day, up to the limit.
     */
    public function autopay(Request $request, DeviceContext $context, Autopay $autopay, HouseholdCards $cards): JsonResponse
    {
        $data = $request->validate([
            'card' => ['required', 'integer', 'min:1'],
            'limit' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'day' => ['sometimes', 'nullable', 'integer', 'between:1,28'],
        ], [
            'limit.regex' => 'Enter a limit in dollars, like 15000.',
            'day.between' => 'Choose a day from the 1st to the 28th — a day every month has.',
        ]);

        $home = $this->accounts->home($context);
        $card = $cards->find($home['unit'], $home['account'], (int) $data['card']) ?? throw ApiError::notFound('not_found', 'No saved card of yours with that id. Save a card first.');
        $limit = isset($data['limit']) ? Money::of((string) $data['limit'], 'JMD')->getMinorAmount()->toInt() : Autopay::DEFAULT_LIMIT_MINOR;
        $today = $this->siteToday($context);

        try {
            $arrangement = $autopay->turnOn($home['unit'], $home['account'], $card, $limit, (int) ($data['day'] ?? Autopay::DEFAULT_DAY), $today);
        } catch (DomainException $refused) {
            throw ApiError::conflict('autopay_unavailable', $refused->getMessage());
        }

        return response()->json(Autopay::shape($arrangement, $today), 201);
    }

    /** AutoPay turned off — `autopay.destroy`. */
    public function cancelAutopay(int $autopay, DeviceContext $context, Autopay $arrangements): JsonResponse
    {
        $home = $this->accounts->home($context);
        $arrangement = AutopayArrangement::query()->whereKey($autopay)->where('unit_id', $home['unit']->id)->first()
            ?? throw ApiError::notFound('not_found', 'No AutoPay of your household\'s with that id.');

        $arrangements->turnOff($home['unit'], $home['account'], $arrangement);

        return response()->json(Autopay::shape(null, $this->siteToday($context)));
    }

    /**
     * The household's charges, newest first, each with what is still owed on it.
     *
     * PAYMENTS CLEAR THE OLDEST CHARGE FIRST — the walk the ageing uses — so the
     * outstanding balance sits on the newest charges, and a charge is `paid` once
     * nothing of the balance reaches back to it.
     *
     * A REVERSED CHARGE IS NOT A BILL (bug hunt B057). Reversing a run posts its mirror
     * entry, which takes the charge back out of the balance, and marks the charge
     * `reversed` — the row stays, for the record. Walked with the rest, it was handed the
     * balance the mirror had just taken away from it: the charge taken back read
     * "overdue", with the whole amount on it and its reference to quote, and the charge
     * actually owed read "paid". It is left out, the rule `Dues::oldestOpenChargeDates`
     * already keeps — "a reversed charge was never owed" — so this list, the ageing and
     * the balance card can never disagree. Left out rather than sent as `reversed`: the
     * apps in the field read a status they do not know from its amount, and a reversed
     * charge with nothing outstanding would be drawn "Paid", which it never was. The
     * statement still shows the charge and its reversal, line by line.
     *
     * OVERDUE THE DAY AFTER IT FALLS DUE, ON THE ESTATE'S CALENDAR (bug hunt B058). It
     * was `isPast()` against UTC midnight: from 7 PM in Kingston a charge due tomorrow
     * read "overdue", and every charge read overdue for the whole of the day it fell
     * due — which the ageing (`due_on <= as-at`, zero days on the due date) never said.
     *
     * @return list<array<string, mixed>>
     */
    private function charges(Unit $unit, Carbon $today): array
    {
        $left = max(0, $this->dues->unpaidOf($unit)->getMinorAmount()->toInt());
        $todayDate = $today->toDateString();

        return Charge::query()->where('unit_id', $unit->id)->where('status', '!=', 'reversed')->orderByDesc('due_on')->orderByDesc('id')->limit(120)->get()
            ->map(static function (Charge $c) use (&$left, $todayDate): array {
                $amount = (int) $c->getAttribute('amount_minor');
                $owed = min($amount, $left);
                $left -= $owed;
                $dueOn = Carbon::parse((string) $c->getRawOriginal('due_on'))->toDateString();

                return [
                    'id' => $c->id,
                    'reference' => (string) $c->getAttribute('reference'),
                    'type' => (string) $c->getAttribute('type'),
                    'period' => $c->getAttribute('period'),
                    'description' => (string) $c->getAttribute('description'),
                    'amount' => Decimal::of($amount),
                    'outstanding' => Decimal::of($owed),
                    'due_on' => $dueOn,
                    'status' => match (true) {
                        $owed === 0 => 'paid',
                        $owed < $amount => 'part_paid',
                        // Dates compared as dates: "Y-m-d" strings order the way the calendar does.
                        $dueOn < $todayDate => 'overdue',
                        default => 'due',
                    },
                ];
            })->all();
    }

    /** Today on the estate's own calendar, as a date — what the ledger's ageing counts from (B058, B121). */
    private function siteToday(DeviceContext $context): Carbon
    {
        return Carbon::parse(SiteTime::today($context->tenantId)->toDateString());
    }

    /** @return array{unit: Unit, household: Household} */
    private function household(int $household, DeviceContext $context): array
    {
        $home = $this->accounts->home($context);

        if ($home['household']->id !== $household) {
            throw ApiError::notFound('not_found', 'No household of yours with that id.');
        }

        return ['unit' => $home['unit'], 'household' => $home['household']];
    }

    /** @return array<string, mixed> */
    private function paymentShape(Payment $p): array
    {
        return [
            'id' => $p->id,
            'receipt_no' => $p->receipt_no,
            'amount' => Decimal::of((int) $p->amount_minor),
            'currency' => $p->currency,
            'method' => $p->method,
            'reference' => $p->reference,
            'received_at' => $p->received_at->toIso8601String(),
            'status' => $p->status,
            // A test card paid it (D-179): the receipt is real, no money moved, and the phone says so beside it.
            'is_demonstration' => (bool) $p->is_demonstration,
        ];
    }
}
