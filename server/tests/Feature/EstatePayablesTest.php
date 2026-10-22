<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\Journal;
use App\Models\Estate\Vendor;
use App\Services\Estate\Ledger;
use App\Services\Estate\Payables;
use App\Services\Estate\Posting;
use App\Support\SiteTime;
use Brick\Money\Money;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\Estate\PayablesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| What the estate owes, traced to the lines it is made of
|--------------------------------------------------------------------------
|
| The same rule as `EstateArrearsTest`, on the other side of the books: a screen
| figure is not proven by rendering, it is proven by equalling the sum of
| specific posted journal lines. Total payable, a vendor's balance and what one
| bill still owes are all summed here in raw SQL written out longhand, so that
| the expectation and the application reach the same number by two different
| routes. A test that called the method it was checking would prove only that the
| method is deterministic.
|
| J$121,690 IS THE FIGURE FOUR SCREENS SHARE. Board 27's four open bills, board
| 27's "Total payable" tile, board 25's balance for 2000 Accounts Payable and
| board 25's "Bills payable" KPI. It is one number because every bill posts
| through `Payables`, and this file is where that stops being a claim.
|
| THE REFUSALS ARE PROVEN WITHOUT POSTING ANYTHING. A payment refused for a
| missing TRN, an over-payment refused for a missing reason and a reconciliation
| refused for an outstanding difference all throw before they write, so the
| estate under test still holds exactly the figures the boards state after this
| file has run.
|
*/

/**
 * The estate these tests read, built once per process.
 *
 * A DATABASE OF ITS OWN, for the reason `arrearsEstate()` gives: the figures
 * under test are the boards' own, and reading the development estate would make
 * the suite pass or fail on whatever somebody last seeded there. It is built by
 * the same seeder that builds Phoenix Park, because what is being proven is that
 * the seeder's arithmetic and the ledger's agree.
 */
function payablesEstate(): string
{
    static $built = false;

    // The configured prefix, never the literal: see `FacilitiesFixture::database()`.
    $database = config('tenancy.database.prefix').'payablestest';

    config([
        'database.connections.tenant' => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        ),
        'database.default' => 'tenant',
    ]);

    DB::purge('tenant');

    if ($built) {
        return $database;
    }

    $owner = DB::connection('mysql_owner');
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::purge('tenant');

    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--database' => 'tenant',
        '--force' => true,
    ]);

    // Through Artisan rather than by constructing the seeder, so it gets the
    // console output its own `$this->call()` writes to.
    Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);

    $built = true;

    return $database;
}

/** Runs a closure inside the estate under test. */
function inPayablesEstate(callable $work): mixed
{
    payablesEstate();

    return $work();
}

/**
 * What the estate owes altogether, summed in raw SQL.
 *
 * Written out longhand on purpose, and credits less debits because 2000 is a
 * liability: a bill credits it and a payment debits it back down. This is the
 * second, independent route to every payables figure the screens show, and it
 * must not share an implementation with the first.
 */
function payableTotal(): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.credit_minor - l.debit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', ['2000'])->owed;
}

/** What the estate owes one supplier, from that supplier's own lines. */
function payableOfVendor(int $vendorId): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.credit_minor - l.debit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.vendor_id = ?
    ', ['2000', $vendorId])->owed;
}

/** One vendor by the name every board identifies it by. */
function vendorNamed(string $name): Vendor
{
    return Vendor::where('name', $name)->firstOrFail();
}

/* ------------------------------------------------------------------ */
/* the tie every payables screen rests on */
/* ------------------------------------------------------------------ */

