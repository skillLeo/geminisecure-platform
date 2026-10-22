<?php

declare(strict_types=1);

use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The chart of accounts shows the surplus, so the balance sheet visibly balances (EST-S-07, M85 A5)
|--------------------------------------------------------------------------
|
| M81 §3.5: "Accumulated Fund reads 0, the current-year surplus appears nowhere, so assets ≠ liabilities + equity on
| screen. The books balance; the screen does not show it." Nothing closes income and expense into 3000, so the surplus
| sits in them until a year is closed. The Equity group carries it (this year's, and any earlier year's not yet closed),
| and every group states its total, so assets equal liabilities plus equity on the screen itself.
|
| An estate database of this file's own with an empty ledger, so every figure below is known exactly.
|
*/

function balanceSheetEstate(): void
{
    static $built = false;

    // The configured prefix, never the literal: see `FacilitiesFixture::database()`.
    $database = config('tenancy.database.prefix').'balancesheettest';

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
    balanceSheetEstate();

    DB::connection('tenant')->beginTransaction();

    $this->travelTo(Carbon::parse('2026-10-11 15:00:00', 'UTC'));
    $this->ledger = app(Ledger::class);
});

afterEach(function (): void {
    DB::connection('tenant')->rollBack();
});

/** The chart's rows by group label, each as [code, name, balance]. */
function balanceSheetGroups(array $chart): array
{
    return collect($chart['groups'])->mapWithKeys(static fn (array $group): array => [
        $group['label'] => collect($group['rows'])->map(static fn (array $row): array => [$row['code'], $row['name'], $row['balance_minor']])->all(),
    ])->all();
}

it('carries this year\'s surplus and an earlier year\'s not yet closed in Equity, totals every group, and so balances', function (): void {
    $post = fn (string $memo, string $debit, string $credit, int $minor, string $on) => $this->ledger->post($memo, [
        Posting::debit($debit, $minor),
        Posting::credit($credit, $minor),
    ], on: $on);

    $post('Opening fund', '1010', '3000', 2_000_000_00, '2025-01-01');
    $post('Clubhouse hire, 2025', '1000', '4100', 300_000_00, '2025-12-15');
    $post('Gate repair, 2025', '5100', '1000', 50_000_00, '2025-12-20');
    $post('Dues collected, March', '1000', '4000', 1_000_000_00, '2026-03-10');
    $post('Security services, October', '5050', '1000', 400_000_00, '2026-10-05');
    $post('Dues collected after today', '1000', '4000', 99_999_00, '2026-10-20');

    $chart = $this->ledger->chartOfAccounts();
    $groups = collect($chart['groups'])->keyBy('label');
    $equity = balanceSheetGroups($chart)['Equity'];

    // 2,000,000 fund + 250,000 from 2025 (300,000 less 50,000) + 600,000 this year (1,000,000 less 400,000).
    expect($equity)->toBe([
        ['3000', 'Accumulated Fund', 2_000_000_00],
        ['', 'Surplus from earlier years, not yet closed to the fund', 250_000_00],
        ['', 'Surplus for 2026 so far', 600_000_00],
    ])
        ->and($groups['Assets']['total_minor'])->toBe(2_850_000_00)
        ->and($groups['Liabilities']['total_minor'])->toBe(0)
        ->and($groups['Equity']['total_minor'])->toBe(2_850_000_00)
        // Income and expense stay the month's (October): the 400,000 of security, none of March's dues.
        ->and($groups['Income']['total_minor'])->toBe(0)
        ->and($groups['Expenses']['total_minor'])->toBe(400_000_00)
        ->and($chart['balance'])->toBe(['assets_minor' => 2_850_000_00, 'liabilities_and_equity_minor' => 2_850_000_00]);

    // A month already over is read as it closed: as at 31 August, no October security yet.
    $august = $this->ledger->chartOfAccounts(Carbon::parse('2026-08-01'));

    expect(balanceSheetGroups($august)['Equity'][2])->toBe(['', 'Surplus for 2026 so far', 1_000_000_00])
        ->and($august['balance'])->toBe(['assets_minor' => 3_250_000_00, 'liabilities_and_equity_minor' => 3_250_000_00]);
});

it('names a deficit a deficit, leaves out an earlier year that left nothing, and still balances', function (): void {
    // The repair is owed, not paid, so no bank goes below nothing.
    $this->ledger->post('Dues collected', [Posting::debit('1000', 100_000_00), Posting::credit('4000', 100_000_00)], on: '2026-02-01');
    $this->ledger->post('Roof repair, not yet paid', [Posting::debit('5100', 300_000_00), Posting::credit('2200', 300_000_00)], on: '2026-02-02');

    $chart = $this->ledger->chartOfAccounts();

    expect(balanceSheetGroups($chart)['Equity'])->toBe([
        ['3000', 'Accumulated Fund', 0],
        ['', 'Deficit for 2026 so far', -200_000_00],
    ])
        ->and($chart['balance'])->toBe(['assets_minor' => 100_000_00, 'liabilities_and_equity_minor' => 100_000_00]);
});

it('adds no surplus and no totals for a viewer the estate\'s position is withheld from', function (): void {
    $this->ledger->post('Dues collected', [Posting::debit('1000', 100_000_00), Posting::credit('4000', 100_000_00)], on: '2026-02-01');

    $chart = $this->ledger->chartOfAccounts(null, false);

    expect(collect(balanceSheetGroups($chart)['Equity'])->pluck(0)->all())->toBe(['3000'])
        ->and(collect($chart['groups'])->pluck('total_minor')->unique()->all())->toBe([null])
        ->and($chart['balance'])->toBeNull();
});
