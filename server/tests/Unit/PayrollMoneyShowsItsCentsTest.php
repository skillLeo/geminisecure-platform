<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payroll money shows its cents (batch 1, Residents' SERIOUS finding, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| Whole dollars where cents exist: the run list's gross and net per run (a formatter named `exact` that dropped them),
| the run page's summary boxes (its net pay is the bank's total to the cent), and every employee's monthly rate. Every
| amount that can carry cents shows them on the payroll pages of both consoles. Kept, on purpose: the run list's KPI
| tiles ("$440k", "$1.23M"), an abbreviated summary read at a glance, which never pretends to be the figure itself.
|
*/

function payrollMoneyPage(string $page): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/'.$page);
}

it('rounds no payroll amount to whole dollars on either console', function () {
    $pages = array_merge(
        glob(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Payroll/*.vue') ?: [],
        glob(dirname(__DIR__, 2).'/resources/js/Pages/Gemini/Payroll/*.vue') ?: [],
    );

    $found = [];

    foreach ($pages as $page) {
        if (str_contains((string) file_get_contents($page), 'maximumFractionDigits: 0')) {
            $found[] = basename($page);
        }
    }

    expect($found)->toBe([]);
});

it('writes each run\'s gross and net, the run\'s totals and each monthly rate to the cent', function () {
    $twoPlaces = "toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })";

    $runs = payrollMoneyPage('Estate/Payroll/Runs.vue');
    $run = payrollMoneyPage('Estate/Payroll/Run.vue');
    $employees = payrollMoneyPage('Estate/Payroll/Employees.vue');

    expect(preg_match('/const exact = \(minor\) =>[^;]*?'.preg_quote($twoPlaces, '/').'/s', $runs))->toBe(1)
        ->and(str_contains($run, 'const box = (entry) => (entry.value_minor === undefined ? entry.value : exact(entry.value_minor))'))->toBeTrue()
        ->and(preg_match('/const rate = \(minor\) => `\$\$\{\(minor \/ 100\)\.'.preg_quote($twoPlaces, '/').'\} a month`/', $employees))->toBe(1)
        // The KPI tiles stay abbreviated: a summary read at a glance.
        ->and(str_contains($runs, 'const tile = (kpi) => (kpi.value_minor === undefined ? kpi.value : abbreviated(kpi.value_minor))'))->toBeTrue();
});