it('sums the four open bills to the J$121,690 four boards state', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);

        // The application's figure, read from the control account.
        $shown = $payables->totalPayable();

        // The same figure, summed independently in raw SQL over the lines.
        expect($shown)->toBe(payableTotal())
            ->and($shown)->toBe(121_690_00);

        // And the board's own tile is that number and not a second arithmetic
        // over the billing rows.
        $kpis = collect($payables->billsBoard()['kpis'])->keyBy('key');

        expect($kpis['payable']['value_minor'])->toBe(121_690_00);

        /*
         * The four bills the board draws as open, added up the way a reader of
         * board 27 would add them. This is the only place the `bills` table is
         * allowed to supply a total, and it is here precisely to show that it
         * agrees with the ledger rather than being where the ledger came from.
         */
        $open = Bill::query()->where('status', Bill::APPROVED)->sum('amount_minor');

        expect((int) $open)->toBe(121_690_00)
            ->and(Bill::query()->where('status', Bill::APPROVED)->count())->toBe(4);
    });
});

it('ties the payables control account to the vendor sub-ledger', function () {
    inPayablesEstate(function () {
        $control = Account::where('code', '2000')->firstOrFail();

        $subsidiary = app(Ledger::class)->subsidiaryBalances($control);

        // Every vendor's balance, re-summed one at a time in raw SQL.
        $traced = 0;

        foreach ($subsidiary as $vendorId => $balance) {
            expect($vendorId)->not->toBe('')  // a 2000 line with no vendor on it
                ->and($balance)->toBe(payableOfVendor((int) $vendorId));

            $traced += $balance;
        }

        // The tie itself: the control account is the sub-ledger, totalled.
        expect($traced)->toBe(payableTotal())
            ->and($traced)->toBe(121_690_00);

        /*
         * And it is a tie rather than a coincidence because no line can escape
         * it. A 2000 line without a vendor would sit inside the control balance
         * and outside every vendor's, which is exactly the drift the sub-ledger
         * exists to make visible.
         */
        $orphaned = (int) DB::connection('tenant')->selectOne('
            SELECT COUNT(*) AS n
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE a.code = ? AND l.vendor_id IS NULL
        ', ['2000'])->n;

        expect($orphaned)->toBe(0);
    });
});

it('names each vendor on its own lines and each open bill on its own entries', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);
        $outstanding = $payables->outstandingByBill();

        /*
         * THE BILLS THIS ESTATE HAS POSTED, NOT EVERY ROW IN THE TABLE (M6 3.1).
         *
         * This walk used to take `Bill::query()->get()` and demand an entry behind every
         * row. That is true of a bill that has been approved and deliberately false of one
         * that has only been recorded — and the TRN refusal further down this file records
         * exactly such a draft, because an invoice arrives whether or not the supplier's
         * paperwork is in order. This estate's database is not rolled back, so that draft
         * survives, and the walk failed whenever the refusal drew first. The order was never
         * the fault: a draft carrying no entry is the CORRECT state, and this test was
         * asserting something untrue about a row it had not written.
         *
         * So the walk is scoped to the bills that have been posted, and the drafts are
         * asserted for what they are.
         */
        $bills = Bill::query()->with('vendor')->get();
        $posted = $bills->where('status', '!=', Bill::DRAFT);

        expect($posted)->not->toBeEmpty();

        foreach ($posted as $bill) {
            // Every posted bill has an entry. A billing row with no entry behind
            // it is money the accounts never heard of.
            expect($bill->journal_ref)->not->toBeNull();

            $owed = $outstanding[$bill->id] ?? 0;

            expect($owed)->toBe($bill->status === Bill::PAID ? 0 : $bill->amount_minor);
        }

        // And the other half of the same rule: a draft is recorded, so the estate
        // knows the invoice came, and it is carried by no entry until it is approved.
        foreach ($bills->where('status', Bill::DRAFT) as $draft) {
            expect($draft->journal_ref)->toBeNull()
                ->and($outstanding[$draft->id] ?? 0)->toBe(0);
        }

        // What each bill still owes adds up to what the estate owes.
        expect(array_sum($outstanding))->toBe(payableTotal());
    });
});

