<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\Vendor;
use App\Services\Estate\Payables;
use Brick\Money\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phoenix Park's suppliers, its open bills and last month's bank statement —
 * boards 26, 27, 28 and 39.
 *
 * THE FIGURE EVERYTHING HANGS ON IS J$121,690. Board 27's four open bills —
 * J$8,500 + J$12,000 + J$6,890 + J$94,300 — are also board 27's "Total payable"
 * tile, also board 25's balance for 2000 Accounts Payable, and also board 25's
 * "Bills payable" KPI. Four screens, one number, and it is only one number
 * because every bill here posts through `Payables` and every one of those totals
 * is the same journal lines read back. The settled Gate2 bill is correctly
 * outside it: it was paid, so its credit to 2000 has a debit against it.
 *
 * IT IS IDEMPOTENT AND IT HAS TO BE. Journals are append-only. A seeder that
 * re-approved these five bills on a second run would double the estate's
 * liabilities with no way back — so every bill is checked for before it is
 * recorded, and the reconciliation is written once or not at all.
 *
 * ASSUMPTION: the TRNs are invented. No board draws one, and every vendor needs
 * one on file or none of these bills could be paid at all — which is the rule
 * `Payables::pay()` enforces and the reason the column exists.
 *
 * CONTENT RESIDUAL — board 28 draws three of its five statement lines as already
 * matched, and none of the three can be matched here. A maintenance-fee deposit
 * of exactly J$612,400, a prior Island Electric invoice and a staff payroll batch
 * are entries this estate does not have: the first would have to replace the dues
 * receipts the arrears fit already posted, the second is a bill nobody drew in
 * full, and the third is board 30's. Matching a statement line to an entry that
 * does not exist — or to one for a different amount — is exactly the false tie a
 * reconciliation exists to prevent, so all five lines are seeded as the statement
 * itself reads and the matching is left to be done on the screen.
 */
class PayablesSeeder extends Seeder
{
    /**
     * Board 26's register, in the order the board draws it.
     *
     * `trade` is what board 26 prints under the name and board 39 calls the
     * vendor's category; the coarse Maintenance/Utilities column is NOT stored
     * beside it, because it is which expense account the vendor's bills land on
     * and `Payables` reads that back from the journal.
     *
     * @var list<array{name: string, trade: string, trn: string, contact: ?string, phone: ?string, email: ?string}>
     */
    private const VENDORS = [
        ['name' => 'Island Electric Services', 'trade' => 'Electrical contractor', 'trn' => '100-482-517', 'contact' => 'Owen Grant', 'phone' => '(876) 555 0110', 'email' => null],
        ['name' => 'AquaTech Pool Services', 'trade' => 'Pool & water systems', 'trn' => '100-517-234', 'contact' => null, 'phone' => '(876) 555 0123', 'email' => null],
        ['name' => 'FitFix Equipment Repair', 'trade' => 'Gym & fitness equipment', 'trn' => '100-663-908', 'contact' => null, 'phone' => '(876) 555 0141', 'email' => null],
        ['name' => 'Gate2 Contractor Ltd', 'trade' => 'Barrier & access systems', 'trn' => '100-741-226', 'contact' => null, 'phone' => '(876) 555 0159', 'email' => null],
        // A real company's name, so never its real mailbox: a seeded address reaches nobody (M35 §5).
        ['name' => 'Jamaica Public Service Co.', 'trade' => 'Utilities', 'trn' => '100-000-155', 'contact' => null, 'phone' => null, 'email' => 'customercare@example.com'],
    ];

