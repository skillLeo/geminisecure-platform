<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\JournalLine;
use App\Models\Estate\Unit;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Double-entry: the three promises, asserted rather than assumed
|--------------------------------------------------------------------------
|
|   1. DEBITS EQUAL CREDITS AT EVERY WRITE
|   2. SUB-LEDGERS TIE TO THEIR CONTROL ACCOUNTS
|   3. A POSTED JOURNAL CANNOT BE EDITED
|
| None of the three is checkable by looking. An unbalanced ledger does not look
| unbalanced; a control account that has drifted shows a plausible number on
| every screen; an edited entry looks exactly like one nobody touched. So each
| is proven here, and proven at the DATABASE rather than at the service — every
| test below that attempts a forbidden write does it with raw SQL, going around
| `Ledger` entirely, because a guarantee that only holds while everybody
| remembers to use one class is not a guarantee.
|
| THESE RUN AGAINST A REAL ESTATE DATABASE. The triggers and CHECK constraints
| ARE the subject; a sqlite or an in-memory double would assert nothing. The
| fixture builds `<prefix>ledgertest` once and runs the tenant migrations into
| it, which is what an estate gets at provisioning.
|
| The one layer NOT exercised here is the revoked MySQL grant, because the test
| user owns the schema. `gate:ledger` and `gate:isolation` prove that layer
| against the real estate databases, where the estate's own restricted user is
| the one connecting.
|
*/

/**
 * The estate database these tests run in, built once per process.
 *
 * Dropped and rebuilt rather than reused: a leftover from an interrupted run
 * would silently change what "the ledger is empty" means, and every balance
 * assertion below depends on knowing exactly what has been posted.
 */
function ledgerDatabase(): string
{
    /*
     * Only the expensive half is memoised. The application is rebuilt between
     * tests and forgets its configuration, so the connection has to be declared
     * every time — while dropping and migrating the schema must happen exactly
     * once per process.
     */
    static $built = false;

    // The configured prefix, never the literal: see `FacilitiesFixture::database()`.
    $database = config('tenancy.database.prefix').'ledgertest';

    /*
     * Configured exactly as the tenancy bootstrapper configures it at runtime:
     * a `tenant` connection pointed at this estate, and made the default, so
     * every model in App\Models\Estate resolves here without declaring a
     * connection of its own.
     */
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

    $built = true;

    return $database;
}

/** A clean ledger with the estate's chart in it, and two households to bill. */
beforeEach(function () {
    ledgerDatabase();

    $db = DB::connection('tenant');

    /*
     * TRUNCATE rather than DELETE, and only here. The append-only triggers fire
     * on DELETE and refuse it — which is the point — but TRUNCATE is DDL and
     * bypasses them. That is acceptable for resetting a fixture and would not
     * be acceptable anywhere else; the tests below prove DELETE is still
     * refused through every route the application actually has.
     */
    $db->statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['journal_lines', 'journals', 'accounts', 'charges', 'residents', 'households', 'units'] as $table) {
        $db->statement("TRUNCATE TABLE `{$table}`");
    }

    $db->statement('SET FOREIGN_KEY_CHECKS = 1');

    (new ChartOfAccountsSeeder)->run();

    $this->ledger = new Ledger;

    /*
     * Units, not households. The receivable sub-ledger is keyed on the property:
     * a vacant unit still owes its maintenance, so a household-keyed ledger
     * could not represent one at all.
     */
    $this->units = collect(['Lot 1', 'Lot 2'])->map(function (string $reference): Unit {
        $unit = Unit::create(['reference' => $reference, 'block' => 'Block A', 'status' => 'occupied']);

        Household::create(['unit_id' => $unit->id, 'name' => $reference.' household']);

        return $unit;
    });
});

/* ------------------------------------------------------------------ */
/* 1. debits equal credits */
/* ------------------------------------------------------------------ */

