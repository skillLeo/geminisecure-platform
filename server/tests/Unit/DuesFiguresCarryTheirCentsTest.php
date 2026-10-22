<?php

/*
|--------------------------------------------------------------------------
| EVERY DUES FIGURE CARRIES ITS CENTS (Hassam, 12 October 2026: "Every amount that could carry cents, carries them")
|--------------------------------------------------------------------------
|
| The unit ledger's statement (each charge, payment and running balance), its ageing bands and the reversal's amount
| were drawn to the dollar, so $6,200.50 read $6,201 beside a journal that said otherwise. The abbreviated summary cards
| on Dues & ledger and the dashboard ($1.84M, $412k) stay abbreviated, the board's magnitudes, but below a thousand
| nothing is abbreviated, so there they carry their cents too.
*/

function duesCentsSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

it('draws the unit ledger statement, its bands and the reversal to the cent, never to the dollar', function () {
    $ledger = duesCentsSource('resources/js/Pages/Estate/Dues/UnitLedger.vue');

    expect(str_contains($ledger, 'toTheDollar'))->toBeFalse()
        ->and(str_contains($ledger, 'maximumFractionDigits: 0'))->toBeFalse()
        ->and(substr_count($ledger, 'toTheCent(row.'))->toBe(3)
        ->and(str_contains($ledger, 'toTheCent(band.value_minor)'))->toBeTrue()
        ->and(str_contains($ledger, 'toTheCent(reversing.amount_minor)'))->toBeTrue();
});

it('gives an unabbreviated summary figure its cents on Dues & ledger and the dashboard', function () {
    foreach (['resources/js/Pages/Estate/Dues/Arrears.vue', 'resources/js/Pages/Estate/Dashboard.vue'] as $page) {
        $source = duesCentsSource($page);

        expect(str_contains($source, "return `\$\${(minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`"))->toBeTrue()
            ->and(str_contains($source, "return `\$\${value.toLocaleString('en-US')}`"))->toBeFalse();
    }
});