    /**
     * Board 27's five bills, verbatim, in the order the board draws them.
     *
     * `account` is the board's own implication: a ticket-linked job is
     * maintenance and the electricity bill is a utility, which is also what makes
     * board 26's Category column derivable rather than stored.
     *
     * @var list<array{
     *     vendor: string,
     *     description: string,
     *     amount: int,
     *     due: string,
     *     account: string,
     *     ticket: ?int,
     *     label: ?string,
     *     paid: ?string,
     * }>
     */
    private const BILLS = [
        ['vendor' => 'Island Electric Services', 'description' => 'Gate lighting', 'amount' => 8_500_00, 'due' => '2026-09-18', 'account' => Payables::MAINTENANCE, 'ticket' => 1042, 'label' => 'Ticket #1042 · Gate lighting', 'paid' => null],
        ['vendor' => 'AquaTech Pool Services', 'description' => 'Pool filter fault', 'amount' => 12_000_00, 'due' => '2026-09-02', 'account' => Payables::MAINTENANCE, 'ticket' => 1041, 'label' => 'Ticket #1041 · Pool filter fault', 'paid' => null],
        ['vendor' => 'FitFix Equipment Repair', 'description' => 'Gym equipment', 'amount' => 6_890_00, 'due' => '2026-09-22', 'account' => Payables::MAINTENANCE, 'ticket' => 1037, 'label' => 'Ticket #1037 · Gym equipment', 'paid' => null],
        ['vendor' => 'Gate2 Contractor Ltd', 'description' => 'Barrier arm sensor', 'amount' => 14_200_00, 'due' => '2026-09-09', 'account' => Payables::MAINTENANCE, 'ticket' => 1031, 'label' => 'Ticket #1031 · Barrier arm sensor', 'paid' => '2026-09-05'],
        ['vendor' => 'Jamaica Public Service Co.', 'description' => 'Common area electricity · August', 'amount' => 94_300_00, 'due' => '2026-09-25', 'account' => Payables::UTILITIES, 'ticket' => null, 'label' => null, 'paid' => null],
    ];

    /**
     * How many days before its due date a bill is agreed.
     *
     * FIFTEEN, AND IT IS DERIVED, NOT CHOSEN. Board 39 dates the Island Electric
     * gate-lighting bill Sep 3, 2026 and board 27 gives the same bill a due date
     * of Sep 18 — a fortnight and a day. Applying that term to the other four
     * puts each of them on a date the boards do not contradict.
     */
    private const TERM_DAYS = 15;

    /**
     * Board 28's statement, in the order the board draws it.
     *
     * SIGNED, because that is what a statement is. Money in is positive and
     * money out negative; the board prints neither sign, and the direction is
     * what stops a payment out being matched to a receipt in.
     *
     * The day is a day of the month rather than a date: the board draws "Aug 1"
     * with no year, and the statement is always last month's.
     *
     * @var list<array{day: int, description: string, amount: int, reference: ?string}>
     */
    private const STATEMENT = [
        ['day' => 1, 'description' => 'POS DEP · MAINT FEES', 'amount' => 612_400_00, 'reference' => null],
        ['day' => 5, 'description' => 'EFT · ISLAND ELECTRIC SVC', 'amount' => -9_650_00, 'reference' => 'prior invoice'],
        ['day' => 28, 'description' => 'SALARY BATCH · STAFF', 'amount' => -323_005_00, 'reference' => null],
        ['day' => 14, 'description' => 'EFT · UNKNOWN REF 88213', 'amount' => -6_500_00, 'reference' => '88213'],
        ['day' => 31, 'description' => 'BANK FEE · MONTHLY', 'amount' => -1_200_00, 'reference' => null],
    ];

    /**
     * What the bank said the operating account closed at.
     *
     * Board 25 draws J$3,890,214 against 1000 Operating Bank Account, so that is
     * the closing figure. The opening is that less the month's movement, which
     * makes the statement internally true: opening plus every line equals
     * closing, and the difference a reconciliation reports is then exactly what
     * has not yet been claimed.
     */
    private const CLOSING = 3_890_214_00;

    public function run(): void
    {
        $vendors = $this->seedVendors();

        $this->seedBills($vendors);
        $this->seedStatement();
    }

