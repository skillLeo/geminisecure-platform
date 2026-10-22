<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Money\Money;

/**
 * Formats money for display, in one place.
 *
 * Every amount in this system is stored as an integer of minor units plus an
 * explicit ISO currency. Formatting is therefore a presentation concern and
 * must never involve dividing by 100 by hand: the number of decimal places
 * belongs to the currency, and a hand-rolled divide is silently wrong for any
 * currency that does not have two.
 *
 * ASSUMPTION Q-001: JMD and en_JM, pending a ruling on the platform currency.
 * No supplied document names one.
 */
class MoneyFormatter
{
    public const DEFAULT_CURRENCY = 'JMD';

    public const DEFAULT_LOCALE = 'en_JM';

    /** "J$1,234.56" from minor units. */
    public static function fromMinor(int $minor, string $currency = self::DEFAULT_CURRENCY): string
    {
        return self::format(Money::ofMinor($minor, $currency));
    }

    /**
     * "$243,900" — headline money, decimals dropped only when there are none.
     *
     * The boards write dashboard and directory figures without cents, because
     * a platform MRR to the penny is noise at that size. `allowWholeNumber`
     * does exactly that and no more: a fractional amount still prints in full,
     * so this can never quietly round a real figure away.
     *
     * It exists here rather than in each service because two of them had
     * already grown their own version and disagreed — one hand-picked "J$",
     * the other took the locale's symbol — so the dashboard and the client
     * directory wrote the same currency two different ways on adjacent
     * screens. One formatter, one answer.
     */
    public static function whole(int $minor, string $currency = self::DEFAULT_CURRENCY): string
    {
        $money = Money::ofMinor($minor, $currency);

        if (! extension_loaded('intl')) {
            return self::format($money);
        }

        return $money->formatToLocale(self::DEFAULT_LOCALE, allowWholeNumber: true);
    }

    public static function format(Money $money, string $locale = self::DEFAULT_LOCALE): string
    {
        /*
         * formatToLocale needs ext-intl. It is present here, but a formatter
         * that throws in production because an extension is missing would take
         * a whole screen down over a currency symbol, so fall back to the
         * currency code and the correctly scaled amount instead.
         */
        if (extension_loaded('intl')) {
            return $money->formatToLocale($locale);
        }

        return $money->getCurrency()->getCurrencyCode().' '.$money->getAmount();
    }
}
