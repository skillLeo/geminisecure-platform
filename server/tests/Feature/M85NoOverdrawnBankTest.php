<?php

declare(strict_types=1);

use App\Models\Estate\Journal;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| A posting that would overdraw a bank or reserve account is refused (EST-B-02, M85 A6)
|--------------------------------------------------------------------------
|
| M81 §3.7: Reserve Fund 1010 read −$100, "with nothing stopping an account going below zero". The fix: "a posting that
| would overdraw a bank or reserve account is refused with a plain reason". Money cannot leave an account that does not
| hold it, on the day it leaves or on any day after: the running balance from the entry's date on is what is checked.
|
| An estate database of this file's own with an empty ledger, so every balance is known exactly.
|
*/

function noOverdraftEstate(): void
{
    static $built = false;

    // The configured prefix, never the literal: see `FacilitiesFixture::database()`.
    $database = config('tenancy.database.prefix').'nooverdrafttest';

    config([
        'database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => $database]),
        'database.default' => 'tenant',
    ]);

    DB::purge('tenant');

    if ($built) {
        return;
    }

    $owner = DB::connection('mysql_owner');
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::purge('tenant');

    Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--database' => 'tenant', '--force' => true]);

    (new ChartOfAccountsSeeder)->run();

    $built = true;
}

beforeEach(function (): void {
    noOverdraftEstate();

    DB::connection('tenant')->beginTransaction();

    $this->travelTo(Carbon::parse('2026-10-11 15:00:00', 'UTC'));
    $this->ledger = app(Ledger::class);

    $this->move = fn (string $memo, string $debit, string $credit, int $minor, string $on = '2026-10-11') => $this->ledger->post($memo, [
        Posting::debit($debit, $minor),
        Posting::credit($credit, $minor),
    ], on: $on);
});

afterEach(function (): void {
    DB::connection('tenant')->rollBack();
});

it('refuses to take more from the bank than it holds, in plain words, and posts nothing; taking all of it is allowed', function (): void {
    ($this->move)('Dues collected', '1000', '4000', 50_000_00, '2026-10-01');
    $journals = Journal::query()->count();

    expect(fn () => ($this->move)('Gate repair paid', '5100', '1000', 50_000_01))
        ->toThrow(DomainException::class, '1000 Operating Bank Account cannot go below nothing: it holds $50,000.00 on 11 Oct 2026, and this takes $50,000.01 from it.');

    expect(Journal::query()->count())->toBe($journals);

    ($this->move)('Gate repair paid', '5100', '1000', 50_000_00);

    expect(Journal::query()->count())->toBe($journals + 1);
});

it('refuses the same for the reserve fund, which is a bank account and the reserve', function (): void {
    expect(fn () => ($this->move)('Transfer from the reserve', '1000', '1010', 100_00))
        ->toThrow(DomainException::class, '1010 Reserve Fund Account cannot go below nothing: it holds $0.00 on 11 Oct 2026, and this takes $100.00 from it.');
});

it('checks the balance on the day the money leaves, not only today', function (): void {
    ($this->move)('Dues collected', '1000', '4000', 80_000_00, '2026-10-05');

    // On 1 October the account held nothing, whatever it holds today.
    expect(fn () => ($this->move)('Gate repair paid, back-dated', '5100', '1000', 10_000_00, '2026-10-01'))
        ->toThrow(DomainException::class, 'it holds $0.00 on 1 Oct 2026');
});

it('refuses a withdrawal that would leave a later day below nothing', function (): void {
    ($this->move)('Dues collected', '1000', '4000', 100_000_00, '2026-10-01');
    ($this->move)('Contractor paid', '5100', '1000', 100_000_00, '2026-10-08');
    ($this->move)('Dues collected', '1000', '4000', 100_000_00, '2026-10-10');

    // Enough on 5 October (100,000), but from 8 October to 9 October the account would stand at -40,000.
    expect(fn () => ($this->move)('Electricity paid', '5200', '1000', 40_000_00, '2026-10-05'))
        ->toThrow(DomainException::class, 'it holds $0.00 on 8 Oct 2026, and this takes $40,000.00 from it.');
});

it('never refuses money coming in, even to an account already below nothing, and leaves every other account to the entries', function (): void {
    // An overdraft written before the rule (live's 1010 read -$100): its lines and header straight into the tables.
    $id = fn (string $code): int => (int) DB::connection('tenant')->table('accounts')->where('code', $code)->value('id');

    DB::connection('tenant')->table('journal_lines')->insert([
        ['entry_ref' => 'OLD-0001', 'account_id' => $id('5100'), 'line_no' => 1, 'debit_minor' => 100_00, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now()],
        ['entry_ref' => 'OLD-0001', 'account_id' => $id('1010'), 'line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 100_00, 'currency' => 'JMD', 'created_at' => now()],
    ]);
    DB::connection('tenant')->table('journals')->insert([
        'reference' => 'OLD-0001', 'memo' => 'Before the rule', 'source' => 'manual', 'amount_minor' => 100_00,
        'currency' => 'JMD', 'posted_on' => '2026-10-01', 'created_at' => now(),
    ]);

    // Money into it is never refused, even while it stays below nothing.
    ($this->move)('Part of it put back', '1010', '4000', 40_00);

    // Card money in transit is not a bank account: the entries decide it.
    ($this->move)('Card refund before payout', '4000', '1080', 25_00);

    expect(Journal::query()->count())->toBe(3);
});
