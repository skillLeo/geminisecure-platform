<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\BillPayment;
use App\Models\Estate\Journal;
use App\Models\Estate\Vendor;
use App\Models\User;
use App\Support\Seeded\Retirements;
use App\Support\TableQuery;
use Brick\Money\Money;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vendors, bills, payments and the bank statement — boards 26, 27, 28 and 39.
 *
 * THE MIRROR OF `Dues`, AND THE SAME RULE. A bill approved debits an expense and
 * credits 2000 Accounts Payable; paying it debits 2000 and credits the bank.
 * Neither is recorded any other way, so what the estate owes its suppliers and
 * the payables control account cannot drift apart — they are the same rows read
 * two ways.
 *
 * THE VENDOR IS NAMED ON EVERY 2000 LINE. That is what makes the payables
 * sub-ledger tie to its control account exactly as units tie receivables: the
 * control balance is the sum of the lines, the sub-ledger total is the same sum
 * grouped by vendor, and a line that forgot its vendor would appear in the first
 * and vanish from the second. `Ledger::subsidiaryBalances()` is the tie, and
 * `EstatePayablesTest` re-sums both sides in raw SQL.
 *
 * NOT ONE FIGURE BELOW COMES FROM THE `bills` TABLE. Total payable, a vendor's
 * balance, what one bill still owes, paid year-to-date — every one is summed from
 * posted journal lines. The billing rows supply only what bookkeeping has no
 * place for: a due date, a TRN, a cheque number, a ticket. A total derived from
 * the billing tables would agree with the accounts by coincidence, and coincidence
 * is what fails at an audit.
 *
 * TWO REFUSALS THAT ARE NOT VALIDATION. A bill may be RECORDED for a vendor with
 * no TRN and may never be PAID to one — withholding compliance bites when money
 * moves, not when an invoice arrives, and refusing to record it would understate
 * what the estate owes. And a payment may not exceed what a bill still owes
 * unless somebody writes down why. Both throw `DomainException` naming the rule,
 * because the person who hits them has to be told what to do next.
 */
class Payables
{
    /** The control account every bill and every payment moves. */
    public const PAYABLE = '2000';

    /** Where the money leaves from, unless a caller names another bank. */
    public const BANK = '1000';

    public const MAINTENANCE = '5100';

    public const UTILITIES = '5200';

    /**
     * What board 26's Category column means in the books.
     *
     * The coarse grouping is not stored on a vendor: it is which expense account
     * that vendor's bills actually landed on, read back from the journal. A copy
     * kept beside the vendor would be free to say "Maintenance" about costs that
     * all went to 5200.
     *
     * The key type is `int|string` and not `string`, because PHP silently turns
     * a wholly numeric array key into an integer. Reading it back with a string
     * code still works — the same conversion happens on lookup — but a chart
     * numbered "1100-01" would key as a string, so both are true of this map.
     *
     * @var array<int|string, string>
     */
    public const EXPENSE_GROUPS = [
        self::MAINTENANCE => 'Maintenance',
        self::UTILITIES => 'Utilities',
    ];

    /** What each derived bill state is called on screen. */
    public const STATUS_LABELS = [
        'draft' => 'Draft',
        'unpaid' => 'Unpaid',
        'overdue' => 'Overdue',
        'paid' => 'Paid',
        'void' => 'Void',
    ];

    public function __construct(private readonly Ledger $ledger) {}

    /* ------------------------------------------------------------------ */
    /* writing */
    /* ------------------------------------------------------------------ */

    /**
     * Put a supplier on the register — board 26's "Add vendor" (12 §2, Wave 1).
     *
     * THE TRN IS ASKED FOR AND NOT REQUIRED HERE, and that is the ruling's own
     * shape: "TRN required before a bill may be paid." A supplier goes on the
     * register when the estate starts dealing with them, paperwork or not; a
     * bill can be recorded and approved against them; and `pay()` is where the
     * missing TRN refuses. A register that refused to list a supplier without
     * one would understate what the estate owes.
     *
     * One name, once. Two rows for one supplier is two sub-ledgers for one
     * debt, and the treasurer would owe the sum of both.
     */
    public function addVendor(
        string $name,
        ?string $category = null,
        ?string $trn = null,
        ?string $contactName = null,
        ?string $contactPhone = null,
        ?string $contactEmail = null,
    ): Vendor {
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('A vendor has a name. A blank row on the register is a supplier nobody can pay.');
        }

        if (Vendor::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
            throw new DomainException(sprintf(
                '%s is already on the register. Two rows for one supplier would be two sub-ledgers for one debt.',
                $name,
            ));
        }

        $trn = trim((string) $trn);