it('leaves the liability exactly where it is when the seeder runs again', function () {
    inPayablesEstate(function () {
        $before = payableTotal();

        /*
         * THE SEEDER IS THE ONLY THING THAT COULD DOUBLE THIS. Journals are
         * append-only, so a second run that re-approved the five bills would add
         * another J$121,690 to what the estate owes and no application path
         * could take it back off — the ledger has no delete, by design. This is
         * the test that stops a routine `db:seed` from doing it.
         */
        Artisan::call('db:seed', ['--class' => PayablesSeeder::class, '--force' => true]);

        expect(payableTotal())->toBe($before)
            ->and($before)->toBe(121_690_00);

        /*
         * AND IT WROTE NO SECOND STATEMENT FOR THE OPERATING ACCOUNT (M6 3.1).
         *
         * This used to count EVERY reconciliation in the estate and demand exactly one,
         * which is a total this test does not own. The last test in this file opens a
         * statement of its own against the reserve account 1010 — deliberately, because
         * completing a period is irreversible and signing off the estate's live August would
         * leave every later test reading a closed book — and this estate's database is
         * committed rather than rolled back, so that second row is permanent. The count was
         * therefore 1 or 2 depending on which test drew first, and it would have gone to 3
         * the day anybody added a second reconciliation test.
         *
         * What this test is actually about is the SEEDER not writing twice, so the assertion
         * is scoped to the row the seeder writes: the operating account's statement, of which
         * there is exactly one, before the re-run and after it.
         */
        $operating = Account::where('code', Payables::BANK)->firstOrFail();

        expect(BankReconciliation::query()->where('account_id', $operating->id)->count())->toBe(1);
    });
});

it('draws board 27 as five rows with the badges the board draws', function () {
    inPayablesEstate(function () {
        // A fixed date, because "overdue" is arithmetic against today and board
        // 27 was drawn in early September 2026. The board's own figures are only
        // true on a day, and the day is stated rather than assumed.
        $board = app(Payables::class)->billsBoard(Carbon::parse('2026-09-10'));

        $rows = collect($board['rows'])->keyBy('reference');

        expect($rows->get('Ticket #1042 · Gate lighting')['status'])->toBe('unpaid')
            ->and($rows->get('Ticket #1041 · Pool filter fault')['status'])->toBe('overdue')
            ->and($rows->get('Ticket #1037 · Gym equipment')['status'])->toBe('unpaid')
            ->and($rows->get('Common area electricity · August')['status'])->toBe('unpaid')

            // Settled five days before it fell due, and still on the screen
            // because a treasurer who has just paid something needs to see it go.
            ->and($rows->get('Ticket #1031 · Barrier arm sensor')['status_label'])->toBe('Paid 5 Sep');

        $kpis = collect($board['kpis'])->keyBy('key');

        // The overdue tile is the AquaTech bill and nothing else — summed from
        // that bill's own lines, not from its `amount_minor`.
        expect($kpis['overdue']['value_minor'])->toBe(12_000_00)
            ->and($kpis['paid_month']['value_minor'])->toBe(14_200_00);
    });
});

