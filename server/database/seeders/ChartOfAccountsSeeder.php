<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Estate\Account;
use Illuminate\Database\Seeder;

/**
 * The estate's chart of accounts - board screen community-admin-25.
 *
 * Every account the board draws, at its own code, plus the one it does not.
 *
 * WHY THERE IS AN EQUITY ACCOUNT THE BOARD DOES NOT SHOW. The board groups its
 * chart under Assets, Liabilities, Income and Expenses. Total those four as
 * drawn and they do not balance: J$11,640,904 of debits against J$3,084,185 of
 * credits, out by J$8,556,719. That difference is not a mistake in the figures -
 * it IS the estate's accumulated fund, the members' equity, and every set of
 * books has one. The Build Spec's `account` entity lists `equity` as one of the
 * five types precisely because a ledger cannot balance without it.
 *
 * So 3000 Accumulated Fund is real, it is in the chart, and the screen shows it.
 * The alternative was a chart of accounts screen that hides an account from the
 * accountant, and a trial balance that could never agree. Recorded as a content
 * residual against the board rather than resolved by hiding a row.
 *
 * TWO CONTROL ACCOUNTS, and they are what `gate:ledger` ties:
 *   1200 Dues Receivable    against every UNIT's balance — the property owes
 *                           the dues, so a vacant unit still has a ledger
 *   2000 Accounts Payable   against every vendor's balance
 *
 * TWO BANK ACCOUNTS, flagged, because bank reconciliation reconciles one
 * account against one statement and cannot know which without being told.
 *
 * ONE RESERVE, MARKED (bug hunt B035). 1010 is the estate's reserve fund, and
 * the chart's "Reserve fund" tile reads the account carrying that mark rather
 * than guessing from the codes. Marked when the account is created only, so a
 * re-run never re-marks an account an estate has since decided is not its
 * reserve.
 *
 * Runs INSIDE tenancy. Every account here belongs to one estate and lives in
 * that estate's own database.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * The chart, in code order.
     *
     * [code, name, type, is_control, subsidiary, is_bank_account]
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool, 4: string|null, 5: bool}>
     */
    public const CHART = [
        ['1000', 'Operating Bank Account', Account::ASSET, false, null, true],
        ['1010', 'Reserve Fund Account', Account::ASSET, false, null, true],

        /*
         * What test cards "paid" in the demonstration checkout (M12 §2.6; D-179). NOT a bank account: no money is in
         * it. A demonstration payment clears the household's receivable as a real one does, and its debit lands here
         * so the operating account never shows money that did not arrive.
         */
        /*
         * A real card payment through the gateway (M16 §2.2; CardGateway), between the provider capturing it and
         * paying it out. The household's receivable clears at capture; the operating account moves only at payout.
         */
        ['1080', 'Card Payments in Transit', Account::ASSET, false, null, false],
        ['1090', 'Demonstration Card Payments (no money)', Account::ASSET, false, null, false],
        ['1200', 'Dues Receivable', Account::ASSET, true, Account::SUBSIDIARY_UNITS, false],

        ['2000', 'Accounts Payable', Account::LIABILITY, true, Account::SUBSIDIARY_VENDORS, false],
        ['2100', 'Statutory Deductions Payable', Account::LIABILITY, false, null, false],
        ['2200', 'Resident Deposits Held', Account::LIABILITY, false, null, false],

        /*
         * The members' accumulated fund. Not on the board; see the class
         * docblock. Without it the estate's own trial balance cannot agree,
         * and a chart of accounts that cannot produce a trial balance is a
         * list of names.
         */
        ['3000', 'Accumulated Fund', Account::EQUITY, false, null, false],

        ['4000', 'Maintenance Fee Income', Account::INCOME, false, null, false],
        ['4100', 'Amenity Booking Fees', Account::INCOME, false, null, false],

        ['5000', 'Staff Payroll', Account::EXPENSE, false, null, false],

        /*
         * The employer's own NIS, NHT, Education Tax and HEART (Q-002, ruled —
         * D-083). Not on board 25, because no estate posted it when the board
         * was drawn; the ruling that it is posted is what the account is for.
         */
        ['5010', 'Employer Statutory Contributions', Account::EXPENSE, false, null, false],
        ['5050', 'Security Services (Gemini Security Ltd)', Account::EXPENSE, false, null, false],
        ['5100', 'Maintenance & Repairs', Account::EXPENSE, false, null, false],
        ['5200', 'Utilities (Common Areas)', Account::EXPENSE, false, null, false],

        // The card provider's fee, posted at payout so the books agree with the bank statement (CardGateway).
        // 5910, clear of the plain expense codes an office adds for itself (5300, 5400 …).
        ['5910', 'Bank & Card Charges', Account::EXPENSE, false, null, false],
    ];

    /** The account the chart's "Reserve fund" tile reads. */
    public const RESERVE_FUND = '1010';

    public function run(): void
    {
        foreach (self::CHART as [$code, $name, $type, $isControl, $subsidiary, $isBank]) {
            /*
             * Keyed on the code, and updating only what is safe to update. An
             * account's TYPE is never rewritten here: changing it would flip
             * which side of it is a balance and silently restate every figure
             * ever posted to it. A chart that needs a type changed needs a new
             * account and a transfer, which is a decision, not a seed.
             */
            $account = Account::firstOrNew(['code' => $code]);

            $account->fill([
                'name' => $name,
                'is_control' => $isControl,
                'subsidiary' => $subsidiary,
                'is_bank_account' => $isBank,
            ]);

            if (! $account->exists) {
                $account->type = $type;
                $account->is_active = true;
                $account->is_reserve_fund = $code === self::RESERVE_FUND;
            }

            $account->save();
        }
    }
}