        if ($trn !== '') {
            $digits = preg_replace('/\D/', '', $trn) ?? '';

            if (strlen($digits) !== 9) {
                throw new DomainException('A Jamaican TRN is nine digits. Leave it blank until the supplier sends it: the bill can be recorded, and payment will wait for it.');
            }

            // Stored the way the register prints it: 100-482-517.
            $trn = substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3);
        }

        return Vendor::create([
            'name' => $name,
            'category' => $category === null || trim($category) === '' ? null : trim($category),
            'trn' => $trn === '' ? null : $trn,
            'contact_name' => $contactName === null || trim($contactName) === '' ? null : trim($contactName),
            'contact_phone' => $contactPhone === null || trim($contactPhone) === '' ? null : trim($contactPhone),
            'contact_email' => $contactEmail === null || trim($contactEmail) === '' ? null : strtolower(trim($contactEmail)),
            'status' => Vendor::ACTIVE,
        ]);
    }

    /**
     * Edit a supplier — board 27's "Edit vendor" (12 §2, Wave 2).
     *
     * WHAT CHANGES HERE CHANGES WHO THE ESTATE MAY PAY, and the TRN rule is the
     * whole reason this screen states itself out loud: a supplier with no TRN
     * can be billed and cannot be paid, so filling one in is what unblocks
     * payment, and clearing one blocks it again on the next attempt. Bills
     * already posted do not move — a journal carries the amount, not the
     * vendor's paperwork.
     *
     * A VENDOR IS DEACTIVATED, NEVER DELETED. The bills against them are
     * journals with their name on, and a deactivated supplier keeps every one
     * of them; they only stop appearing as somewhere new money can go.
     *
     * @param  array<string, mixed>  $fields
     */
    public function editVendor(Vendor $vendor, array $fields): Vendor
    {
        $name = trim((string) ($fields['name'] ?? ''));

        if ($name === '') {
            throw new DomainException('A vendor has a name. A blank row on the register is a supplier nobody can pay.');
        }

        $clash = Vendor::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->where('id', '!=', $vendor->id)
            ->exists();

        if ($clash) {
            throw new DomainException(sprintf(
                '%s is already on the register. Two rows for one supplier would be two sub-ledgers for one debt.',
                $name,
            ));
        }

        $trn = trim((string) ($fields['trn'] ?? ''));

        if ($trn !== '') {
            $digits = preg_replace('/\D/', '', $trn) ?? '';

            if (strlen($digits) !== 9) {
                throw new DomainException('A Jamaican TRN is nine digits. Leave it blank until the supplier sends it: bills can still be recorded, and payment will wait for it.');
            }

            $trn = substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3);
        }

        $status = ($fields['status'] ?? Vendor::ACTIVE) === Vendor::INACTIVE ? Vendor::INACTIVE : Vendor::ACTIVE;

        if ($status === Vendor::INACTIVE) {
            $drafts = $vendor->bills()->where('status', Bill::DRAFT)->count();
            $approved = $vendor->bills()->where('status', Bill::APPROVED)->count();

            /*
             * WHAT TO DO ABOUT EACH, IN WORDS THE CONSOLE CAN DO (bug hunt B036).
             * This used to say "Settle or void them first" when nothing could
             * void a bill. An approved bill is a liability on 2000 and is paid; a
             * draft recorded by mistake is voided from its row on Bills &
             * payments.
             */
            if ($drafts + $approved > 0) {
                throw new DomainException(sprintf(
                    '%s still has %s. %s: a supplier made inactive with a bill open is a debt with nowhere to pay it.',
                    $vendor->name,
                    implode(' and ', array_filter([
                        $approved > 0 ? $approved.' approved bill'.($approved === 1 ? '' : 's') : null,
                        $drafts > 0 ? $drafts.' draft bill'.($drafts === 1 ? '' : 's') : null,
                    ])),
                    implode('; ', array_filter([
                        $approved > 0 ? 'Pay what has been approved' : null,
                        $drafts > 0 ? 'Void a draft the estate does not owe with Void on its row in Bills & payments, or approve it' : null,
                    ])),
                ));
            }
        }

        $vendor->fill([
            'name' => $name,
            'category' => ($fields['category'] ?? null) === null || trim((string) $fields['category']) === '' ? null : trim((string) $fields['category']),
            'trn' => $trn === '' ? null : $trn,
            'contact_name' => ($fields['contact_name'] ?? null) === null || trim((string) $fields['contact_name']) === '' ? null : trim((string) $fields['contact_name']),
            'contact_phone' => ($fields['contact_phone'] ?? null) === null || trim((string) $fields['contact_phone']) === '' ? null : trim((string) $fields['contact_phone']),
            'contact_email' => ($fields['contact_email'] ?? null) === null || trim((string) $fields['contact_email']) === '' ? null : strtolower(trim((string) $fields['contact_email'])),
            'status' => $status,
        ])->save();

        return $vendor;
    }

    /**
     * Record an invoice that has arrived. NO JOURNAL IS RAISED.
     *
     * A recorded bill is a draft, and a draft is not a liability. An invoice that
     * has arrived but has not been agreed is a claim, and posting it on arrival
     * would let a disputed bill change the estate's accounts before anybody
     * decided it should. `approve()` is where it becomes money owed.
     *
     * There is no `by` argument, and the table has no column for one. Recording
     * an invoice is clerical; APPROVING it is the act with a name against it,
     * and giving the two the same signature would suggest otherwise.
     *
     * @param  string  $account  the expense account code the bill will debit — a plumber and an electricity bill are different costs
     */
    public function recordBill(
        Vendor $vendor,
        Money $amount,
        string $description,
        Carbon|string $dueOn,
        string $account = self::MAINTENANCE,
        ?int $ticketId = null,
        ?string $ticketLabel = null,
    ): Bill {
        if ($amount->isNegativeOrZero()) {
            throw new DomainException(
                'A bill must be a positive amount. A supplier crediting the estate issues a credit '.
                'note, which is a different document with its own entry, not a negative invoice.'
            );
        }

        return DB::connection('tenant')->transaction(function () use (
            $vendor, $amount, $description, $dueOn, $account, $ticketId, $ticketLabel
        ): Bill {
            $due = $dueOn instanceof Carbon ? $dueOn->copy() : Carbon::parse($dueOn);

            $expense = Account::where('code', $account)->first();

            if ($expense === null) {
                throw new DomainException(
                    "No account [{$account}] in this estate's chart. A bill has to say which cost it ".
                    'is before it can be approved, and an account invented on the way past would '.
                    'become a permanent line in the chart.'
                );
            }

            /*
             * AN ACTIVE EXPENSE ACCOUNT, AND NOTHING ELSE (bug hunt B024). The
             * form only ever offers expense accounts; the server took any code.
             * A bill "for" 2000 then approved as Dr 2000 with no vendor and Cr
             * 2000 with one — the control balance did not move, the supplier's
             * did, and the payables tie broke on an append-only ledger nobody can
             * correct. Against 1200 it broke the receivables tie the same way;
             * against 1000 it credited the bank for a bill nobody paid.
             */
            if ($expense->type !== Account::EXPENSE || ! $expense->is_active) {
                throw new DomainException($this->notAnExpense($expense));
            }

            return Bill::create([
                'vendor_id' => $vendor->id,
                'reference' => $this->nextBillReference($due),
                'description' => $description,
                'amount_minor' => $amount->getMinorAmount()->toInt(),
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'due_on' => $due->toDateString(),
                'account_id' => $expense->id,
                'ticket_id' => $ticketId,
                'ticket_label' => $ticketLabel,
                'status' => Bill::DRAFT,
            ]);
        });
    }

    /**
     * Agree the bill, and post the liability: Dr the expense, Cr 2000.
     *
     * THE VENDOR GOES ON THE 2000 LINE and nowhere else. The expense line belongs
     * to the estate's costs and has no sub-ledger; the payable line belongs to
     * one supplier, and that link is the whole of the payables sub-ledger.
     *
     * `$on` exists because a bill is agreed on a date, and a seeded or
     * back-entered estate has bills agreed before today. It defaults to today —
     * the ESTATE's today (bug hunt B023, B082), because a bill approved at 8 PM
     * on the last day of September in Kingston is September's cost, although the
     * UTC calendar has already turned to October.
     *
     * ONE APPROVAL, HOWEVER MANY PRESSES (bug hunt B020, B075). The check on
     * the copy the caller holds refuses the ordinary late click without a lock.
     * What stops a double-click is the second check: inside the transaction the
     * bill is read again under `FOR UPDATE`, so a second request that loaded the
     * same draft waits for the first to commit, finds it approved, and is
     * refused — where it used to post the liability a second time. The unique
     * entry per bill on `journals` is the database's own copy of the rule.
     */
    public function approve(Bill $bill, ?User $by = null, Carbon|string|null $on = null): Bill
    {
        $this->approvableAccount($bill);

        return DB::connection('tenant')->transaction(function () use ($bill, $by, $on): Bill {
            $current = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            $account = $this->approvableAccount($current);
            $memo = $this->memoFor($current);

            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit($account->code, $current->amount_minor, $memo),
                    Posting::credit(self::PAYABLE, $current->amount_minor, $memo, vendorId: $current->vendor_id),
                ],
                on: $on ?? Ledger::today(),
                source: Ledger::SOURCE_BILL,
                sourceId: $current->id,
                by: $by,
                prefix: 'BIL',
            );

            $current->forceFill([
                'status' => Bill::APPROVED,
                'approved_by' => $by?->getKey(),
                'approved_by_name' => $by?->name,
                'approved_at' => $on === null
                    ? now()
                    : ($on instanceof Carbon ? $on->copy() : Carbon::parse($on)),
                'journal_ref' => $entry->reference,
            ])->save();

            // The caller's copy says what the row now says.
            $bill->setRawAttributes($current->getAttributes(), true);

            return $bill;
        });
    }

    /**
     * The account an approval debits — or why this bill cannot be approved.
     *
     * Asked twice by `approve()`: once of the caller's copy, and once of the row
     * as it stands under the lock, because the second is the only answer a
     * concurrent approval cannot have made stale.
     */
    private function approvableAccount(Bill $bill): Account
    {
        if ($bill->status === Bill::VOID) {
            throw new DomainException(sprintf(
                'Bill [%s] was voided (the estate decided it does not owe it), and a void bill is not approved. '.
                'If it is owed after all, record it again.',
                $bill->reference,
            ));
        }

        if ($bill->status !== Bill::DRAFT) {
            throw new DomainException(sprintf(
                'Bill [%s] is already %s. Approving it a second time would post the liability twice, '.
                'and the ledger cannot be unposted.',
                $bill->reference,
                $bill->status,
            ));
        }

        $account = $bill->account;

        if ($account === null) {
            throw new DomainException(sprintf(
                'Bill [%s] names no expense account, so there is nothing to debit. Every cost the '.
                'estate agrees to has to say what kind of cost it is.',
                $bill->reference,
            ));
        }

        // A draft written before the rule, or around it, is refused here and posts nothing (B024).
        if ($account->type !== Account::EXPENSE) {
            throw new DomainException('Bill ['.$bill->reference.'] cannot be approved: '.$this->notAnExpense($account));
        }

        return $account;
    }

    /** Why a bill cannot be charged to this account (bug hunt B024). */
    private function notAnExpense(Account $account): string
    {
        $is = match (true) {
            ! $account->is_active => 'archived',
            $account->type === Account::ASSET => 'an asset account',
            $account->type === Account::LIABILITY => 'a liability account',
            $account->type === Account::EQUITY => 'an equity account',
            $account->type === Account::INCOME => 'an income account',
            default => 'not an expense account',
        };

        return sprintf(
            '%s %s is %s, not an active expense account, and a bill is charged to an active expense account '.
            'only. It debits the cost it is for and credits 2000 Accounts Payable; charged anywhere else it '.
            'moves a balance the bill has nothing to do with (against a control account it breaks that '.
            'account\'s tie to its sub-ledger), and the ledger cannot be unposted.',
            $account->code,
            $account->name,
            $is,
        );
    }

    /**
     * Void a draft the estate does not owe (bug hunt B036). NO JOURNAL.
     *
     * A draft has raised no liability, so there is nothing to reverse: voiding
     * it is the record of a decision — the reason, the person, the moment — and
     * the row stays on the supplier's record, marked void. It is the approver's
     * decision, the mirror of approving, and the route asks for the same
     * permission.
     *
     * AN APPROVED BILL IS NEVER VOIDED. Its liability is on 2000 and the ledger
     * cannot be unposted; it is paid, or corrected by a journal somebody decides
     * to post. The draft is read again under `FOR UPDATE`, so a void cannot land
     * beside an approval of the same bill: whichever commits second finds the
     * other's result and is refused.
     */
    public function void(Bill $bill, string $reason, ?User $by = null): Bill
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'Say why the estate does not owe this bill. A void with no reason is a claim that disappeared, '.
                'and the first question anyone asks of it is why.'
            );
        }

        $this->refuseVoid($bill);

        return DB::connection('tenant')->transaction(function () use ($bill, $reason, $by): Bill {
            $current = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            $this->refuseVoid($current);

            $current->forceFill([
                'status' => Bill::VOID,
                'void_reason' => mb_substr($reason, 0, 190),
                'voided_by' => $by?->getKey(),
                'voided_by_name' => $by?->name,
                'voided_at' => now(),
            ])->save();

            $bill->setRawAttributes($current->getAttributes(), true);

            return $bill;
        });
    }

    /** Why this bill cannot be voided — asked of the caller's copy, then under the lock. */
    private function refuseVoid(Bill $bill): void
    {
        if ($bill->status === Bill::VOID) {
            throw new DomainException(sprintf('Bill [%s] is already void.', $bill->reference));
        }

        if ($bill->status !== Bill::DRAFT) {
            throw new DomainException(sprintf(
                'Bill [%s] has been approved: its liability is posted to 2000 Accounts Payable, and an approved '.
                'bill is paid, or corrected by a journal, and never voided. The ledger cannot be unposted.',
                $bill->reference,
            ));
        }
    }

    /**
     * Pay a bill, and post the entry: Dr 2000, Cr the bank.
     *
     * THE TRN IS CHECKED FIRST, before anything about the bill. It is the only
     * one of these refusals that cannot be resolved on the payment screen — the
     * supplier has to send paperwork — so telling somebody their bill is not
     * approved and letting them fix that before hitting the compliance wall
     * wastes the one thing they cannot fix themselves.
     *
     * AN OVERPAYMENT NEEDS A SENTENCE, not a larger number. A payment above
     * what the bill still owes leaves the vendor's sub-ledger in debit, which is
     * a real thing — a deposit, a duplicate to be recovered — and each of those
     * is a fact somebody knows and the ledger should record.
     */
    public function pay(
        Bill $bill,
        Money $amount,
        string $method,
        Carbon|string $paidOn,
        ?User $by = null,
        ?string $reference = null,
        ?string $overpaymentReason = null,
        string $bankAccount = self::BANK,
    ): BillPayment {
        $vendor = $bill->vendor;

        if (! $vendor->hasTrn()) {
            throw new DomainException(sprintf(
                'Vendor [%s] has no TRN on file, so this bill cannot be paid. A bill may be recorded '.
                'without one, since refusing that would understate what the estate owes, but withholding '.
                'compliance bites when money moves. Put the taxpayer registration number on the '.
                'vendor record first.',
                $vendor->name,
            ));
        }

        if ($amount->isNegativeOrZero()) {
            throw new DomainException(
                'A payment must be a positive amount. Recovering money from a supplier is a refund '.
                'with its own entry, not a negative payment.'
            );
        }

        $minor = $amount->getMinorAmount()->toInt();

        /*
         * NOT A DAY THAT HAS NOT HAPPENED (bug hunt B033). A receipt dated
         * tomorrow is refused, and a bill payment was not: "2027-09-24" for
         * "2026-09-24" posted BPY-2027-09 a year ahead and marked the bill paid
         * today, while "Paid this month" and Paid YTD left it out. Compared as
         * calendar dates against the ESTATE's today.
         */
        $paid = $paidOn instanceof Carbon ? $paidOn->copy() : Carbon::parse($paidOn);

        if ($paid->toDateString() > Ledger::today()->toDateString()) {
            throw new DomainException(sprintf(
                'A payment dated %s has not happened yet: today is %s. A payment is dated the day the money '.
                'moved, and a cheque written for a later date is paid on the day it clears.',
                $paid->format('j M Y'),
                Ledger::today()->format('j M Y'),
            ));
        }

        $this->refusePayment($bill, $minor, $overpaymentReason);

        return DB::connection('tenant')->transaction(function () use (
            $bill, $vendor, $minor, $amount, $method, $paid, $by, $reference, $overpaymentReason, $bankAccount
        ): BillPayment {
            /*
             * WHAT THE BILL STILL OWES, READ UNDER A LOCK (bug hunt B020). The
             * check above read it before the transaction, so two payments against
             * one bill — two tabs, one cheque keyed twice — could each find it
             * owing and each post, and the over-payment rule never saw the second.
             * The bill row is the lock both of them take, so the second waits for
             * the first and is asked the question again with the first's payment
             * in the answer.
             */
            $current = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            $this->refusePayment($current, $minor, $overpaymentReason);

            $payment = BillPayment::create([
                'bill_id' => $current->id,
                'amount_minor' => $minor,
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'method' => $method,
                'reference' => $reference,
                'paid_on' => $paid->toDateString(),
                'paid_by' => $by?->getKey(),
                'paid_by_name' => $by?->name,
                'overpayment_reason' => $overpaymentReason,
            ]);

            // The console's middot, never an em dash (Law 3), as a work order's own label already reads.
            $memo = 'Payment to '.$vendor->name.' · '.$current->referenceLabel();

            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit(self::PAYABLE, $minor, $memo, vendorId: $vendor->id),
                    Posting::credit($bankAccount, $minor, $memo),
                ],
                on: $paid,
                source: Ledger::SOURCE_BILL_PAYMENT,
                sourceId: $payment->id,
                by: $by,
                prefix: 'BPY',
            );

            $payment->forceFill(['journal_ref' => $entry->reference])->save();

            /*
             * Settled when the LEDGER says so, re-read after the entry. Marking
             * it paid because the amount looked like the whole bill would be the
             * billing table deciding what the accounts mean, and a part payment
             * followed by a rounding difference would close a bill that still
             * owes money.
             */
            if ($this->outstandingOf($current) <= 0) {
                $current->forceFill(['status' => Bill::PAID])->save();
            }

            $bill->setRawAttributes($current->getAttributes(), true);

            return $payment;
        });
    }

    /**
     * Why this payment cannot be posted against this bill, as it stands.
     *
     * Asked of the caller's copy first, so the ordinary refusal needs no lock,
     * and again of the row under the lock, where no concurrent payment can have
     * made the answer stale.
     */
    private function refusePayment(Bill $bill, int $minor, ?string $overpaymentReason): void
    {
        if (! $bill->isPosted()) {
            throw new DomainException(sprintf(
                'Bill [%s] is %s and has raised no liability, so there is nothing on 2000 to pay '.
                'down. Approve it first, so the estate\'s accounts say it owes the money before they '.
                'say it paid it.',
                $bill->reference,
                $bill->status,
            ));
        }

        $outstanding = $this->outstandingOf($bill);

        if ($minor > $outstanding && trim((string) $overpaymentReason) === '') {
            throw new DomainException(sprintf(
                'This payment of %s exceeds the %s still outstanding on bill [%s]. A payment cannot '.
                'exceed the bill without an explicit over-payment reason: the excess leaves the '.
                'vendor in debit on 2000, and a balance nobody explained is one nobody can recover.',
                $this->format($minor),
                $this->format($outstanding),
                $bill->reference,
            ));
        }
    }

    /* ------------------------------------------------------------------ */
    /* reading — every figure below is the ledger, read back */
    /* ------------------------------------------------------------------ */

    /** What the estate owes every supplier put together — board 27's KPI, board 25's 2000. */
    public function totalPayable(?Carbon $asAt = null): int
    {
        return $this->ledger->balanceOf($this->payableAccount(), $asAt)->getMinorAmount()->toInt();
    }

    /**
     * What the estate owes each vendor, from the control account's own lines.
     *
     * @return array<int, int> vendor id => minor units owed
     */
    public function vendorBalances(): array
    {
        $balances = [];

        foreach ($this->ledger->subsidiaryBalances($this->payableAccount()) as $vendorId => $balance) {
            // The empty key is every 2000 line posted without a vendor on it.
            // It cannot happen through this class, and it is dropped here rather
            // than silently folded into a vendor's balance.
            if ($vendorId !== '') {
                $balances[(int) $vendorId] = $balance;
            }
        }

        return $balances;
    }

    /**
     * What one bill still owes, summed from its own entries.
     *
     * The bill's `journal_ref` and its payments' are used as the LINK — which
     * entries belong to this bill — and nothing else. The arithmetic is the
     * ledger's: credits to 2000 less debits, across exactly those references.
     */
    public function outstandingOf(Bill $bill): int
    {
        return $this->outstandingByBill([$bill->id])[$bill->id] ?? 0;
    }

    /**
     * The same, for many bills at once.
     *
     * @param  list<int>|null  $billIds  null for every bill in the estate
     * @return array<int, int> bill id => minor units still outstanding
     */
    public function outstandingByBill(?array $billIds = null): array
    {
        $bills = DB::connection('tenant')->table('bills')->whereNotNull('journal_ref');
        $payments = DB::connection('tenant')
            ->table('bill_payments')
            ->whereNotNull('journal_ref');

        if ($billIds !== null) {
            $bills->whereIn('id', $billIds);
            $payments->whereIn('bill_id', $billIds);
        }

        /** @var array<string, int> $owner entry reference => the bill it belongs to */
        $owner = [];

        foreach ($bills->get(['id', 'journal_ref']) as $row) {
            $owner[(string) $row->journal_ref] = (int) $row->id;
        }

        foreach ($payments->get(['bill_id', 'journal_ref']) as $row) {
            $owner[(string) $row->journal_ref] = (int) $row->bill_id;
        }

        if ($owner === []) {
            return [];
        }

        $rows = DB::connection('tenant')
            ->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', self::PAYABLE)
            ->whereIn('journal_lines.entry_ref', array_keys($owner))
            ->selectRaw('journal_lines.entry_ref as entry_ref, SUM(journal_lines.credit_minor - journal_lines.debit_minor) as owed')
            ->groupBy('journal_lines.entry_ref')
            ->get();

        $outstanding = [];

        foreach ($rows as $row) {
            $billId = $owner[(string) $row->entry_ref];
            $outstanding[$billId] = ($outstanding[$billId] ?? 0) + (int) $row->owed;
        }

        return $outstanding;
    }

    /**
     * What each vendor has actually been paid, in a period.
     *
     * The DEBIT side of 2000, and only on entries a payment raised. A reversal
     * of a wrongly approved bill also debits 2000, and counting it as money paid
     * would tell a committee the estate had settled an invoice it had merely
     * cancelled.
     *
     * @return array<int, int> vendor id => minor units paid
     */
    public function paidByVendor(?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = DB::connection('tenant')
            ->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
            ->where('accounts.code', self::PAYABLE)
            ->where('journals.source', Ledger::SOURCE_BILL_PAYMENT)
            ->whereNotNull('journal_lines.vendor_id')
            ->selectRaw('journal_lines.vendor_id as vendor_id, SUM(journal_lines.debit_minor) as paid')
            ->groupBy('journal_lines.vendor_id');

        if ($from !== null) {
            $query->where('journals.posted_on', '>=', $from->toDateString());
        }

        if ($to !== null) {
            $query->where('journals.posted_on', '<=', $to->toDateString());
        }

        $paid = [];

        foreach ($query->get() as $row) {
            $paid[(int) $row->vendor_id] = (int) $row->paid;
        }

        return $paid;
    }

    /**
     * Which expense account each vendor's costs actually land on.
     *
     * Read from the journal, not from the vendor row: the pairing of a supplier
     * with a kind of cost is something the books already know, and storing it a
     * second time would let the two disagree. Where a vendor's bills straddle two
     * accounts the larger one wins, because the column is a description of the
     * supplier and not of any one invoice.
     *
     * @return array<int, string> vendor id => account code
     */
    public function expenseAccountsByVendor(): array
    {
        $rows = DB::connection('tenant')
            ->table('journal_lines as payable')
            ->join('accounts as control', 'control.id', '=', 'payable.account_id')
            ->join('journal_lines as expense', 'expense.entry_ref', '=', 'payable.entry_ref')
            ->join('accounts as cost', 'cost.id', '=', 'expense.account_id')
            ->where('control.code', self::PAYABLE)
            ->where('cost.type', Account::EXPENSE)
            ->whereNotNull('payable.vendor_id')
            ->selectRaw('payable.vendor_id as vendor_id, cost.code as code, SUM(expense.debit_minor) as spent')
            ->groupBy('payable.vendor_id', 'cost.code')
            ->orderByDesc('spent')
            ->get();

        $accounts = [];

        foreach ($rows as $row) {
            // Ordered by spend descending, so the first row for a vendor is the
            // account most of its money went to.
            $accounts[(int) $row->vendor_id] ??= (string) $row->code;
        }

        return $accounts;
    }

    /* ------------------------------------------------------------------ */
    /* what the screens draw */
    /* ------------------------------------------------------------------ */

    /**
     * Board 26 — the supplier register.
     *
     * WHAT EVERY SUPPLIER WAS PAID IS THE ESTATE'S SPENDING (M10 §5). The Admin
     * Assistant holds Entry on Accounting to add a supplier and record a bill,
     * and needs the register's names, trades, contacts and TRN flags to do it —
     * not the year's payments to each, their total, or what every supplier is
     * owed side by side. For a viewer who may not read the estate's position
     * (`Dues::maySeeEstatePosition()` on Accounting, asked by the controller)
     * those figures are null and are not read from the ledger at all.
     *
     * @param  bool  $maySeePosition  whether the viewer may read the estate's spending — see `Dues::maySeeEstatePosition()`
     * @return array<string, mixed>
     */
    public function vendorsBoard(bool $maySeePosition = true, ?TableQuery $table = null): array
    {
        $table ??= self::vendorsTable(null, $maySeePosition);
        $paidYtd = $maySeePosition ? $this->paidByVendor(Ledger::today()->startOfYear()) : [];
        $owed = $maySeePosition ? $this->vendorBalances() : [];
        $accounts = $this->expenseAccountsByVendor();

        $rows = [];

        foreach (Vendor::query()->orderBy('id')->get() as $vendor) {
            $code = $accounts[$vendor->id] ?? null;

            // The register's search (M32 F5): the supplier, its trade, its contact and the expense group it is filed under.
            if ($table->search !== '' && ! str_contains(mb_strtolower(implode(' ', [$vendor->name, $vendor->category, $vendor->contactLine(), $vendor->contact_email, $code === null ? '' : (self::EXPENSE_GROUPS[$code] ?? $code)])), mb_strtolower($table->search))) {
                continue;
            }

            $rows[] = [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'initials' => $vendor->initials(),
                'trade' => (string) ($vendor->category ?? ''),
                'category' => $code === null ? null : (self::EXPENSE_GROUPS[$code] ?? $code),
                'contact' => $vendor->contactLine(),
                'paid_ytd_minor' => $maySeePosition ? ($paidYtd[$vendor->id] ?? 0) : null,
                'owed_minor' => $maySeePosition ? ($owed[$vendor->id] ?? 0) : null,
                'status' => $vendor->status,
                'status_label' => ucfirst($vendor->status),
                'has_trn' => $vendor->hasTrn(),
            ];
        }

        // The whole register, ordered by the server and cut to the page (M32 F5, `TableQuery::pageOf`).
        $page = $table->pageOf($rows);

        return [
            'rows' => $page->items(),
            'table' => $table->meta($page),

            // Drawn nowhere on the board, and carried anyway: it is the figure a
            // treasurer checks the column against, and computing it in the page
            // would put arithmetic over money in a template. Every supplier the
            // search admits, not this page's.
            'paid_ytd_total_minor' => $maySeePosition ? array_sum(array_column($rows, 'paid_ytd_minor')) : null,
            'positionWithheld' => ! $maySeePosition,
            'positionReason' => Dues::POSITION_WITHHELD.' Each bill\'s own amount is on the supplier\'s record.',
        ];
    }

    /**
     * What the supplier register orders by (M32 F5): its rows' own fields. Name order by default — it is a register.
     * What each was paid and is owed only for a viewer who may read the estate's spending (M10 §5).
     *
     * @return array<string, string>
     */
    public static function vendorSorts(bool $maySeePosition = true): array
    {
        return [
            'name' => 'name',
            'trade' => 'trade',
            'category' => 'category',
            'contact' => 'contact',
            ...($maySeePosition ? ['paid' => 'paid_ytd_minor', 'owed' => 'owed_minor'] : []),
            'status' => 'status',
        ];
    }

    public static function vendorsTable(?Request $request = null, bool $maySeePosition = true): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::vendorSorts($maySeePosition), 'name');
    }

    /**
     * What Bills & payments orders by (M32 F5). Soonest due first by default: the question this screen is asked is
     * what is owed next. Status by board 27's badge — overdue, unpaid, draft, paid.
     *
     * @return array<string, string|\Closure>
     */
    public static function billSorts(): array
    {
        $statusOrder = ['overdue' => 1, 'unpaid' => 2, 'draft' => 3, 'paid' => 4, 'void' => 5];

        return [
            'vendor' => static fn (array $row): string => (string) $row['vendor']['name'],
            'reference' => 'reference',
            'amount' => 'amount_minor',
            'outstanding' => 'outstanding_minor',
            'due' => 'due_sort',
            'status' => static fn (array $row): int => $statusOrder[$row['status']] ?? 9,
        ];
    }

    public static function billsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::billSorts(), 'due');
    }

    /**
     * Board 39 — one vendor's own sub-ledger.
     *
     * "Currently owed" is this vendor's balance on 2000 and NOT the sum of its
     * unpaid bills. The two agree, and they agree because every bill posts; the
     * board's own note says the hero figures are computed over the whole bill set
     * rather than the rows drawn beneath them, which only the ledger can do.
     *
     * THE BILLS STAY; THE TWO HERO TOTALS DO NOT, where the estate's position is
     * withheld (M10 §5). Each bill's amount and what it still owes are what the
     * office records and pays against. "Paid YTD" and "Currently owed" are the
     * estate's spending with this supplier summed, and are absent rather than
     * blank — the strip reflows to the count and the date.
     *
     * THE HISTORY IS THE SHARED TABLE (M32 F5): every bill, ordered over the whole set and cut to the page; the hero
     * figures are still the whole set's.
     *
     * @param  bool  $maySeePosition  whether the viewer may read the estate's spending — see `Dues::maySeeEstatePosition()`
     * @return array<string, mixed>
     */
    public function vendorBoard(Vendor $vendor, bool $maySeePosition = true, ?TableQuery $table = null): array
    {
        $table ??= self::vendorBillsTable();
        $outstanding = $this->outstandingByBill();

        $bills = $vendor->bills()
            ->with('payments')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];

        foreach ($bills as $bill) {
            $settled = $bill->payments->sortByDesc('paid_on')->first();
            $state = $bill->boardStatus();

            $rows[] = [
                'id' => $bill->id,
                'reference' => $bill->referenceLabel(),
                'ticket_id' => $bill->ticket_id,
                'amount_minor' => $bill->amount_minor,
                'outstanding_minor' => $outstanding[$bill->id] ?? 0,
                'date' => ($bill->approved_at ?? $bill->created_at)?->format('j M Y'),
                // What the history orders by (M32 F5): the day it was agreed; one not yet agreed has none.
                'approved_sort' => $bill->approved_at?->toIso8601String(),
                'status' => $state,
                'status_label' => $state === 'paid' && $settled !== null
                    ? 'Paid '.$settled->paid_on->format('j M')
                    : self::STATUS_LABELS[$state],

                // Why a void bill is void, and who decided (B036).
                'void_note' => $state === 'void'
                    ? trim('Voided'.($bill->voided_by_name === null ? '' : ' by '.$bill->voided_by_name)
                        .($bill->voided_at === null ? '' : ' on '.$bill->voided_at->format('j M Y'))
                        .($bill->void_reason === null ? '' : ': '.$bill->void_reason))
                    : null,
            ];
        }

        /*
         * "Vendor since" is the oldest bill the estate agreed, and the vendor's
         * own record only where there is none. A supplier that has been on the
         * register for a year and never invoiced is a relationship that started
         * when it was added, which is a different fact and the only one there is.
         */
        $since = $vendor->created_at;

        if ($bills->isNotEmpty()) {
            $oldest = $bills->last();
            $since = $oldest->approved_at ?? $oldest->created_at ?? $since;
        }

        $page = $table->pageOf($rows);

        return [
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'initials' => $vendor->initials(),
                'trade' => (string) ($vendor->category ?? ''),
                'contact_name' => $vendor->contact_name,
                'contact_phone' => $vendor->contact_phone,
                'contact_email' => $vendor->contact_email,
                'trn' => $vendor->trn,
                'status' => $vendor->status,
                'status_label' => ucfirst($vendor->status),

                // "Electrical contractor · Owen Grant · (876) 555 0110" — the
                // trade and the contact line, and whichever of them exists.
                'sub' => implode(' · ', array_filter([
                    $vendor->category,
                    $vendor->contactLine(),
                ])),
            ],
            'stats' => [
                ...($maySeePosition ? [
                    ['key' => 'paid_ytd', 'value_minor' => $this->paidByVendor(Ledger::today()->startOfYear())[$vendor->id] ?? 0, 'label' => 'Paid YTD'],
                    ['key' => 'owed', 'value_minor' => $this->vendorBalances()[$vendor->id] ?? 0, 'label' => 'Currently owed'],
                ] : []),

                // A count and a date, not money. The board draws all four in the
                // same strip, so the shape carries both and the page decides how
                // to print each.
                ['key' => 'jobs', 'value' => $bills->where('status', Bill::PAID)->count(), 'label' => 'Jobs completed'],
                ['key' => 'since', 'value' => $since?->format('M Y'), 'label' => 'Vendor since'],
            ],
            'bills' => array_values($page->items()),
            'table' => $table->meta($page),
            'positionWithheld' => ! $maySeePosition,
            'positionReason' => Dues::POSITION_WITHHELD.' Each bill\'s own amount is below.',
        ];
    }

    /**
     * What one supplier's bill history orders by (M32 F5). Date is the order it always had: the newest agreed bill
     * first, and a bill not yet agreed after them all.
     *
     * @return array<string, string|\Closure>
     */
    public static function vendorBillSorts(): array
    {
        $statusOrder = ['overdue' => 1, 'unpaid' => 2, 'draft' => 3, 'paid' => 4, 'void' => 5];

        return [
            'reference' => 'reference',
            'amount' => 'amount_minor',
            'date' => static fn (array $row): array => [$row['approved_sort'] === null ? 0 : 1, (string) $row['approved_sort'], $row['id']],
            'status' => static fn (array $row): int => $statusOrder[$row['status']] ?? 9,
        ];
    }

    /** One supplier's bill history as the address asks for it (M32 F5): the newest agreed bill first. */
    public static function vendorBillsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::vendorBillSorts(), 'date', 'desc');
    }

    /**
     * Board 27 — the payables sub-ledger and its four tiles.
     *
     * THE TABLE IS OPEN BILLS PLUS WHAT WAS SETTLED THIS MONTH, which is exactly
     * the five rows the board draws: four unpaid and one paid five days ago. A
     * payables screen listing every bill the estate ever paid would bury the
     * only question it is asked — what is still owed — and a treasurer who has
     * just paid something still needs to see that it went.
     *
     * THE ROWS ARE THE OFFICE'S; THE THREE MONEY TILES ARE THE ESTATE'S (M10 §5).
     * The Admin Assistant pays a bill from its row, and the pay form is filled
     * from that bill's `outstanding_minor` — so each row keeps its figures.
     * "Total payable", "Overdue" and "Paid this month" are what the estate owes
     * and spends as a whole; for a viewer who may not read its position they
     * are absent, and "Due this week", a count, stays.
     *
     * @param  bool  $maySeePosition  whether the viewer may read the estate's payables as a whole — see `Dues::maySeeEstatePosition()`
     * @return array<string, mixed>
     */
    public function billsBoard(?Carbon $asAt = null, bool $maySeePosition = true, ?TableQuery $table = null): array
    {
        // The estate's today, not UTC's: a bill due on the 30th is not overdue at 8 PM that evening (B023).
        $today = $asAt?->copy() ?? Ledger::today();
        $table ??= self::billsTable();
        $outstanding = $this->outstandingByBill();

        $bills = Bill::query()
            ->with(['vendor', 'payments'])
            ->where('status', '!=', Bill::VOID)
            ->where(function (Builder $query) use ($today): void {
                $query->whereIn('status', [Bill::DRAFT, Bill::APPROVED])
                    ->orWhereHas(
                        'payments',
                        fn (Builder $paid) => $paid->where('paid_on', '>=', $today->copy()->startOfMonth()->toDateString()),
                    );
            })
            ->orderBy('id')
            ->get();

        $rows = [];
        $overdue = 0;
        $dueThisWeek = 0;

        foreach ($bills as $bill) {
            $state = $bill->boardStatus($today);
            $settled = $bill->payments->sortByDesc('paid_on')->first();
            $owed = $outstanding[$bill->id] ?? 0;

            if ($state === 'overdue') {
                $overdue += $owed;
            }

            if ($state === 'unpaid' && $bill->due_on->lessThanOrEqualTo($today->copy()->addDays(7))) {
                $dueThisWeek++;
            }

            $rows[] = [
                'id' => $bill->id,
                'vendor' => [
                    'id' => $bill->vendor_id,
                    'name' => $bill->vendor->name,
                    'initials' => $bill->vendor->initials(),
                    'has_trn' => $bill->vendor->hasTrn(),
                ],
                'reference' => $bill->referenceLabel(),
                'ticket_id' => $bill->ticket_id,
                'is_ticket' => $bill->ticket_id !== null,
                'amount_minor' => $bill->amount_minor,
                'outstanding_minor' => $owed,
                'due_on' => $bill->due_on->format('j M Y'),
                // What the Due heading orders by (M32 F5): the date itself, not the words it is printed in.
                'due_sort' => $bill->due_on->toDateString(),
                'status' => $state,
                'status_label' => $state === 'paid' && $settled !== null
                    ? 'Paid '.$settled->paid_on->format('j M')
                    : self::STATUS_LABELS[$state],

                /*
                 * The payment the row's remittance advice is issued against —
                 * the most recent one, which is what settled it. Null while the
                 * bill is unpaid, and the row draws no document control then.
                 */
                'settled_payment_id' => $settled?->id,
            ];
        }

        // The tiles above are every bill's; the table is the search's bills, ordered by the server and paged (M32 F5).
        if ($table->search !== '') {
            $needle = mb_strtolower($table->search);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower($row['vendor']['name'].' '.$row['reference']), $needle)));
        }

        $page = $table->pageOf($rows);
        $rows = $page->items();

        return [
            'table' => $table->meta($page),
            'kpis' => [
                ...($maySeePosition ? [
                    ['key' => 'payable', 'value_minor' => $this->totalPayable(), 'label' => 'Total payable'],
                    ['key' => 'overdue', 'value_minor' => $overdue, 'label' => 'Overdue'],
                ] : []),

                // A COUNT, and the board draws it without a currency symbol.
                ['key' => 'due_week', 'value' => $dueThisWeek, 'label' => 'Due this week'],

                ...($maySeePosition
                    ? [['key' => 'paid_month', 'value_minor' => array_sum($this->paidByVendor($today->copy()->startOfMonth(), $today)), 'label' => 'Paid this month']]
                    : []),
            ],
            'rows' => $rows,
            'positionWithheld' => ! $maySeePosition,
            'positionReason' => Dues::POSITION_WITHHELD.' Each bill\'s own amount and what it still owes are on its row.',
        ];
    }

    /**
     * Board 28 — the statement on the left, what is still to account for on the
     * right.
     *
     * @return array<string, mixed>
     */
    public function reconciliationBoard(?BankReconciliation $reconciliation = null): array
    {
        $reconciliation ??= $this->currentReconciliation();

        if ($reconciliation === null) {
            return ['reconciliation' => null, 'lines' => [], 'ledger_side' => [], 'counts' => ['unmatched' => 0, 'to_match' => 0]];
        }

        $lines = $reconciliation->lines()->get();
        $candidates = $this->ledgerCandidates($reconciliation);

        $rows = [];
        $ledgerSide = [];

        foreach ($lines as $line) {
            $rows[] = [
                'id' => $line->id,
                'description' => Bill::plain($line->description),
                'date' => $line->value_date->format('j M'),

                // The bank's own annotation, drawn after the date with a middot.
                'note' => $line->bank_reference,
                'amount_minor' => $line->amount_minor,
                'is_withdrawal' => $line->isWithdrawal(),
                'matched' => $line->isMatched(),
                'matched_entry_ref' => $line->matched_entry_ref,
            ];

            if ($line->isMatched()) {
                continue;
            }

            $candidate = $candidates[$line->id] ?? null;

            $ledgerSide[] = [
                'line_id' => $line->id,
                'amount_minor' => $line->amount_minor,

                /*
                 * A PROPOSAL, NOT A MATCH. An unclaimed entry on this account,
                 * in this period, for exactly this amount and in the same
                 * direction is a strong candidate and nothing more — the estate
                 * can genuinely pay two suppliers the same amount in a month, so
                 * a person still has to say which one this is. Where there is no
                 * candidate at all the line is a bank-only item and needs an
                 * entry raising before it can be matched to anything.
                 */
                'entry_ref' => $candidate === null ? null : $candidate['reference'],
                'label' => $candidate === null ? 'No ledger entry for this line' : $candidate['memo'],
                'hint' => $candidate === null ? 'Needs a journal entry' : 'No bank line linked yet',
            ];
        }

        $difference = $this->differenceOf($reconciliation);

        return [
            'reconciliation' => [
                'id' => $reconciliation->id,
                'account' => $reconciliation->account->optionLabel(),
                'period' => $reconciliation->periodLabel(),
                'statement_date' => $reconciliation->statement_date->format('j M Y'),
                'opening_minor' => $reconciliation->opening_minor,
                'closing_minor' => $reconciliation->closing_minor,
                'movement_minor' => $reconciliation->movementMinor(),
                'difference_minor' => $difference,
                'can_complete' => $difference === 0 && ! $reconciliation->isCompleted(),
                'status' => $reconciliation->status,
            ],
            'lines' => $rows,
            'ledger_side' => $ledgerSide,
            'counts' => [
                'unmatched' => count($ledgerSide),
                'to_match' => count($ledgerSide),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* bank reconciliation */
    /* ------------------------------------------------------------------ */

    /** The reconciliation a treasurer is working on — the newest one still open. */
    public function currentReconciliation(): ?BankReconciliation
    {
        return BankReconciliation::query()
            ->with('account')
            ->orderByRaw("status = '".BankReconciliation::COMPLETED."'")
            ->orderByDesc('statement_date')
            ->first();
    }

    /**
     * Assert that one statement line and one posted entry are the same event.
     *
     * NOTHING ON THE LEDGER SIDE IS WRITTEN. The claim is recorded on the
     * statement line, which is the only side of this that can be changed — and
     * has to be, because a treasurer will pair the wrong two items.
     */
    public function match(BankStatementLine $line, string $entryRef): BankStatementLine
    {
        $reconciliation = $line->reconciliation;

        if ($reconciliation->isCompleted()) {
            throw new DomainException(sprintf(
                'The %s reconciliation is completed and its matching is closed. Re-opening a signed-off '.
                'period is a decision, not a correction.',
                $reconciliation->periodLabel(),
            ));
        }

        if ($line->isMatched()) {
            throw new DomainException(sprintf(
                'Statement line [%s] is already matched to entry [%s]. Unmatch it first, so the change '.
                'is two deliberate acts rather than one silent overwrite.',
                $line->description,
                (string) $line->matched_entry_ref,
            ));
        }

        $entry = Journal::where('reference', $entryRef)->first();

        if ($entry === null) {
            throw new DomainException(
                "No posted entry [{$entryRef}] in this estate's ledger. A match is a claim that a bank ".
                'line and an entry are the same event, and a claim about an entry that does not exist '.
                'is what a reconciliation is meant to catch.'
            );
        }

        /*
         * THE SAME EVENT, OR NO MATCH (bug hunt B034). The screen only ever
         * proposes an entry on the reconciled account, for the line's amount and
         * in its direction; the server used to take any reference that existed.
         * A crafted match to a dues charge that never touched the bank zeroed the
         * difference, the period was signed off, and signing off is for good. So
         * the entry has to have moved THIS account, by exactly this line's
         * signed amount — money in against money in — and must not already be
         * claimed on any statement.
         */
        $movement = DB::connection('tenant')
            ->table('journal_lines')
            ->where('entry_ref', $entry->reference)
            ->where('account_id', $reconciliation->account_id)
            ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(debit_minor - credit_minor), 0) as movement')
            ->first();

        $account = $reconciliation->account;

        if ($movement === null || (int) $movement->line_count === 0) {
            throw new DomainException(sprintf(
                'Entry [%s] never moved %s %s, so it cannot be the bank line "%s". A match is a claim that the '.
                'two are one event, and an entry that never touched this account is not the event its '.
                'statement records.',
                $entry->reference,
                $account->code,
                $account->name,
                $line->description,
            ));
        }

        if ((int) $movement->movement !== $line->amount_minor) {
            throw new DomainException(sprintf(
                'Entry [%s] moved %s %s by %s, and this bank line is %s. One event moves the bank by one '.
                'amount in one direction, so these are two different events.',
                $entry->reference,
                $account->code,
                $account->name,
                $this->signed((int) $movement->movement),
                $this->signed($line->amount_minor),
            ));
        }

        $claim = BankStatementLine::query()
            ->with('reconciliation')
            ->where('matched_entry_ref', $entryRef)
            ->whereKeyNot($line->getKey())
            ->first();

        if ($claim !== null && (int) $claim->bank_reconciliation_id === (int) $reconciliation->id) {
            throw new DomainException(sprintf(
                'Entry [%s] is already matched to another line on this statement. One entry is one '.
                'event, and matching it twice would account for the same money twice.',
                $entryRef,
            ));
        }

        if ($claim !== null) {
            throw new DomainException(sprintf(
                'Entry [%s] is already matched to a line on the %s statement. One entry is one event, and '.
                'claiming it on two statements would account for the same money twice.',
                $entryRef,
                $claim->reconciliation->periodLabel(),
            ));
        }

        $line->forceFill(['matched_entry_ref' => $entryRef, 'matched_at' => now()])->save();

        return $line;
    }

    /** A signed movement as a refusal message prints it — "+5,000.00", "−6,500.00". */
    private function signed(int $minor): string
    {
        return ($minor < 0 ? '−' : '+').$this->format(abs($minor));
    }

    /** Take the claim back. The entry is untouched, because it always was. */
    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        if ($line->reconciliation->isCompleted()) {
            throw new DomainException(sprintf(
                'The %s reconciliation is completed. Unpicking a match inside it would change a figure '.
                'somebody has already signed off.',
                $line->reconciliation->periodLabel(),
            ));
        }

        $line->forceFill(['matched_entry_ref' => null, 'matched_at' => null])->save();

        return $line;
    }

    /**
     * What the bank reports moving, less everything the estate has claimed.
     *
     * Zero means the whole month is accounted for. Anything else is money one
     * side knows about and the other does not — and that residue is the entire
     * value of a reconciliation.
     */
    public function differenceOf(BankReconciliation $reconciliation): int
    {
        $matched = (int) DB::connection('tenant')
            ->table('bank_statement_lines')
            ->where('bank_reconciliation_id', $reconciliation->id)
            ->whereNotNull('matched_entry_ref')
            ->sum('amount_minor');

        return $reconciliation->movementMinor() - $matched;
    }

    /**
     * Sign the period off. REFUSED WHILE A DIFFERENCE REMAINS.
     *
     * The message names the figure, because "there is a difference" tells a
     * treasurer only that something is wrong and nothing about what — and the
     * amount is usually enough on its own to say which line it is.
     */
    public function complete(BankReconciliation $reconciliation, ?User $by = null): BankReconciliation
    {
        if ($reconciliation->isCompleted()) {
            throw new DomainException(sprintf(
                'The %s reconciliation was completed on %s. Completing it again would restate a period '.
                'that is already signed off.',
                $reconciliation->periodLabel(),
                $reconciliation->completed_at?->format('j M Y') ?? 'an earlier date',
            ));
        }

        $difference = $this->differenceOf($reconciliation);

        if ($difference !== 0) {
            $unmatched = (int) BankStatementLine::query()
                ->where('bank_reconciliation_id', $reconciliation->id)
                ->whereNull('matched_entry_ref')
                ->count();

            throw new DomainException(sprintf(
                'The %s reconciliation is out by %s across %d unmatched line%s and cannot be completed. '.
                'The difference is the movement the bank reports less every line the estate has claimed '.
                'as one of its own entries; signing that off is exactly what a reconciliation exists to '.
                'prevent.',
                $reconciliation->periodLabel(),
                $this->format(abs($difference)),
                $unmatched,
                $unmatched === 1 ? '' : 's',
            ));
        }

        $reconciliation->forceFill([
            'status' => BankReconciliation::COMPLETED,
            'completed_at' => now(),
            'reconciled_by' => $by?->getKey(),
            'reconciled_by_name' => $by?->name,
        ])->save();

        return $reconciliation;
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * An unclaimed entry for each unmatched line, where one exists.
     *
     * Compared on the SIGNED movement, so a $6,500 payment out cannot be
     * proposed against a $6,500 receipt in. The movement is summed from the
     * entry's own lines on the reconciled account, which is the only thing that
     * makes "this entry moved the bank by this much" true of an entry with more
     * than two lines — a payroll batch touching the bank once and two liabilities
     * has a total that is nothing like its bank leg.
     *
     * @return array<int, array{reference: string, memo: string, movement: int}>
     */
    private function ledgerCandidates(BankReconciliation $reconciliation): array
    {
        $lines = $reconciliation->lines()->whereNull('matched_entry_ref')->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $month = $reconciliation->statement_date->copy();

        $entries = DB::connection('tenant')
            ->table('journals')
            ->join('journal_lines', 'journal_lines.entry_ref', '=', 'journals.reference')
            ->where('journal_lines.account_id', $reconciliation->account_id)
            ->whereBetween('journals.posted_on', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->selectRaw('journals.reference as reference, journals.memo as memo, SUM(journal_lines.debit_minor - journal_lines.credit_minor) as movement')
            ->groupBy('journals.reference', 'journals.memo')
            ->get();

        // Every entry any statement has already claimed, so the same entry is
        // never proposed twice — nor proposed here after another statement
        // claimed it, which `match()` would refuse (B034).
        $claimed = BankStatementLine::query()
            ->whereIn('matched_entry_ref', $entries->pluck('reference')->all())
            ->pluck('matched_entry_ref')
            ->all();

        $candidates = [];

        foreach ($lines as $line) {
            foreach ($entries as $entry) {
                $reference = (string) $entry->reference;

                if ((int) $entry->movement !== $line->amount_minor || in_array($reference, $claimed, true)) {
                    continue;
                }

                $candidates[$line->id] = [
                    'reference' => $reference,
                    'memo' => (string) $entry->memo,
                    'movement' => (int) $entry->movement,
                ];

                $claimed[] = $reference;

                break;
            }
        }

        return $candidates;
    }

    private function payableAccount(): Account
    {
        return Account::where('code', self::PAYABLE)->firstOrFail();
    }

    /** The memo both sides of an approval carry — the supplier and the work, "Gate2 Contractor Ltd · #1031 · Barrier arm sensor". */
    private function memoFor(Bill $bill): string
    {
        return mb_substr($bill->vendor->name.' · '.$bill->referenceLabel(), 0, 200);
    }

    /** "AP-2026-09-0004" — sequential within the month a bill falls due. */
    private function nextBillReference(Carbon $due): string
    {
        $stem = sprintf('AP-%s-', $due->format('Y-m'));

        // Past every reference the unique index holds, a retired sample bill's too (M33 §5).
        $last = Retirements::seeingEverything(static fn (): mixed => Bill::query()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference'));

        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($stem))) + 1;

        return $stem.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Minor units as a refusal message prints them — "12,000.00". */
    private function format(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}
