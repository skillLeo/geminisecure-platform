<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;
use Throwable;

/**
 * THE ESTATE'S OWN EXAMPLE OF A PHONE NUMBER (M32 F7). "That does not look like a Jamaican number … for example +92 301
 * 111 6259": the example was written into the rule (`PhoneNumber::ABROAD`, "a tester in Pakistan"), not taken from the
 * viewer's browser as it seemed, and it was Pakistan's on a Jamaican estate's form. An example is the ESTATE'S country
 * now, from the estate's own record, never from the viewer's browser, device, locale or address.
 *
 * THE COUNTRY IS THE ESTATE'S TIME ZONE'S. An estate stores no country; it stores the zone its day is kept in
 * (`tenants.timezone`, America/Jamaica at Phoenix Park), and the zone database names the country that keeps each zone.
 * Outside an estate — the Gemini console's own forms — it is the platform's home, Jamaica, where every client is.
 *
 * WHAT THE PLATFORM CAN READ IS NOT EVERY COUNTRY'S LOCAL HABIT. A number typed without "+" is read as North American
 * (`JamaicanNumber`: Jamaica's 876 and 658 are inside that plan), so an estate inside it is shown a local example and an
 * estate outside it is shown its number written with "+", which is the form that is read there.
 */
final class EstatePhone
{
    /** The platform's home: every client so far, and the Gemini console's own forms. */
    public const HOME = 'JM';

    /**
     * The examples, by country. Every number is in a range set aside for fiction or examples (555-01xx in the North
     * American plan, Ofcom's 07700 900xxx), so no example is anybody's phone.
     *
     * @var array<string, array{adjective: string, local: string, international: string}>
     */
    private const EXAMPLES = [
        'JM' => ['adjective' => 'Jamaican', 'local' => '876 555 0123', 'international' => '+1 876 555 0123'],
        'TT' => ['adjective' => 'Trinidad and Tobago', 'local' => '868 555 0123', 'international' => '+1 868 555 0123'],
        'BB' => ['adjective' => 'Barbadian', 'local' => '246 555 0123', 'international' => '+1 246 555 0123'],
        'BS' => ['adjective' => 'Bahamian', 'local' => '242 555 0123', 'international' => '+1 242 555 0123'],
        'KY' => ['adjective' => 'Caymanian', 'local' => '345 555 0123', 'international' => '+1 345 555 0123'],
        'US' => ['adjective' => 'US', 'local' => '212 555 0123', 'international' => '+1 212 555 0123'],
        'CA' => ['adjective' => 'Canadian', 'local' => '416 555 0123', 'international' => '+1 416 555 0123'],
        'GB' => ['adjective' => 'UK', 'local' => '07700 900123', 'international' => '+44 7700 900123'],
    ];

    /** The North American Numbering Plan's countries among those above: a number typed without "+" is read as theirs. */
    private const NANP = ['JM', 'TT', 'BB', 'BS', 'KY', 'US', 'CA'];

    /** The country an estate is in, from its time zone; the platform's home outside an estate, or for a zone with none. */
    public static function country(?string $tenantId = null): string
    {
        $inEstate = ($tenantId !== null && $tenantId !== '') || (function_exists('tenant') && tenant() !== null);

        if (! $inEstate) {
            return self::HOME;
        }

        try {
            $code = (new DateTimeZone(SiteTime::zone($tenantId)))->getLocation()['country_code'] ?? '';
        } catch (Throwable) {
            $code = '';
        }

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : self::HOME;
    }

    /**
     * The example a page's phone boxes show (the shared `phoneExample` prop): as typed here, and in full.
     *
     * @return array{local: string, international: string}
     */
    public static function example(?string $tenantId = null): array
    {
        $phone = self::forEstate($tenantId);

        return ['local' => $phone['local'], 'international' => $phone['international']];
    }

    /**
     * What a phone field on this estate's forms says: its example, and the two sentences a number that is not one gets.
     *
     * @return array{country: string, local: string, international: string, typed_without_plus: bool, invalid: string, abroad: string}
     */
    public static function forEstate(?string $tenantId = null): array
    {
        $country = self::country($tenantId);
        $nanp = in_array($country, self::NANP, true);
        $example = self::EXAMPLES[$country] ?? null;

        if ($example === null) {
            // A country this table does not hold: no invented example, only how to write one.
            $sentence = 'That does not look like a phone number. Write it with + and the country code first.';

            return ['country' => $country, 'local' => '+…', 'international' => '+…', 'typed_without_plus' => false, 'invalid' => $sentence, 'abroad' => $sentence];
        }

        $local = $nanp ? $example['local'] : $example['international'];

        return [
            'country' => $country,
            // What a form's box shows before anything is typed: the number as the platform reads it here.
            'local' => $local,
            'international' => $example['international'],
            'typed_without_plus' => $nanp,
            'invalid' => $nanp
                ? 'That does not look like a phone number. Example: '.$example['local'].'.'
                : 'That does not look like a phone number. Write it with + and the country code, for example '.$example['international'].'.',
            'abroad' => $nanp
                ? 'That does not look like a '.$example['adjective'].' number. For a number in another country, start with + and the country code, for example '.$example['international'].'.'
                : 'That does not look like a phone number. Write it with + and the country code, for example '.$example['international'].'.',
        ];
    }
}