it('posts a balanced entry and gives it a readable reference', function () {
    $entry = $this->ledger->post('Monthly maintenance fee', [
        Posting::debit('1200', 310_000_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 310_000_00),
    ], on: '2026-09-01');

    expect($entry->reference)->toBe('JV-2026-09-0001')
        ->and($entry->amount_minor)->toBe(310_000_00)
        ->and($entry->lines)->toHaveCount(2)
        ->and($entry->lines[0]->debit_minor)->toBe(310_000_00)
        ->and($entry->lines[0]->credit_minor)->toBe(0)
        ->and($entry->lines[1]->credit_minor)->toBe(310_000_00);
});

it('numbers entries in sequence within a month and starts again in the next', function () {
    $this->ledger->post('One', [Posting::debit('1000', 100), Posting::credit('4000', 100)], on: '2026-09-01');
    $second = $this->ledger->post('Two', [Posting::debit('1000', 100), Posting::credit('4000', 100)], on: '2026-09-30');
    $october = $this->ledger->post('Three', [Posting::debit('1000', 100), Posting::credit('4000', 100)], on: '2026-10-01');

    expect($second->reference)->toBe('JV-2026-09-0002')
        ->and($october->reference)->toBe('JV-2026-10-0001');
});

it('refuses an entry whose two sides differ', function () {
    expect(fn () => $this->ledger->post('Wrong', [
        Posting::debit('1000', 100_00),
        Posting::credit('4000', 90_00),
    ]))->toThrow(DomainException::class, 'does not balance');

    // And nothing was written on the way to being refused.
    expect(Journal::count())->toBe(0)->and(JournalLine::count())->toBe(0);
});

