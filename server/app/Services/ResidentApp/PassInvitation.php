<?php

namespace App\Services\ResidentApp;

use App\Models\Estate\VisitorPass;
use App\Support\SiteTime;
use Carbon\Carbon;
use DateTimeInterface;

/**
 * THE WORDS THAT GO WITH A VISITOR PASS (M18F §4) — the caption under the picture, and the words-only version for a
 * text message. Written here once; the Resident App writes the very same words when it has no connection
 * (apps/resident/src/features/visitors/passes.ts `passInvitation`), and a test on each side holds the same cases.
 *
 * The rules, from the client, as M98 (Hassam, 12 Oct 2026) left them:
 *   - the date appears ONCE, in the one form every screen uses: "27 Sep 2026" (it was "Sunday 27 September");
 *   - NO CLOCK NAMED (it said "Jamaica time"): "Everything on a pass is estate time by definition: the estate is in
 *     Jamaica, the gate is in Jamaica, the visitor arrives in Jamaica." The times are still the estate's clock;
 *   - the host's name is on it, so the visitor knows who invited them;
 *   - the code stands out (WhatsApp shows *CODE* in bold);
 *   - no app jargon — no "e-Pass", no "QR", no "signed";
 *   - the greeting takes a comma, never a dash (Law 3: no em dash in anything a person reads), as the app's does.
 *
 * Each pass type is greeted as what it is: a guest, a regular visitor, a contractor, a delivery.
 *
 * THE TEXT MESSAGE is kept to the plain GSM alphabet — ASCII hyphens, no dashes or curly quotes — because one character
 * outside it makes the whole message UCS-2, 70 characters a segment instead of 160, and the words below would cost three
 * or four messages instead of two.
 */
final class PassInvitation
{
    /**
     * @param  array{estate: string, unit: ?string, phase: ?string, host: string, zone: string}  $at
     * @return array{message: string, sms: string}
     */
    public static function for(VisitorPass $pass, array $at): array
    {
        return self::words((string) $pass->category, (string) $pass->visitor_name, (string) $pass->code, $pass->valid_from, $pass->valid_to, $at);
    }

    /**
     * @param  array{estate: string, unit: ?string, phase: ?string, host: string, zone: string}  $at
     * @return array{message: string, sms: string}
     */
    public static function words(string $category, string $visitor, string $code, DateTimeInterface $from, DateTimeInterface $to, array $at): array
    {
        $zone = SiteTime::valid($at['zone']);
        $a = Carbon::instance($from)->setTimezone($zone);
        $b = Carbon::instance($to)->setTimezone($zone);
        $name = self::tidy($visitor) !== '' ? self::tidy($visitor) : 'there';
        $host = self::tidy($at['host']);
        $estate = self::tidy($at['estate']);
        $unit = self::tidy((string) $at['unit']);
        $phase = self::tidy((string) $at['phase']);

        // "Phoenix Park Village 1, Lot 47 (Phase 2)" — each part only where there is one.
        $place = $estate.($unit !== '' ? ', '.$unit : '').($phase !== '' && $unit !== '' ? ' ('.$phase.')' : '');
        $placeShort = $estate.($unit !== '' ? ', '.$unit : '');

        $sameDay = $a->isSameDay($b);
        // "27 Sep 2026, 8:58 – 11:00 AM" · "27 Sep 2026, 6:00 AM to 3 Oct 2026, 6:00 PM": the same in the text message,
        // with a plain hyphen in its range.
        $when = $sameDay ? $a->format('j M Y').', '.self::range($a, $b, ' – ') : $a->format('j M Y, g:i A').' to '.$b->format('j M Y, g:i A');
        $whenSms = $sameDay ? $a->format('j M Y').', '.self::range($a, $b, '-') : $a->format('j M Y, g:i A').' to '.$b->format('j M Y, g:i A');
        $sent = $host !== '' ? "\n\nSent by {$host}" : '';
        $sentSms = $host !== '' ? " Sent by {$host}." : '';

        [$hello, $helloSms, $gate] = match ($category) {
            'recurring' => [
                "Hi {$name}, here is your pass for {$place}.",
                "Hi {$name} - your pass for {$placeShort}, valid",
                'Show this pass at the gate each time you come, or give the guard the code *'.$code.'*.',
            ],
            'contractor' => [
                "Hello {$name}, you're booked to work at {$place}.",
                "Hello {$name} - you're booked to work at {$placeShort} on",
                'Show this pass at the gate, or give the guard the code *'.$code.'*. Please have your ID ready.',
            ],
            'delivery' => [
                "Hello {$name}, a delivery to {$place} is expected.",
                "Hello {$name} - a delivery to {$placeShort} is expected",
                'Show this pass at the gate, or give the guard the code *'.$code.'*.',
            ],
            default => [
                "Hi {$name}, you're expected at {$place}.",
                "Hi {$name} - you're expected at {$placeShort} on",
                'Show this pass at the gate, or give the guard the code *'.$code.'*.',
            ],
        };

        $message = "{$hello}\n\n".($category === 'recurring' && ! $sameDay ? 'Valid from ' : '')."{$when}.\n\n{$gate}{$sent}";

        $smsGate = $category === 'contractor' ? " Give the guard the code {$code} at the gate, with your ID." : " Give the guard the code {$code} at the gate.";
        $sms = match ($category) {
            'recurring' => "{$helloSms} ".($sameDay ? '' : 'from ')."{$whenSms}.{$smsGate}{$sentSms}",
            'delivery' => "{$helloSms} {$whenSms}.{$smsGate}{$sentSms}",
            default => "{$helloSms} {$whenSms}.{$smsGate}{$sentSms}",
        };

        return ['message' => $message, 'sms' => $sms];
    }

    /** "8:58 – 11:00 AM", or "11:00 PM – 1:00 AM": one meridiem when both ends share it. */
    private static function range(Carbon $a, Carbon $b, string $dash): string
    {
        return $a->format('A') === $b->format('A') ? $a->format('g:i').$dash.$b->format('g:i A') : $a->format('g:i A').$dash.$b->format('g:i A');
    }

    private static function tidy(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
