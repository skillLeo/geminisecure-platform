<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE DEMONSTRATION'S PLACEHOLDER PEOPLE, TOLD APART FROM REAL ONES (M32 F1).
 *
 * `EstateFinanceSeeder` gave every occupied lot a primary resident named "Lot N householder", at `lotN@phoenixpark.test`,
 * a domain that takes no mail. Nobody created them, and on a live estate they stand beside real people: a resident added
 * at Lot 413 became a member under one, and the register showed the placeholder instead of him.
 *
 * A resident is a SAMPLE when their address is on that domain or their name is that pattern; a household is a sample
 * when the person who holds it is. The consoles mark them `sample` in the chip M30's Places uses; the Resident App does
 * not show them at all. Nothing here removes one: whether the demonstration's rows go is KI-25's decision, and
 * `residents:retire-samples` is the one, reviewed, act that hides a placeholder once a real person holds its household.
 */
final class SampleResidents
{
    /** The demonstration's own mail domain: deliberately unroutable. */
    public const DOMAIN = '@phoenixpark.test';

    /** "Lot 413 householder", "Lot 1999A householder". */
    public const NAME_PATTERN = '/^lot\s+\S+\s+householder$/i';

    public static function isSample(?string $name, ?string $email): bool
    {
        return ($email !== null && str_ends_with(strtolower(trim($email)), self::DOMAIN))
            || ($name !== null && preg_match(self::NAME_PATTERN, trim($name)) === 1);
    }

    /**
     * WHETHER A SCREEN MARKS ONE AS A SAMPLE (M60 §C3; Hassam, 9 October 2026: "The mark never lives in a field a human
     * reads … that is where it would surface in front of the committee"). While the platform is a demonstration
     * (`demo.enabled`) every seeded person IS the demonstration, and the chip put the word "sample" beside every one of
     * them in front of the committee ("Omar Brown sample"); so the estate console draws them as the people they stand
     * for. With demonstration mode off, a placeholder still standing beside real people is told apart (M32 F1).
     */
    public static function marked(bool $sample): bool
    {
        return $sample && ! (bool) config('demo.enabled');
    }

    /**
     * The same rule as a SQL condition on a residents table (or its alias), for a query that must tell them apart
     * without reading every row into PHP.
     */
    public static function sql(string $table = 'residents'): string
    {
        return '(LOWER('.$table.".email) LIKE '%".self::DOMAIN."' OR ".$table.".full_name REGEXP '^lot[[:space:]]+[^[:space:]]+[[:space:]]+householder$')";
    }
}
