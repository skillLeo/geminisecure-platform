<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use App\Services\Estate\Reports;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A report as a file: numeric amounts, one currency label, totals and the period (REP-6, E2E-M10; M85 Phase B)
|--------------------------------------------------------------------------
|
| M81 §5: "Arrears Ageing says 'Balance JMD' with a numeric CSV; P&L exports '$74,737,126.08' as text, no totals row,
| no period in the file or its name. One currency label, numeric CSV, totals, period." The file is the report: each
| money column headed "<name> JMD" with plain numbers a spreadsheet adds up, a Total row where a column adds up, the
| report's own totals, the period it covers, and the period in the file's name.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();

    // An export writes its audit entry centrally: rolled back, as every file's is, or a later file reads it as its own.
    DB::connection('mysql')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

it('writes the P&L with plain amounts under one currency label, its totals and its period', function (): void {
    DB::connection('tenant')->beginTransaction();

    try {
        // A year of this file's own: income in, then a repair paid from it.
        $ledger = app(Ledger::class);
        $ledger->post('M85 export: dues', [Posting::debit('1000', 1_234_567_89), Posting::credit('4000', 1_234_567_89)], on: '2014-03-01');
        $ledger->post('M85 export: repair', [Posting::debit('5100', 1_000_00), Posting::credit('1000', 1_000_00)], on: '2014-03-02');

        $file = Reports::csv(app(Reports::class)->run('profit_and_loss', '2014-01-01', '2014-12-31'));
    } finally {
        DB::connection('tenant')->rollBack();
    }

    expect($file['headers'])->toBe(['Kind', 'Account', 'Name', 'Amount JMD'])
        ->and(array_map(static fn (array $row): string => (string) $row[3], array_slice($file['rows'], 0, 2)))->toBe(['1234567.89', '1000.00'])
        ->and(array_slice($file['rows'], 2))->toBe([
            [],
            ['Income', '1234567.89'],
            ['Expenses', '1000.00'],
            ['Surplus', '1233567.89'],
            ['Period', 'January 1, 2014 to December 31, 2014'],
        ]);
});

it('ends the Arrears Ageing with a Total that is the sum of its balances', function (): void {
    $file = Reports::csv(app(Reports::class)->run('arrears_ageing', '2026-01-01', '2026-12-31'));

    $rows = array_values(array_filter($file['rows'], static fn (array $row): bool => count($row) === 5));
    $total = array_pop($rows);
    $sum = array_sum(array_map(static fn (array $row): int => Money::of((string) $row[3], 'JMD')->getMinorAmount()->toInt(), $rows));

    expect($file['headers'][3])->toBe('Balance JMD')
        ->and($total[0])->toBe('Total')
        ->and(Money::of((string) $total[3], 'JMD')->getMinorAmount()->toInt())->toBe($sum)
        ->and(end($file['rows']))->toBe(['Period', app(Reports::class)->run('arrears_ageing', '2026-01-01', '2026-12-31')['period']]);
});

it('names the file for the period it covers', function (): void {
    FacilitiesFixture::platform();

    $response = $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/reports/profit_and_loss/export').'?from=2014-01-01&to=2014-12-31')
        ->assertOk();

    expect((string) $response->headers->get('Content-Disposition'))->toContain('profit-and-loss-2014-01-01-to-2014-12-31.csv');
});
