<?php

declare(strict_types=1);

namespace App\Support;

/**
 * AN ADDRESS THAT CAN RECEIVE MAIL, AND THE ONE THE PERSON PROBABLY MEANT (M18G §4).
 *
 * Found on production, 27 Sep: a household member signed in as "…@gmail.comm". The relay accepted both codes — it
 * accepts anything well-formed — and the screen truthfully said they had been sent, to a domain that does not exist.
 * Nothing arrived, and the person waited.
 *
 *   suggest()        "gmail.comm" → "gmail.com": the common misspellings of the common providers, and a one-letter
 *                    slip from one of them. A suggestion is only ever offered — people do own unusual domains.
 *   receivesMail()   whether the domain publishes anywhere to deliver to: an MX record, or failing that an address
 *                    (RFC 5321 §5.1's implicit MX). A domain with neither cannot receive anything, and that is worth
 *                    knowing in the half-second before claiming a send. A lookup that cannot be made (no DNS at all) is
 *                    NOT a "no": the address is given the benefit of the doubt rather than a false refusal.
 */
final class EmailAddress
{
    /** The providers people in Jamaica actually use, and the domains a slip turns them into. */
    private const PROVIDERS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'live.com', 'aol.com', 'msn.com',
        'yahoo.co.uk', 'hotmail.co.uk', 'protonmail.com', 'proton.me', 'me.com', 'mail.com', 'flowja.com', 'cwjamaica.com',
    ];

    private const KNOWN_SLIPS = [
        'gmail.comm' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.cm' => 'gmail.com',
        'gmail.om' => 'gmail.com', 'gmail.cim' => 'gmail.com', 'gmail.vom' => 'gmail.com', 'gmail.xom' => 'gmail.com',
        'gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmali.com' => 'gmail.com',
        'gnail.com' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gmail.coml' => 'gmail.com', 'g.mail.com' => 'gmail.com',
        'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.con' => 'hotmail.com', 'hotmai.com' => 'hotmail.com',
        'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yhoo.com' => 'yahoo.com',
        'outlok.com' => 'outlook.com', 'outloo.com' => 'outlook.com', 'outlook.con' => 'outlook.com',
        'iclod.com' => 'icloud.com', 'icloud.con' => 'icloud.com', 'icoud.com' => 'icloud.com',
    ];

    /** The address with its likely meant domain, or null when there is nothing to suggest. */
    public static function suggest(string $address): ?string
    {
        $address = trim($address);
        $at = strrpos($address, '@');

        if ($at === false) {
            return null;
        }

        $local = substr($address, 0, $at);
        $domain = mb_strtolower(substr($address, $at + 1));

        if ($local === '' || $domain === '' || in_array($domain, self::PROVIDERS, true)) {
            return null;
        }

        $meant = self::KNOWN_SLIPS[$domain] ?? null;

        if ($meant === null) {
            foreach (self::PROVIDERS as $provider) {
                // One letter added, dropped, swapped or changed from a provider people use.
                if (abs(strlen($provider) - strlen($domain)) <= 1 && levenshtein($domain, $provider) === 1) {
                    $meant = $provider;
                    break;
                }
            }
        }

        return $meant === null ? null : $local.'@'.$meant;
    }

    /** Whether the address's domain publishes somewhere to deliver mail to. True when DNS cannot be asked at all. */
    public static function receivesMail(string $address): bool
    {
        if (! (bool) config('mail.mailers.smtp.check_domains', true)) {
            return true;
        }

        $domain = mb_strtolower(trim((string) substr($address, (int) strrpos($address, '@') + 1)));

        if ($domain === '' || ! str_contains($domain, '.')) {
            return false;
        }

        $ascii = function_exists('idn_to_ascii') ? (idn_to_ascii($domain) ?: $domain) : $domain;

        try {
            if (@checkdnsrr($ascii.'.', 'MX')) {
                return true;
            }

            if (@checkdnsrr($ascii.'.', 'A') || @checkdnsrr($ascii.'.', 'AAAA')) {
                return true;
            }

            // Nothing for the domain: but if DNS itself is not answering (a known domain fails too), do not refuse.
            return ! @checkdnsrr('gmail.com.', 'MX');
        } catch (\Throwable) {
            return true;
        }
    }

    /** "That address's domain (gmail.comm) cannot receive email. Did you mean andrea@gmail.com?" */
    public static function cannotReceive(string $address): string
    {
        $domain = (string) substr($address, (int) strrpos($address, '@') + 1);
        $meant = self::suggest($address);

        return 'That address cannot receive email — "'.$domain.'" has nowhere to deliver to.'.($meant === null ? ' Check the spelling.' : ' Did you mean '.$meant.'?');
    }
}