it('posts a bill as a debit to its expense account and a credit to 2000', function () {
    inPayablesEstate(function () {
        $bill = Bill::where('description', 'Common area electricity · August')->firstOrFail();

        $lines = DB::connection('tenant')->select('
            SELECT a.code, l.debit_minor, l.credit_minor, l.vendor_id
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE l.entry_ref = ?
             ORDER BY l.line_no
        ', [$bill->journal_ref]);

        expect($lines)->toHaveCount(2)

            // The electricity bill is a utility, not maintenance — which is what
            // makes board 26's Category column derivable from the books.
            ->and($lines[0]->code)->toBe('5200')
            ->and((int) $lines[0]->debit_minor)->toBe(94_300_00)
            ->and($lines[0]->vendor_id)->toBeNull()

            // And the vendor is on the payable line and nowhere else. The
            // expense belongs to the estate's costs; the liability belongs to
            // one supplier, and that is the whole of the sub-ledger.
            ->and($lines[1]->code)->toBe('2000')
            ->and((int) $lines[1]->credit_minor)->toBe(94_300_00)
            ->and((int) $lines[1]->vendor_id)->toBe($bill->vendor_id);
    });
});

it('leaves a settled bill outside what the estate owes', function () {
    inPayablesEstate(function () {
        $gate2 = vendorNamed('Gate2 Contractor Ltd');
        $bill = Bill::where('vendor_id', $gate2->id)->firstOrFail();

        // Board 27 draws it "Paid 5 Sep" and excludes its J$14,200 from the
        // total payable. It is excluded here because its credit to 2000 has a
        // debit against it, not because a status column says so.
        expect($bill->status)->toBe(Bill::PAID)
            ->and(app(Payables::class)->outstandingOf($bill))->toBe(0)
            ->and(payableOfVendor($gate2->id))->toBe(0);
    });
});

it('traces what each vendor has been paid to the debits on the control account', function () {
    inPayablesEstate(function () {
        $paid = app(Payables::class)->paidByVendor(Carbon::today()->startOfYear());

        foreach ($paid as $vendorId => $amount) {
            $traced = (int) DB::connection('tenant')->selectOne('
                SELECT COALESCE(SUM(l.debit_minor), 0) AS paid
                  FROM journal_lines l
                  JOIN accounts a ON a.id = l.account_id
                  JOIN journals j ON j.reference = l.entry_ref
                 WHERE a.code = ? AND l.vendor_id = ? AND j.source = ?
                   AND j.posted_on >= ?
            ', ['2000', $vendorId, Ledger::SOURCE_BILL_PAYMENT, Carbon::today()->startOfYear()->toDateString()])->paid;

            expect($amount)->toBe($traced);
        }

        // Only the settled bill has moved money out of the estate so far.
        expect(array_sum($paid))->toBe(14_200_00);
    });
});

it('draws one vendor board from the ledger and not from its bill rows', function () {
    inPayablesEstate(function () {
        $vendor = vendorNamed('Island Electric Services');
        $board = app(Payables::class)->vendorBoard($vendor);

        $stats = collect($board['stats'])->keyBy('key');

        // Board 39's "Currently owed" against the one unpaid bill it draws.
        expect($stats['owed']['value_minor'])->toBe(payableOfVendor($vendor->id))
            ->and($stats['owed']['value_minor'])->toBe(8_500_00)
            ->and($board['bills'][0]['reference'])->toBe('Ticket #1042 · Gate lighting');
    });
});

/* ------------------------------------------------------------------ */
/* the refusals — none of which writes anything */
/* ------------------------------------------------------------------ */

it('refuses to pay a vendor with no TRN, having let the bill be recorded', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);
        $before = payableTotal();

        $vendor = Vendor::create([
            'name' => 'Harbour Glazing Co.',
            'category' => 'Glazing',
            'status' => Vendor::ACTIVE,
        ]);

        // RECORDING IT IS ALLOWED. An invoice arrives whether or not the
        // supplier's paperwork is in order, and refusing to record it would
        // understate what the estate owes.
        $bill = $payables->recordBill(
            vendor: $vendor,
            amount: Money::ofMinor(4_000_00, 'JMD'),
            description: 'Clubhouse window replacement',
            dueOn: Carbon::today()->addDays(30),
        );

        expect($bill->status)->toBe(Bill::DRAFT)
            ->and($bill->journal_ref)->toBeNull();

        expect(fn () => $payables->pay(
            bill: $bill,
            amount: Money::ofMinor(4_000_00, 'JMD'),
            method: 'bank',
            paidOn: Ledger::today(),
        ))->toThrow(DomainException::class, 'no TRN on file');

        // And nothing was posted on the way to being refused.
        expect(payableTotal())->toBe($before);
    });
});

it('refuses a payment above what a bill still owes unless a reason is given', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);
        $before = payableTotal();

        $bill = Bill::where('description', 'Gate lighting')->firstOrFail();

        expect($payables->outstandingOf($bill))->toBe(8_500_00);

        expect(fn () => $payables->pay(
            bill: $bill,
            amount: Money::ofMinor(9_000_00, 'JMD'),
            method: 'bank',
            paidOn: Ledger::today(),
        ))->toThrow(DomainException::class, 'over-payment reason');

        expect(payableTotal())->toBe($before);
    });
});

/**
 * Runs a closure inside the estate under test and takes back everything it wrote.
 *
 * This estate is committed, not rolled back, and the figures every test above
 * reads are the seeder's. A test that posts has to leave them as it found them.
 */
