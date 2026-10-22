<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\EmailAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * AN EMAIL ADDRESS WHOSE DOMAIN CAN RECEIVE MAIL (M18G §4.2) — so "…@gmail.comm" is caught when it is typed, with the
 * address it probably meant, instead of being stored and written to for ever. Run after `email`.
 */
final class ReceivesMail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_contains($value, '@') && ! EmailAddress::receivesMail($value)) {
            $fail(EmailAddress::cannotReceive($value));
        }
    }
}
