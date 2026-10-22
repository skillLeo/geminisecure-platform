<?php

declare(strict_types=1);

use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use App\Services\Estate\Reports;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Every money report's totals are the ledger's, summed another way (EST-UC-16, made permanent; M85 Phase B)
|--------------------------------------------------------------------------
|
| M81 §5: "EST-UC-16's assertion becomes permanent: every report's total is asserted against the ledger, not against
| itself." Each total below is summed here in SQL written out longhand from the posted journal lines, never through
| the code that drew the report, so the two reach the same number by different routes or the test says which differs.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
});

/** A report's summary card, as a number of minor units. */
function m85Card(array $report, string $label): int
{
    $value = collect($report['summary'])->firstWhere('label', $label)['value'] ?? null;

    expect($value)->not->toBeNull("the report has no card named {$label}");

    return (int) round(((float) str_replace(['$', ','], '', (string) $value)) * 100);
}

it('totals the Profit & Loss to the income and expense lines posted in its period', function (): void {
    DB::connection('tenant')->beginTransaction();

    try {
        $ledger = app(Ledger::class);
        $ledger->post('M85 tie: dues', [Posting::debit('1000', 905_000_00), Posting::credit('4000', 905_000_00)], on: '2015-04-01');
        $ledger->post('M85 tie: booking fee', [Posting::debit('1000', 12_500_00), Posting::credit('4100', 12_500_00)], on: '2015-04-02');
        $ledger->post('M85 tie: repair', [Posting::debit('5100', 61_250_00), Posting::credit('1000', 61_250_00)], on: '2015-04-03');

        $report = app(Reports::class)->run('profit_and_loss', '2015-01-01', '2015-12-31');

        $sum = static fn (string $type, string $sign): int => (int) DB::connection('tenant')->selectOne("
            SELECT COALESCE(SUM({$sign}), 0) AS total
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
              JOIN journals j ON j.reference = l.entry_ref
             WHERE a.type = ? AND j.posted_on BETWEEN '2015-01-01' AND '2015-12-31'
        ", [$type])->total;

        $income = $sum('income', 'l.credit_minor - l.debit_minor');
        $expense = $sum('expense', 'l.debit_minor - l.credit_minor');

        expect(m85Card($report, 'Income'))->toBe($income)
            ->and(m85Card($report, 'Expenses'))->toBe($expense)
            ->and(m85Card($report, 'Surplus'))->toBe($income - $expense)
            ->and($income)->toBe(917_500_00);
    } finally {
        DB::connection('tenant')->rollBack();
    }
});

it('totals the Arrears Ageing to what the households owe on 1200 today, row by row and bucket by bucket', function (): void {
    // A lot of this file's own owes September's fee, so the totals are never compared across nothing.
    $unit = FacilitiesFixture::scratchUnit('Lot 98591', 'Fx Ledger Tie');
    FacilitiesFixture::boot();
    DB::connection('tenant')->beginTransaction();

    try {
        app(Dues::class)->charge($unit, Money::of(6_200, 'JMD'), 'M85 tie: September fee', '2026-09-01');

        m85ArrearsTie();
    } finally {
        DB::connection('tenant')->rollBack();
    }
});

/** The ageing's rows and its buckets each add up to what the households owe on 1200 today, and it has rows. */
function m85ArrearsTie(): void
{
    $report = app(Reports::class)->run('arrears_ageing', '2026-01-01', '2026-12-31');

    // Each unit's 1200 lines as at the estate's today; what is owed is the sum of the balances above nothing.
    $owed = (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(balance), 0) AS owed FROM (
            SELECT SUM(l.debit_minor - l.credit_minor) AS balance
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
              JOIN journals j ON j.reference = l.entry_ref
             WHERE a.code = ? AND l.unit_id IS NOT NULL AND j.posted_on <= ?
             GROUP BY l.unit_id
        ) units WHERE balance > 0
    ', ['1200', Ledger::today()->toDateString()])->owed;

    $rows = array_sum(array_map(static fn (array $row): int => (int) round(((float) $row[3]) * 100), $report['rows']));

    // The summary is "Total outstanding" and then the four buckets.
    $buckets = array_sum(array_map(
        static fn (array $card): int => (int) round(((float) str_replace(['$', ','], '', $card['value'])) * 100),
        array_filter($report['summary'], static fn (array $card): bool => $card['label'] !== 'Total outstanding'),
    ));

    expect($report['rows'])->not->toBeEmpty()
        ->and($rows)->toBe($owed)
        ->and(m85Card($report, 'Total outstanding'))->toBe($owed)
        ->and($buckets)->toBe($owed);
}