function inPayablesEstateRolledBack(callable $work): mixed
{
    payablesEstate();

    $connection = DB::connection('tenant');
    $connection->beginTransaction();

    try {
        return $work();
    } finally {
        $connection->rollBack();
    }
}

/*
 * TWO REQUESTS, ONE BILL (bug hunt B020, B075). A double-click on Approve loads
 * the draft twice. Each copy passes the check it was loaded with, so the second
 * posted the liability again: two BIL entries on 2000, a supplier shown as owed
 * for ever, and a ledger that cannot unpost either.
 */
it('refuses to approve a bill a second time from a copy loaded before the first approval posted', function () {
    inPayablesEstateRolledBack(function () {
        $payables = app(Payables::class);

        $draft = $payables->recordBill(
            vendor: vendorNamed('Island Electric Services'),
            amount: Money::ofMinor(3_000_00, 'JMD'),
            description: 'Double-click guard',
            dueOn: Carbon::today()->addDays(10),
        );

        $first = Bill::query()->findOrFail($draft->id);
        $second = Bill::query()->findOrFail($draft->id);

        $payables->approve($first);

        expect(fn () => $payables->approve($second))
            ->toThrow(DomainException::class, 'a second time');

        expect(Journal::query()->where('source', Ledger::SOURCE_BILL)->where('source_id', $draft->id)->count())->toBe(1)
            ->and($payables->outstandingOf($draft->refresh()))->toBe(3_000_00);
    });
});

it('refuses an unexplained over-payment when another payment lands between the check and the post', function () {
    inPayablesEstateRolledBack(function () {
        $payables = app(Payables::class);
        $bill = Bill::where('description', 'Gate lighting')->firstOrFail();

        expect($payables->outstandingOf($bill))->toBe(8_500_00);

        /*
         * The other request, exactly between this one's outstanding check and its
         * post: the office pays the whole bill in the other tab. Played through a
         * query listener so the interleaving is the same every run.
         */
        $raced = false;

        DB::connection('tenant')->listen(function ($query) use (&$raced, $payables, $bill): void {
            if ($raced || ! str_contains($query->sql, 'owed')) {
                return;
            }

            $raced = true;

            $payables->pay(
                bill: Bill::query()->findOrFail($bill->id),
                amount: Money::ofMinor(8_500_00, 'JMD'),
                method: 'bank',
                paidOn: Ledger::today(),
            );
        });

        expect(fn () => $payables->pay(
            bill: $bill,
            amount: Money::ofMinor(8_500_00, 'JMD'),
            method: 'bank',
            paidOn: Ledger::today(),
        ))->toThrow(DomainException::class, 'over-payment reason');

        expect($raced)->toBeTrue()
            ->and($bill->payments()->count())->toBe(1)
            ->and($payables->outstandingOf($bill->refresh()))->toBe(0);
    });
});

/*
 * THE ESTATE'S DAY, NOT UTC'S (bug hunt B023, B082). At 8:30 PM on Sep 30 in
 * Kingston it is 01:30 on Oct 1 in UTC. A bill approved then is September's cost,
 * and a bill due on the 30th is not overdue yet.
 */
it('posts a bill approved in the evening on the estate\'s date, and calls a bill due today unpaid rather than overdue', function () {
    inPayablesEstateRolledBack(function () {
        $payables = app(Payables::class);

        $this->travelTo(Carbon::parse('2026-10-01 01:30:00', 'UTC'));

        $bill = $payables->recordBill(
            vendor: vendorNamed('Island Electric Services'),
            amount: Money::ofMinor(2_500_00, 'JMD'),
            description: 'Approved on the last evening of the month',
            dueOn: '2026-09-30',
        );

        $payables->approve($bill);

        $entry = Journal::query()->where('reference', $bill->refresh()->journal_ref)->firstOrFail();

        expect($entry->posted_on->toDateString())->toBe('2026-09-30')
            ->and($entry->reference)->toStartWith('BIL-2026-09-');

        $row = collect($payables->billsBoard()['rows'])->firstWhere('id', $bill->id);

        expect($row['status'])->toBe('unpaid');
    });
});

