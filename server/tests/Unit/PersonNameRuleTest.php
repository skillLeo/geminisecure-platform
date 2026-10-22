<?php

declare(strict_types=1);

use App\Rules\PersonName;

/*
|--------------------------------------------------------------------------
| A person's name that looks like one (E2E-M1, register DR-111)
|--------------------------------------------------------------------------
|
| The technician's name on Assign vendor took "djskb", and the household was told a technician called djskb was
| coming. A name is letters, with the spaces, apostrophes, hyphens and dots names are written with; two letters at
| least; and a vowel somewhere in it. The message follows the standard (M81 §8.1): what is wrong and what right
| looks like.
|
*/

function nameRefusal(string $value): ?string
{
    $refusal = null;

    (new PersonName)->validate('name', $value, function (string $message) use (&$refusal): void {
        $refusal = $message;
    });

    return $refusal;
}

it('takes the names people have', function (string $name) {
    expect(nameRefusal($name))->toBeNull();
})->with(['Owen Grant', "D'Angelo Brown", 'Jean-Luc Picard', 'O. Grant', 'Marcia Bennett-Reid', 'Tracy Ng', 'José Ángel', 'Ty']);

it('refuses what is not a name, in the standard\'s words', function (string $typed) {
    expect(nameRefusal($typed))->toBe(PersonName::MESSAGE);
})->with(['djskb', '12345', 'x', 'Owen 2', '@@@', 'Owen_Grant', '---', 'zzzz']);

it('says what is wrong and what right looks like', function () {
    expect(PersonName::MESSAGE)->toBe('That does not look like a name. Example: Owen Grant.');
});
