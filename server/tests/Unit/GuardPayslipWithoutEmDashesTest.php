<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The guard's payslip, in the app and on the PDF, says it without an em dash (M53 Law 3; the test campaign, 12 Oct)
|--------------------------------------------------------------------------
|
| The app's note under Education Tax read "On statutory income — gross less NIS.", and the PDF a guard keeps read
| "Overtime — time and a half", "NET PAY — October 2026", "Year to date — 2026" and, in its title, "Payslip — ...".
| Read from the sources, so a new one cannot slip back in: every string literal of the controller, and the template's
| text once its Blade comments and the lone "—" that stands for an empty amount (Law 5's decision, not this one) are
| taken out.
|
*/

it('writes no em dash in any string the payslip address sends the Guard App', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Api/V1/Guard/PayslipsController.php');

    $found = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && str_contains($token[1], '—')) {
            $found[] = 'line '.$token[2].': '.mb_substr($token[1], 0, 90);
        }
    }

    expect($found)->toBe([], implode("\n", $found));
});

it('prints no em dash on the payslip PDF a guard keeps, the empty amount\'s lone dash aside', function () {
    $template = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/documents/payslip.blade.php');

    $read = preg_replace('/\{\{--.*?--\}\}/s', '', $template);
    $read = str_replace("?? '—'", '', (string) $read);

    $found = [];

    foreach (preg_split('/\R/', (string) $read) ?: [] as $number => $line) {
        if (str_contains($line, '—')) {
            $found[] = 'line '.($number + 1).': '.trim(mb_substr($line, 0, 110));
        }
    }

    expect($found)->toBe([], implode("\n", $found));
});