it('refuses an unbalanced entry written straight to the tables, bypassing the service', function () {
    $cash = Account::where('code', '1000')->first();
    $income = Account::where('code', '4000')->first();

    DB::connection('tenant')->table('journal_lines')->insert([
        ['entry_ref' => 'RAW-1', 'account_id' => $cash->id, 'line_no' => 1, 'debit_minor' => 1000, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now()],
        ['entry_ref' => 'RAW-1', 'account_id' => $income->id, 'line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 900, 'currency' => 'JMD', 'created_at' => now()],
    ]);

    // The DATABASE refuses it, not the service. That is the whole point: a
    // guarantee that only holds while everybody uses one class is not one.
    expect(fn () => DB::connection('tenant')->table('journals')->insert([
        'reference' => 'RAW-1', 'memo' => 'raw', 'source' => 'manual', 'amount_minor' => 1000,
        'currency' => 'JMD', 'posted_on' => '2026-09-01', 'created_at' => now(),
    ]))->toThrow(QueryException::class, 'debits must equal credits');
});

it('refuses a header whose total disagrees with its own lines', function () {
    $cash = Account::where('code', '1000')->first();
    $income = Account::where('code', '4000')->first();

    DB::connection('tenant')->table('journal_lines')->insert([
        ['entry_ref' => 'RAW-2', 'account_id' => $cash->id, 'line_no' => 1, 'debit_minor' => 1000, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now()],
        ['entry_ref' => 'RAW-2', 'account_id' => $income->id, 'line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 1000, 'currency' => 'JMD', 'created_at' => now()],
    ]);

    /*
     * The entry balances and the header lies about the size of it. This is the
     * failure that hides best — every screen reading the header shows the wrong
     * number while the trial balance agrees perfectly.
     */
    expect(fn () => DB::connection('tenant')->table('journals')->insert([
        'reference' => 'RAW-2', 'memo' => 'raw', 'source' => 'manual', 'amount_minor' => 5000,
        'currency' => 'JMD', 'posted_on' => '2026-09-01', 'created_at' => now(),
    ]))->toThrow(QueryException::class, 'must equal the sum of its debits');
});

it('refuses a one-sided entry', function () {
    $cash = Account::where('code', '1000')->first();

    DB::connection('tenant')->table('journal_lines')->insert([
        'entry_ref' => 'RAW-3', 'account_id' => $cash->id, 'line_no' => 1,
        'debit_minor' => 1000, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now(),
    ]);

    expect(fn () => DB::connection('tenant')->table('journals')->insert([
        'reference' => 'RAW-3', 'memo' => 'raw', 'source' => 'manual', 'amount_minor' => 1000,
        'currency' => 'JMD', 'posted_on' => '2026-09-01', 'created_at' => now(),
    ]))->toThrow(QueryException::class, 'at least two lines');
});

it('refuses a line carrying both a debit and a credit', function () {
    $cash = Account::where('code', '1000')->first();

    expect(fn () => DB::connection('tenant')->table('journal_lines')->insert([
        'entry_ref' => 'RAW-4', 'account_id' => $cash->id, 'line_no' => 1,
        'debit_minor' => 500, 'credit_minor' => 500, 'currency' => 'JMD', 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses a negative posting, at the service and at the database', function () {
    expect(fn () => Posting::debit('1000', -500))
        ->toThrow(InvalidArgumentException::class, 'must be a positive amount');

    $cash = Account::where('code', '1000')->first();

    expect(fn () => DB::connection('tenant')->table('journal_lines')->insert([
        'entry_ref' => 'RAW-5', 'account_id' => $cash->id, 'line_no' => 1,
        'debit_minor' => -500, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses to mix currencies in one entry', function () {
    expect(fn () => $this->ledger->post('Mixed', [
        Posting::debit('1000', 100, currency: 'JMD'),
        Posting::credit('4000', 100, currency: 'USD'),
    ]))->toThrow(DomainException::class, 'One entry, one currency');
});

it('refuses to post against an account that is not in the chart', function () {
    expect(fn () => $this->ledger->post('Typo', [
        Posting::debit('9999', 100),
        Posting::credit('4000', 100),
    ]))->toThrow(DomainException::class, 'No account [9999]');
});

it('refuses to post against an archived account but keeps its history', function () {
    $reserve = Account::where('code', '1010')->first();

    // The operating account holds the money before it is moved (EST-B-02).
    $this->ledger->post('Opening', [Posting::debit('1000', 50_000_00), Posting::credit('3000', 50_000_00)]);

    $this->ledger->post('Transfer to reserve', [
        Posting::debit('1010', 50_000_00),
        Posting::credit('1000', 50_000_00),
    ]);

    $reserve->archive();

    expect(fn () => $this->ledger->post('Another transfer', [
        Posting::debit('1010', 1_000_00),
        Posting::credit('1000', 1_000_00),
    ]))->toThrow(DomainException::class, 'archived and cannot take new postings');

    // Archiving is not deleting: the balance is still there and still reported.
    expect($this->ledger->balanceOf($reserve->fresh())->getMinorAmount()->toInt())->toBe(50_000_00);
});

it('reports a balance on the side the account normally sits', function () {
    $this->ledger->post('Fee raised', [
        Posting::debit('1200', 100_000_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 100_000_00),
    ]);

    $receivable = Account::where('code', '1200')->first();
    $income = Account::where('code', '4000')->first();

    // An asset debited and an income credited are BOTH positive. Printing a
    // liability as negative because the arithmetic ran the other way is how a
    // balance sheet becomes unreadable.
    expect($this->ledger->balanceOf($receivable)->getMinorAmount()->toInt())->toBe(100_000_00)
        ->and($this->ledger->balanceOf($income)->getMinorAmount()->toInt())->toBe(100_000_00);
});

it('produces a trial balance whose two columns agree', function () {
    $this->ledger->post('Opening', [
        Posting::debit('1000', 8_556_719_00),
        Posting::credit('3000', 8_556_719_00),
    ], on: '2026-01-01');

    $this->ledger->post('Fee raised', [
        Posting::debit('1200', 310_000_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 310_000_00),
    ], on: '2026-09-01');

    $rows = $this->ledger->trialBalance();

    expect($rows->sum('debit_minor'))->toBe($rows->sum('credit_minor'))
        ->and($rows->sum('debit_minor'))->toBe(8_866_719_00);
});

/* ------------------------------------------------------------------ */
/* 2. sub-ledgers tie to their control accounts */
/* ------------------------------------------------------------------ */

it('ties the receivables control account to the households behind it', function () {
    [$one, $two] = [$this->units[0], $this->units[1]];

    $this->ledger->post('September dues', [
        Posting::debit('1200', 310_000_00, unitId: $one->id),
        Posting::debit('1200', 240_000_00, unitId: $two->id),
        Posting::credit('4000', 550_000_00),
    ], on: '2026-09-01');

    $this->ledger->post('Payment received', [
        Posting::debit('1000', 110_000_00),
        Posting::credit('1200', 110_000_00, unitId: $two->id),
    ], on: '2026-09-14');

    $control = Account::where('code', '1200')->first();
    $subsidiary = $this->ledger->subsidiaryBalances($control);

    expect($subsidiary[$one->id])->toBe(310_000_00)
        ->and($subsidiary[$two->id])->toBe(130_000_00)

        // The tie itself: the control account equals the sum of the sub-ledger.
        ->and(array_sum($subsidiary))->toBe($this->ledger->balanceOf($control)->getMinorAmount()->toInt())
        ->and(array_sum($subsidiary))->toBe(440_000_00);
});

it('makes a control-account line with no household visible instead of losing it', function () {
    $this->ledger->post('September dues', [
        Posting::debit('1200', 310_000_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 310_000_00),
    ], on: '2026-09-01');

    // The failure that actually happens: a line posted to receivables with
    // nobody named. It counts in the control balance and appears on no
    // household's statement, so the estate chases a debt that is on nobody's
    // ledger.
    $this->ledger->post('Dues, household not recorded', [
        Posting::debit('1200', 50_000_00),
        Posting::credit('4000', 50_000_00),
    ], on: '2026-09-02');

    $control = Account::where('code', '1200')->first();
    $subsidiary = $this->ledger->subsidiaryBalances($control);

    // Grouped under the empty key rather than dropped, which is what lets
    // gate:ledger name it as an amount sitting on nobody.
    expect($subsidiary[''])->toBe(50_000_00)
        ->and($this->ledger->balanceOf($control)->getMinorAmount()->toInt())->toBe(360_000_00);
});

it('refuses to total a sub-ledger for an account that is not a control account', function () {
    expect(fn () => $this->ledger->subsidiaryBalances(Account::where('code', '4000')->first()))
        ->toThrow(InvalidArgumentException::class, 'not a control account');
});

/* ------------------------------------------------------------------ */
/* 3. a posted journal cannot be edited */
/* ------------------------------------------------------------------ */

it('refuses to add a line to an entry that is already posted', function () {
    $entry = $this->ledger->post('Fee raised', [
        Posting::debit('1200', 100_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 100_00),
    ]);

    $cash = Account::where('code', '1000')->first();

    // Without this the ledger would be forgeable a second at a time: post a
    // balanced entry, then unbalance it.
    expect(fn () => DB::connection('tenant')->table('journal_lines')->insert([
        'entry_ref' => $entry->reference, 'account_id' => $cash->id, 'line_no' => 99,
        'debit_minor' => 100, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now(),
    ]))->toThrow(QueryException::class, 'that journal is posted');
});

it('refuses to edit or delete a posted header or a posted line, in raw SQL', function () {
    $entry = $this->ledger->post('Fee raised', [
        Posting::debit('1200', 100_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 100_00),
    ]);

    $db = DB::connection('tenant');

    expect(fn () => $db->table('journals')->where('id', $entry->id)->update(['memo' => 'edited']))
        ->toThrow(QueryException::class);

    expect(fn () => $db->table('journals')->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class);

    expect(fn () => $db->table('journal_lines')->where('entry_ref', $entry->reference)->update(['memo' => 'edited']))
        ->toThrow(QueryException::class);

    expect(fn () => $db->table('journal_lines')->where('entry_ref', $entry->reference)->delete())
        ->toThrow(QueryException::class);

    // Still exactly as posted.
    expect($entry->fresh()->memo)->toBe('Fee raised')
        ->and($entry->fresh()->lines)->toHaveCount(2);
});

it('reverses an entry by mirroring every line, never by negating a total', function () {
    $original = $this->ledger->post('Fee raised in error', [
        Posting::debit('1200', 75_000_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 75_000_00),
    ], on: '2026-09-01');

    $reversal = $this->ledger->reverse($original, 'Charged to the wrong unit');

    expect($reversal->reverses_journal_id)->toBe($original->id)
        ->and($reversal->source)->toBe(Ledger::SOURCE_REVERSAL)
        ->and($reversal->reference)->toStartWith('REV-')

        // Positive, like every entry. A reversal is not a negative amount.
        ->and($reversal->amount_minor)->toBe(75_000_00)

        // Mirrored: what was debited is credited, on the same account, for the
        // same household.
        ->and($reversal->lines[0]->credit_minor)->toBe(75_000_00)
        ->and($reversal->lines[0]->debit_minor)->toBe(0)
        ->and($reversal->lines[0]->unit_id)->toBe($this->units[0]->id)
        ->and($reversal->lines[1]->debit_minor)->toBe(75_000_00);

    // And the pair nets to nothing on every account it touched.
    foreach (['1200', '4000'] as $code) {
        $account = Account::where('code', $code)->first();
        expect($this->ledger->balanceOf($account)->getMinorAmount()->toInt())->toBe(0);
    }

    // Both remain. That pair IS the audit trail — a deleted row cannot show an
    // auditor that a mistake was made and corrected.
    expect(Journal::count())->toBe(2);
});

it('refuses to reverse the same entry twice', function () {
    $original = $this->ledger->post('Fee raised', [
        Posting::debit('1200', 100_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 100_00),
    ]);

    $this->ledger->reverse($original, 'First correction');

    // Reversing twice would post the original amount a second time rather than
    // cancelling it.
    expect(fn () => $this->ledger->reverse($original->fresh(), 'Again'))
        ->toThrow(DomainException::class, 'already been reversed');
});

/*
 * TWO REQUESTS, ONE REVERSAL (bug hunt B020). Reversing a 450-unit run takes
 * over a second, and a second click on "Confirm reversal" arrives while the first
 * is still writing. Both used to pass the "already reversed?" check before either
 * had committed, and both posted a full mirror: every household credited twice.
 * The other request is played here through a query listener, at exactly the
 * moment between this one's check and its write, so the race is the same every run.
 */
it('refuses a reversal that another request posted between the check and the write', function () {
    $original = $this->ledger->post('Fee raised', [
        Posting::debit('1200', 6_200_00, unitId: $this->units[0]->id),
        Posting::debit('1200', 6_200_00, unitId: $this->units[1]->id),
        Posting::credit('4000', 12_400_00),
    ]);

    $raced = false;

    DB::connection('tenant')->listen(function ($query) use (&$raced, $original): void {
        if ($raced || ! str_contains($query->sql, 'reverses_journal_id')) {
            return;
        }

        $raced = true;

        (new Ledger)->reverse(Journal::query()->findOrFail($original->id), 'The first click');
    });

    expect(fn () => $this->ledger->reverse($original, 'The second click'))
        ->toThrow(DomainException::class, 'already been reversed');

    expect($raced)->toBeTrue()
        ->and(Journal::query()->where('reverses_journal_id', $original->id)->count())->toBe(1)
        ->and($this->ledger->balanceOf(Account::where('code', '1200')->first())->getMinorAmount()->toInt())->toBe(0);
});

it('refuses a second reversal of one entry at the database, whatever wrote it', function () {
    $original = $this->ledger->post('Fee raised', [
        Posting::debit('1200', 100_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 100_00),
    ]);

    $this->ledger->reverse($original, 'First correction');

    $receivable = Account::where('code', '1200')->first();
    $income = Account::where('code', '4000')->first();

    DB::connection('tenant')->table('journal_lines')->insert([
        ['entry_ref' => 'RAW-REV-2', 'account_id' => $receivable->id, 'line_no' => 1, 'debit_minor' => 0, 'credit_minor' => 100_00, 'currency' => 'JMD', 'unit_id' => $this->units[0]->id, 'created_at' => now()],
        ['entry_ref' => 'RAW-REV-2', 'account_id' => $income->id, 'line_no' => 2, 'debit_minor' => 100_00, 'credit_minor' => 0, 'currency' => 'JMD', 'unit_id' => null, 'created_at' => now()],
    ]);

    expect(fn () => DB::connection('tenant')->table('journals')->insert([
        'reference' => 'RAW-REV-2', 'memo' => 'raw second reversal', 'source' => Ledger::SOURCE_REVERSAL, 'source_id' => $original->id,
        'amount_minor' => 100_00, 'currency' => 'JMD', 'posted_on' => '2026-09-01', 'reverses_journal_id' => $original->id, 'created_at' => now(),
    ]))->toThrow(QueryException::class, 'Duplicate entry');
});

it('refuses a second entry for one bill, bill payment, pay run or return at the database', function () {
    // Credited to 2100, a liability: the rule here is one entry per source row, and an empty bank cannot pay (EST-B-02).
    foreach ([Ledger::SOURCE_BILL, Ledger::SOURCE_BILL_PAYMENT, Ledger::SOURCE_PAYROLL, Ledger::SOURCE_PAYROLL_REMITTANCE] as $source) {
        $this->ledger->post('Once', [Posting::debit('5100', 100), Posting::credit('2100', 100)], source: $source, sourceId: 41);

        expect(fn () => $this->ledger->post('Twice', [Posting::debit('5100', 100), Posting::credit('2100', 100)], source: $source, sourceId: 41))
            ->toThrow(QueryException::class, 'Duplicate entry');
    }

    // What was refused left no half-written entry behind.
    expect(Journal::query()->count())->toBe(4)
        ->and(JournalLine::query()->count())->toBe(8);

    // An entry typed by hand names no source row, and any number of them may exist.
    $this->ledger->post('Hand-posted', [Posting::debit('5100', 100), Posting::credit('2100', 100)]);
    $this->ledger->post('Hand-posted again', [Posting::debit('5100', 100), Posting::credit('2100', 100)]);

    expect(Journal::query()->where('source', Ledger::SOURCE_MANUAL)->count())->toBe(2);
});

it('refuses to reverse a reversal', function () {
    $original = $this->ledger->post('Fee raised', [
        Posting::debit('1200', 100_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 100_00),
    ]);

    $reversal = $this->ledger->reverse($original, 'Correction');

    expect(fn () => $this->ledger->reverse($reversal, 'Undo the undo'))
        ->toThrow(DomainException::class, 'itself a reversal');
});

/*
 * THE ESTATE'S DAY, NOT UTC'S (bug hunt B023, B082). At 8:30 PM on Sep 30 in
 * Kingston it is already Oct 1 in UTC. An entry posted then with no date, and a
 * reversal made then, belong to September — and the chart still reads September.
 */
it('dates an entry, a reversal and the chart\'s period on the estate\'s calendar in the evening', function () {
    $this->travelTo(Carbon::parse('2026-10-01 01:30:00', 'UTC'));

    $entry = $this->ledger->post('Fee raised', [
        Posting::debit('1200', 6_200_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 6_200_00),
    ]);

    expect($entry->posted_on->toDateString())->toBe('2026-09-30')
        ->and($entry->reference)->toBe('JV-2026-09-0001');

    $reversal = $this->ledger->reverse($entry, 'Billed at last year\'s rate');

    expect($reversal->posted_on->toDateString())->toBe('2026-09-30')
        ->and($reversal->reference)->toStartWith('REV-2026-09-');

    expect($this->ledger->chartOfAccounts()['period'])->toBe('September 2026');
});

/*
 * ONE MONTH'S INCOME UNDER A ONE-MONTH LABEL (bug hunt B079). October's run is
 * offered on 25 September and posts on 1 October. The chart, labelled September,
 * counted both months' dues as September's income and October's as receivable
 * already.
 */
it('counts only the month\'s own income for the month, and reads balances as at today', function () {
    $this->travelTo(Carbon::parse('2026-09-25 15:00:00', 'UTC'));

    $this->ledger->post('September dues', [
        Posting::debit('1200', 6_200_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 6_200_00),
    ], on: '2026-09-01');

    $this->ledger->post('October dues, billed in advance', [
        Posting::debit('1200', 6_200_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 6_200_00),
    ], on: '2026-10-01');

    $chart = $this->ledger->chartOfAccounts();

    $row = collect($chart['groups'])->flatMap(fn (array $group): array => $group['rows'])->firstWhere('code', '4000');
    $kpis = collect($chart['kpis'])->keyBy('key');

    expect($row['balance_minor'])->toBe(6_200_00)
        ->and($kpis['receivable']['value_minor'])->toBe(6_200_00)
        ->and($chart['period'])->toBe('September 2026')
        ->and($chart['asAt'] ?? null)->toBe('25 Sep 2026');

    // A month already over is read as it closed.
    $august = $this->ledger->chartOfAccounts(Carbon::parse('2026-08-01'));
    $row = collect($august['groups'])->flatMap(fn (array $group): array => $group['rows'])->firstWhere('code', '4000');

    expect($august['period'])->toBe('August 2026')
        ->and($row['balance_minor'])->toBe(0)
        ->and(collect($august['kpis'])->keyBy('key')['receivable']['value_minor'])->toBe(0);
});

/*
 * THE RESERVE IS THE ACCOUNT MARKED AS THE RESERVE (bug hunt B035). The tile used
 * to be whichever bank account had the highest code, so adding 1020 Petty Cash
 * relabelled petty cash as the estate's reserve.
 */
it('draws the reserve fund tile from the account marked as the reserve, and no tile where none is', function () {
    // The operating account holds what it pays out (EST-B-02), so cash on hand is 0 + 50,000 + 100 across the three.
    $this->ledger->post('Opening', [Posting::debit('1000', 50_100_00), Posting::credit('3000', 50_100_00)]);
    $this->ledger->post('Transfer to reserve', [Posting::debit('1010', 50_000_00), Posting::credit('1000', 50_000_00)]);

    $this->ledger->addAccount('1020', 'Petty Cash', Account::ASSET, isBankAccount: true);
    $this->ledger->post('Float', [Posting::debit('1020', 100_00), Posting::credit('1000', 100_00)]);

    $kpis = collect($this->ledger->chartOfAccounts()['kpis'])->keyBy('key');

    expect($kpis['reserve']['value_minor'])->toBe(50_000_00)
        ->and($kpis['reserve']['label'])->toBe('Reserve fund')
        ->and($kpis['cash']['value_minor'])->toBe(50_100_00);

    Account::query()->where('code', '1010')->update(['is_reserve_fund' => false]);

    expect(collect($this->ledger->chartOfAccounts()['kpis'])->pluck('key')->all())->toBe(['cash', 'receivable', 'payable']);
});

it('refuses to delete an account that has been posted to, and archives it instead', function () {
    $this->ledger->post('Fee raised', [
        Posting::debit('1200', 100_00, unitId: $this->units[0]->id),
        Posting::credit('4000', 100_00),
    ]);

    $used = Account::where('code', '1200')->first();

    expect(fn () => $used->delete())->toThrow(LogicException::class, 'cannot be deleted');

    // The database says so too, independently of the model.
    expect(fn () => DB::connection('tenant')->table('accounts')->where('id', $used->id)->delete())
        ->toThrow(QueryException::class);

    $used->archive();

    expect($used->fresh()->is_active)->toBeFalse()
        ->and($used->fresh()->archived_at)->not->toBeNull();
});

it('lets an unused account be deleted, because nothing depends on it', function () {
    $spare = Account::create(['code' => '5900', 'name' => 'Sundry', 'type' => Account::EXPENSE, 'is_active' => true]);

    expect(fn () => $spare->delete())->not->toThrow(LogicException::class);
    expect(Account::where('code', '5900')->exists())->toBeFalse();
});