it('refuses a bill payment dated after the estate\'s today', function () {
    inPayablesEstateRolledBack(function () {
        $payables = app(Payables::class);
        $bill = Bill::where('description', 'Gate lighting')->firstOrFail();

        expect(fn () => $payables->pay(
            bill: $bill,
            amount: Money::ofMinor(100_00, 'JMD'),
            method: 'bank',
            paidOn: SiteTime::today()->addYear()->toDateString(),
        ))->toThrow(DomainException::class, 'has not happened yet');

        expect($bill->payments()->count())->toBe(0)
            ->and($payables->outstandingOf($bill))->toBe(8_500_00);
    });
});

/*
 * A MATCH IS A CLAIM THAT TWO RECORDS ARE ONE EVENT (bug hunt B034). The screen
 * only ever proposes an entry on the reconciled account, for the line's amount and
 * direction; the server took any reference at all, so a crafted request could zero
 * the difference with a charge that never touched the bank.
 */
it('matches a bank line only to an entry that moved that account by that amount, and only once', function () {
    inPayablesEstateRolledBack(function () {
        $payables = app(Payables::class);
        $ledger = app(Ledger::class);
        $reserve = Account::where('code', '1010')->firstOrFail();

        // The bank and the reserve hold money before the statement's month: neither can go below nothing (EST-B-02).
        $ledger->post('Opening balances', [
            Posting::debit('1000', 10_000_00),
            Posting::debit('1010', 6_000_00),
            Posting::credit('3000', 16_000_00),
        ], on: '2025-11-01');

        $reconciliation = BankReconciliation::create([
            'account_id' => $reserve->id,
            'statement_date' => '2025-11-30',
            'opening_minor' => 0,
            'closing_minor' => 5_000_00,
            'currency' => 'JMD',
            'status' => BankReconciliation::OPEN,
        ]);

        $line = BankStatementLine::create([
            'bank_reconciliation_id' => $reconciliation->id,
            'value_date' => '2025-11-03',
            'description' => 'TRANSFER — RESERVE TOP UP',
            'amount_minor' => 5_000_00,
            'currency' => 'JMD',
        ]);

        // An entry that never touched the reserve account.
        $charge = Journal::query()->where('source', Ledger::SOURCE_CHARGE)->orderBy('id')->firstOrFail();

        expect(fn () => $payables->match($line, $charge->reference))->toThrow(DomainException::class, 'never moved');

        // One that did, for another amount.
        $short = $ledger->post('Reserve top up', [Posting::debit('1010', 4_000_00), Posting::credit('1000', 4_000_00)], on: '2025-11-03');

        expect(fn () => $payables->match($line, $short->reference))->toThrow(DomainException::class, 'moved');

        // One that moved it the other way, for the same amount.
        $out = $ledger->post('Reserve drawn', [Posting::debit('1000', 5_000_00), Posting::credit('1010', 5_000_00)], on: '2025-11-04');

        expect(fn () => $payables->match($line, $out->reference))->toThrow(DomainException::class, 'moved');

        expect($line->refresh()->matched_entry_ref)->toBeNull()
            ->and($payables->differenceOf($reconciliation))->toBe(5_000_00);

        // The same event: matched.
        $topUp = $ledger->post('Reserve top up', [Posting::debit('1010', 5_000_00), Posting::credit('1000', 5_000_00)], on: '2025-11-03');

        $payables->match($line, $topUp->reference);

        expect($line->refresh()->matched_entry_ref)->toBe($topUp->reference)
            ->and($payables->differenceOf($reconciliation))->toBe(0);

        // And an entry claimed on one statement cannot be claimed on another.
        $december = BankReconciliation::create([
            'account_id' => $reserve->id,
            'statement_date' => '2025-12-31',
            'opening_minor' => 5_000_00,
            'closing_minor' => 10_000_00,
            'currency' => 'JMD',
            'status' => BankReconciliation::OPEN,
        ]);

        $again = BankStatementLine::create([
            'bank_reconciliation_id' => $december->id,
            'value_date' => '2025-12-02',
            'description' => 'TRANSFER — RESERVE TOP UP',
            'amount_minor' => 5_000_00,
            'currency' => 'JMD',
        ]);

        expect(fn () => $payables->match($again, $topUp->reference))->toThrow(DomainException::class, 'already matched');
    });
});

