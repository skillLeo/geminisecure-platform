<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| What the residents pages say carries no em dash (M53 Law 3; the remaining copy, M72 §2.6)
|--------------------------------------------------------------------------
|
| The residents service still wrote em dashes into what people read: the claims queue's title ("Unit claims — 3
| pending"), a claimant's name beside their arrears ("Tanya Simms — 90+ days arrears"), a person's status, the edit
| panel's choice of primary, and five refusals. The middot where two parts sit side by side, a colon or a semicolon
| where a sentence goes on. One sentence keeps its em dash for now: `RESTRICTED_LABEL`, "Access restricted — contact
| management", is the guard's own words at the gate, shared with the Guard App and the API's contract, so it changes
| with them or not at all (the register says so).
|
*/

it('leaves no em dash in a quoted string of the residents service, but the gate\'s shared sentence', function (): void {
    $php = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Estate/Residents.php');

    $offending = collect(explode("\n", $php))
        ->reject(static fn (string $line): bool => (bool) preg_match('#^\s*(\*|//|/\*)#', $line))
        ->reject(static fn (string $line): bool => str_contains($line, 'RESTRICTED_LABEL'))
        ->filter(static fn (string $line): bool => (bool) preg_match("/'(?!—')[^'\n]*—[^'\n]*'/u", $line))
        ->map(static fn (string $line): string => trim($line))
        ->values()
        ->all();

    expect($offending)->toBe([]);
});

/*
 * And what the rest of the lane says (M98 final pass, 12 Oct): the refusals under Add resident's fields, the
 * confirmations after End access and Give access back, the claims queue's choices, the household removal's refusals and
 * the phone number's own sentences, which the Add resident form showed with an em dash when the phone was left blank.
 */
it('leaves no em dash in a quoted string the residents pages show', function (string $file): void {
    $php = (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);

    $offending = collect(explode("\n", $php))
        ->reject(static fn (string $line): bool => (bool) preg_match('#^\s*(\*|//|/\*)#', $line))
        ->filter(static fn (string $line): bool => (bool) preg_match("/'(?!—')[^'\n]*—[^'\n]*'/u", $line))
        ->map(static fn (string $line): string => trim($line))
        ->values()
        ->all();

    expect($offending)->toBe([]);
})->with([
    'app/Http/Controllers/Estate/ResidentsController.php',
    'app/Http/Controllers/Estate/DeletedAccountsController.php',
    'app/Services/Estate/ResidentInvites.php',
    'app/Services/Estate/HouseholdRemoval.php',
    'app/Services/Estate/ResidentImport.php',
    'app/Services/Estate/DeletedAccounts.php',
    'app/Support/EstatePhone.php',
    'app/Models/Estate/UnitClaim.php',
]);
