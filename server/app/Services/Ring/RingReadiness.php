<?php

declare(strict_types=1);

namespace App\Services\Ring;

/**
 * WHETHER A GUARD'S PHONE CAN RING LIKE A CALL — read on the phone, decided here (spec item 3).
 *
 * The Guard App reads its own settings and reports them with every `push.register`, and
 * with every `shown` receipt as a compact bitmask. The SERVER decides what they add up to,
 * so that the console's "call-style ready" means one thing on every screen and no phone
 * can talk itself into a better answer than its settings give.
 *
 * NOTHING HERE EVER REFUSES A REGISTRATION (G1). `push.register` validates `token`,
 * `platform` and `muted` and nothing else; `ring` is handed to `parse()` raw, which never
 * throws. A value it does not know is `unknown`, and `unknown` in a key that decides
 * `not_ready` counts as `not_ready` — so a doubtful report always lands on the side that
 * is ALSO sent the ordinary visible alert every round. The one thing worse than a phone
 * the console under-rates is a phone that gets no push at all because its report was
 * refused, and that is what a strict rule here would have caused.
 *
 *   not_ready  not configured, notifications off, the ring channel off, full-screen alerts
 *              off, alarm volume at zero, no alarm tone — or any of those unknown
 *   partial    rings, but: Do Not Disturb bypass not on, battery optimised, background
 *              restricted, alarm volume low, no screen lock, or anything else unknown
 *   ready      everything above in order
 */
final class RingReadiness
{
    public const READY = 'ready';

    public const PARTIAL = 'partial';

    public const NOT_READY = 'not_ready';

    public const UNKNOWN = 'unknown';

    /**
     * Every key the phone may send, and the values each may take. Anything else is dropped
     * (an unknown key) or read as `unknown` (an unknown value).
     *
     * @var array<string, list<string>>
     */
    public const VALUES = [
        'notifications' => ['on', 'off'],
        'ring_channel' => ['on', 'off'],
        'full_screen' => ['on', 'off', 'not_needed'],
        'dnd_bypass' => ['on', 'off', 'unknown'],
        'battery' => ['exempt', 'optimised', 'unknown'],
        'background' => ['allowed', 'restricted', 'unknown'],
        'alarm_volume' => ['ok', 'low', 'off'],
        'alarm_tone' => ['set', 'none'],
        'screen_lock' => ['on', 'off'],
    ];

    /** The keys whose doubt means "cannot ring", not "rings, but". */
    private const DECIDES_NOT_READY = ['configured', 'notifications', 'ring_channel', 'full_screen', 'alarm_volume', 'alarm_tone'];

    /**
     * THE `ready` BITMASK ON A RECEIPT (G15) — one bit per readable row of the phone's
     * readiness screen, in that screen's order. A bit that is 0 is the row OFF. Alarm
     * volume has one bit, so a 0 is read as `off` rather than `low`: of the two, it is the
     * reading that never over-states what a phone can do. A mask comes from the ring module
     * itself, so the phone is `configured`.
     *
     * @var array<int, array{0: string, 1: string, 2: string}> bit => [key, value when 1, value when 0]
     */
    public const BITS = [
        0 => ['notifications', 'on', 'off'],
        1 => ['ring_channel', 'on', 'off'],
        2 => ['full_screen', 'on', 'off'],
        3 => ['dnd_bypass', 'on', 'off'],
        4 => ['battery', 'exempt', 'optimised'],
        5 => ['background', 'allowed', 'restricted'],
        6 => ['alarm_volume', 'ok', 'off'],
        7 => ['alarm_tone', 'set', 'none'],
        8 => ['screen_lock', 'on', 'off'],
    ];

    /**
     * The phone's report, kept to what is known — or null when it is not a report at all.
     *
     * Never throws, whatever it is handed (G1).
     *
     * @return array<string, mixed>|null
     */
    public static function parse(mixed $ring): ?array
    {
        if (! is_array($ring) || array_is_list($ring) && $ring !== []) {
            return null;
        }

        $out = ['v' => self::version($ring['v'] ?? null)];

        $configured = $ring['configured'] ?? null;
        $out['configured'] = match (true) {
            $configured === true, $configured === 1, $configured === '1', $configured === 'true' => true,
            $configured === false, $configured === 0, $configured === '0', $configured === 'false' => false,
            default => self::UNKNOWN,
        };

        foreach (self::VALUES as $key => $values) {
            $value = $ring[$key] ?? null;
            $out[$key] = is_string($value) && in_array($value, $values, true) ? $value : self::UNKNOWN;
        }

        $sdk = $ring['sdk'] ?? null;
        $sdk = is_int($sdk) || (is_string($sdk) && ctype_digit($sdk)) ? (int) $sdk : null;
        $out['sdk'] = $sdk !== null && $sdk >= 21 && $sdk <= 99 ? $sdk : null;

        $maker = $ring['maker'] ?? null;
        $maker = is_string($maker) ? mb_substr(trim((string) preg_replace('/[^a-z0-9 _-]/', '', strtolower($maker))), 0, 20) : '';
        $out['maker'] = $maker === '' ? null : $maker;

        return $out;
    }

