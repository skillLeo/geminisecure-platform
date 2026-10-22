<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M11 found the receipt gaps a different way, and the same gaps
|--------------------------------------------------------------------------
|
| A gap in the receipt sequence is a finding, not a figure: a number allocated
| and carried by no receipt, which somebody has to account for. The register used
| to find them by fetching every receipt number the estate has issued — 11,726 at
| Phoenix Park — and testing each number in the sequence against them. M11 A2
| finds them in SQL and pages the register. Neither may change which numbers are
| reported missing, or where on the screen each one is drawn.
|
| The yardstick is `Tests\Support\LegacyReceipts`, the old code verbatim, run over
| `EstateScaleFixture`'s own 11,726 receipts — first as built, then with numbers
| knocked out at the floor, in the middle and at the top, and with receipts that
| are not in the format, inside a transaction that is rolled back.
|
*/

use App\Services\Estate\Receipts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstateScaleFixture;
use Tests\Support\LegacyReceipts;

beforeEach(function (): void {
    EstateScaleFixture::boot();
    EstateScaleFixture::matrix();

    DB::connection('mysql')->beginTransaction();

    // The estate's central row, so `Receipts::prefix()` answers with the prefix its receipts carry.
    EstateScaleFixture::enter();
    EstateScaleFixture::boot();

    $this->receipts = app(Receipts::class);
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/** A page of the register as an address asks for it (M32 F5): `?q=…&sort=…&dir=…&page=…`. */
function receiptsAt(Receipts $receipts, array $query = []): array
{
    return $receipts->registerPage(table: Receipts::registerTable(Request::create('/finance/receipts', 'GET', $query)));
}

/** @return array{0: string, 1: int, 2: int} */
function receiptSequence(): array
{
    $prefix = app(Receipts::class)->prefix();
    $row = DB::connection('tenant')->table('receipt_sequences')->where('prefix', $prefix)->first();

    return [$prefix, (int) $row->first_no, (int) $row->last_no];
}

it('reads the sequence the scale estate was built with', function (): void {
    [$prefix, $first, $last] = receiptSequence();

    expect($prefix)->toBe('PHOENI')
        ->and($first)->toBe(1)
        ->and($last)->toBe(11_726)
        ->and(array_column($this->receipts->register()['gaps'], 'number'))->toBe(LegacyReceipts::gapNumbers($prefix, $first, $last));
});

it('finds exactly the gaps it found before, wherever numbers go missing and whatever else is in the table', function (): void {
    $tenant = DB::connection('tenant');
    $tenant->beginTransaction();

    try {
        [$prefix] = receiptSequence();
        $unit = (int) $tenant->table('units')->orderBy('id')->value('id');

        // Knocked out: the very first number, one in the middle, two side by side.
        foreach (['00001', '05000', '08000', '08001'] as $no) {
            $tenant->table('payments')->where('receipt_no', $prefix.'-R-'.$no)->update(['receipt_no' => $prefix.'-X-'.$no]);
        }

        // Past the old high-water mark: numbers handed out and a few carried.
        $tenant->table('receipt_sequences')->where('prefix', $prefix)->update(['last_no' => 11_740]);

        $receipt = static fn (string $no): array => [
            'unit_id' => $unit,
            'receipt_no' => $no,
            'amount_minor' => 100,
            'currency' => 'JMD',
            'method' => 'cash',
            'received_at' => now()->subDay(),
            'entered_at' => now(),
            'status' => 'recorded',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $tenant->table('payments')->insert([
            $receipt($prefix.'-R-11730'),       // carried, in the new range
            $receipt($prefix.'-R-011733'),      // six digits, still number 11,733
            $receipt($prefix.'-R-00000'),       // number zero: absent, as ->filter() made it
            $receipt($prefix.'-R-11735abc'),    // not in the format: not a number at all
            $receipt(strtolower($prefix).'-r-11736'), // LIKE matches it; the pattern is case-sensitive and does not
            $receipt('LEGACY-11737'),           // another prefix entirely
        ]);

        [$prefix, $first, $last] = receiptSequence();
        $legacy = LegacyReceipts::gapNumbers($prefix, $first, $last);

        expect(array_column($this->receipts->register()['gaps'], 'number'))->toBe($legacy)
            ->and($legacy)->toContain(1, 5000, 8000, 8001, 11_727, 11_736, 11_740)
            ->and($legacy)->not->toContain(11_730, 11_733);

        // And with the floor raised past the first gap: below the floor nothing was allocated.
        $tenant->table('receipt_sequences')->where('prefix', $prefix)->update(['first_no' => 3]);

        [$prefix, $first, $last] = receiptSequence();

        expect(array_column($this->receipts->register()['gaps'], 'number'))->toBe(LegacyReceipts::gapNumbers($prefix, $first, $last))
            ->and(array_column($this->receipts->register()['gaps'], 'number'))->not->toContain(1);

        /*
         * EVERY RECEIPT ON EXACTLY ONE PAGE, IN SEQUENCE ORDER, AND EVERY GAP DRAWN
         * ON EXACTLY ONE PAGE, inside the run of the sequence that page shows.
         */
        $page = receiptsAt($this->receipts);
        $seen = [];
        $drawn = [];
        $numbers = [];

        for ($p = 1; $p <= $page['table']['last']; $p++) {
            $one = receiptsAt($this->receipts, ['page' => $p]);

            foreach ($one['rows'] as $row) {
                $seen[] = $row['id'];
                $numbers[] = $this->receipts->numberOf($row['receipt_no']);
            }

            foreach ($one['page_gaps'] as $gap) {
                $drawn[] = $gap['number'];
            }

            expect($one['gaps'])->toBe($page['gaps']);
        }

        $total = (int) $tenant->table('payments')->count();
        $numbered = array_values(array_filter($numbers, static fn (?int $n): bool => $n !== null));

        expect($seen)->toHaveCount($total)
            ->and(array_unique($seen))->toHaveCount($total)
            ->and($page['table']['total'])->toBe($total)

            // Newest number first, and the receipts that carry no number after all of them.
            ->and($numbered)->toBe(collect($numbered)->sortDesc()->values()->all())
            ->and(array_slice($numbers, count($numbered)))->each->toBeNull()

            // Each gap drawn once, and every gap drawn.
            ->and(collect($drawn)->sort()->values()->all())->toBe(array_column($page['gaps'], 'number'))
            ->and(count(array_unique($drawn)))->toBe(count($drawn));
    } finally {
        $tenant->rollBack();
    }

    expect(receiptSequence()[2])->toBe(11_726);
});

it('finds a receipt by its number, its lot or its bank reference, and draws no gap in a searched list', function (): void {
    [$prefix] = receiptSequence();

    $one = receiptsAt($this->receipts, ['q' => $prefix.'-R-05001']);

    expect(array_column($one['rows'], 'receipt_no'))->toBe([$prefix.'-R-05001'])
        ->and($one['page_gaps'])->toBe([])
        ->and($one['search'])->toBe($prefix.'-R-05001');

    $lot = DB::connection('tenant')->table('units')->where('reference', 'Lot 47')->value('id');
    $expected = (int) DB::connection('tenant')->table('payments')
        ->join('units', 'units.id', '=', 'payments.unit_id')
        ->where('units.reference', 'like', '%Lot 1999A%')
        ->count();

    expect(receiptsAt($this->receipts, ['q' => 'Lot 1999A'])['table']['total'])->toBe($expected);

    $forLot = receiptsAt($this->receipts, ['q' => 'Lot 47']);

    expect($forLot['table']['total'])->toBeGreaterThan(0)
        ->and(collect($forLot['rows'])->every(static fn (array $row): bool => str_contains($row['unit'], 'Lot 47') || str_contains((string) $row['receipt_no'], 'Lot 47') || str_contains((string) $row['reference'], 'Lot 47')))->toBeTrue()
        ->and(collect($forLot['rows'])->pluck('unit_id')->contains($lot))->toBeTrue();

    expect(receiptsAt($this->receipts, ['q' => 'nothing-is-called-this'])['rows'])->toBe([]);
});

it('draws a gap in place only in the sequence\'s own order, and orders the whole register by any other (M32 F5)', function (): void {
    $tenant = DB::connection('tenant');
    $tenant->beginTransaction();

    try {
        [$prefix] = receiptSequence();
        $tenant->table('payments')->where('receipt_no', $prefix.'-R-11720')->update(['receipt_no' => $prefix.'-X-11720']);

        $inSequence = receiptsAt($this->receipts);
        expect($inSequence['in_sequence'])->toBeTrue()
            ->and(array_column($inSequence['page_gaps'], 'number'))->toBe([11_720]);

        // By amount, largest first: the largest receipt on the estate heads page one, and no gap is drawn.
        $byAmount = receiptsAt($this->receipts, ['sort' => 'amount', 'dir' => 'desc']);
        $amounts = array_column($byAmount['rows'], 'amount_minor');

        expect($byAmount['in_sequence'])->toBeFalse()
            ->and($byAmount['page_gaps'])->toBe([])
            ->and($amounts)->toBe(collect($amounts)->sortDesc()->values()->all())
            ->and($amounts[0])->toBe((int) $tenant->table('payments')->max('amount_minor'));

        // And ascending, the smallest.
        expect(receiptsAt($this->receipts, ['sort' => 'amount', 'dir' => 'asc'])['rows'][0]['amount_minor'])
            ->toBe((int) $tenant->table('payments')->min('amount_minor'));
    } finally {
        $tenant->rollBack();
    }
});
