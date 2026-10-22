<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Accounting pages write a date one way: "2 Sep 2026" (M53 Law 5; Hassam, 12 Oct, batch 1)
|--------------------------------------------------------------------------
|
| The Bills page's Due date read "Sep 2, 2026", a paid bill "Paid Sep 5", the statement's lines "Aug 1", the chart's
| note "as at Sep 25, 2026" and the overdraft refusal "on Oct 11, 2026": the month first, where the Reports and the
| account drill-down already said "2 Sep 2026". A month on its own ("October 2026", a period's name) is not a date and
| stays; the Gemini console's own forms are its console's decision and are not read here.
|
| Read from the services and the controller the Accounting pages are drawn from.
|
*/

it('writes no month-first date in the services and the controller the Accounting pages come from', function (string $file): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);

    preg_match_all('/->format\(\'(M j, Y|M j|F j, Y|F j)\'\)/', $source, $found);

    expect($found[0])->toBe([], $file.' still writes a date with the month first: '.implode(', ', $found[0]));
})->with([
    'the payables: bills, vendors, the statement' => ['app/Services/Estate/Payables.php'],
    'the ledger: the chart\'s as-at note and the overdraft refusal' => ['app/Services/Estate/Ledger.php'],
    'the payables controller: the payment-date refusal' => ['app/Http/Controllers/Estate/PayablesController.php'],
]);