it('refuses to complete a reconciliation while a difference remains', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);
        $reconciliation = $payables->currentReconciliation();

        expect($reconciliation)->not->toBeNull();

        /*
         * The difference, summed independently: what the bank says moved over
         * the period, less every line the estate has claimed as one of its own
         * entries. The seed claims none of them, so the whole month's movement
         * is outstanding.
         */
        $movement = $reconciliation->closing_minor - $reconciliation->opening_minor;

        $claimed = (int) DB::connection('tenant')->selectOne('
            SELECT COALESCE(SUM(amount_minor), 0) AS claimed
              FROM bank_statement_lines
             WHERE bank_reconciliation_id = ? AND matched_entry_ref IS NOT NULL
        ', [$reconciliation->id])->claimed;

        expect($payables->differenceOf($reconciliation))->toBe($movement - $claimed)
            ->and($payables->differenceOf($reconciliation))->not->toBe(0);

        expect(fn () => $payables->complete($reconciliation))
            ->toThrow(DomainException::class, 'cannot be completed');

        expect($reconciliation->refresh()->status)->toBe(BankReconciliation::OPEN);
    });
});

it('closes a period once every line on the statement is claimed', function () {
    inPayablesEstateRolledBack(function () {
        $payables = app(Payables::class);

        /*
         * A statement of its own, against the reserve account, rather than the
         * seeded one. Completing a period is irreversible by design, and a test
         * that signed off the estate's live August would leave every test after
         * it reading a closed book — so this one is rolled back as well.
         */
        $reconciliation = BankReconciliation::create([
            'account_id' => Account::where('code', '1010')->firstOrFail()->id,
            'statement_date' => Carbon::today()->startOfYear()->subMonth()->endOfMonth()->toDateString(),
            'opening_minor' => 0,
            'closing_minor' => 5_000_00,
            'currency' => 'JMD',
            'status' => BankReconciliation::OPEN,
        ]);

        $line = BankStatementLine::create([
            'bank_reconciliation_id' => $reconciliation->id,
            'value_date' => $reconciliation->statement_date->copy()->startOfMonth()->toDateString(),
            'description' => 'TRANSFER — RESERVE TOP UP',
            'amount_minor' => 5_000_00,
            'currency' => 'JMD',
        ]);

        // The bank holds the top-up before the statement's month: it cannot go below nothing (EST-B-02).
        app(Ledger::class)->post('Opening balance', [
            Posting::debit('1000', 5_000_00),
            Posting::credit('3000', 5_000_00),
        ], on: $reconciliation->statement_date->copy()->startOfMonth()->subDay());

        /*
         * THE ENTRY THIS LINE IS: the top-up itself, on the reserve account, for
         * the line's amount (B034). This used to match whichever journal came
         * first, which the server now refuses — a match is a claim that the two
         * are one event.
         */
        $entry = app(Ledger::class)->post(
            'Reserve top up',
            [Posting::debit('1010', 5_000_00), Posting::credit('1000', 5_000_00)],
            on: $line->value_date,
        );

        // A match is an assertion recorded on the statement side; the entry it
        // names is never touched, because a posted entry cannot be.
        $payables->match($line, $entry->reference);

        expect($line->refresh()->matched_entry_ref)->toBe($entry->reference)
            ->and($payables->differenceOf($reconciliation))->toBe(0);

        $payables->complete($reconciliation);

        expect($reconciliation->refresh()->status)->toBe(BankReconciliation::COMPLETED)

            // And a completed period is closed to matching, or the figure
            // somebody signed off would move underneath them.
            ->and(fn () => $payables->unmatch($line->refresh()))->toThrow(DomainException::class);
    });
});
