<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\EstatePhone;
use App\Support\JamaicanNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A PHONE NUMBER THAT LOOKS LIKE ONE (M18C §6), read by `JamaicanNumber::normalise` — the same reading that stores it,
 * so a number this passes is always one the platform can store and dial. The message says what is wrong and what a
 * right one looks like, in the words the client asked for, beside the field.
 *
 * THE EXAMPLE IS THE ESTATE'S COUNTRY'S (M32 F7): the estate the request is in, or the one named, never the viewer's.
 * It used to be written here as Pakistan's ("+92 301 111 6259"), whichever estate the form was for.
 */
final class PhoneNumber implements ValidationRule
{
    /** A Jamaican estate's sentence, for a caller outside any estate that names none; an estate's own is `EstatePhone`. */
    public const MESSAGE = 'That does not look like a phone number. Example: 876 555 0123.';

    /** @param  string|null  $tenantId  the estate the number is for, when the request is not inside it (a guard's, in the Gemini console) */
    public function __construct(private readonly ?string $tenantId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || JamaicanNumber::normalise($value) === null) {
            $digits = is_string($value) ? (string) preg_replace('/\D+/', '', $value) : '';
            $phone = EstatePhone::forEstate($this->tenantId);

            // Ten digits or more with no "+": a real number somewhere else, typed without its country code.
            $fail(strlen($digits) >= 10 && ! str_starts_with(trim((string) $value), '+') ? $phone['abroad'] : $phone['invalid']);
        }
    }
}
