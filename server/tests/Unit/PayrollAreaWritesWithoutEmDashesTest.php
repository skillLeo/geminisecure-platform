<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payroll, shifts and the guard's payslip say it without an em dash (M53 Law 3; the test campaign, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| The sweep after the payslip's em dashes found seventeen more in this lane's sentences: the employee form's four
| refusals, the Gemini pay run's exception reasons and its S01 message, the bank and summary files and their
| descriptions, the exported file's title, the clock-out refusal the Guard App shows, the geofence a supervisor reads
| in the audit log, and a reason on the exceptions page. Every string literal of these files is read, code comments
| aside, and every line of the payroll pages outside their comments, so a new one cannot slip back in. The lone "—"
| that stands for an empty amount is Law 5's decision, not this one, and is left.
|
*/

function payrollAreaRoot(): string
{
    return dirname(__DIR__, 2);
}

it('writes no em dash in any string the payroll area can show, post or send', function () {
    $files = [
        'app/Services/Estate/Payroll.php',
        'app/Services/Payroll/PayrollCalculator.php',
        'app/Services/Payroll/StatutoryRates.php',
        'app/Services/Payroll/StatutoryFilingRegister.php',
        'app/Services/Payroll/RateTable.php',
        'app/Services/Payroll/GuardPayrollApproval.php',
        'app/Services/Payroll/BankCreditCsv.php',
        'app/Services/Payroll/PayrollSummaryXlsx.php',
        'app/Http/Controllers/Estate/PayrollController.php',
        'app/Http/Controllers/Gemini/PayrollController.php',
        'app/Http/Controllers/Api/V1/Guard/PayslipsController.php',
        'app/Services/Dispatch/ShiftClock.php',
        'app/Services/Tracking/GuardSignal.php',
    ];

    $found = [];

    foreach ($files as $file) {
        foreach (token_get_all((string) file_get_contents(payrollAreaRoot().'/'.$file)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            if (str_contains($token[1], '—') && trim($token[1], "'\"") !== '—') {
                $found[] = $file.':'.$token[2].': '.mb_substr($token[1], 0, 90);
            }
        }
    }

    expect($found)->toBe([], implode("\n", $found));
});

it('draws no em dash on the payroll pages of either console, the empty amount\'s lone dash aside', function () {
    $pages = array_merge(
        glob(payrollAreaRoot().'/resources/js/Pages/Estate/Payroll/*.vue') ?: [],
        glob(payrollAreaRoot().'/resources/js/Pages/Gemini/Payroll/*.vue') ?: [],
    );

    expect($pages)->not->toBe([]);

    $found = [];

    foreach ($pages as $page) {
        $source = (string) file_get_contents($page);
        $source = (string) preg_replace('/<!--.*?-->/s', '', $source);
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            $code = (string) preg_replace('#^\s*//.*$#', '', $line);

            if (str_contains($code, '—') && ! preg_match("/['\"]—['\"]/", $code)) {
                $found[] = basename($page).': '.trim(mb_substr($code, 0, 110));
            }
        }
    }

    expect($found)->toBe([], implode("\n", $found));
});