    /**
     * The report a receipt's bitmask carries, or null when it is not a mask.
     *
     * @param  array<string, mixed>|null  $previous  the report the phone last registered with
     * @return array<string, mixed>|null
     */
    public static function fromBits(mixed $ready, ?array $previous = null): ?array
    {
        if (is_string($ready) && ctype_digit($ready)) {
            $ready = (int) $ready;
        }

        if (! is_int($ready) || $ready < 0 || $ready > 0xFFFF) {
            return null;
        }

        // What the mask cannot carry — the version, the handset — is kept from the registration.
        $out = [
            'v' => self::version($previous['v'] ?? 1) ?? 1,
            'configured' => true,
        ];

        foreach (self::BITS as $bit => [$key, $on, $off]) {
            $out[$key] = ($ready & (1 << $bit)) !== 0 ? $on : $off;
        }

        $out['sdk'] = $previous['sdk'] ?? null;
        $out['maker'] = $previous['maker'] ?? null;

        return $out;
    }

    /**
     * What the report adds up to — decided here, never by the phone. Null for no report.
     *
     * @param  array<string, mixed>|null  $report
     */
    public static function state(?array $report): ?string
    {
        if ($report === null) {
            return null;
        }

        foreach (self::DECIDES_NOT_READY as $key) {
            $value = $report[$key] ?? self::UNKNOWN;

            if ($value === self::UNKNOWN || $value === false || in_array($value, ['off', 'none'], true)) {
                return self::NOT_READY;
            }
        }

        $partial = ($report['dnd_bypass'] ?? self::UNKNOWN) !== 'on'
            || ($report['battery'] ?? self::UNKNOWN) !== 'exempt'
            || ($report['background'] ?? self::UNKNOWN) !== 'allowed'
            || ($report['alarm_volume'] ?? self::UNKNOWN) === 'low'
            || ($report['screen_lock'] ?? self::UNKNOWN) !== 'on';

        return $partial ? self::PARTIAL : self::READY;
    }

    /**
     * The rows a person reads, in the phone screen's order: each ON or OFF, what it is, and —
     * when it is not right — what is wrong, in the words a summary line uses.
     *
     * @param  array<string, mixed>|null  $report
     * @return list<array{key: string, label: string, on: bool|null, value: string, problem: string|null}>
     */
    public static function rows(?array $report): array
    {
        if ($report === null) {
            return [];
        }

        $labels = [
            'notifications' => ['Notifications', ['on' => true, 'off' => false], ['off' => 'notifications OFF']],
            'ring_channel' => ['Call-style alert channel', ['on' => true, 'off' => false], ['off' => 'the call-style channel OFF']],
            'full_screen' => ['Full-screen alerts', ['on' => true, 'not_needed' => true, 'off' => false], ['off' => 'full-screen alerts OFF']],
            'dnd_bypass' => ['Do Not Disturb bypass', ['on' => true, 'off' => false], ['off' => 'Do Not Disturb bypass OFF']],
            'battery' => ['Battery optimisation exempt', ['exempt' => true, 'optimised' => false], ['optimised' => 'battery saver ON']],
            'background' => ['Background activity allowed', ['allowed' => true, 'restricted' => false], ['restricted' => 'background activity restricted']],
            'alarm_volume' => ['Alarm volume', ['ok' => true, 'low' => false, 'off' => false], ['low' => 'alarm volume low', 'off' => 'alarm volume OFF']],
            'alarm_tone' => ['Alarm tone set', ['set' => true, 'none' => false], ['none' => 'no alarm tone']],
            'screen_lock' => ['Screen lock', ['on' => true, 'off' => false], ['off' => 'no screen lock']],
        ];

        $rows = [];

        foreach ($labels as $key => [$label, $truth, $problems]) {
            $value = (string) ($report[$key] ?? self::UNKNOWN);
            $on = $truth[$value] ?? null;

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'on' => $on,
                'value' => $value,
                'problem' => $on === true ? null : ($problems[$value] ?? mb_strtolower($label).' not known'),
            ];
        }

        return $rows;
    }

    private static function version(mixed $v): ?int
    {
        if (is_string($v) && ctype_digit($v)) {
            $v = (int) $v;
        }

        return is_int($v) && $v >= 1 && $v <= 999 ? $v : null;
    }
}
