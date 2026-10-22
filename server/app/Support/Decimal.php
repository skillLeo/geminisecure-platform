<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minor units as the decimal string an app displays — "38450.00" (13 D2, D3).
 *
 * A STRING, NOT A FLOAT. JSON numbers become doubles on a phone, and a double
 * cannot hold 0.10; the string is exactly what the ledger holds, and the app
 * formats it without doing arithmetic on it.
 */
final class Decimal
{
    public static function of(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
