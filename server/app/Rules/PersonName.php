<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A PERSON'S NAME THAT LOOKS LIKE ONE (E2E-M1). The technician's name on Assign vendor took "djskb", and the household
 * was told a technician called djskb was coming. A name is letters, with the spaces, apostrophes, hyphens and dots
 * names are written with; two letters at least; and a vowel somewhere in it, which is what keyboard noise lacks. The
 * message is the validation standard's (M81 §8.1): what is wrong and what right looks like, beside the field.
 */
final class PersonName implements ValidationRule
{
    public const MESSAGE = 'That does not look like a name. Example: Owen Grant.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = is_string($value) ? trim($value) : '';

        $shaped = preg_match("/^\\p{L}[\\p{L}\\p{M}'’ .\\-]*$/u", $name) === 1;
        $letters = (int) preg_match_all('/\p{L}/u', $name);
        $vowel = preg_match('/[aeiouyàáâãäåæèéêëìíîïòóôõöøùúûüýÿ]/iu', $name) === 1;

        if (! $shaped || $letters < 2 || ! $vowel) {
            $fail(self::MESSAGE);
        }
    }
}