    /**
     * The register. Keyed on the name, which is what the boards identify a
     * supplier by and what a bill is recorded against.
     *
     * PUBLIC, because the facilities seeder needs it before this one runs.
     * Board 17 assigns four of these five suppliers to maintenance tickets, and
     * a ticket cannot be assigned to a vendor that does not exist yet — while
     * the bills below cannot be recorded until those same tickets do, because
     * `bills.ticket_id` is a foreign key to them. Calling this step directly is
     * the only ordering that satisfies both, and a second copy of the register
     * in the other seeder would be a second place for a TRN to be wrong.
     *
     * @return array<string, Vendor>
     */
    public function seedVendors(): array
    {
        $vendors = [];

        foreach (self::VENDORS as $row) {
            $vendor = Vendor::firstOrNew(['name' => $row['name']]);

            $vendor->fill([
                'category' => $row['trade'],
                'contact_name' => $row['contact'],
                'contact_phone' => $row['phone'],
                'contact_email' => $row['email'],
                'status' => Vendor::ACTIVE,
            ]);

            /*
             * The TRN is written once and never overwritten. It is the only
             * field on this record that decides whether money may leave the
             * estate, and a seed that reset it on every run could quietly undo a
             * treasurer taking it off a vendor whose paperwork had lapsed.
             */
            if (! $vendor->exists) {
                $vendor->trn = $row['trn'];
            }

            $vendor->save();

            $vendors[$row['name']] = $vendor;
        }

        return $vendors;
    }

    /**
     * The five bills, each recorded, approved, and paid where the board says it
     * was paid.
     *
     * @param  array<string, Vendor>  $vendors
     */
    private function seedBills(array $vendors): void
    {
        $payables = app(Payables::class);

        foreach (self::BILLS as $row) {
            $vendor = $vendors[$row['vendor']];

            /*
             * Checked before anything is posted, and checked on the vendor and
             * the description rather than on a reference this seeder does not
             * allocate. Journals are append-only: a second run that approved
             * these again would double what the estate owes, and no application
             * path could put it back.
             */
            $exists = Bill::query()
                ->where('vendor_id', $vendor->id)
                ->where('description', $row['description'])
                ->exists();

            if ($exists) {
                continue;
            }

            $due = Carbon::parse($row['due']);

            $bill = $payables->recordBill(
                vendor: $vendor,
                amount: Money::ofMinor($row['amount'], 'JMD'),
                description: $row['description'],
                dueOn: $due,
                account: $row['account'],
                ticketId: $row['ticket'],
                ticketLabel: $row['label'],
            );

            $payables->approve($bill, on: $due->copy()->subDays(self::TERM_DAYS));

            if ($row['paid'] === null) {
                continue;
            }

            $payables->pay(
                bill: $bill,
                amount: Money::ofMinor($row['amount'], 'JMD'),
                method: 'bank',
                paidOn: Carbon::parse($row['paid']),
            );
        }
    }

    /**
     * Last month's statement for the operating account.
     *
     * WRITTEN ONCE OR NOT AT ALL. A second reconciliation for the same account
     * and month is refused by a unique key, and one for a different month would
     * be a statement nobody received — so an estate that already has one is left
     * alone entirely.
     */
    private function seedStatement(): void
    {
        if (BankReconciliation::query()->exists()) {
            return;
        }

        $account = Account::where('code', Payables::BANK)->first();

        if ($account === null) {
            return;
        }

        $month = Carbon::today()->startOfMonth()->subMonth();

        $movement = array_sum(array_column(self::STATEMENT, 'amount'));

        $reconciliation = BankReconciliation::create([
            'account_id' => $account->id,
            'statement_date' => $month->copy()->endOfMonth()->toDateString(),
            'opening_minor' => self::CLOSING - $movement,
            'closing_minor' => self::CLOSING,
            'currency' => 'JMD',
            'status' => BankReconciliation::OPEN,
        ]);

        foreach (self::STATEMENT as $row) {
            BankStatementLine::create([
                'bank_reconciliation_id' => $reconciliation->id,

                // Clamped, because a bank fee charged on the last day of the
                // month falls on the 30th in half of them.
                'value_date' => $month->copy()->addDays(min($row['day'], $month->daysInMonth) - 1)->toDateString(),
                'description' => $row['description'],
                'amount_minor' => $row['amount'],
                'currency' => 'JMD',
                'bank_reference' => $row['reference'],
            ]);
        }
    }
}
